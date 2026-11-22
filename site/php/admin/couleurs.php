<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/couleurs.php';

require_admin();
$db = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $error = null;
        foreach ((array) ($_POST['c'] ?? []) as $name => $value) {
            if (!is_string($name) || !is_string($value) || !isset(COLORS[$name])) continue;
            $error = color_save($db, $name, $value);
            if ($error !== null) {
                $error = COLORS[$name]['label'] . ' : ' . $error;
                break;
            }
        }
        $_SESSION['color_msg'] = $error ? ['error', $error] : ['ok', 'Couleurs enregistrées : elles sont sur le site en quelques minutes.'];
    } elseif ($action === 'reset') {
        foreach (array_keys(COLORS) as $name) delete_setting($db, "couleur:$name");
        $_SESSION['color_msg'] = ['ok', 'Couleurs d’origine rétablies.'];
    }
    header('Location: /admin/couleurs', true, 303);
    exit;
}

[$msgType, $msg] = $_SESSION['color_msg'] ?? [null, null];
unset($_SESSION['color_msg']);
$custom = color_overrides($db);
$warnings = color_warnings($custom);
$vars = '';
foreach (COLORS as $name => $meta) $vars .= "--$name:" . ($custom[$name] ?? $meta['default']) . ';';

ob_start();
?>
<h1>Couleurs du site</h1>
<p>Changez les couleurs de l'accueil, du panier, de la commande (livraison) et des pages de paiement (facturation).
  Choisissez une couleur puis « Enregistrer » : elle apparaît sur le site en quelques minutes. Les nuances proches (survol des boutons, fonds, textes atténués) suivent automatiquement.
  Cette administration garde ses couleurs.</p>
<?php if ($msg): ?><p class="notice<?= $msgType === 'error' ? ' error' : '' ?>"><?= h($msg) ?></p><?php endif ?>
<div class="detail">
  <section class="panel">
    <h2>Palette</h2>
    <form method="post" id="couleurs"><?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php foreach (COLORS as $name => $meta): $value = $custom[$name] ?? $meta['default']; ?>
        <div class="field color-field">
          <label for="c-<?= h($name) ?>"><?= h($meta['label']) ?><?= isset($custom[$name]) ? ' <span class="pill payee">modifiée</span>' : '' ?></label>
          <div class="color-row">
            <input type="color" id="c-<?= h($name) ?>" name="c[<?= h($name) ?>]" value="<?= h($value) ?>" data-var="--<?= h($name) ?>" data-default="<?= h($meta['default']) ?>">
            <code><?= h($value) ?></code>
          </div>
          <p class="hint"><?= h($meta['hint']) ?></p>
        </div>
      <?php endforeach ?>
      <button class="cta">Enregistrer les couleurs</button>
    </form>
    <?php if ($custom): ?>
      <form method="post" data-confirm="Revenir aux couleurs d’origine ?" style="margin-top:14px;"><?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <button class="cta outline">Rétablir les couleurs d’origine</button>
      </form>
    <?php endif ?>
  </section>

  <section class="panel">
    <h2>Aperçu</h2>
    <div class="theme-preview" id="preview" style="<?= h($vars) ?>">
      <div class="tp-navy"><strong>les hormones d'Yvonne</strong><span>Texte crème sur bleu foncé</span><em>Accent pêche</em></div>
      <div class="tp-cream"><span>Texte bleu foncé sur fond crème</span> <a href="#" onclick="return false">Lien terracotta</a>
        <button type="button" class="tp-btn">Je commande</button> <b class="tp-err">Message d’erreur</b></div>
    </div>
    <?php foreach ($warnings as $w): ?><p class="notice error"><?= h($w) ?></p><?php endforeach ?>
    <p class="hint">L'aperçu se met à jour dès que vous choisissez une couleur (sans l'enregistrer).</p>
  </section>
</div>
<script>
  document.querySelectorAll('#couleurs input[type=color]').forEach((i) =>
    i.addEventListener('input', () => {
      document.getElementById('preview').style.setProperty(i.dataset.var, i.value);
      i.parentElement.querySelector('code').textContent = i.value;
    }),
  );
</script>
<?php
admin_page('Couleurs', (string) ob_get_clean());
