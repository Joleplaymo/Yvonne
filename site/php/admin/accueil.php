<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/accueil.php';

require_admin();
$db = db();
$types = home_block_types();

/** Valeur d'un sélecteur de couleur : '' = d'origine, nom de couleur, ou « custom » + code saisi. */
function posted_color(string $choice, string $custom): ?string
{
    if ($choice === 'custom') {
        $custom = strtolower(trim($custom));
        return home_color_valid($custom) ? $custom : null;
    }
    return isset(HOME_COLORS[$choice]) ? $choice : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $layout = home_layout($db);
    $pos = null;
    foreach ($layout as $i => $s) if ($s['id'] === $id) $pos = $i;
    $msg = null;

    if ($action === 'add' && isset($types[(string) ($_POST['type'] ?? '')])) {
        if (count($layout) >= HOME_MAX_SECTIONS) {
            $msg = ['error', 'Trop de sections (' . HOME_MAX_SECTIONS . ' maximum).'];
        } else {
            $type = (string) $_POST['type'];
            $defaults = array_map(fn($f) => $f['kind'] === 'select' ? (string) array_key_first($f['options']) : '', $types[$type]['fields']);
            $new = ['id' => home_new_id(), 'type' => $type, 'visible' => true, 'bg' => null, 'fg' => null, 'pad' => null, 'p' => $defaults];
            $layout[] = $new;
            home_layout_save($db, $layout);
            $id = $new['id'];
            $msg = ['ok', 'Section ajoutée en bas de la page : remplissez-la puis déplacez-la où vous voulez.'];
        }
    } elseif ($action === 'reset') {
        foreach ($layout as $s) if (!isset(HOME_BUILTIN[$s['type']])) photo_reset(home_image_slot($s['id']));
        home_layout_reset($db);
        $id = '';
        $msg = ['ok', 'Mise en page d’origine rétablie.'];
    } elseif ($pos !== null) {
        $s = $layout[$pos];
        $custom = !isset(HOME_BUILTIN[$s['type']]);
        if ($action === 'up' || $action === 'down') {
            $to = $action === 'up' ? $pos - 1 : $pos + 1;
            if (isset($layout[$to])) {
                [$layout[$pos], $layout[$to]] = [$layout[$to], $layout[$pos]];
                home_layout_save($db, $layout);
            }
            $msg = ['ok', 'Section déplacée.'];
        } elseif ($action === 'delete' && $custom) {
            photo_reset(home_image_slot($id));
            array_splice($layout, $pos, 1);
            home_layout_save($db, $layout);
            $msg = ['ok', 'Section supprimée.'];
            $id = '';
        } elseif ($action === 'rmimg' && $custom) {
            photo_reset(home_image_slot($id));
            $msg = ['ok', 'Photo retirée.'];
        } elseif ($action === 'save') {
            $s['visible'] = isset($_POST['visible']);
            $s['bg'] = posted_color((string) ($_POST['bg'] ?? ''), (string) ($_POST['bg_custom'] ?? ''));
            $fg = (string) ($_POST['fg'] ?? '');
            $s['fg'] = in_array($fg, ['cream', 'navy'], true) ? $fg : null;
            $pad = (string) ($_POST['pad'] ?? '');
            $s['pad'] = isset(HOME_PADS[$pad]) ? $pad : null;
            if ($custom) {
                $posted = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
                foreach ($types[$s['type']]['fields'] as $name => $f) {
                    if ($f['kind'] === 'image') continue;
                    $v = $posted[$name] ?? '';
                    $s['p'][$name] = home_clean_value($f, is_string($v) ? $v : '');
                }
            }
            $layout[$pos] = $s;
            home_layout_save($db, $layout);
            $msg = ['ok', 'Section enregistrée : elle est sur le site en quelques minutes.'];
            if ($custom && !empty($_FILES['image']['name'])) {
                $err = photo_save(home_image_slot($id), $_FILES['image']);
                if ($err !== null) $msg = ['error', 'Section enregistrée, mais la photo n’a pas été remplacée : ' . $err];
            }
        }
    }
    if ($msg) $_SESSION['home_msg'] = [$id, $msg[0], $msg[1]];
    header('Location: /admin/accueil' . ($id !== '' ? '#s-' . rawurlencode($id) : ''), true, 303);
    exit;
}

