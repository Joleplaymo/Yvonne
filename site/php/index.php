<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require __DIR__ . '/_prive/textes.php';
require __DIR__ . '/_prive/couleurs.php';
require __DIR__ . '/_prive/accueil.php';
require __DIR__ . '/_prive/entete.php';
require __DIR__ . '/_prive/seo.php';

// Accueil : page générée au build (_prive/accueil.html) avec les textes, la mise en page, l'en-tête, le pied de page et les couleurs choisis dans l'admin.
$html = (string) file_get_contents(__DIR__ . '/_prive/accueil.html');
try {
    $db = db();
    $texts = text_overrides($db);
    $html = text_apply($html, $texts);
    $html = seo_apply($html, $db);
    $layout = home_layout($db, $texts);
} catch (Throwable $e) {
    // Base illisible : mieux vaut afficher la page d'origine que casser la page d'accueil.
    error_log('accueil : ' . $e->getMessage());
    $texts = [];
    $layout = home_default_layout();
}
$html = home_apply($html, $layout, $texts);
// Marqueurs des textes : le navigateur n'en a pas besoin.
$html = site_finish(preg_replace('/<!--\/?t:[a-z0-9_]+-->/', '', $html));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $html;
