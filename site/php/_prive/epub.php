<?php
declare(strict_types=1);

// Livre numérique (.epub) : validation du fichier déposé et tatouage par acheteur
// (page « exemplaire », dc:rights, marque HMAC cachée). ZipArchive + DOMDocument uniquement.
// Le maître n'est jamais modifié : chaque exemplaire est réécrit dans une nouvelle archive.

const EPUB_MIMETYPE = 'application/epub+zip';
const EPUB_OPF_NS = 'http://www.idpf.org/2007/opf';
const EPUB_DC_NS = 'http://purl.org/dc/elements/1.1/';
const EPUB_CONTAINER_NS = 'urn:oasis:names:tc:opendocument:xmlns:container';
// Obfuscation de polices (IDPF, Adobe) : pas un DRM, la clé dérive de l'identifiant unique (jamais modifié ici).
const EPUB_FONT_OBFUSCATION = ['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC'];

function epub_xml(string|false $xml): ?DOMDocument
{
    if ($xml === false || $xml === '') return null;
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $d->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $d : null;
}

// Chemin d'archive résolu depuis un dossier de base (« OEBPS/ ») : décodage, ancre retirée, « .. » résolus.
function epub_resolve(string $base, string $href): string
{
    $parts = [];
    foreach (explode('/', $base . rawurldecode(explode('#', $href)[0])) as $p) {
        if ($p === '..') array_pop($parts);
        elseif ($p !== '' && $p !== '.') $parts[] = $p;
    }
    return implode('/', $parts);
}

// [chemin de l'OPF, document] lus via META-INF/container.xml, ou null.
function epub_opf(ZipArchive $zip): ?array
{
    $c = epub_xml($zip->getFromName('META-INF/container.xml'));
    $root = $c?->getElementsByTagNameNS(EPUB_CONTAINER_NS, 'rootfile')->item(0);
    $path = $root instanceof DOMElement ? $root->getAttribute('full-path') : '';
    $opf = $path === '' ? null : epub_xml($zip->getFromName($path));
    if (!$opf || $opf->documentElement->namespaceURI !== EPUB_OPF_NS) return null;
    return [$path, $opf];
}

function epub_dir(string $path): string
{
    return str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
}

// Éléments de l'OPF utilisés par le tatouage, ou null si manifest, metadata ou spine (non vide) manquent.
function epub_parts(DOMDocument $opf): ?array
{
    $x = new DOMXPath($opf);
    $x->registerNamespace('opf', EPUB_OPF_NS);
    $x->registerNamespace('dc', EPUB_DC_NS);
    $p = ['x' => $x];
    foreach (['manifest', 'metadata', 'spine'] as $n) $p[$n] = $x->query("/opf:package/opf:$n")->item(0);
    $p['itemrefs'] = $p['spine'] ? iterator_to_array($x->query('opf:itemref', $p['spine'])) : [];
    return $p['manifest'] && $p['metadata'] && $p['itemrefs'] ? $p : null;
}

// Fichiers référencés (src, href, xlink:href) par un document, résolus depuis son dossier.
function epub_refs(string $content, string $docPath): array
{
    preg_match_all('/\b(?:src|href)\s*=\s*(["\'])(.*?)\1/i', $content, $m);
    return array_map(fn($h) => epub_resolve(epub_dir($docPath), html_entity_decode($h, ENT_QUOTES | ENT_XML1)), $m[2]);
}

// Itemref de la couverture : landmarks EPUB 3, sinon <guide>, sinon 1re page du spine qui affiche
// l'image de couverture (properties « cover-image » ou <meta name="cover">), sinon 1er itemref.
function epub_cover(ZipArchive $zip, DOMXPath $x, string $opfDir, array $items, array $itemrefs): DOMElement
{
    $byPath = [];
    foreach ($itemrefs as $ir) {
        if (isset($items[$ir->getAttribute('idref')])) $byPath[$items[$ir->getAttribute('idref')][0]] ??= $ir;
    }
    $has = fn(string $attr, string $t) => "contains(concat(' ', normalize-space($attr), ' '), ' $t ')";
    $tokens = fn(DOMElement $e) => preg_split('/\s+/', trim($e->getAttribute('properties')), -1, PREG_SPLIT_NO_EMPTY);
    $paths = [];
    foreach ($items as [$path, $el]) {
        if (!in_array('nav', $tokens($el), true) || !($nav = epub_xml($zip->getFromName($path)))) continue;
        $nx = new DOMXPath($nav);
        $nx->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
        $nx->registerNamespace('epub', 'http://www.idpf.org/2007/ops');
        foreach ($nx->query("//h:nav[{$has('@epub:type', 'landmarks')}]//h:a[{$has('@epub:type', 'cover')}]/@href") as $h) $paths[] = epub_resolve(epub_dir($path), $h->value);
    }
    foreach ($x->query('//opf:guide/opf:reference[@type="cover"]') as $ref) $paths[] = epub_resolve($opfDir, $ref->getAttribute('href'));
    foreach ($paths as $p) if (isset($byPath[$p])) return $byPath[$p];

    $images = [];
    $metaCover = (string) $x->evaluate('string(//opf:metadata/opf:meta[@name="cover"]/@content)');
    foreach ($items as $id => [$path, $el]) {
        if (in_array('cover-image', $tokens($el), true) || $id === $metaCover) $images[] = $path;
    }
    foreach ($images as $img) if (isset($byPath[$img])) return $byPath[$img]; // <meta name="cover"> vers une page
    if ($images) {
        foreach ($byPath as $path => $ir) {
            if ($items[$ir->getAttribute('idref')][1]->getAttribute('media-type') !== 'application/xhtml+xml') continue;
            if (array_intersect($images, epub_refs((string) $zip->getFromName($path), $path))) return $ir;
        }
    }
    return $itemrefs[0];
}

