<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/ebook.php';

require_admin();
$db = db();
$ref = (string) ($_GET['ref'] ?? '');
$o = preg_match('/^EB-\d{4,}$/', $ref) ? find_ebook_order($db, $ref) : null;
if (!$o) admin_page('Introuvable', '<p>Commande introuvable. <a href="/admin">← Retour</a></p>', 404);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    $result = $action;
    switch ($action) {
        case 'deliver':
            if ($o['status'] === 'annulee') {
                $result = 'refused-cancelled';
            } elseif (!is_file(ebook_master_path())) {
                $result = 'refused-master';
            } else {
                $token = ebook_deliver($db, $ref);
                // mail_ebook_delivered() ne renvoie rien : un échec d'envoi n'est visible que dans les journaux du serveur.
                mail_ebook_delivered(find_ebook_order($db, $ref), $token);
            }
            break;
        case 'cancel':
            ebook_set_status($db, $ref, 'annulee');
            break;
        case 'reopen':
            ebook_set_status($db, $ref, 'attente_paiement');
            break;
        case 'note':
            ebook_set_admin_note($db, $ref, (string) ($_POST['admin_note'] ?? ''));
            break;
    }
    header('Location: /admin/' . rawurlencode($ref) . '?ok=' . rawurlencode($result), true, 303);
    exit;
}

$status = $o['status'];
$price = eur((int) $o['price_cents']);
$downloads = ebook_downloads_of($db, (int) $o['id']);
$notices = [
    'deliver' => ['Lien envoyé au client.', false],
    'cancel' => ['Commande annulée.', false],
    'reopen' => ['Commande remise en attente de paiement ; le lien précédent ne fonctionne plus.', false],
    'note' => ['Note enregistrée.', false],
    'refused-master' => ['Envoi impossible : le fichier du livre est absent. Déposez-le dans « livre numérique » puis recommencez.', true],
    'refused-cancelled' => ['Cette commande est annulée : réactivez-la d\'abord.', true],
];
$ok = (string) ($_GET['ok'] ?? '');

ob_start();
?>
<p><a href="/admin">← Toutes les commandes</a></p>
<h1><?= h($o['ref']) ?> <span class="pill <?= h($status) ?>"><?= h(EBOOK_STATUS_LABELS[$status]) ?></span></h1>
<?php if (isset($notices[$ok])): ?><p class="notice<?= $notices[$ok][1] ? ' error' : '' ?>"><?= h($notices[$ok][0]) ?></p><?php endif ?>

<div class="detail">
  <section class="panel">
    <h2>Commande</h2>
    <p>Passée le <?= h(fr_date($o['created_at'], true)) ?><br>
      <?= h($o['first_name'] . ' ' . $o['last_name']) ?> — <a href="mailto:<?= h($o['email']) ?>"><?= h($o['email']) ?></a></p>
    <table class="totals"><tbody>
      <tr class="total"><td>Livre numérique — total attendu sur Revolut</td><td><?= h($price) ?></td></tr>
    </tbody></table>
    <p>Payée : <?= h(fr_date($o['paid_at'], true)) ?></p>
    <p><a href="<?= h(ebook_order_page_url($o)) ?>" target="_blank" rel="noopener">Page de suivi client ↗</a></p>
  </section>

  <section class="panel">
    <h2>Consentement</h2>
    <p><?= h($o['consent_text']) ?></p>
    <p class="hint">Accepté le <?= h(fr_date($o['consent_at'], true)) ?> · IP <?= h($o['consent_ip']) ?></p>
    <h2>Téléchargement</h2>
    <p><?= (int) $o['dl_count'] ?> / <?= (int) $o['dl_max'] ?> utilisés<?= $o['dl_expires_at'] ? ' · lien valable jusqu\'au ' . h(fr_date($o['dl_expires_at'], true)) : '' ?></p>
  </section>
</div>

<section class="panel">
  <h2>Historique des téléchargements</h2>
  <?php if (!$downloads): ?>
    <p>Aucun téléchargement.</p>
  <?php else: ?>
    <table class="list">
      <thead><tr><th>Date</th><th>Adresse IP</th><th class="opt">Navigateur</th></tr></thead>
      <tbody>
      <?php foreach ($downloads as $d): ?>
        <tr><td><?= h(fr_date($d['at'], true)) ?></td><td><?= h($d['ip']) ?></td><td class="opt"><?= h($d['user_agent']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</section>

<section class="actions">
  <h2>Actions</h2>
  <?php if ($status === 'attente_paiement'): ?>
    <form method="post" class="act"><?= csrf_field() ?>
      <input type="hidden" name="action" value="deliver">
      <button class="cta">Paiement reçu — envoyer le lien (<?= h($price) ?>)</button>
    </form>
  <?php elseif ($status === 'livree'): ?>
    <form method="post" class="act" data-confirm="Un nouveau lien va être envoyé au client. L'ancien lien ne fonctionnera plus. Continuer ?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="deliver">
      <button class="cta terra">Renvoyer un nouveau lien</button>
      <span class="hint">L'ancien lien cesse de fonctionner et le compteur repart à zéro.</span>
    </form>
  <?php endif ?>
  <?php if ($status !== 'annulee'): ?>
    <form method="post" class="act" data-confirm="Annuler cette commande ?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button class="cta outline">Annuler la commande</button>
    </form>
  <?php else: ?>
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
