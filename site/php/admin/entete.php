<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/entete.php';

require_admin();
$db = db();

/** Fond choisi : nom de couleur ou « custom » + code saisi (voir color_select). */
function posted_bg(array $in): string
{
    $bg = (string) ($in['bg'] ?? '');
    return $bg === 'custom' ? strtolower(trim((string) ($in['bg_custom'] ?? ''))) : $bg;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $part = ($_POST['part'] ?? '') === 'footer' ? 'footer' : 'header';
    $in = is_array($_POST['c'] ?? null) ? $_POST['c'] : [];
    if (($_POST['op'] ?? '') === 'reset') {
        chrome_reset($db, $part);
        $_SESSION['chrome_msg'] = [$part, 'ok', ($part === 'header' ? 'En-tête' : 'Pied de page') . ' d’origine rétabli.'];
    } else {
        $in['bg'] = posted_bg($in);
        // Lignes de liens : les lignes vides disparaissent.
        $in['links'] = array_values(array_filter(is_array($in['links'] ?? null) ? $in['links'] : [], 'is_array'));
        chrome_save($db, $part, $in);
        $_SESSION['chrome_msg'] = [$part, 'ok', 'Enregistré : c’est sur le site en quelques minutes.'];
    }
    header('Location: /admin/entete#' . $part, true, 303);
    exit;
}

[$flashPart, $flashType, $flash] = $_SESSION['chrome_msg'] ?? [null, null, null];
unset($_SESSION['chrome_msg']);
$header = chrome_load($db, 'header');
$footer = chrome_load($db, 'footer');
$h = $header ?? chrome_header_default();
$f = $footer ?? chrome_footer_default();