[$flashId, $flashType, $flash] = $_SESSION['home_msg'] ?? [null, null, null];
unset($_SESSION['home_msg']);
$layout = home_layout($db);
$customized = home_layout_custom($db);

function color_select(string $name, ?string $value, string $customName): string
{
    $isCustom = $value !== null && !isset(HOME_COLORS[$value]);
    $o = '<option value="">D’origine</option>';
    foreach (HOME_COLORS as $k => $c) $o .= '<option value="' . h($k) . '"' . ($value === $k ? ' selected' : '') . '>' . h($c[0]) . '</option>';
    $o .= '<option value="custom"' . ($isCustom ? ' selected' : '') . '>Autre couleur…</option>';
    return '<select name="' . h($name) . '">' . $o . '</select> <input type="color" name="' . h($customName) . '" value="'
        . h($isCustom ? $value : '#cccccc') . '" aria-label="Autre couleur">';
}

ob_start();
?>
<h1>Mise en page de l'accueil</h1>
<p>L'accueil est une suite de sections. Pour chacune, vous pouvez la <strong>déplacer</strong> (flèches), la <strong>masquer</strong>,
  changer son <strong>fond</strong>, la couleur de son <strong>texte</strong> et son <strong>espacement</strong>.
  Vous pouvez aussi <strong>ajouter vos propres sections</strong> (texte, photo, image + texte, colonnes, citation), par exemple pour remplacer un bloc de texte par une photo.</p>
<p class="hint">Les textes et photos des 6 sections d'origine se changent dans les onglets « textes » et « photos ».
  Pour une section d'origine, choisissez un fond de même nature (foncé pour une section foncée) : certains petits textes gardent leur couleur.
  <a href="/" target="_blank">Voir l'accueil</a> (les changements apparaissent en quelques minutes).</p>

