<?php
declare(strict_types=1);
// Tests de validation et de tatouage des epub. Lancement : npm run test:php (Docker, PHP 8.3).

require __DIR__ . '/../../php/_prive/epub.php';

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
function throws(callable $fn): bool
{
    try { $fn(); return false; } catch (Throwable) { return true; }
}

$dir = sys_get_temp_dir() . '/yv-test-epub-' . getmypid();
mkdir($dir);
$secret = 'secret-de-test';
$buyer = ['name' => 'Zoé <b>&"O\'Hara" 🌸', 'email' => 'zoe@example.fr', 'ref' => 'EB-0007', 'date' => '22/11/2026'];
$mark = '<!-- yv:' . substr(hash_hmac('sha256', 'EB-0007', $secret), 0, 16) . ' -->';

function xml_ok(string $xml): ?DOMDocument
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $d->loadXML($xml);
    $errs = libxml_get_errors();
    libxml_clear_errors();
    return $ok && !$errs ? $d : null;
}
// Tatoue une fixture et renvoie l'archive ouverte, l'OPF, son dossier et la liste des idref du spine.
function watermarked(string $dir, string $name, int $version, bool $sub, bool $fixed): array
{
    global $buyer, $secret;
    epub_fixture("$dir/$name-master.epub", $version, $sub, $fixed);
    epub_watermark("$dir/$name-master.epub", "$dir/$name.epub", $buyer, $secret);
    $z = new ZipArchive();
    $z->open("$dir/$name.epub");
    $container = xml_ok($z->getFromName('META-INF/container.xml'));
    $opfPath = $container->getElementsByTagName('rootfile')->item(0)->getAttribute('full-path');
    $opfDir = str_contains($opfPath, '/') ? substr($opfPath, 0, strrpos($opfPath, '/') + 1) : '';
    $opf = xml_ok($z->getFromName($opfPath));
    $spine = [];
    foreach ($opf->getElementsByTagName('itemref') as $ir) $spine[] = $ir->getAttribute('idref');
    $items = [];
    foreach ($opf->getElementsByTagName('item') as $it) $items[$it->getAttribute('id')] = $it->getAttribute('href');
    $exId = array_search('exemplaire.xhtml', $items, true);
    return ['zip' => $z, 'opf' => $opf, 'opfRaw' => $z->getFromName($opfPath), 'dir' => $opfDir, 'spine' => $spine, 'items' => $items, 'exId' => $exId];
}

// --- Validation
foreach ([3, 2] as $v) {
    epub_fixture("$dir/fx$v.epub", $v);
    $r = epub_validate("$dir/fx$v.epub");
    check("fixture valide (epub $v)", $r['ok'] && $r['errors'] === [] && $r['layout'] === 'reflowable' && $r['title'] !== '');
}
epub_fixture("$dir/racine.epub", 3, false);
check('fixture valide (OPF à la racine)', epub_validate("$dir/racine.epub")['ok']);

file_put_contents("$dir/texte.epub", 'pas un zip');
$r = epub_validate("$dir/texte.epub");
check('fichier non ZIP refusé', !$r['ok'] && count($r['errors']) === 1);
$z = new ZipArchive();
$z->open("$dir/quelconque.zip", ZipArchive::CREATE);
$z->addFromString('lisez-moi.txt', 'bonjour');
$z->close();
$r = epub_validate("$dir/quelconque.zip");
check('zip quelconque refusé', !$r['ok'] && count($r['errors']) >= 2);

