<?php
declare(strict_types=1);
// Tests des aides d'administration du livre numérique. Lancement : npm run test:php (Docker, PHP 8.3).

$data = sys_get_temp_dir() . '/yv-test-ebadm-' . getmypid();
putenv('DATA_DIR=' . $data);
require __DIR__ . '/../../php/_prive/ebook_admin.php';

$fails = 0;
$count = 0;
function check(string $name, bool $cond): void
{
    global $fails, $count;
    $count++;
    if (!$cond) { $fails++; echo "ÉCHEC : $name\n"; }
}

// --- Prix
foreach (['4,99' => 499, '4.99' => 499, '4,99 €' => 499, ' 4,99€ ' => 499, '5' => 500, '4,9' => 490, '0,01' => 1, '100' => 10000, '100,00' => 10000, "4,99\u{00A0}€" => 499] as $in => $out) {
    check("prix « $in » → $out", ebook_parse_price((string) $in) === $out);
}
foreach (['', '0', '0,00', '-4,99', '100,01', '101', 'abc', '4,999', '4,,99', '1e3', '4 99 99'] as $bad) {
    check("prix « $bad » refusé", ebook_parse_price($bad) === null);
}

// --- CGV
check('cgv vide', ebook_cgv_valid(''));
check('cgv https', ebook_cgv_valid('https://exemple.fr/cgv'));
check('cgv http refusée', !ebook_cgv_valid('http://exemple.fr/cgv'));
check('cgv javascript refusée', !ebook_cgv_valid('javascript:alert(1)'));
check('cgv avec guillemet refusée', !ebook_cgv_valid('https://exemple.fr/"onclick=x'));
check('cgv avec espace refusée', !ebook_cgv_valid('https://exemple.fr/a b'));

// --- Limites php.ini
check('ini 8M', ebook_ini_bytes('8M') === 8 * 1024 * 1024);
check('ini 2G', ebook_ini_bytes('2G') === 2 * 1024 ** 3);
check('ini 512K', ebook_ini_bytes('512K') === 512 * 1024);
check('ini octets', ebook_ini_bytes('1048576') === 1048576);
check('ini illimité', ebook_ini_bytes('-1') === 0 && ebook_ini_bytes('0') === 0);
check('max : 50 Mo si php.ini large', ebook_max_upload_bytes('128M', '128M') === 50 * 1024 * 1024);
check('max : upload_max_filesize plus petit', ebook_max_upload_bytes('2M', '8M') === 2 * 1024 * 1024);
check('max : post_max_size plus petit', ebook_max_upload_bytes('40M', '10M') === 10 * 1024 * 1024);
check('max : illimité ignoré', ebook_max_upload_bytes('-1', '0') === 50 * 1024 * 1024);
check('taille Mo', ebook_format_size(5 * 1024 * 1024) === '5,0 Mo');
check('taille Ko', ebook_format_size(2048) === '2 Ko');

// --- Dépôt du maître
$dest = $data . '/epub/maitre.epub';
$good = $data . '-good.epub';
epub_fixture($good);
$fixed = $data . '-fixed.epub';
epub_fixture($fixed, 3, true, true);
$zip = $data . '-notepub.zip';
$z = new ZipArchive();
$z->open($zip, ZipArchive::CREATE);
$z->addFromString('a.txt', 'bonjour');
$z->close();

[$err, $warn] = ebook_install_master($zip, $dest, 1_000_000);
check('zip non epub refusé', $err !== null && !is_file($dest));
[$err] = ebook_install_master($good, $dest, 100);
check('fichier trop lourd refusé', $err !== null && str_contains($err, 'trop lourd') && !is_file($dest));
[$err] = ebook_install_master($data . '-absent.epub', $dest, 1_000_000);
check('fichier absent refusé', $err !== null);

[$err, $warn] = ebook_install_master($good, $dest, 10_000_000);
check('epub valide installé', $err === null && $warn === null && is_file($dest));
check('maître en 0600', (fileperms($dest) & 0777) === 0600);
check('dossier en 0700', (fileperms(dirname($dest)) & 0777) === 0700);
check('aucun .part résiduel', glob(dirname($dest) . '/*.part') === []);
check('contenu identique', md5_file($dest) === md5_file($good));
$info = ebook_master_info();
check('infos du maître', $info !== null && $info['ok'] && $info['size'] === filesize($good));

$before = md5_file($dest);
ebook_install_master($zip, $dest, 1_000_000);
check('échec : maître en place intact', md5_file($dest) === $before);

[$err, $warn] = ebook_install_master($fixed, $dest, 10_000_000);
check('mise en page fixe : avertissement', $err === null && $warn !== null && str_contains($warn, 'fin'));

echo $fails ? "\n$fails/$count test(s) en échec\n" : "$count tests ebook admin PHP OK\n";
exit($fails ? 1 : 0);
