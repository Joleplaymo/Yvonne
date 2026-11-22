<?php
declare(strict_types=1);
// Tests de l'exemplaire tatoué servi par /telecharger. Lancement : npm run test:php (Docker, PHP 8.3).

$data = sys_get_temp_dir() . '/yv-test-dl-' . getmypid();
putenv('DATA_DIR=' . $data);
require __DIR__ . '/../../php/_prive/telechargement.php';

$fails = 0;
$count = 0;
function check(string $name, bool $cond): void
{
    global $fails, $count;
    $count++;
    if (!$cond) { $fails++; echo "ÉCHEC : $name\n"; }
}

$db = db(':memory:');
$o = create_ebook_order($db, ['first_name' => 'Yvonne', 'last_name' => 'Dupont', 'email' => 'y@example.fr'], 499, '198.51.100.1');
ebook_deliver($db, $o['ref']);
$o = find_ebook_order($db, $o['ref']);

$buyer = ebook_buyer($o);
check('acheteur : nom, email, ref', $buyer['name'] === 'Yvonne Dupont' && $buyer['email'] === 'y@example.fr' && $buyer['ref'] === 'EB-0001');
check('acheteur : date jj/mm/aaaa', preg_match('#^\d{2}/\d{2}/\d{4}$#', $buyer['date']) === 1);

$tmpDir = ebook_tmp_dir();
check('tmp dir sous data_dir/epub', $tmpDir === $data . '/epub/tmp');

$master = "$data-master.epub";
epub_fixture($master);

// maître absent
$threw = false;
try { ebook_build_copy($db, $o, $tmpDir, "$data-absent.epub"); } catch (RuntimeException) { $threw = true; }
check('maître absent : exception', $threw);

// exemplaire
$copy = ebook_build_copy($db, $o, $tmpDir, $master);
check('copie dans le dossier tmp', dirname($copy) === $tmpDir && is_file($copy));
check('dossier tmp en 0700', DIRECTORY_SEPARATOR === '/' && (fileperms($tmpDir) & 0777) === 0700);
$zip = new ZipArchive();
check('copie : zip valide', $zip->open($copy) === true);
$all = '';
for ($i = 0; $i < $zip->numFiles; $i++) $all .= (string) $zip->getFromIndex($i);
$zip->close();
check('copie : nom et ref de l\'acheteur', str_contains($all, 'Yvonne Dupont') && str_contains($all, 'EB-0001'));
$masterZip = new ZipArchive();
$masterZip->open($master);
$mAll = '';
for ($i = 0; $i < $masterZip->numFiles; $i++) $mAll .= (string) $masterZip->getFromIndex($i);
check('maître intact (sans nom d\'acheteur)', !str_contains($mAll, 'Yvonne Dupont'));
unlink($copy);

// échec : maître invalide -> aucune copie résiduelle
file_put_contents("$data-bad.epub", 'pas un epub');
$threw = false;
try { ebook_build_copy($db, $o, $tmpDir, "$data-bad.epub"); } catch (RuntimeException) { $threw = true; }
check('maître invalide : exception, aucun résidu', $threw && (glob($tmpDir . '/yv-*') ?: []) === []);

// nettoyage opportuniste
$old = "$tmpDir/yv-vieux";
$new = "$tmpDir/yv-recent";
file_put_contents($old, 'x');
file_put_contents($new, 'x');
touch($old, time() - 7200);
ebook_prepare_tmp_dir($tmpDir);
check('nettoyage : vieux supprimé, récent gardé', !file_exists($old) && file_exists($new));
unlink($new);

echo $fails === 0 ? "OK ($count vérifications)\n" : "$fails échec(s) sur $count\n";
exit($fails === 0 ? 0 : 1);