// Fixture + encryption.xml (une police factice par algorithme donné)
function encrypted(string $dir, string $name, array $algorithms): string
{
    copy("$dir/fx3.epub", "$dir/$name.epub");
    $z = new ZipArchive();
    $z->open("$dir/$name.epub");
    $data = '';
    foreach ($algorithms as $n => $alg) {
        $z->addFromString("OEBPS/fonts/police-$n.otf", random_bytes(2048));
        $data .= "<enc:EncryptedData><enc:EncryptionMethod Algorithm=\"$alg\"/><enc:CipherData><enc:CipherReference URI=\"OEBPS/fonts/police-$n.otf\"/></enc:CipherData></enc:EncryptedData>";
    }
    $z->addFromString('META-INF/encryption.xml', '<?xml version="1.0" encoding="UTF-8"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">' . $data . '</encryption>');
    $z->close();
    return "$dir/$name.epub";
}
$obf = encrypted($dir, 'obfusque', ['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC']);
$r = epub_validate($obf);
$wmOk = !throws(fn() => epub_watermark($obf, "$dir/obfusque-out.epub", $buyer, $secret));
$z = new ZipArchive();
$z->open("$dir/obfusque-out.epub");
$m = new ZipArchive();
$m->open($obf);
check('epub avec polices obfusquées accepté', $r['ok'] && $r['errors'] === [] && $wmOk
    && $z->getFromName('META-INF/encryption.xml') === $m->getFromName('META-INF/encryption.xml')
    && $z->getFromName('OEBPS/fonts/police-0.otf') === $m->getFromName('OEBPS/fonts/police-0.otf')
    && $z->getFromName('OEBPS/fonts/police-1.otf') === $m->getFromName('OEBPS/fonts/police-1.otf'));
$z->close();
$m->close();
$r = epub_validate(encrypted($dir, 'chiffre', ['http://www.idpf.org/2008/embedding', 'http://www.w3.org/2001/04/xmlenc#aes128-cbc']));
check('epub chiffré (autre algorithme) refusé', !$r['ok'] && count($r['errors']) === 1 && str_contains($r['errors'][0], 'chiffré'));
copy("$dir/fx3.epub", "$dir/illisible.epub");
$z = new ZipArchive();
$z->open("$dir/illisible.epub");
$z->addFromString('META-INF/encryption.xml', 'pas du xml');
$z->close();
check('epub chiffré (encryption.xml illisible) refusé', !epub_validate("$dir/illisible.epub")['ok']);
check('tatouage : maître invalide refusé', throws(fn() => epub_watermark("$dir/chiffre.epub", "$dir/x.epub", $buyer, $secret)));
check('tatouage : maître absent refusé', throws(fn() => epub_watermark("$dir/absent.epub", "$dir/x.epub", $buyer, $secret)));

// --- Tatouage
$before = sha1_file("$dir/fx3.epub");
tempnam($dir, 'dl'); // la destination peut être un fichier temporaire vide
$dest = tempnam($dir, 'dl');
epub_watermark("$dir/fx3.epub", $dest, $buyer, $secret);
check('tatouage : maître inchangé', sha1_file("$dir/fx3.epub") === $before);
check('tatouage : résultat valide', epub_validate($dest)['ok']);

$z = new ZipArchive();
$z->open($dest);
$s = $z->statIndex(0);
check('tatouage : mimetype premier et non compressé', $s['name'] === 'mimetype' && $s['comp_method'] === ZipArchive::CM_STORE
    && $z->getFromIndex(0) === 'application/epub+zip');
$head = file_get_contents($dest, false, null, 0, 38);
check('tatouage : mimetype sans champ extra', unpack('v', substr($head, 28, 2))[1] === 0 && substr($head, 30, 8) === 'mimetype');
$m = new ZipArchive();
$m->open("$dir/fx3.epub");
check('tatouage : image recopiée à l\'identique', $z->getFromName('OEBPS/images/couverture.png') === $m->getFromName('OEBPS/images/couverture.png'));
check('tatouage : nombre de fichiers (+1)', $z->numFiles === $m->numFiles + 1);
$m->close();
$z->close();