function epub_validate(string $path): array
{
    $res = ['ok' => false, 'errors' => [], 'layout' => 'reflowable', 'title' => ''];
    $zip = new ZipArchive();
    if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
        $res['errors'][] = "Ce fichier n'est pas une archive ZIP (un .epub est une archive ZIP).";
        return $res;
    }
    $first = $zip->statIndex(0);
    if ($first === false || $first['name'] !== 'mimetype' || trim((string) $zip->getFromIndex(0)) !== EPUB_MIMETYPE) {
        $res['errors'][] = 'Le premier fichier de l\'archive doit être « mimetype » contenant « ' . EPUB_MIMETYPE . ' ».';
    }
    $opf = epub_opf($zip);
    $parts = $opf ? epub_parts($opf[1]) : null;
    if (!$opf) {
        $res['errors'][] = 'META-INF/container.xml absent ou fichier OPF introuvable.';
    } elseif (!$parts) {
        $res['errors'][] = 'Fichier OPF incomplet (manifest, metadata ou spine vide).';
    } else {
        $x = $parts['x'];
        $res['title'] = trim((string) $x->evaluate('string(//opf:metadata/dc:title[1])'));
        if (trim((string) $x->evaluate('string(//opf:metadata/opf:meta[@property="rendition:layout"])')) === 'pre-paginated') {
            $res['layout'] = 'pre-paginated';
        }
    }
    if ($zip->locateName('META-INF/encryption.xml') !== false) {
        $enc = epub_xml($zip->getFromName('META-INF/encryption.xml'));
        $algs = [];
        foreach ($enc?->getElementsByTagNameNS('http://www.w3.org/2001/04/xmlenc#', 'EncryptionMethod') ?? [] as $m) $algs[] = $m->getAttribute('Algorithm');
        if (!$enc || array_diff($algs, EPUB_FONT_OBFUSCATION)) {
            $res['errors'][] = 'Ce livre est chiffré (DRM) : déposez une version sans chiffrement.';
        }
    }
    $zip->close();
    $res['ok'] = $res['errors'] === [];
    return $res;
}

// Texte sûr pour XML : UTF-8 valide, sans caractères de contrôle interdits.
function epub_text(string $s): string
{
    $s = mb_scrub($s, 'UTF-8');
    return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
}

function epub_page(int $version, string $text): string
{
    $t = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $head = '<title>Exemplaire personnel</title><style type="text/css">p{margin:30% 1em 0;text-align:center;font-style:italic}</style>';
    if ($version >= 3) {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html>\n"
            . "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\" xml:lang=\"fr\" lang=\"fr\">\n"
            . "<head><meta charset=\"utf-8\"/>$head</head>\n<body><p>$t</p></body>\n</html>\n";
    }
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n"
        . "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"fr\">\n<head>$head</head>\n<body><p>$t</p></body>\n</html>\n";
}

// Insère la marque cachée juste après <body…> (sinon après l'élément racine, toujours bien formé).
function epub_mark(string $xhtml, string $comment): string
{
    if (str_starts_with($xhtml, "\xFF\xFE") || str_starts_with($xhtml, "\xFE\xFF")) return $xhtml; // UTF-16 : laissé tel quel
    $out = preg_replace('/<body\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', '$0' . $comment, $xhtml, 1, $n);
    return $n === 1 && is_string($out) ? $out : rtrim($xhtml) . "\n" . $comment . "\n";
}