<?php foreach ($layout as $i => $s):
    $custom = !isset(HOME_BUILTIN[$s['type']]);
    $label = $custom ? $types[$s['type']]['label'] : HOME_BUILTIN[$s['type']];
    $slot = $custom ? home_image_slot($s['id']) : null;
    ?>
  <section class="panel home-sec<?= $s['visible'] ? '' : ' is-hidden' ?>" id="s-<?= h($s['id']) ?>">
    <div class="home-head">
      <h2><?= $i + 1 ?>. <?= h($label) ?><?= $custom ? ' <span class="pill expediee">ajoutée</span>' : '' ?><?= $s['visible'] ? '' : ' <span class="pill annulee">masquée</span>' ?></h2>
      <div class="home-move">
        <?php foreach (['up' => ['↑', 'Monter'], 'down' => ['↓', 'Descendre']] as $a => [$sym, $t]): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($s['id']) ?>"><input type="hidden" name="action" value="<?= $a ?>">
            <button class="cta outline small" aria-label="<?= $t ?>" title="<?= $t ?>"<?= ($a === 'up' && $i === 0) || ($a === 'down' && $i === count($layout) - 1) ? ' disabled' : '' ?>><?= $sym ?></button></form>
        <?php endforeach ?>
      </div>
    </div>
    <?php if ($flashId === $s['id'] && $flash): ?><p class="notice<?= $flashType === 'error' ? ' error' : '' ?>"><?= h($flash) ?></p><?php endif ?>
    <details<?= $flashId === $s['id'] ? ' open' : '' ?>>
      <summary>Modifier cette section</summary>
      <form method="post" enctype="multipart/form-data" class="home-form"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= h($s['id']) ?>"><input type="hidden" name="action" value="save">
        <label class="check"><input type="checkbox" name="visible"<?= $s['visible'] ? ' checked' : '' ?>> Afficher cette section</label>
        <?php if ($custom): foreach ($types[$s['type']]['fields'] as $name => $f): $v = $s['p'][$name] ?? ''; ?>
          <div class="field">
            <label for="f-<?= h($s['id'] . $name) ?>"><?= h($f['label']) ?></label>
            <?php if ($f['kind'] === 'area'): ?>
              <textarea id="f-<?= h($s['id'] . $name) ?>" name="f[<?= h($name) ?>]" rows="<?= (int) ($f['rows'] ?? 4) ?>" maxlength="<?= (int) $f['max'] ?>"><?= h($v) ?></textarea>
            <?php elseif ($f['kind'] === 'select'): ?>
              <select id="f-<?= h($s['id'] . $name) ?>" name="f[<?= h($name) ?>]">
                <?php foreach ($f['options'] as $ok => $ol): ?><option value="<?= h((string) $ok) ?>"<?= $v === (string) $ok ? ' selected' : '' ?>><?= h($ol) ?></option><?php endforeach ?>
              </select>
            <?php elseif ($f['kind'] === 'image'): ?>
              <?php if (photo_is_custom($slot)): ?>
                <img class="photo-preview" src="/photo/<?= h($slot) ?>?w=480&amp;v=<?= photo_version($slot) ?>" alt="">
                <button class="cta outline small" type="submit" form="rmimg-<?= h($s['id']) ?>">Retirer la photo</button>
              <?php endif ?>
              <input type="file" id="f-<?= h($s['id'] . $name) ?>" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
              <p class="hint">Choisissez un fichier puis « Enregistrer cette section » (JPEG, PNG, WebP ou GIF, 15 Mo maximum).</p>
            <?php else: ?>
              <input id="f-<?= h($s['id'] . $name) ?>" name="f[<?= h($name) ?>]" value="<?= h($v) ?>" maxlength="<?= (int) $f['max'] ?>"<?= $f['kind'] === 'url' ? ' inputmode="url" placeholder="/panier?ajouter=1"' : '' ?>>
            <?php endif ?>
          </div>
        <?php endforeach; endif ?>
        <div class="home-look">
          <div class="field"><label>Fond</label><div class="color-row"><?= color_select('bg', $s['bg'], 'bg_custom') ?></div></div>
          <div class="field"><label>Texte</label>
            <select name="fg">
              <option value="">Automatique</option>
              <option value="cream"<?= $s['fg'] === 'cream' ? ' selected' : '' ?>>Clair (crème)</option>
              <option value="navy"<?= $s['fg'] === 'navy' ? ' selected' : '' ?>>Foncé (bleu)</option>
            </select></div>
          <div class="field"><label>Espacement haut et bas</label>
            <select name="pad">
              <option value="">D’origine</option>
              <?php foreach (HOME_PAD_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $s['pad'] === $k ? ' selected' : '' ?>><?= h($l) ?> (<?= HOME_PADS[$k] ?> px)</option><?php endforeach ?>
            </select></div>
        </div>
        <button class="cta">Enregistrer cette section</button>
      </form>
      <?php if ($custom): ?>
        <form method="post" id="rmimg-<?= h($s['id']) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($s['id']) ?>"><input type="hidden" name="action" value="rmimg"></form>
        <form method="post" data-confirm="Supprimer définitivement cette section ?" style="margin-top:12px;"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= h($s['id']) ?>"><input type="hidden" name="action" value="delete">
          <button class="cta outline small">Supprimer cette section</button></form>
      <?php endif ?>
    </details>
  </section>
<?php endforeach ?>

<section class="panel home-sec" id="ajouter">
  <h2>Ajouter une section</h2>
  <form method="post" class="home-add"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <select name="type" aria-label="Type de section">
      <?php foreach ($types as $k => $t): ?><option value="<?= h($k) ?>"><?= h($t['label']) ?> : <?= h($t['hint']) ?></option><?php endforeach ?>
    </select>
    <button class="cta">Ajouter en bas de la page</button>
  </form>
</section>
<?php if ($customized): ?>
  <form method="post" data-confirm="Revenir à la mise en page d’origine ? Les sections ajoutées seront supprimées." style="margin-top:20px;"><?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <button class="cta outline">Rétablir la mise en page d’origine</button>
  </form>
<?php endif;
admin_page('Accueil', (string) ob_get_clean());
