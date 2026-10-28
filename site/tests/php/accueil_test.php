<?php
declare(strict_types=1);
// Tests de la mise en page de l'accueil. Lancement : npm run test:php.

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

$page = '<body><nav>n</nav>'
    . "<!--sec:hero--><section class=\"hero on-navy\">H</section><!--/sec:hero-->\n"
    . "<!--sec:livre--><section id=\"livre\" class=\"section\">L</section><!--/sec:livre-->\n"
    . '<!--sec:citation--><section class="section" style="margin:0">C</section><!--/sec:citation-->'
    . '<!--sec:commander--><section>O</section><!--/sec:commander-->'
    . '<!--sec:autrice--><section>A</section><!--/sec:autrice-->'
    . '<!--sec:distribution--><section>D</section><!--/sec:distribution-->'
    . '<dialog>X</dialog></body>';

$db = db();
$layout = home_layout($db);
check('6 sections d’origine par défaut', count($layout) === 6 && array_column($layout, 'id') === array_keys(HOME_BUILTIN));
$plain = home_apply($page, $layout);
check('par défaut : 6 sections, sans marqueurs', substr_count($plain, '<section') === 6 && !str_contains($plain, '<!--sec:'));
check('par défaut : le reste de la page est conservé', str_starts_with($plain, '<body><nav>n</nav>') && str_ends_with($plain, '<dialog>X</dialog></body>'));
check('par défaut : ordre d’origine', strpos($plain, 'H</section>') < strpos($plain, 'L</section>') && strpos($plain, 'A</section>') < strpos($plain, 'D</section>'));

// Déplacer, masquer, recolorer.
$l = $layout;
[$l[0], $l[1]] = [$l[1], $l[0]];
$l[2]['visible'] = false;
$l[3]['bg'] = 'olive';
$l[3]['pad'] = 'xl';
$l[4]['bg'] = '#112233';
home_layout_save($db, $l);
$got = home_layout($db);
check('ordre enregistré', $got[0]['id'] === 'livre' && $got[1]['id'] === 'hero');
$out = home_apply($page, $got);
check('section déplacée', strpos($out, 'L</section>') < strpos($out, 'H</section>'));
check('section masquée absente', !str_contains($out, '>C</section>'));
check('fond appliqué', str_contains($out, 'background:var(--olive);'));
check('texte clair automatique sur fond foncé', str_contains($out, 'color:var(--cream);'));
check('espacement appliqué', str_contains($out, 'padding-top:140px;padding-bottom:140px;'));
check('couleur libre appliquée', str_contains($out, 'background:#112233;'));
check('fond crème → texte foncé', home_auto_fg('cream') === 'navy' && home_auto_fg('navy') === 'cream');

// Section d'origine qui a déjà un style : fusion.
$l = home_layout($db);
foreach ($l as &$s) if ($s['id'] === 'citation') { $s['visible'] = true; $s['bg'] = 'sand'; }
unset($s);
home_layout_save($db, $l);
check('style existant conservé', str_contains(home_apply($page, home_layout($db)), 'style="margin:0;background:var(--sand);color:var(--navy);"'));

// Valeurs hostiles.
$bad = home_clean_section(['id' => 'hero', 'type' => 'hero', 'bg' => 'url(x)', 'fg' => 'red', 'pad' => '999']);
check('couleur / espacement invalides ignorés', $bad !== null && $bad['bg'] === null && $bad['fg'] === null && $bad['pad'] === null);
check('type inconnu refusé', home_clean_section(['id' => 'bzzzzzz', 'type' => 'script']) === null);
check('identifiant de bloc invalide refusé', home_clean_section(['id' => '../x', 'type' => 'text']) === null);
check('identifiant ≠ type pour une section d’origine', home_clean_section(['id' => 'livre', 'type' => 'hero']) === null);
check('lien javascript: refusé', home_clean_url('javascript:alert(1)') === '');
check('lien // refusé', home_clean_url('//evil.example') === '');
check('liens du site acceptés', home_clean_url('/panier?ajouter=1') === '/panier?ajouter=1' && home_clean_url('#commander') === '#commander' && home_clean_url('https://example.org/a') === 'https://example.org/a');
check('guillemet dans un lien refusé', home_clean_url('/a" onclick="x') === '');

// Blocs libres.
$id = home_new_id();
check('identifiant de bloc valide', (bool) preg_match('/^b[0-9a-f]{6}$/', $id));
$block = ['id' => $id, 'type' => 'text', 'visible' => true, 'bg' => 'navy', 'fg' => null, 'pad' => 's',
    'p' => ['title' => 'Salon <du> livre', 'body' => "Venez **nombreux**\nà Vannes", 'align' => 'center', 'btn_label' => 'Réserver', 'btn_url' => 'javascript:x']];
$l = home_layout($db);
$l[] = $block;
home_layout_save($db, $l);
$got = home_layout($db);
check('bloc enregistré', end($got)['id'] === $id && end($got)['p']['btn_url'] === '');
$html = home_render_block(end($got));
check('bloc : titre échappé', str_contains($html, 'Salon &lt;du&gt; livre'));
check('bloc : gras et retour à la ligne', str_contains($html, '<b>nombreux</b><br />') || str_contains($html, 'Venez <b>nombreux</b><br />'));
check('bloc : pas de bouton sans lien valide', !str_contains($html, 'class="cta"'));
check('bloc : fond et texte automatiques', str_contains($html, 'background:var(--navy);color:var(--cream);'));
check('bloc : alignement', str_contains($html, 'align-center'));
check('bloc ajouté présent dans la page', str_contains(home_apply($page, $got), 'Salon &lt;du&gt;'));
$cols = home_render_block(['id' => $id, 'type' => 'columns', 'visible' => true, 'bg' => null, 'fg' => null, 'pad' => null,
    'p' => ['title' => 'T', 'c1_title' => 'A', 'c1_text' => 'a', 'c2_title' => '', 'c2_text' => '', 'c3_title' => 'C', 'c3_text' => '']]);
check('colonnes : une colonne vide est ignorée', str_contains($cols, 'blk-cols n2'));
check('photo sans image ni légende : rien', home_render_block(['id' => $id, 'type' => 'photo', 'visible' => true, 'bg' => null, 'fg' => null, 'pad' => null, 'p' => ['size' => 'm', 'align' => 'left']]) === '');

check('emplacement de photo des blocs valide', photo_slot_valid(home_image_slot($id)) && !photo_slot_valid('img-../x') && !photo_slot_valid('inconnu'));
check('photo d’un bloc sans fichier : 404', photo_file(home_image_slot($id), 480) === null);

home_layout_reset($db);
check('rétablir : retour à l’origine', !home_layout_custom($db) && count(home_layout($db)) === 6);
check('page sans repères : inchangée', home_apply('<p>x</p>', home_layout($db)) === '<p>x</p>');

echo $fails ? "\n$fails/$count test(s) accueil en échec\n" : "$count tests accueil OK\n";
exit($fails ? 1 : 0);
