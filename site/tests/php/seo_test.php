<?php
declare(strict_types=1);
// Tests du titre et de la description « web » de l'accueil. Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-seo-' . getmypid();
putenv("DATA_DIR=$data");
putenv('PUBLIC_BASE_URL=https://exemple.test');
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/seo.php';

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

$page = '<html><head><title>Les hormones d&#39;Yvonne — Vanessa Gandino</title>'
    . '<meta name="description" content="Description d\'origine"><link rel="icon" href="/favicon.svg"></head><body></body></html>';

$db = db();
$s = seo_load($db);
check('valeurs d’origine par défaut', $s['title'] === SEO_TITLE_DEFAULT && $s['description'] === SEO_DESC_DEFAULT && $s['image'] === true && $s['custom'] === false);
$out = seo_apply($page, $db);
check('un seul titre et une seule description', substr_count($out, '<title>') === 1 && substr_count($out, 'name="description"') === 1);
check('balises d’aperçu ajoutées', str_contains($out, 'property="og:title"') && str_contains($out, 'content="https://exemple.test/"') && str_contains($out, 'og:image" content="https://exemple.test/photo/couverture?w=960"'));
check('reste de l’en-tête conservé', str_contains($out, '<link rel="icon" href="/favicon.svg">') && substr_count($out, '</head>') === 1);

seo_save($db, "  Mon <titre>\n  perso ", 'Une "description" & plus', false);
$s = seo_load($db);
check('texte nettoyé', $s['title'] === 'Mon <titre> perso' && $s['description'] === 'Une "description" & plus' && $s['custom'] === true);
$out = seo_apply($page, $db);
check('titre échappé dans <title>', str_contains($out, '<title>Mon &lt;titre&gt; perso</title>'));
check('description échappée', str_contains($out, 'name="description" content="Une &quot;description&quot; &amp; plus"'));
check('pas de balise injectée', !str_contains($out, '<titre>'));
check('sans image : pas d’og:image', !str_contains($out, 'og:image') && str_contains($out, 'twitter:card" content="summary"'));

seo_save($db, str_repeat('a', 500), str_repeat('b', 900), true);
$s = seo_load($db);
check('longueurs limitées', mb_strlen($s['title']) === SEO_TITLE_MAX && mb_strlen($s['description']) === SEO_DESC_MAX && $s['image'] === true);

seo_save($db, SEO_TITLE_DEFAULT, '', true);
$s = seo_load($db);
check('identique à l’original ou vide = retour à l’original', $s['title'] === SEO_TITLE_DEFAULT && $s['description'] === SEO_DESC_DEFAULT && $s['custom'] === false);

// Photo choisie pour les aperçus de liens.
$im = imagecreatetruecolor(1200, 630);
$png = sys_get_temp_dir() . '/yv-seo-' . getmypid() . '.png';
imagepng($im, $png);
check('photo de partage enregistrée', photo_save('partage', ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => filesize($png)]) === null && photo_is_custom('partage'));
seo_save($db, SEO_TITLE_DEFAULT, '', true);
$out = seo_apply($page, $db);
check('og:image = photo choisie (adresse versionnée)', preg_match('#og:image" content="https://exemple.test/photo/partage\?w=960&amp;v=\d+"#', $out) === 1 && !str_contains($out, 'couverture'));
photo_reset('partage');
check('retrait : retour à la couverture', str_contains(seo_apply($page, $db), 'og:image" content="https://exemple.test/photo/couverture?w=960"'));

seo_save($db, 'X', 'Y', false);
seo_reset($db);
check('rétablir', seo_load($db)['custom'] === false && seo_load($db)['image'] === true);

echo $fails ? "\n$fails/$count test(s) référencement en échec\n" : "$count tests référencement OK\n";
exit($fails ? 1 : 0);
