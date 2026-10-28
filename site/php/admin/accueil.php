<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/accueil.php';

require_admin();
$db = db();
$specs = home_el_specs();

const HOME_PRESETS = [
    'vide' => 'Section vide (à composer)',
    'texte' => 'Titre + texte',
    'photo' => 'Photo',
    'image_texte' => 'Image + texte + bouton',
    'colonnes' => 'Titre + 3 colonnes',
    'citation' => 'Citation',
];

function post_checked($v): bool
{
    return $v === '1' || $v === 'on';
}

/** Section reconstruite à partir du formulaire (structure, ordre et réglages tels qu'affichés). */
function section_from_post(array $in, array $old, array $specs): array
{
    $bg = (string) ($in['bg'] ?? '');
    if ($bg === 'custom') $bg = strtolower(trim((string) ($in['bg_custom'] ?? '')));
    $s = [
        'id' => $old['id'],
        'name' => (string) ($in['name'] ?? ''),
        'visible' => post_checked($in['visible'] ?? ''),
        'bg' => $bg === '' ? null : $bg,
        'fg' => (string) ($in['fg'] ?? ''),
        'pad' => $in['pad'] ?? '',
        'anchor' => strtolower(trim((string) ($in['anchor'] ?? ''))),
        'rows' => [],
    ];
    foreach (is_array($in['rows'] ?? null) ? $in['rows'] : [] as $r) {
        if (!is_array($r)) continue;
        $row = ['gap' => $r['gap'] ?? 32, 'mt' => $r['mt'] ?? 0, 'valign' => $r['valign'] ?? 'start', 'cols' => []];
        foreach (is_array($r['cols'] ?? null) ? $r['cols'] : [] as $c) {
            if (!is_array($c)) continue;
            $col = ['w' => $c['w'] ?? 1, 'card' => post_checked($c['card'] ?? ''), 'm_last' => post_checked($c['m_last'] ?? ''), 'els' => []];
            foreach (is_array($c['els'] ?? null) ? $c['els'] : [] as $e) {
                if (!is_array($e) || !isset($specs[(string) ($e['type'] ?? '')])) continue;
                $p = is_array($e['p'] ?? null) ? $e['p'] : [];
                // Texte lié au catalogue : tant qu'il n'est pas réécrit ici, il suit /admin/textes.
                $key = (string) ($p['key'] ?? '');
                if ($key !== '') {
                    $norm = fn($v) => trim((string) preg_replace('/\r\n?/', "\n", (string) $v));
                    if ($norm($p['text'] ?? '') === $norm($p['orig'] ?? '')) $p['text'] = '';
                    else $p['key'] = '';
                }
                foreach ($specs[(string) $e['type']]['fields'] as $name => $f) {
                    if ($f['kind'] === 'check') $p[$name] = post_checked($p[$name] ?? '') ? '1' : '';
                }
                $col['els'][] = ['id' => (string) ($e['id'] ?? ''), 'type' => (string) $e['type'], 'p' => $p];
            }
            $row['cols'][] = $col;
        }
        $s['rows'][] = $row;
    }
    return home_clean_section($s);
}

function find_el(array &$s, array $path): ?array
{
    return $s['rows'][$path[0]]['cols'][$path[1]]['els'][$path[2]] ?? null;
}

