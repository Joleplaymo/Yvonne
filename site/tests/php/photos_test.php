<?php
declare(strict_types=1);
// Tests du remplacement de photos (nécessite GD). Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-photos-' . getmypid();
putenv("DATA_DIR=$data");
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/photos.php';

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

check('GD disponible', function_exists('imagecreatetruecolor'));

// Image PNG 1200x1800 avec transparence.
$png = tempnam(sys_get_temp_dir(), 'yv') . '.png';
$im = imagecreatetruecolor(1200, 1800);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 100, 100, 1100, 1700, imagecolorallocate($im, 27, 54, 93));
imagepng($im, $png);
imagedestroy($im);

check('emplacement inconnu refusé', photo_save('inconnu', upload($png)) !== null);
check('pas de fichier', photo_save('couverture', ['error' => UPLOAD_ERR_NO_FILE]) === 'Aucun fichier choisi.');
$txt = tempnam(sys_get_temp_dir(), 'yv');
file_put_contents($txt, '<?php echo "pas une image";');
check('fichier non image refusé', photo_save('couverture', upload($txt)) === 'Ce fichier n’est pas une image.');

check('enregistrement PNG', photo_save('couverture', upload($png)) === null);
$ext = function_exists('imagewebp') ? 'webp' : 'jpg';
foreach ([480 => 720, 960 => 1440] as $w => $h) {
    $f = "$data/photos/couverture-$w.$ext";
    $size = @getimagesize($f);
    check("largeur $w générée", $size && $size[0] === $w && $size[1] === $h);
    check("photo_file($w) pointe sur la personnalisée", photo_file('couverture', $w) === $f);
}
check('marquée personnalisée', photo_is_custom('couverture'));
check('largeur inconnue → 480', photo_file('couverture', 123) === "$data/photos/couverture-480.$ext");
check('pas de fichier .tmp restant', !glob("$data/photos/*.tmp"));

// Petite image : jamais agrandie.
$small = tempnam(sys_get_temp_dir(), 'yv') . '.jpg';
$im = imagecreatetruecolor(300, 300);
imagejpeg($im, $small);
imagedestroy($im);
check('enregistrement JPEG', photo_save('partenaire', upload($small)) === null);
check('pas d’agrandissement', getimagesize("$data/photos/partenaire-960.$ext")[0] === 300);

photo_reset('couverture');
check('rétablir supprime la personnalisée', !photo_is_custom('couverture'));
check('données protégées par .htaccess', is_file("$data/.htaccess") || is_file("$data/photos/.htaccess"));

echo $fails ? "\n$fails/$count test(s) photos en échec\n" : "$count tests photos OK\n";
exit($fails ? 1 : 0);
