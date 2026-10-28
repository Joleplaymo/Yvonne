<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require __DIR__ . '/_prive/textes.php';
require __DIR__ . '/_prive/couleurs.php';
require __DIR__ . '/_prive/accueil.php';

// Accueil : page générée au build (_prive/accueil.html) avec les textes, la mise en page et les couleurs choisis dans l'admin.
$html = (string) file_get_contents(__DIR__ . '/_prive/accueil.html');
try {
    $db = db();
    $html = text_apply($html, text_overrides($db));
    $html = home_apply($html, home_layout($db));
} catch (Throwable $e) {
    // Base illisible : mieux vaut afficher la page d'origine que casser la page d'accueil.
    error_log('accueil : ' . $e->getMessage());
}
// Marqueurs des textes : le navigateur n'en a pas besoin.
$html = theme_inject(preg_replace('/<!--\/?t:[a-z0-9_]+-->/', '', $html));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $html;
