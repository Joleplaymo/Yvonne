<?php
declare(strict_types=1);
// Tests de la mise en page de l'accueil (sections > lignes > colonnes > éléments). Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-accueil-' . getmypid();
putenv("DATA_DIR=$data");
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/accueil.php';

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

// --- Page d'origine
$layout = home_layout($db);
check('6 sections d’origine', array_column($layout, 'id') === ['hero', 'livre', 'citation', 'commander', 'autrice', 'distribution']);
check('mise en page non personnalisée au départ', !home_layout_custom($db));
$html = home_render($layout, []);
check('6 sections rendues', substr_count($html, '<section') === 6);
check('titre principal h1 avec retours à la ligne', str_contains($html, '<h1 class="he he-h bigword fs-big"') && str_contains($html, 'les<br />hormones<br />d&#039;Yvonne'));
check('accroche : gras', str_contains($html, '<b>LE</b> livre'));
check('3 colonnes « le livre »', str_contains($html, 'class="hr n3 va-start"'));
check('bouton extrait ouvre la fenêtre', str_contains($html, '<button type="button" class="cta outline" data-open-extract>Lire un extrait</button>'));
check('bouton de commande', str_contains($html, '<a class="cta" href="/panier?ajouter=1">Je le veux !!!!</a>'));
check('ancres de navigation', str_contains($html, 'id="livre"') && str_contains($html, 'id="autrice"') && str_contains($html, 'id="distribution"') && str_contains($html, 'id="commander"'));
check('photo de couverture chargée en priorité', str_contains($html, '/photo/couverture?w=480') && substr_count($html, 'fetchpriority="high"') === 1);
check('lien dans le texte (commander ici)', str_contains($html, '<a href="/panier?ajouter=1">commander ici</a>'));
check('lien mailto dans le texte', str_contains($html, '<a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>'));
check('cartes encadrées', substr_count($html, 'hc hc-card') === 3);
check('colonne passée après sur téléphone', str_contains($html, 'hc m-last'));
check('fond bleu → texte clair', str_contains($html, 'background:var(--navy);color:var(--cream);'));
check('page : repère remplacé', home_apply('<main><!--home--></main>', $layout, []) !== '<main><!--home--></main>' && !str_contains(home_apply('<!--home-->', $layout), '<!--home-->'));

// --- Textes de /admin/textes pris en compte, puis remplacés par un texte propre à l'élément
$withTexts = home_render(home_default_layout(['hero_titre' => 'Mon <titre>', 'pilier1_titre' => 'Premier']), ['hero_titre' => 'Mon <titre>', 'pilier1_titre' => 'Premier']);
check('texte du catalogue modifié : repris', str_contains($withTexts, 'Mon &lt;titre&gt;') && str_contains($withTexts, 'Premier'));

// --- Modifier : déplacer, masquer, recolorer, enregistrer
$l = home_layout($db);
[$l[0], $l[1]] = [$l[1], $l[0]];
$l[2]['visible'] = false;
$l[3]['bg'] = 'olive';
$l[3]['pad'] = 140;
$l[4]['bg'] = '#112233';
$l[4]['rows'][0]['cols'][1]['els'][0]['p']['text'] = 'Autre nom';
unset($l[4]['rows'][0]['cols'][1]['els'][0]['p']['key']);
home_layout_save($db, $l);
check('mise en page personnalisée', home_layout_custom($db));
$got = home_layout($db);
check('ordre enregistré', $got[0]['id'] === 'livre' && $got[1]['id'] === 'hero');
$out = home_render($got, []);
check('section masquée absente', substr_count($out, '<section') === 5);
check('fond olive', str_contains($out, 'background:var(--olive);'));
check('espacement', str_contains($out, 'padding-top:140px;padding-bottom:140px;'));
check('couleur libre', str_contains($out, 'background:#112233;'));
check('texte propre à l’élément', str_contains($out, 'Autre nom'));
check('texte lié conservé ailleurs', str_contains(home_render($got, ['autrice_intro' => 'ZZZ']), 'ZZZ'));