function epub_watermark(string $master, string $dest, array $buyer, string $secret): void
{
    $check = epub_validate($master);
    if (!$check['ok']) throw new RuntimeException('Epub maître invalide : ' . implode(' ', $check['errors']));
    $in = new ZipArchive();
    if ($in->open($master, ZipArchive::RDONLY) !== true) throw new RuntimeException('Epub maître illisible.');
    try {
        [$opfPath, $opf] = epub_opf($in) ?? throw new RuntimeException('OPF introuvable.');
        $opfDir = epub_dir($opfPath);
        $version = (int) $opf->documentElement->getAttribute('version');
        ['x' => $x, 'manifest' => $manifest, 'metadata' => $metadata, 'spine' => $spine, 'itemrefs' => $itemrefs] = epub_parts($opf)
            ?? throw new RuntimeException('OPF incomplet (manifest, metadata ou spine).');

        $items = []; // id => [chemin dans l'archive, élément]
        foreach ($x->query('opf:item', $manifest) as $it) $items[$it->getAttribute('id')] = [epub_resolve($opfDir, $it->getAttribute('href')), $it];

        // Page « exemplaire » : id et chemin libres (chemin comparé sans tenir compte de la casse)
        $id = 'yv-exemplaire';
        for ($i = 2; isset($items[$id]); $i++) $id = "yv-exemplaire-$i";
        $href = 'exemplaire.xhtml';
        $paths = array_map('strtolower', array_column($items, 0));
        for ($i = 2; in_array(strtolower($opfDir . $href), $paths, true) || $in->locateName($opfDir . $href, ZipArchive::FL_NOCASE) !== false; $i++) $href = "exemplaire-$i.xhtml";

        $after = epub_cover($in, $x, $opfDir, $items, $itemrefs);
        $item = $manifest->appendChild($opf->createElementNS(EPUB_OPF_NS, 'item'));
        $item->setAttribute('id', $id);
        $item->setAttribute('href', $href);
        $item->setAttribute('media-type', 'application/xhtml+xml');
        $ref = $opf->createElementNS(EPUB_OPF_NS, 'itemref');
        $ref->setAttribute('idref', $id);
        if ($check['layout'] === 'pre-paginated') {
            $ref->setAttribute('properties', 'rendition:layout-reflowable'); // page de texte au milieu d'un livre fixe
            $spine->appendChild($ref);
        } else {
            $spine->insertBefore($ref, $after->nextSibling);
        }

        $b = array_map(fn($k) => epub_text((string) ($buyer[$k] ?? '')), ['name' => 'name', 'email' => 'email', 'ref' => 'ref', 'date' => 'date']);
        $rights = $metadata->appendChild($opf->createElementNS(EPUB_DC_NS, 'dc:rights'));
        $rights->appendChild($opf->createTextNode("Exemplaire personnel de {$b['name']} — commande {$b['ref']} — {$b['date']}. Diffusion interdite."));
        $page = epub_page($version, "Exemplaire personnel de {$b['name']} ({$b['email']}) — commande {$b['ref']} — {$b['date']}. "
            . 'Ce livre vous est destiné : merci de ne pas le diffuser.');

        // Fichiers XHTML du spine à marquer
        $comment = '<!-- yv:' . substr(hash_hmac('sha256', (string) ($buyer['ref'] ?? ''), $secret), 0, 16) . ' -->';
        $toMark = [];
        foreach ($itemrefs as $ir) {
            $it = $items[$ir->getAttribute('idref')] ?? null;
            if ($it && $it[1]->getAttribute('media-type') === 'application/xhtml+xml') $toMark[$it[0]] = true;
        }
        $opfXml = $opf->saveXML();
        if ($opfXml === false) throw new RuntimeException('Sérialisation OPF impossible.');

        $out = new ZipArchive();
        if ($out->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Écriture de l\'exemplaire impossible.');
        $ok = $out->addFromString('mimetype', EPUB_MIMETYPE) && $out->setCompressionName('mimetype', ZipArchive::CM_STORE);
        for ($i = 0; $ok && $i < $in->numFiles; $i++) {
            $name = (string) $in->getNameIndex($i);
            if ($name === 'mimetype') continue;
            if (str_ends_with($name, '/')) { $ok = $out->addEmptyDir(rtrim($name, '/')); continue; }
            $data = $in->getFromIndex($i); // libzip ne vérifie pas le CRC ici : on le compare nous-mêmes
            if ($data === false || crc32($data) !== $in->statIndex($i)['crc']) { $ok = false; break; }
            if ($name === $opfPath) $data = $opfXml;
            elseif (isset($toMark[$name])) $data = epub_mark($data, $comment);
            $ok = $out->addFromString($name, $data);
        }
        $ok = $ok && $out->addFromString($opfDir . $href, $page);
        if ($ok) {
            $ok = $out->close();
        } else {
            $out->unchangeAll(); // sinon le destructeur écrirait l'archive partielle
            @$out->close();
        }
        if (!$ok) {
            @unlink($dest);
            throw new RuntimeException('Écriture de l\'exemplaire impossible.');
        }
    } finally {
        $in->close();
    }
}