function move_item(array &$list, int $i, int $delta): void
{
    $j = $i + $delta;
    if (isset($list[$i], $list[$j])) [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $op = (string) ($_POST['op'] ?? '');
    [$opName, $opPath] = array_pad(explode(':', $op, 2), 2, '');
    $path = $opPath === '' ? [] : array_map('intval', explode('.', $opPath));
    $id = (string) ($_POST['id'] ?? '');
    $layout = home_layout($db);
    $pos = null;
    foreach ($layout as $i => $s) if ($s['id'] === $id) $pos = $i;
    $msg = null;
    $focus = $id;

    if ($opName === 'add_section' && isset(HOME_PRESETS[(string) ($_POST['preset'] ?? '')])) {
        if (count($layout) >= HOME_LIMITS['sections']) {
            $msg = ['error', 'Trop de sections (' . HOME_LIMITS['sections'] . ' maximum).'];
        } else {
            $new = home_preset((string) $_POST['preset']);
            $layout[] = $new;
            home_layout_save($db, $layout);
            $focus = $new['id'];
            $msg = ['ok', 'Section ajoutée en bas de la page : composez-la puis déplacez-la où vous voulez.'];
        }
    } elseif ($opName === 'reset') {
        foreach ($layout as $s) foreach (home_section_slots($s) as $slot) photo_reset($slot);
        home_layout_reset($db);
        $focus = '';
        $msg = ['ok', 'Mise en page d’origine rétablie.'];
    } elseif ($pos !== null) {
        $old = $layout[$pos];
        $s = str_starts_with($opName, 'sec_') ? $old : section_from_post(is_array($_POST['s'] ?? null) ? $_POST['s'] : [], $old, $specs);
        $msg = ['ok', 'Section enregistrée : elle est sur le site en quelques minutes.'];

        if (!str_starts_with($opName, 'sec_')) {
            // Photos envoyées avec le formulaire : champ « up_{ligne}_{colonne}_{élément} ».
            foreach ($s['rows'] as $ri => $r) foreach ($r['cols'] as $ci => $c) foreach ($c['els'] as $ei => $e) {
                $f = $_FILES["up_{$ri}_{$ci}_{$ei}"] ?? null;
                if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $err = photo_save($e['p']['slot'] ?? '', $f);
                    if ($err !== null) $msg = ['error', 'Section enregistrée, mais une photo n’a pas été remplacée : ' . $err];
                }
            }
            $dropSlots = fn(array $els) => array_map(fn($e) => ($e['p']['slot'] ?? '') === home_image_slot($e['id']) ? photo_reset($e['p']['slot']) : null, $els);
            switch ($opName) {
                case 'row_add':
                    if (count($s['rows']) < HOME_LIMITS['rows']) $s['rows'][] = ['gap' => 32, 'mt' => 24, 'valign' => 'start', 'cols' => [['w' => 1, 'els' => []]]];
                    $msg = ['ok', 'Ligne ajoutée en bas de la section.'];
                    break;
                case 'row_up': case 'row_down':
                    move_item($s['rows'], $path[0] ?? -1, $opName === 'row_up' ? -1 : 1);
                    $msg = ['ok', 'Ligne déplacée.'];
                    break;
                case 'row_del':
                    foreach ($s['rows'][$path[0] ?? -1]['cols'] ?? [] as $c) $dropSlots($c['els']);
                    array_splice($s['rows'], $path[0] ?? -1, 1);
                    $msg = ['ok', 'Ligne supprimée.'];
                    break;
                case 'col_add':
                    if (isset($s['rows'][$path[0]]) && count($s['rows'][$path[0]]['cols']) < HOME_LIMITS['cols']) $s['rows'][$path[0]]['cols'][] = ['w' => 1, 'els' => []];
                    $msg = ['ok', 'Colonne ajoutée à droite.'];
                    break;
                case 'col_left': case 'col_right':
                    if (isset($s['rows'][$path[0]])) move_item($s['rows'][$path[0]]['cols'], $path[1] ?? -1, $opName === 'col_left' ? -1 : 1);
                    $msg = ['ok', 'Colonne déplacée.'];
                    break;
                case 'col_del':
                    if (isset($s['rows'][$path[0]]['cols'][$path[1]])) {
                        $dropSlots($s['rows'][$path[0]]['cols'][$path[1]]['els']);
                        array_splice($s['rows'][$path[0]]['cols'], $path[1], 1);
                    }
                    $msg = ['ok', 'Colonne supprimée.'];
                    break;
                case 'el_add':
                    $type = (string) ($_POST['newtype'][$path[0]][$path[1]] ?? '');
                    if (isset($specs[$type], $s['rows'][$path[0]]['cols'][$path[1]]) && count($s['rows'][$path[0]]['cols'][$path[1]]['els']) < HOME_LIMITS['els']) {
                        $newEl = home_clean_el(['type' => $type, 'p' => $type === 'text' ? ['text' => 'Votre texte…'] : ($type === 'heading' ? ['text' => 'Votre titre'] : [])]);
                        $s['rows'][$path[0]]['cols'][$path[1]]['els'][] = $newEl;
                        $_SESSION['home_open'] = $newEl['id'];
                    }
                    $msg = ['ok', 'Élément ajouté en bas de la colonne.'];
                    break;
                case 'el_up': case 'el_down':
                    if (isset($s['rows'][$path[0]]['cols'][$path[1]])) move_item($s['rows'][$path[0]]['cols'][$path[1]]['els'], $path[2] ?? -1, $opName === 'el_up' ? -1 : 1);
                    $msg = ['ok', 'Élément déplacé.'];
                    break;
                case 'el_del':
                    if ($el = find_el($s, $path)) {
                        $dropSlots([$el]);
                        array_splice($s['rows'][$path[0]]['cols'][$path[1]]['els'], $path[2], 1);
                    }
                    $msg = ['ok', 'Élément supprimé.'];
                    break;
                case 'img_del':
                    if ($el = find_el($s, $path)) photo_reset((string) ($el['p']['slot'] ?? ''));
                    $msg = ['ok', 'Photo personnalisée retirée.'];
                    break;
            }
            $layout[$pos] = $s;
        } elseif ($opName === 'sec_up' || $opName === 'sec_down') {
            move_item($layout, $pos, $opName === 'sec_up' ? -1 : 1);
            $msg = ['ok', 'Section déplacée.'];
        } elseif ($opName === 'sec_delete') {
            foreach (home_section_slots($old) as $slot) photo_reset($slot);
            array_splice($layout, $pos, 1);
            $focus = '';
            $msg = ['ok', 'Section supprimée.'];
        } elseif ($opName === 'sec_dup' && count($layout) < HOME_LIMITS['sections']) {
            $copy = $old;
            $copy['id'] = home_new_id();
            $copy['name'] = mb_substr(trim($old['name'] . ' (copie)'), 0, 60);
            $copy['anchor'] = '';
            foreach ($copy['rows'] as &$r) foreach ($r['cols'] as &$c) foreach ($c['els'] as &$e) {
                $oldSlot = (string) ($e['p']['slot'] ?? '');
                $own = $oldSlot === home_image_slot($e['id']);
                $e['id'] = home_new_id();
                if ($own) {
                    photo_copy($oldSlot, home_image_slot($e['id']));
                    $e['p']['slot'] = home_image_slot($e['id']);
                }
            }
            unset($r, $c, $e);
            array_splice($layout, $pos + 1, 0, [$copy]);
            $focus = $copy['id'];
            $msg = ['ok', 'Section dupliquée juste en dessous.'];
        }
        home_layout_save($db, $layout);
    }
    if ($msg) $_SESSION['home_msg'] = [$focus, $msg[0], $msg[1]];
    header('Location: /admin/accueil' . ($focus !== '' ? '#sec-' . rawurlencode($focus) : ''), true, 303);
    exit;
}

