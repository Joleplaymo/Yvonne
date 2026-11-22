<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require __DIR__ . '/_prive/photos.php';

// /photo/{emplacement}?w=480|960 : sert la photo personnalisée, sinon l'originale.
$file = photo_file((string) ($_GET['slot'] ?? ''), (int) ($_GET['w'] ?? 0));
if ($file === null) {
    http_response_code(404);
    exit;
}

$mtime = (int) filemtime($file);
$etag = '"' . dechex($mtime) . '-' . dechex((int) filesize($file)) . '"';
header('Content-Type: ' . (str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg'));
// Cache court : une photo remplacée dans l'admin apparaît en quelques minutes au plus.
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($file));
readfile($file);