function opts(array $options, string $value): string
{
    $o = '';
    foreach ($options as $k => $l) $o .= '<option value="' . h((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . h((string) $l) . '</option>';
    return $o;
}

function color_select(string $name, string $value, string $customName): string
{
    $isCustom = !isset(HOME_COLORS[$value]);
    $o = '';
    foreach (HOME_COLORS as $k => $c) $o .= '<option value="' . h($k) . '"' . ($value === $k ? ' selected' : '') . '>' . h($c[0]) . '</option>';
    $o .= '<option value="custom"' . ($isCustom ? ' selected' : '') . '>Autre couleur…</option>';
    return '<select name="' . h($name) . '">' . $o . '</select> <input type="color" name="' . h($customName) . '" value="' . h($isCustom ? $value : '#cccccc') . '" aria-label="Autre couleur">';
}

$textColors = array_map(fn($c) => $c[0], chrome_text_colors());
$flashBox = fn(string $part) => $flashPart === $part && $flash ? '<p class="notice' . ($flashType === 'error' ? ' error' : '') . '">' . h($flash) . '</p>' : '';

ob_start();
?>
<h1>En-tête et pied de page</h1>
<p>La barre du haut et le bas de page, sur toutes les pages du site (accueil, panier, commande, paiement). Les lignes de liens laissées vides disparaissent ;
  pour en ajouter, remplissez une ligne vide. Les couleurs de la palette générale se règlent dans « couleurs ».</p>

<section class="panel home-sec" id="header">
  <h2>En-tête (barre du haut)<?= $header ? ' <span class="pill payee">modifié</span>' : '' ?></h2>
  <?= $flashBox('header') ?>
  <form method="post" class="home-form"><?= csrf_field() ?><input type="hidden" name="part" value="header">
    <div class="home-look">
      <div class="field"><label>Nom du site (à gauche)</label><input name="c[brand]" value="<?= h($h['brand']) ?>" maxlength="60"></div>
      <div class="field"><label>Lien du nom</label><input name="c[brand_url]" value="<?= h($h['brand_url']) ?>" maxlength="300"></div>
    </div>
    <table class="ship-table">
      <thead><tr><th>Lien : texte</th><th>Adresse (/#livre, /panier, https://…)</th><th>Visible sur téléphone</th></tr></thead>
      <tbody>
      <?php for ($i = 0; $i < CHROME_MAX_LINKS; $i++): $l = $h['links'][$i] ?? ['label' => '', 'url' => '', 'mobile' => false]; ?>
        <tr>
          <td><input name="c[links][<?= $i ?>][label]" value="<?= h($l['label']) ?>" maxlength="40" aria-label="Lien <?= $i + 1 ?> : texte"></td>
          <td><input name="c[links][<?= $i ?>][url]" value="<?= h($l['url']) ?>" maxlength="300" aria-label="Lien <?= $i + 1 ?> : adresse"></td>
          <td><input type="checkbox" name="c[links][<?= $i ?>][mobile]" value="1"<?= $l['mobile'] ? ' checked' : '' ?> aria-label="Lien <?= $i + 1 ?> visible sur téléphone"></td>
        </tr>
      <?php endfor ?>
      </tbody>
    </table>
    <div class="home-look">
      <label class="check"><input type="checkbox" name="c[cart]" value="1"<?= $h['cart'] ? ' checked' : '' ?>> Afficher le lien « panier »</label>
      <div class="field"><label>Texte du lien panier</label><input name="c[cart_label]" value="<?= h($h['cart_label']) ?>" maxlength="40"></div>
      <div class="field"><label>Fond</label><div class="color-row"><?= color_select('c[bg]', $h['bg'], 'c[bg_custom]') ?></div></div>
      <div class="field"><label>Couleur du texte</label><select name="c[fg]"><?= opts(['' => 'Automatique'] + $textColors, $h['fg']) ?></select></div>
      <div class="field"><label>Couleur du lien panier</label><select name="c[accent]"><?= opts($textColors, $h['accent']) ?></select></div>
      <div class="field"><label>Hauteur de la barre</label><select name="c[pad]"><?= opts(array_combine(CHROME_HEADER_PADS, array_map(fn($p) => "$p px", CHROME_HEADER_PADS)), (string) $h['pad']) ?></select></div>
      <label class="check"><input type="checkbox" name="c[sticky]" value="1"<?= $h['sticky'] ? ' checked' : '' ?>> Reste visible en défilant</label>
    </div>
    <button class="cta">Enregistrer l’en-tête</button>
  </form>
  <?php if ($header): ?>
    <form method="post" data-confirm="Revenir à l’en-tête d’origine ?" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="part" value="header"><input type="hidden" name="op" value="reset">
      <button class="cta outline small">Rétablir l’en-tête d’origine</button></form>
  <?php endif ?>
</section>

<section class="panel home-sec" id="footer">
  <h2>Pied de page<?= $footer ? ' <span class="pill payee">modifié</span>' : '' ?></h2>
  <?= $flashBox('footer') ?>
  <form method="post" class="home-form"><?= csrf_field() ?><input type="hidden" name="part" value="footer">
    <div class="field"><label>Texte (<code>**gras**</code>, <code>[lien](https://…)</code>, <code>[écrivez-nous](mailto:…)</code>)</label>
      <textarea name="c[text]" rows="3" maxlength="600"><?= h($f['text']) ?></textarea></div>
    <table class="ship-table">
      <thead><tr><th>Icône</th><th>Adresse (https://…, mailto:…)</th><th>Nom (lecteurs d’écran)</th></tr></thead>
      <tbody>
      <?php for ($i = 0; $i < CHROME_MAX_LINKS; $i++): $l = $f['links'][$i] ?? ['icon' => 'instagram', 'url' => '', 'label' => '']; ?>
        <tr>
          <td><select name="c[links][<?= $i ?>][icon]" aria-label="Lien <?= $i + 1 ?> : icône"><?= opts(chrome_icons(), $l['icon']) ?></select></td>
          <td><input name="c[links][<?= $i ?>][url]" value="<?= h($l['url']) ?>" maxlength="300" aria-label="Lien <?= $i + 1 ?> : adresse"></td>
          <td><input name="c[links][<?= $i ?>][label]" value="<?= h($l['label']) ?>" maxlength="40" aria-label="Lien <?= $i + 1 ?> : nom"></td>
        </tr>
      <?php endfor ?>
      </tbody>
    </table>
    <div class="home-look">
      <div class="field"><label>Fond</label><div class="color-row"><?= color_select('c[bg]', $f['bg'], 'c[bg_custom]') ?></div></div>
      <div class="field"><label>Couleur du texte</label><select name="c[fg]"><?= opts(['' => 'Automatique'] + $textColors, $f['fg']) ?></select></div>
      <div class="field"><label>Hauteur du pied de page</label><select name="c[pad]"><?= opts(array_combine(CHROME_FOOTER_PADS, array_map(fn($p) => "$p px", CHROME_FOOTER_PADS)), (string) $f['pad']) ?></select></div>
    </div>
    <button class="cta">Enregistrer le pied de page</button>
  </form>
  <?php if ($footer): ?>
    <form method="post" data-confirm="Revenir au pied de page d’origine ?" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="part" value="footer"><input type="hidden" name="op" value="reset">
      <button class="cta outline small">Rétablir le pied de page d’origine</button></form>
  <?php endif ?>
</section>
<?php
admin_page('En-tête et pied de page', (string) ob_get_clean());