foreach ([3, 2] as $v) {
    $w = watermarked($dir, "wm$v", $v, true, false);
    check("tatouage : page ajoutée après la couverture (epub $v)", $w['exId'] !== false && array_search($w['exId'], $w['spine'], true) === 1
        && $w['dir'] === 'OEBPS/' && $w['zip']->locateName('OEBPS/exemplaire.xhtml') !== false);
    $page = xml_ok((string) $w['zip']->getFromName($w['dir'] . 'exemplaire.xhtml'));
    check("tatouage : nom à caractères spéciaux (epub $v)", $page !== null && $w['opf'] !== null
        && str_contains($page->textContent, $buyer['name']) && str_contains($page->textContent, 'EB-0007')
        && str_contains($page->textContent, 'zoe@example.fr') && str_contains($page->textContent, 'merci de ne pas le diffuser'));
    check("tatouage : doctype (epub $v)", str_contains((string) $w['zip']->getFromName($w['dir'] . 'exemplaire.xhtml'), $v === 3 ? '<!DOCTYPE html>' : 'XHTML 1.1'));
    $rights = $w['opf']->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'rights');
    check("tatouage : métadonnées (epub $v)", $rights->length === 1 && str_contains($rights->item(0)->textContent, 'EB-0007'));
    $marked = true;
    foreach ($w['spine'] as $idref) {
        if ($idref === $w['exId']) continue;
        $x = (string) $w['zip']->getFromName($w['dir'] . $w['items'][$idref]);
        $marked = $marked && str_contains($x, $mark) && xml_ok($x) !== null;
    }
    check("tatouage : marque cachée (epub $v)", $marked && count($w['spine']) === 4);
    check("tatouage : nav/ncx sans marque (epub $v)", !str_contains((string) $w['zip']->getFromName($w['dir'] . ($v === 3 ? 'nav.xhtml' : 'toc.ncx')), 'yv:'));
    // La clé d'obfuscation des polices dérive de l'identifiant unique : il ne doit jamais changer.
    $m = new ZipArchive();
    $m->open("$dir/wm$v-master.epub");
    $uid = function (DOMDocument $opf): string {
        $id = $opf->documentElement->getAttribute('unique-identifier');
        foreach ($opf->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'identifier') as $el) {
            if ($el->getAttribute('id') === $id) return $el->C14N();
        }
        return '';
    };
    $masterUid = $uid(xml_ok($m->getFromName('OEBPS/content.opf')));
    check("tatouage : identifiant unique inchangé (epub $v)", $masterUid !== '' && $uid($w['opf']) === $masterUid
        && $w['opf']->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'identifier')->length === 1);
    $m->close();
    $w['zip']->close();
}

$w = watermarked($dir, 'racine', 3, false, false);
check('tatouage : OPF à la racine', $w['dir'] === '' && array_search($w['exId'], $w['spine'], true) === 1 && $w['zip']->locateName('exemplaire.xhtml') !== false);
$w['zip']->close();

epub_fixture("$dir/fixe.epub", 3, true, true);
check('mise en page fixe détectée', epub_validate("$dir/fixe.epub")['layout'] === 'pre-paginated');
$w = watermarked($dir, 'fixe', 3, true, true);
check('tatouage : mise en page fixe → page en fin', array_search($w['exId'], $w['spine'], true) === count($w['spine']) - 1);
$w['zip']->close();

// Collision : le maître contient déjà un exemplaire.xhtml et un item d'id identique
copy("$dir/fx3.epub", "$dir/collision.epub");
$z = new ZipArchive();
$z->open("$dir/collision.epub");
$z->addFromString('OEBPS/exemplaire.xhtml', 'ancien');
$opfXml = str_replace('</manifest>', '<item id="yv-exemplaire" href="exemplaire.xhtml" media-type="application/xhtml+xml"/></manifest>', $z->getFromName('OEBPS/content.opf'));
$z->addFromString('OEBPS/content.opf', $opfXml);
$z->close();
epub_watermark("$dir/collision.epub", "$dir/collision-out.epub", $buyer, $secret);
$z = new ZipArchive();
$z->open("$dir/collision-out.epub");
$opf = xml_ok($z->getFromName('OEBPS/content.opf'));
$ids = [];
$hrefs = [];
foreach ($opf->getElementsByTagName('item') as $it) { $ids[] = $it->getAttribute('id'); $hrefs[] = $it->getAttribute('href'); }
check('tatouage : id et href sans collision', count($ids) === count(array_unique($ids)) && count($hrefs) === count(array_unique($hrefs))
    && $z->getFromName('OEBPS/exemplaire.xhtml') === 'ancien');
$z->close();

array_map('unlink', glob("$dir/*"));
rmdir($dir);

echo $fails ? "\n$fails/$count test(s) en échec\n" : "$count tests epub OK\n";
exit($fails ? 1 : 0);
