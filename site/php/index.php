<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require __DIR__ . '/_prive/textes.php';
require __DIR__ . '/_prive/couleurs.php';

// Accueil : page générée au build (_prive/accueil.html) avec les textes modifiés depuis l'admin.
$html = (string) file_get_contents(__DIR__ . '/_prive/accueil.html');
try {
    $html = text_apply($html, text_overrides(db()));
} catch (Throwable $e) {
    // Base illisible : mieux vaut afficher les textes d'origine que casser la page d'accueil.
    error_log('accueil : ' . $e->getMessage());
}
// Marqueurs des textes : le navigateur n'en a pas besoin.
$html = theme_inject(preg_replace('/<!--\/?t:[a-z0-9_]+-->/', '', $html));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $html;
