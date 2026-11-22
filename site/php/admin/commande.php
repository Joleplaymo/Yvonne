<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';

require_admin();
$db = db();
$ref = (string) ($_GET['ref'] ?? '');
$o = preg_match('/^YV-\d{4,}$/', $ref) ? find_order($db, $ref) : null;
if (!$o) admin_page('Introuvable', '<p>Commande introuvable. <a href="/admin">← Retour</a></p>', 404);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    $notify = ($_POST['notify'] ?? '') === 'on';
    switch ($action) {
        case 'paid':
            $o = set_status($db, $ref, 'payee');
            if ($notify) mail_order_paid($o);
            break;
        case 'ship':
            $o = set_status($db, $ref, 'expediee', mb_substr(trim((string) ($_POST['tracking'] ?? '')), 0, 40));
            if ($notify) mail_order_shipped($o);
            break;
        case 'cancel':
            set_status($db, $ref, 'annulee');
            break;
        case 'reopen':
            set_status($db, $ref, 'attente_paiement');
            break;
        case 'note':
            set_admin_note($db, $ref, (string) ($_POST['admin_note'] ?? ''));
            break;
    }
    header('Location: /admin/' . rawurlencode($ref) . '?ok=' . rawurlencode($action), true, 303);
    exit;
}

$label = implode("\n", [
    "{$o['first_name']} {$o['last_name']}",
    $o['phone'],
    $o['email'],
    'Point relais : ' . $o['relay_name'] . ($o['relay_id'] !== '' ? " (n° {$o['relay_id']})" : ''),
    $o['relay_address'],
    "{$o['relay_postcode']} {$o['relay_city']} {$o['relay_country']}",
    'Colis : ' . $o['qty'] . ' livre' . ($o['qty'] > 1 ? 's' : ''),
]);
$total = eur((int) $o['total_cents']);
$status = $o['status'];

ob_start();
?>
<p><a href="/admin">← Toutes les commandes</a></p>
<h1><?= h($o['ref']) ?> <span class="pill <?= h($status) ?>"><?= h(STATUS_LABELS[$status]) ?></span></h1>
<?php if (isset($_GET['ok'])): ?><p class="notice">Enregistré.</p><?php endif ?>

<div class="detail">
  <section class="panel">
    <h2>Commande</h2>
    <p>Passée le <?= h(fr_date($o['created_at'], true)) ?></p>
    <table class="totals"><tbody>
      <tr><td><?= (int) $o['qty'] ?> × livre à <?= h(eur((int) $o['unit_cents'])) ?></td><td><?= h(eur($o['qty'] * $o['unit_cents'])) ?></td></tr>
      <tr><td>Livraison</td><td><?= h(eur((int) $o['shipping_cents'])) ?></td></tr>
      <tr class="total"><td>Total attendu sur Revolut</td><td><?= h($total) ?></td></tr>
    </tbody></table>
    <p>Payée : <?= h(fr_date($o['paid_at'], true)) ?><br>Expédiée : <?= h(fr_date($o['shipped_at'], true)) ?><?= $o['tracking'] !== '' ? ' — suivi <strong>' . h($o['tracking']) . '</strong>' : '' ?></p>
    <?php if ($o['note'] !== ''): ?><p><strong>Message du client :</strong><br><?= nl2br(h($o['note'])) ?></p><?php endif ?>
    <p><a href="<?= h(order_page_url($o)) ?>" target="_blank">Page de suivi client ↗</a></p>
  </section>

  <section class="panel">
    <h2>Pour l'étiquette Mondial Relay</h2>
    <pre id="label"><?= h($label) ?></pre>
    <div class="cta-row">
      <button class="cta outline" type="button" data-copy-from="#label">Copier</button>
      <a class="cta outline" href="https://www.mondialrelay.fr/envoi-de-colis/" target="_blank" rel="noopener">Créer l'envoi ↗</a>
    </div>
  </section>
</div>

<section class="actions">
  <h2>Actions</h2>
  <?php if ($status === 'attente_paiement'): ?>
    <form method="post" class="act"><?= csrf_field() ?>
      <input type="hidden" name="action" value="paid">
      <label><input type="checkbox" name="notify" checked> prévenir le client par e-mail</label>
      <button class="cta">Paiement Revolut reçu (<?= h($total) ?>)</button>
    </form>
  <?php endif ?>
  <?php if ($status === 'payee' || $status === 'attente_paiement'): ?>
    <form method="post" class="act"><?= csrf_field() ?>
      <input type="hidden" name="action" value="ship">
      <div class="field"><label for="tracking">N° de suivi Mondial Relay</label><input id="tracking" name="tracking" maxlength="40"></div>
      <label><input type="checkbox" name="notify" checked> envoyer le suivi au client</label>
      <button class="cta terra">Marquer expédiée</button>
    </form>
  <?php endif ?>
  <?php if ($status !== 'annulee' && $status !== 'expediee'): ?>
    <form method="post" class="act" data-confirm="Annuler cette commande ?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button class="cta outline">Annuler la commande</button>
    </form>
  <?php endif ?>
  <?php if ($status === 'annulee'): ?>
    <form method="post" class="act"><?= csrf_field() ?>
      <input type="hidden" name="action" value="reopen">
      <button class="cta outline">Réactiver (en attente de paiement)</button>
    </form>
  <?php endif ?>
  <form method="post" class="act note"><?= csrf_field() ?>
    <input type="hidden" name="action" value="note">
    <div class="field"><label for="admin_note">Note interne</label><textarea id="admin_note" name="admin_note"><?= h($o['admin_note']) ?></textarea></div>
    <button class="cta outline">Enregistrer la note</button>
  </form>
</section>
<?php
admin_page($o['ref'], (string) ob_get_clean());
