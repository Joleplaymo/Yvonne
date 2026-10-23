<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/photos.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $slot = (string) ($_POST['slot'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'upload') {
        $msg = photo_save($slot, $_FILES['photo'] ?? []) ?? 'ok';
    } elseif ($action === 'reset' && isset(PHOTO_SLOTS[$slot])) {
        photo_reset($slot);
        $msg = 'reset';
    } else {
        $msg = 'Action inconnue.';
    }
    $_SESSION['photo_msg'] = [$slot, $msg];
    header('Location: /admin/photos#' . rawurlencode($slot), true, 303);
    exit;
}

[$flashSlot, $flash] = $_SESSION['photo_msg'] ?? [null, null];
unset($_SESSION['photo_msg']);
$maxUpload = ini_get('upload_max_filesize');

ob_start();
?>
<h1>Photos du site</h1>
<p>Choisissez une image puis « Remplacer » : elle apparaît sur le site en quelques minutes (redimensionnée et optimisée automatiquement).
  Les cadres du site gardent leur forme : une image d'un autre format sera recadrée au centre.</p>
<div class="photo-grid">
<?php foreach (PHOTO_SLOTS as $slot => $meta): $custom = photo_is_custom($slot); ?>
  <section class="panel photo-card" id="<?= h($slot) ?>">
    <h2><?= h($meta['label']) ?></h2>
    <p class="hint"><?= h($meta['hint']) ?></p>
    <img src="/photo/<?= h($slot) ?>?w=480&amp;t=<?= time() ?>" alt="" class="photo-preview" loading="lazy">
    <p class="hint"><?= $custom ? 'Photo personnalisée' : 'Photo d’origine' ?></p>
    <?php if ($flashSlot === $slot): ?>
      <?php if ($flash === 'ok'): ?><p class="notice">Photo remplacée.</p>
      <?php elseif ($flash === 'reset'): ?><p class="notice">Photo d’origine rétablie.</p>
      <?php else: ?><p class="notice error"><?= h($flash) ?></p><?php endif ?>
    <?php endif ?>
    <form method="post" enctype="multipart/form-data" class="photo-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="slot" value="<?= h($slot) ?>">
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" required>
      <button class="cta">Remplacer</button>
    </form>
    <?php if ($custom): ?>
      <form method="post" data-confirm="Revenir à la photo d’origine ?"><?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="slot" value="<?= h($slot) ?>">
        <button class="cta outline">Rétablir l’originale</button>
      </form>
    <?php endif ?>
  </section>
<?php endforeach ?>
</div>
<p class="hint">Formats acceptés : JPEG, PNG, WebP, GIF. Taille maximale : 15 Mo (limite de l'hébergement : <?= h($maxUpload) ?>).</p>
<?php
admin_page('Photos', (string) ob_get_clean());