[$flashId, $flashType, $flash] = $_SESSION['home_msg'] ?? [null, null, null];
unset($_SESSION['home_msg']);
$forceOpen = (string) ($_SESSION['home_open'] ?? '');
unset($_SESSION['home_open']);
$layout = home_layout($db);
$customized = home_layout_custom($db);
$texts = text_overrides($db);

function opts(array $options, string $value): string
{
    $o = '';
    foreach ($options as $k => $l) $o .= '<option value="' . h((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . h((string) $l) . '</option>';
    return $o;
}

function btn(string $op, string $label, string $title = '', bool $disabled = false, string $confirm = ''): string
{
    return '<button class="cta outline small" name="op" value="' . h($op) . '" title="' . h($title ?: $label) . '"'
        . ($disabled ? ' disabled' : '') . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . h($label) . '</button>';
}

function color_select(string $name, ?string $value, string $customName): string
{
    $isCustom = $value !== null && !isset(HOME_COLORS[$value]);
    $o = '<option value="">D’origine</option>';
    foreach (HOME_COLORS as $k => $c) $o .= '<option value="' . h($k) . '"' . ($value === $k ? ' selected' : '') . '>' . h($c[0]) . '</option>';
    $o .= '<option value="custom"' . ($isCustom ? ' selected' : '') . '>Autre couleur…</option>';
    return '<select name="' . h($name) . '">' . $o . '</select> <input type="color" name="' . h($customName) . '" value="' . h($isCustom ? $value : '#cccccc') . '" aria-label="Autre couleur">';
}

ob_start();
?>
<h1>Mise en page de l'accueil</h1>
<p>L'accueil est fait de <strong>sections</strong>. Une section contient des <strong>lignes</strong>, une ligne des <strong>colonnes</strong>, une colonne des <strong>éléments</strong>
  (titre, paragraphe, image, boutons, icône, image + légende, espace). Tout peut être ajouté, supprimé, déplacé et réglé : textes, photos, tailles, couleurs, espacements, alignements.</p>
<p class="hint">Mode d'emploi : ouvrez une section (« Modifier cette section »), changez ce que vous voulez, puis « Enregistrer cette section ».
  Les flèches, « + » et « × » enregistrent aussi vos changements en cours. Les changements apparaissent sur le site en quelques minutes.
  <a href="/" target="_blank">Voir l'accueil</a></p>

<p class="home-tools">
  <button type="button" class="cta outline small" data-fold="open">Tout déplier</button>
  <button type="button" class="cta outline small" data-fold="close">Tout replier</button>
  <button type="button" class="cta outline small" data-fold="sections">Sections ouvertes, détails repliés</button>
</p>

<?php foreach ($layout as $si => $s):
    $label = $s['name'] !== '' ? $s['name'] : 'Section ' . ($si + 1);
    $isFlash = $flashId === $s['id'];
    ?>
  <form method="post" enctype="multipart/form-data" class="panel home-sec<?= $s['visible'] ? '' : ' is-hidden' ?>" id="sec-<?= h($s['id']) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= h($s['id']) ?>">
    <button class="visually-hidden" name="op" value="save" tabindex="-1">Enregistrer</button>
    <div class="home-head">
      <h2><?= $si + 1 ?>. <?= h($label) ?><?= $s['visible'] ? '' : ' <span class="pill annulee">masquée</span>' ?></h2>
      <div class="home-move">
        <?= btn('sec_up', '↑', 'Monter la section', $si === 0) ?>
        <?= btn('sec_down', '↓', 'Descendre la section', $si === count($layout) - 1) ?>
        <?= btn('sec_dup', 'Dupliquer', 'Dupliquer la section') ?>
        <?= btn('sec_delete', 'Supprimer', 'Supprimer la section', false, 'Supprimer définitivement cette section ?') ?>
      </div>
    </div>
    <?php if ($isFlash && $flash): ?><p class="notice<?= $flashType === 'error' ? ' error' : '' ?>"><?= h($flash) ?></p><?php endif ?>
    <details id="k-<?= h($s['id']) ?>"<?= $isFlash ? ' open data-force' : '' ?>>
      <summary>Modifier cette section<?= $s['rows'] ? ' <span class="hint">(' . count($s['rows']) . ' ligne' . (count($s['rows']) > 1 ? 's' : '') . ')</span>' : '' ?></summary>
      <div class="home-look">
        <div class="field"><label>Nom (pour vous repérer)</label><input name="s[name]" value="<?= h($s['name']) ?>" maxlength="60"></div>
        <div class="field"><label>Adresse d’ancre (ex. « livre » pour un lien #livre)</label><input name="s[anchor]" value="<?= h($s['anchor']) ?>" maxlength="30" pattern="[a-z0-9-]*"></div>
        <label class="check"><input type="checkbox" name="s[visible]" value="1"<?= $s['visible'] ? ' checked' : '' ?>> Afficher cette section</label>
        <div class="field"><label>Fond</label><div class="color-row"><?= color_select('s[bg]', $s['bg'], 's[bg_custom]') ?></div></div>
        <div class="field"><label>Couleur du texte de la section</label>
          <select name="s[fg]"><?= opts(['' => 'Automatique', 'cream' => 'Clair (crème)', 'navy' => 'Foncé (bleu)'], (string) $s['fg']) ?></select></div>
        <div class="field"><label>Espacement haut et bas</label>
          <select name="s[pad]"><option value="">D’origine (68 px)</option><?= opts(home_px_options(HOME_PADS, '0 px'), (string) $s['pad']) ?></select></div>
      </div>

      <?php foreach ($s['rows'] as $ri => $r): ?>
        <details class="home-row" id="k-<?= h($s['id']) ?>-r<?= $ri ?>" open>
          <summary>Ligne <?= $ri + 1 ?> <span class="hint">· <?= count($r['cols']) ?> colonne<?= count($r['cols']) > 1 ? 's' : '' ?>, <?= array_sum(array_map(fn($c) => count($c['els']), $r['cols'])) ?> élément(s)</span></summary>
          <div class="home-bar">
            <strong>Réglages de la ligne</strong>
            <label>Écart entre colonnes <select name="s[rows][<?= $ri ?>][gap]"><?= opts(home_px_options(HOME_GAPS), (string) $r['gap']) ?></select></label>
            <label>Alignement vertical <select name="s[rows][<?= $ri ?>][valign]"><?= opts(['start' => 'En haut', 'center' => 'Centré', 'end' => 'En bas'], $r['valign']) ?></select></label>
            <label>Espace au-dessus <select name="s[rows][<?= $ri ?>][mt]"><?= opts(home_px_options(HOME_SPACES), (string) $r['mt']) ?></select></label>
            <span class="home-move">
              <?= btn("row_up:$ri", '↑', 'Monter la ligne', $ri === 0) ?><?= btn("row_down:$ri", '↓', 'Descendre la ligne', $ri === count($s['rows']) - 1) ?>
              <?= btn("row_del:$ri", '× ligne', 'Supprimer la ligne', false, 'Supprimer cette ligne et tout son contenu ?') ?>
            </span>
          </div>
          <div class="home-cols">
          <?php foreach ($r['cols'] as $ci => $c): $cn = "s[rows][$ri][cols][$ci]"; ?>
            <details class="home-col" id="k-<?= h($s['id']) ?>-r<?= $ri ?>-c<?= $ci ?>" open>
              <summary>Colonne <?= $ci + 1 ?> <span class="hint">· largeur <?= $c['w'] ?>, <?= count($c['els']) ?> élément(s)</span></summary>
              <div class="home-bar">
                <strong>Réglages de la colonne</strong>
                <label>Largeur <select name="<?= $cn ?>[w]"><?php for ($w = 1; $w <= 20; $w++): ?><option value="<?= $w ?>"<?= $c['w'] === $w ? ' selected' : '' ?>><?= $w ?></option><?php endfor ?></select></label>
                <label class="check"><input type="checkbox" name="<?= $cn ?>[card]" value="1"<?= $c['card'] ? ' checked' : '' ?>> Encadrée</label>
                <label class="check"><input type="checkbox" name="<?= $cn ?>[m_last]" value="1"<?= $c['m_last'] ? ' checked' : '' ?>> Après les autres sur téléphone</label>
                <span class="home-move">
                  <?= btn("col_left:$ri.$ci", '←', 'Colonne vers la gauche', $ci === 0) ?><?= btn("col_right:$ri.$ci", '→', 'Colonne vers la droite', $ci === count($r['cols']) - 1) ?>
                  <?= btn("col_del:$ri.$ci", '× colonne', 'Supprimer la colonne', false, 'Supprimer cette colonne et tout son contenu ?') ?>
                </span>
              </div>
              <?php foreach ($c['els'] as $ei => $e):
                  $spec = $specs[$e['type']];
                  $en = "$cn" . "[els][$ei]";
                  $slot = $e['p']['slot'] ?? null;
                  ?>
                <?php
                  $prevText = trim(strip_tags(home_el_text($e['p'], $texts) ?: (string) ($e['p']['alt'] ?? $e['p']['b1_label'] ?? '')));
                  $isForced = $forceOpen === $e['id'];
                  ?>
                <details class="home-el" id="k-<?= h($s['id']) ?>-e<?= h($e['id']) ?>"<?= $isForced ? ' open data-force' : '' ?>>
                  <summary><?= h($spec['label']) ?><?= $prevText !== '' ? ' <span class="hint">· ' . h(mb_substr($prevText, 0, 60)) . (mb_strlen($prevText) > 60 ? '…' : '') . '</span>' : '' ?></summary>
                  <div class="home-el-body">
                  <input type="hidden" name="<?= $en ?>[type]" value="<?= h($e['type']) ?>">
                  <input type="hidden" name="<?= $en ?>[id]" value="<?= h($e['id']) ?>">
                  <?php if ($slot): ?><input type="hidden" name="<?= $en ?>[p][slot]" value="<?= h($slot) ?>"><?php endif ?>
                  <?php if (($e['p']['key'] ?? '') !== ''): $resolved = home_el_text($e['p'], $texts); ?>
                    <input type="hidden" name="<?= $en ?>[p][key]" value="<?= h($e['p']['key']) ?>">
                    <input type="hidden" name="<?= $en ?>[p][orig]" value="<?= h($resolved) ?>">
                  <?php endif ?>
                  <div class="home-move home-el-move">
                    <?= btn("el_up:$ri.$ci.$ei", '↑', 'Monter l’élément', $ei === 0) ?><?= btn("el_down:$ri.$ci.$ei", '↓', 'Descendre l’élément', $ei === count($c['els']) - 1) ?>
                    <?= btn("el_del:$ri.$ci.$ei", '× élément', 'Supprimer l’élément', false, 'Supprimer cet élément ?') ?>
                  </div>
                  <?php foreach ($spec['fields'] as $name => $f):
                      $fid = "f-{$s['id']}-$ri-$ci-$ei-$name";
                      $v = (string) ($e['p'][$name] ?? '');
                      if ($name === 'text' && ($e['p']['key'] ?? '') !== '') $v = $resolved;
                      ?>
                    <?php if ($f['kind'] === 'check'): ?>
                      <label class="check"><input type="checkbox" name="<?= $en ?>[p][<?= $name ?>]" value="1"<?= $v === '1' ? ' checked' : '' ?>> <?= h($f['label']) ?></label>
                    <?php elseif ($f['kind'] === 'image'): ?>
                      <div class="field"><label for="<?= $fid ?>"><?= h($f['label']) ?></label>
                        <?php if (photo_file($slot, 480) !== null): ?>
                          <img class="photo-preview" src="/photo/<?= h($slot) ?>?w=480&amp;v=<?= photo_version($slot) ?>" alt="">
                          <?php if (photo_is_custom($slot)): ?><?= btn("img_del:$ri.$ci.$ei", 'Retirer la photo personnalisée', '', false, 'Retirer la photo personnalisée ?') ?><?php endif ?>
                        <?php else: ?><p class="hint">Pas encore de photo.</p><?php endif ?>
                        <input type="file" id="<?= $fid ?>" name="up_<?= "{$ri}_{$ci}_{$ei}" ?>" accept="image/jpeg,image/png,image/webp,image/gif">
                        <p class="hint">Choisissez un fichier puis enregistrez (JPEG, PNG, WebP ou GIF, 15 Mo maximum).</p></div>
                    <?php else: ?>
                      <div class="field"><label for="<?= $fid ?>"><?= h($f['label']) ?></label>
                        <?php if ($f['kind'] === 'area'): ?>
                          <textarea id="<?= $fid ?>" name="<?= $en ?>[p][<?= $name ?>]" rows="<?= (int) ($f['rows'] ?? 3) ?>" maxlength="<?= (int) $f['max'] ?>"><?= h($v) ?></textarea>
                        <?php elseif ($f['kind'] === 'select'): ?>
                          <select id="<?= $fid ?>" name="<?= $en ?>[p][<?= $name ?>]"><?= opts($f['options'], $v) ?></select>
                        <?php else: ?>
                          <input id="<?= $fid ?>" name="<?= $en ?>[p][<?= $name ?>]" value="<?= h($v) ?>" maxlength="<?= (int) $f['max'] ?>"<?= $f['kind'] === 'url' ? ' inputmode="url"' : '' ?>>
                        <?php endif ?>
                      </div>
                    <?php endif ?>
                  <?php endforeach ?>
                  </div>
                </details>
              <?php endforeach ?>
              <div class="home-add">
                <select name="newtype[<?= $ri ?>][<?= $ci ?>]" aria-label="Type d’élément à ajouter"><?= opts(array_map(fn($x) => $x['label'], $specs), 'text') ?></select>
                <?= btn("el_add:$ri.$ci", '+ Ajouter un élément') ?>
              </div>
            </details>
          <?php endforeach ?>
          </div>
          <?= btn("col_add:$ri", '+ Ajouter une colonne', '', count($r['cols']) >= HOME_LIMITS['cols']) ?>
        </details>
      <?php endforeach ?>
      <p><?= btn('row_add', '+ Ajouter une ligne', '', count($s['rows']) >= HOME_LIMITS['rows']) ?></p>
      <button class="cta" name="op" value="save">Enregistrer cette section</button>
    </details>
  </form>
<?php endforeach ?>

<form method="post" class="panel home-sec" id="ajouter"><?= csrf_field() ?>
  <h2>Ajouter une section</h2>
  <div class="home-add">
    <select name="preset" aria-label="Modèle de section"><?= opts(HOME_PRESETS, 'texte') ?></select>
    <button class="cta" name="op" value="add_section">Ajouter en bas de la page</button>
  </div>
</form>
<?php if ($customized): ?>
  <form method="post" style="margin-top:20px;"><?= csrf_field() ?>
    <button class="cta outline" name="op" value="reset" data-confirm="Revenir à la mise en page d’origine ? Les sections ajoutées et les réglages seront perdus.">Rétablir la mise en page d’origine</button>
  </form>
<?php endif ?>
<script>
  // Replier / déplier : l'état de chaque bloc est retenu le temps de la session (il survit à l'enregistrement).
  const folds = [...document.querySelectorAll('details[id^="k-"]')];
  const store = (k, v) => { try { sessionStorage.setItem('yv-fold-' + k, v ? '1' : '0'); } catch (e) {} };
  const load = (k) => { try { return sessionStorage.getItem('yv-fold-' + k); } catch (e) { return null; } };
  folds.forEach((d) => {
    const saved = load(d.id);
    if (saved !== null && !d.hasAttribute('data-force')) d.open = saved === '1';
    d.addEventListener('toggle', () => store(d.id, d.open));
  });
  document.querySelectorAll('[data-fold]').forEach((b) =>
    b.addEventListener('click', () => {
      const mode = b.dataset.fold;
      folds.forEach((d) => {
        const isSection = /^k-[a-z0-9]+$/.test(d.id);
        d.open = mode === 'open' ? true : mode === 'close' ? false : isSection;
        store(d.id, d.open);
      });
    }),
  );
  document.querySelectorAll('button[data-confirm]').forEach((b) =>
    b.addEventListener('click', (e) => { if (!confirm(b.dataset.confirm)) e.preventDefault(); }),
  );
</script>
<?php
admin_page('Accueil', (string) ob_get_clean());
