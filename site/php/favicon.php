<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require __DIR__ . '/_prive/favicon.php';

// /favicon/{48|180|192}.png et /favicon.ico : sert le favicon personnalisé (404 sinon : le navigateur garde /favicon.svg).
$file = favicon_file((int) ($_GET['s'] ?? 0));
if ($file === null) {
    http_response_code(404);
    exit;
}

$mtime = (int) filemtime($file);
$etag = '"' . dechex($mtime) . '-' . dechex((int) filesize($file)) . '"';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($file));
readfile($file);
