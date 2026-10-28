<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require __DIR__ . '/_prive/couleurs.php';

// /panier et /commande : pages générées au build (_prive/{page}.html), avec les couleurs choisies dans l'admin.
$page = (string) ($_GET['p'] ?? '');
if (!in_array($page, ['panier', 'commande'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo theme_inject((string) file_get_contents(__DIR__ . "/_prive/$page.html"));
