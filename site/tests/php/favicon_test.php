<?php
declare(strict_types=1);
// Tests du favicon modifiable. Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-favicon-' . getmypid();
putenv("DATA_DIR=$data");
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/entete.php';

$fails = 0;
$count = 0;
function check(string $name, bool $cond): void
{
    global $fails, $count;
    $count++;
    if (!$cond) {
        $fails++;
        echo "ÉCHEC : $name\n";
    }
}
function upload(string $file): array
{
    return ['error' => UPLOAD_ERR_OK, 'tmp_name' => $file, 'size' => filesize($file)];
}

$page = '<html><head><link rel="icon" href="/favicon.svg" type="image/svg+xml"><title>T</title></head><body></body></html>';

check('sans favicon : gabarit d’origine conservé', !favicon_is_custom() && favicon_apply($page) === $page && str_contains(site_finish($page), '/favicon.svg'));
check('sans fichier : aucun favicon', favicon_save(['error' => UPLOAD_ERR_NO_FILE]) === 'Aucun fichier choisi.');

$txt = sys_get_temp_dir() . '/yv-fav-' . getmypid() . '.txt';
file_put_contents($txt, 'pas une image');
check('fichier non image refusé', str_contains((string) favicon_save(upload($txt)), 'pas une image'));

// Image rectangulaire 400×200 : rouge à gauche, bleu à droite (le recadrage garde le centre).
$im = imagecreatetruecolor(400, 200);
imagefill($im, 0, 0, imagecolorallocate($im, 255, 0, 0));
imagefilledrectangle($im, 200, 0, 399, 199, imagecolorallocate($im, 0, 0, 255));
$png = sys_get_temp_dir() . '/yv-fav-' . getmypid() . '.png';
imagepng($im, $png);
check('enregistrement PNG', favicon_save(upload($png)) === null && favicon_is_custom());
foreach (FAVICON_SIZES as $s) {
    $size = @getimagesize((string) favicon_file($s));
    check("taille $s×$s", $size !== false && $size[0] === $s && $size[1] === $s && $size['mime'] === 'image/png');
}
check('taille inconnue', favicon_file(64) === null);

$out = favicon_apply($page);
check('ancien favicon retiré, nouveaux en place', !str_contains($out, 'favicon.svg') && str_contains($out, 'href="/favicon/48.png?v=') && str_contains($out, 'sizes="192x192"') && str_contains($out, 'rel="apple-touch-icon" href="/favicon/180.png?v='));
check('balises dans le <head>', preg_match('#<link rel="apple-touch-icon"[^>]*></head>#', $out) === 1 && substr_count($out, '</head>') === 1);
check('appliqué par site_finish', str_contains(site_finish($page), '/favicon/48.png'));

$fav = imagecreatefrompng((string) favicon_file(48));
check('recadrage carré au centre (moitié rouge, moitié bleue)', (imagecolorat($fav, 4, 24) & 0xFFFFFF) === 0xFF0000 && (imagecolorat($fav, 44, 24) & 0xFFFFFF) === 0x0000FF);
imagedestroy($fav);

// Remplacement puis retour à l’origine.
$im2 = imagecreatetruecolor(100, 100);
imagepng($im2, $png);
check('remplacement', favicon_save(upload($png)) === null);
favicon_reset();
check('rétablir : plus de favicon personnalisé', !favicon_is_custom() && favicon_file(48) === null && favicon_apply($page) === $page);

echo $fails ? "\n$fails/$count test(s) favicon en échec\n" : "$count tests favicon OK\n";
exit($fails ? 1 : 0);