// Epub minimal valide (couverture + 2 chapitres) pour les tests et le bouton « Tester le tatouage ».
function epub_fixture(string $dest, int $version = 3, bool $opfInSubdir = true, bool $fixed = false): void
{
    $d = $opfInSubdir ? 'OEBPS/' : '';
    $v3 = $version >= 3;
    $vp = $v3 && $fixed ? '<meta name="viewport" content="width=600, height=800"/>' : '';
    $doc = fn(string $title, string $body) => $v3
        ? "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html>\n<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\" xml:lang=\"fr\" lang=\"fr\">\n<head><title>$title</title>$vp</head>\n<body>$body</body>\n</html>\n"
        : "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"fr\">\n<head><title>$title</title></head>\n<body>$body</body>\n</html>\n";
    $files = [
        'META-INF/container.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<container version=\"1.0\" xmlns=\"" . EPUB_CONTAINER_NS . "\"><rootfiles><rootfile full-path=\"{$d}content.opf\" media-type=\"application/oebps-package+xml\"/></rootfiles></container>\n",
        "{$d}images/couverture.png" => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
        "{$d}cover.xhtml" => $doc('Couverture', '<div><img src="images/couverture.png" alt="Couverture"/></div>'),
        "{$d}chapitre-1.xhtml" => $doc('Chapitre 1', '<h1>Chapitre 1</h1><p>Il était une fois Yvonne.</p>'),
        "{$d}chapitre-2.xhtml" => $doc('Chapitre 2', '<h1>Chapitre 2</h1><p>Fin.</p>'),
    ];
    $nav = '<li><a href="chapitre-1.xhtml">Chapitre 1</a></li><li><a href="chapitre-2.xhtml">Chapitre 2</a></li>';
    if ($v3) {
        $files["{$d}nav.xhtml"] = $doc('Sommaire', "<nav epub:type=\"toc\"><h1>Sommaire</h1><ol>$nav</ol></nav>");
        $meta = '<meta property="dcterms:modified">2026-11-22T00:00:00Z</meta>' . ($fixed ? '<meta property="rendition:layout">pre-paginated</meta>' : '');
        $extra = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
        $spineAttr = $guide = '';
    } else {
        $points = '';
        foreach ([1, 2] as $n) $points .= "<navPoint id=\"np$n\" playOrder=\"$n\"><navLabel><text>Chapitre $n</text></navLabel><content src=\"chapitre-$n.xhtml\"/></navPoint>";
        $files["{$d}toc.ncx"] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ncx xmlns=\"http://www.daisy.org/z3986/2005/ncx/\" version=\"2005-1\"><head><meta name=\"dtb:uid\" content=\"urn:uuid:7c4b1f8e-0000-4000-8000-000000000001\"/></head><docTitle><text>Exemple</text></docTitle><navMap>$points</navMap></ncx>\n";
        $meta = '<meta name="cover" content="cover-image"/>';
        $extra = '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
        $spineAttr = ' toc="ncx"';
        $guide = '<guide><reference type="cover" title="Couverture" href="cover.xhtml"/></guide>';
    }
    $files["{$d}content.opf"] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<package xmlns=\"" . EPUB_OPF_NS . "\" version=\"" . ($v3 ? '3.0' : '2.0') . "\" unique-identifier=\"bookid\">\n"
        . "<metadata xmlns:dc=\"" . EPUB_DC_NS . "\"><dc:identifier id=\"bookid\">urn:uuid:7c4b1f8e-0000-4000-8000-000000000001</dc:identifier><dc:title>Les hormones d'Yvonne (exemple)</dc:title><dc:language>fr</dc:language>$meta</metadata>\n"
        . "<manifest>$extra<item id=\"cover-image\" href=\"images/couverture.png\" media-type=\"image/png\"" . ($v3 ? ' properties="cover-image"' : '') . "/>"
        . '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/><item id="ch1" href="chapitre-1.xhtml" media-type="application/xhtml+xml"/><item id="ch2" href="chapitre-2.xhtml" media-type="application/xhtml+xml"/></manifest>' . "\n"
        . "<spine$spineAttr><itemref idref=\"cover\"/><itemref idref=\"ch1\"/><itemref idref=\"ch2\"/></spine>\n$guide</package>\n";

    $z = new ZipArchive();
    if ($z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Écriture de la fixture impossible.');
    $z->addFromString('mimetype', EPUB_MIMETYPE);
    $z->setCompressionName('mimetype', ZipArchive::CM_STORE);
    foreach ($files as $name => $data) $z->addFromString($name, $data);
    if (!$z->close()) throw new RuntimeException('Écriture de la fixture impossible.');
}
