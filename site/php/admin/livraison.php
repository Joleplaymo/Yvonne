<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';

require_admin();
$db = db();
$maxQty = cfg()['max_qty'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $r = shipping_from_form((array) ($_POST['min_qty'] ?? []), (array) ($_POST['price'] ?? []), $maxQty);
        if ($r['ok']) {
            set_setting($db, 'shipping_table', shipping_table_string($r['tiers']));
            $_SESSION['ship_msg'] = ['ok', 'Frais de port enregistrés : ils s’appliquent dès maintenant sur le site.'];
        } else {
            // On réaffiche la saisie pour qu'elle puisse être corrigée.
            $_SESSION['ship_msg'] = ['error', $r['error']];
            $_SESSION['ship_form'] = [(array) $_POST['min_qty'], (array) $_POST['price']];
        }
    } elseif ($action === 'reset') {
        delete_setting($db, 'shipping_table');
        $_SESSION['ship_msg'] = ['ok', 'Retour aux frais de port du fichier de configuration.'];
    }
    header('Location: /admin/livraison', true, 303);
    exit;
}

[$msgType, $msg] = $_SESSION['ship_msg'] ?? [null, null];
$form = $_SESSION['ship_form'] ?? null;
unset($_SESSION['ship_msg'], $_SESSION['ship_form']);

$pricing = pricing($db);
$custom = get_setting($db, 'shipping_table') !== null;
$updated = setting_updated_at($db, 'shipping_table');

// Lignes du formulaire : saisie rejetée, sinon paliers actuels ; + lignes vides pour en ajouter.
$str = fn($v) => is_scalar($v) ? (string) $v : '';
if ($form) {
    $rows = array_map(fn($q, $p) => [$str($q), $str($p)], $form[0], $form[1]);
} else {
    $rows = array_map(fn($t) => [(string) $t['minQty'], number_format($t['cents'] / 100, 2, ',', '')], $pricing['shipping']);
}
$target = min(8, max(4, count($rows) + 2));
while (count($rows) < $target) $rows[] = ['', ''];

ob_start();
?>
<h1>Frais de port</h1>
<p>Prix de la livraison en point relais Mondial Relay selon le nombre de livres commandés.
  Chaque palier s'applique <strong>à partir de</strong> sa quantité, jusqu'au palier suivant. Mettre 0 pour offrir la livraison.</p>
<?php if ($msg): ?><p class="notice<?= $msgType === 'error' ? ' error' : '' ?>"><?= h($msg) ?></p><?php endif ?>

<div class="detail">
  <section class="panel">
    <h2>Paliers</h2>
    <form method="post" class="ship-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <table class="ship-table">
        <thead><tr><th>À partir de (livres)</th><th>Prix (€)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => [$q, $p]): ?>
          <tr>
            <td><input name="min_qty[]" value="<?= h((string) $q) ?>" inputmode="numeric" aria-label="Palier <?= $i + 1 ?> : à partir de combien de livres"></td>
            <td><input name="price[]" value="<?= h((string) $p) ?>" inputmode="decimal" placeholder="ex. 4,90" aria-label="Palier <?= $i + 1 ?> : prix en euros"></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <p class="hint">Pour supprimer un palier, videz ses deux cases. Le palier « 1 » est obligatoire. Quantité maximale par commande : <?= (int) $maxQty ?>.</p>
      <button class="cta">Enregistrer</button>
    </form>
    <?php if ($custom): ?>
      <form method="post" data-confirm="Revenir aux frais de port du fichier de configuration ?" style="margin-top:14px;"><?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <button class="cta outline">Revenir aux valeurs de config.php</button>
      </form>
      <p class="hint">Modifié le <?= h(fr_date($updated, true)) ?>.</p>
    <?php else: ?>
      <p class="hint">Valeurs actuelles : celles du fichier de configuration.</p>
    <?php endif ?>
  </section>

  <section class="panel">
    <h2>Ce que paie le client</h2>
    <table class="totals">
      <tbody>
      <?php for ($q = 1; $q <= $maxQty; $q++): $t = compute_totals($q, $pricing); ?>
        <tr><td><?= $q ?> livre<?= $q > 1 ? 's' : '' ?></td><td>port <?= h(eur($t['shipping_cents'])) ?> — total <?= h(eur($t['total_cents'])) ?></td></tr>
      <?php endfor ?>
      </tbody>
    </table>
    <p class="hint">Livre à <?= h(eur($pricing['unitCents'])) ?> l’exemplaire. Les commandes déjà passées gardent le prix payé.</p>
  </section>
</div>
<?php
admin_page('Frais de port', (string) ob_get_clean());
