<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/seo.php';

require_admin();
$db = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    if (($_POST['op'] ?? '') === 'reset') {
        seo_reset($db);
        $_SESSION['seo_msg'] = 'Titre et description d’origine rétablis.';
    } else {
        seo_save($db, (string) ($_POST['title'] ?? ''), (string) ($_POST['description'] ?? ''), isset($_POST['image']));
        $_SESSION['seo_msg'] = 'Enregistré : Google et les aperçus de liens le prennent en compte progressivement (cela peut prendre quelques jours).';
    }
    header('Location: /admin/referencement', true, 303);
    exit;
}

$msg = $_SESSION['seo_msg'] ?? null;
unset($_SESSION['seo_msg']);
$s = seo_load($db);
$host = (string) parse_url(cfg()['public_base_url'], PHP_URL_HOST);

ob_start();
?>
<h1>Description du site (référencement)</h1>
<p>Le titre et la description de la page d'accueil, tels qu'ils apparaissent dans <strong>Google</strong> et dans les <strong>aperçus de liens</strong>
  (WhatsApp, Facebook, Instagram, messages…). Les autres pages (panier, commande) gardent leur titre.</p>
<?php if ($msg): ?><p class="notice"><?= h($msg) ?></p><?php endif ?>
<div class="detail">
  <section class="panel">
    <form method="post"><?= csrf_field() ?>
      <div class="field">
        <label for="title">Titre <span class="hint" id="title-count"></span></label>
        <input id="title" name="title" value="<?= h($s['title']) ?>" maxlength="<?= SEO_TITLE_MAX ?>">
        <p class="hint">Idéal : 60 caractères ou moins, sinon Google le coupe.</p>
      </div>
      <div class="field" style="margin-top:16px;">
        <label for="description">Description <span class="hint" id="desc-count"></span></label>
        <textarea id="description" name="description" rows="4" maxlength="<?= SEO_DESC_MAX ?>"><?= h($s['description']) ?></textarea>
        <p class="hint">Idéal : entre 120 et 160 caractères. Une phrase qui donne envie de cliquer.</p>
      </div>
      <label class="check" style="display:flex;gap:8px;align-items:center;margin:16px 0;"><input type="checkbox" name="image" value="1"<?= $s['image'] ? ' checked' : '' ?>>
        Montrer la couverture du livre dans les aperçus de liens (WhatsApp, Facebook…)</label>
      <button class="cta">Enregistrer</button>
    </form>
    <?php if ($s['custom']): ?>
      <form method="post" data-confirm="Revenir au titre et à la description d’origine ?" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="op" value="reset">
        <button class="cta outline small">Rétablir l’original</button></form>
    <?php endif ?>
  </section>
  <section class="panel">
    <h2>Aperçu dans Google</h2>
    <div class="seo-preview">
      <div class="seo-url"><?= h($host) ?></div>
      <div class="seo-title" id="pv-title"><?= h($s['title']) ?></div>
      <div class="seo-desc" id="pv-desc"><?= h($s['description']) ?></div>
    </div>
    <p class="hint">Aperçu indicatif : Google peut réécrire le titre et la description selon la recherche.</p>
  </section>
</div>
<script>
  const t = document.getElementById('title'), d = document.getElementById('description');
  const cut = (s, n) => (s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s);
  const sync = () => {
    document.getElementById('title-count').textContent = '(' + t.value.length + ' caractères)';
    document.getElementById('desc-count').textContent = '(' + d.value.length + ' caractères)';
    document.getElementById('pv-title').textContent = cut(t.value, 60);
    document.getElementById('pv-desc').textContent = cut(d.value, 160);
  };
  t.addEventListener('input', sync);
  d.addEventListener('input', sync);
  sync();
</script>
<?php
admin_page('Référencement', (string) ob_get_clean());