// --- Ajouter / supprimer lignes, colonnes, éléments (comme le fait l'admin)
$s = home_preset('colonnes');
check('modèle « colonnes » : 2 lignes, 3 colonnes', count($s['rows']) === 2 && count($s['rows'][1]['cols']) === 3);
$s['rows'][1]['cols'][] = ['w' => 1, 'els' => [home_clean_el(['type' => 'image', 'p' => []])]];
$s = home_clean_section($s);
check('4e colonne ajoutée', count($s['rows'][1]['cols']) === 4);
check('colonne d’image sans photo : rien d’affiché', !str_contains(home_render([$s], []), '<figure'));
array_splice($s['rows'][1]['cols'], 0, 1);
$s = home_clean_section($s);
check('colonne supprimée', count($s['rows'][1]['cols']) === 3);
for ($i = 0; $i < 20; $i++) $s['rows'][1]['cols'][] = ['w' => 1, 'els' => []];
check('maximum de colonnes', count(home_clean_section($s)['rows'][1]['cols']) === HOME_LIMITS['cols']);

$photo = home_preset('photo');
$slot = $photo['rows'][0]['cols'][0]['els'][0]['p']['slot'];
check('photo neuve : emplacement propre', $slot === home_image_slot($photo['rows'][0]['cols'][0]['els'][0]['id']) && photo_slot_valid($slot));
check('photo neuve sans fichier : introuvable', photo_file($slot, 480) === null);
check('emplacements invalides refusés', !photo_slot_valid('img-../x') && !photo_slot_valid('inconnu') && !photo_slot_valid('img-'));
check('sections propres : emplacements listés', home_section_slots($photo) === [$slot]);
check('section d’origine : ses emplacements partagés ne sont pas supprimables', home_section_slots(home_layout($db)[1]) === []);

// --- Éléments : texte, gras, lien, échappement
$n = 0;
$t = home_render_el(['id' => 'xxx', 'type' => 'text', 'p' => ['text' => 'a <b> **gras** [lien](https://example.org/x?a=1&b=2) [pirate](javascript:alert(1))', 'size' => '17', 'weight' => '400', 'italic' => '', 'color' => '', 'align' => 'left', 'maxw' => '', 'pos' => 'left', 'mt' => '16']], [], $n);
check('texte échappé', str_contains($t, 'a &lt;b&gt;'));
check('gras', str_contains($t, '<b>gras</b>'));
check('lien valide', str_contains($t, '<a href="https://example.org/x?a=1&amp;b=2">lien</a>'));
check('lien javascript: laissé en texte', !str_contains($t, 'href="javascript'));

// --- Valeurs hostiles
check('type d’élément inconnu refusé', home_clean_el(['type' => 'script', 'p' => []]) === null);
$bad = home_clean_section(['id' => '../x', 'bg' => 'url(x)', 'fg' => 'red', 'pad' => '999', 'anchor' => 'a b', 'rows' => [['cols' => [['els' => [['type' => 'heading', 'p' => ['size' => '9999', 'level' => 'script', 'align' => 'x', 'color' => '<x>']]]]]]]]);
check('identifiant, fond, texte, espacement, ancre invalides corrigés', home_id_valid($bad['id']) && $bad['bg'] === null && $bad['fg'] === null && $bad['pad'] === null && $bad['anchor'] === '');
$h = $bad['rows'][0]['cols'][0]['els'][0]['p'];
check('réglages d’élément invalides ramenés aux valeurs permises', $h['size'] === '38' && $h['level'] === 'h2' && $h['align'] === 'left' && $h['color'] === '');
check('lien javascript: refusé', home_clean_url('javascript:alert(1)') === '' && home_clean_url('//evil.example') === '' && home_clean_url('/a" onclick="x') === '');
check('liens du site acceptés', home_clean_url('/panier?ajouter=1') === '/panier?ajouter=1' && home_clean_url('#extrait') === '#extrait' && home_clean_url('mailto:a@b.fr') === 'mailto:a@b.fr');
check('clé de catalogue inconnue ignorée', !isset(home_clean_el(['type' => 'text', 'p' => ['key' => 'inconnue']])['p']['key']));

// --- Contraste automatique
check('texte automatique selon le fond', home_auto_fg('cream') === 'navy' && home_auto_fg('navy') === 'cream' && home_auto_fg(null) === null);

// --- Rétablir
home_layout_reset($db);
check('rétablir : retour à l’origine', !home_layout_custom($db) && count(home_layout($db)) === 6);
check('ancien format de mise en page ignoré', (function () use ($db) {
    set_setting($db, HOME_SETTING, json_encode([['id' => 'hero', 'type' => 'hero']]));
    $ok = count(home_layout($db)) === 6 && home_layout($db)[0]['rows'] !== [];
    home_layout_reset($db);
    return $ok;
})());

echo $fails ? "\n$fails/$count test(s) accueil en échec\n" : "$count tests accueil OK\n";
exit($fails ? 1 : 0);
