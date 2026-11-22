<?php
declare(strict_types=1);
// Tests des couleurs modifiables. Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-couleurs-' . getmypid();
putenv("DATA_DIR=$data");
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/couleurs.php';

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

$db = db();
check('aucune couleur personnalisée au départ', color_overrides($db) === []);
check('sans personnalisation : pas de style', theme_style([]) === '');

check('couleur inconnue refusée', color_save($db, 'inconnue', '#000000') !== null);
check('format invalide refusé', color_save($db, 'olive', 'rouge') !== null);
check('format #rgb refusé', color_save($db, 'olive', '#fff') !== null);
check('injection refusée', color_save($db, 'olive', '#000000;}</style><script>') !== null);
check('rien enregistré après refus', color_overrides($db) === []);

check('enregistrement (majuscules acceptées)', color_save($db, 'olive', ' #AA3300 ') === null);
check('couleur rangée en minuscules', (color_overrides($db)['olive'] ?? null) === '#aa3300');
$css = theme_style(color_overrides($db));
check('variable injectée', str_contains($css, '--olive:#aa3300;'));
check('nuance de survol déduite', str_contains($css, '--olive-dark:color-mix('));
check('autres couleurs intactes', !str_contains($css, '--navy:'));

$html = '<html><head><title>x</title></head><body></body></html>';
$out = theme_inject($html);
check('style inséré avant </head>', str_contains($out, '</style></head>') && substr_count($out, '<style id="theme">') === 1);
check('page inchangée sans couleur', (color_save($db, 'olive', COLORS['olive']['default']) ?? '') === '' && theme_inject($html) === $html);

color_save($db, 'olive', '#aa3300');
color_save($db, 'olive', '');
check('vide = retour à l’originale', color_overrides($db) === []);

check('pas d’alerte avec la palette d’origine', color_warnings([]) === []);
check('alerte si crème sur crème', color_warnings(['navy' => '#f0e8d0']) !== []);
check('contraste noir/blanc = 21', abs(color_contrast('#000000', '#ffffff') - 21.0) < 0.01);

echo $fails ? "\n$fails/$count test(s) couleurs en échec\n" : "$count tests couleurs OK\n";
exit($fails ? 1 : 0);
