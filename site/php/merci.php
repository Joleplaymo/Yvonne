<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require_once __DIR__ . '/_prive/paiement.php';

$ref = (string) ($_GET['ref'] ?? '');
$token = (string) ($_GET['t'] ?? '');
$order = null;
if (preg_match('/^YV-\d{4,}$/', $ref)) {
    $found = find_order(db(), $ref);
    if ($found && $token !== '' && hash_equals($found['token'], $token)) $order = $found;
}

if (!$order) {
    render_page('Commande introuvable', '<h1 style="font-size:40px;">Commande introuvable</h1>
      <p class="lead">Le lien est peut-être incomplet. Retrouvez le bon lien dans l\'e-mail de confirmation, ou écrivez à
      <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>.</p>', 'gabarit', 404);
}

$o = $order;
$link = pay_link_paper((int) $o['total_cents'], $o['ref']);
$pay = $link['url'];
$amountInLink = $link['prefilled'];
$total = eur((int) $o['total_cents']);

ob_start();
?>
<ol class="steps" aria-label="Étapes de la commande">
  <li><b>1</b>Panier</li><li><b>2</b>Livraison & coordonnées</li><li class="current" aria-current="step"><b>3</b>Paiement</li>
</ol>
<p class="ref">Commande <strong><?= h($o['ref']) ?></strong> · <?= h(STATUS_LABELS[$o['status']]) ?></p>

<?php if ($o['status'] === 'attente_paiement'): ?>
  <div class="pay-grid">
    <div>
      <h1 style="font-size:44px;">Merci <?= h($o['first_name']) ?> !<br>Dernière étape : le paiement.</h1>
      <ol class="howto">
        <li>Cliquez sur le bouton ci-dessous : Revolut s'ouvre<?= $amountInLink ? ' avec le montant déjà rempli' : '' ?>.</li>
        <?php if (!$amountInLink): ?><li>Saisissez le montant exact : <strong><?= h($total) ?></strong>.</li><?php endif ?>
        <li>Dans le message du paiement, indiquez la référence <strong class="mono"><?= h($o['ref']) ?></strong>.</li>
        <li>Je confirme la réception et prépare votre colis. Vous recevez un e-mail à chaque étape.</li>
      </ol>
      <div class="cta-row" style="margin-top:28px;">
        <a class="cta terra" href="<?= h($pay) ?>" target="_blank" rel="noopener">Payer <?= h($total) ?> avec Revolut</a>
        <button class="cta outline" type="button" data-copy="<?= h($o['ref']) ?>"><i data-icon="copy"></i> <span>Copier la référence</span></button>
      </div>
      <p class="hint">Un souci pour payer ? <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a></p>
    </div>
    <aside class="panel">
      <table class="totals"><tbody>
        <tr><td><?= (int) $o['qty'] ?> × Les hormones d'Yvonne</td><td><?= h(eur($o['qty'] * $o['unit_cents'])) ?></td></tr>
        <tr><td>Livraison point relais</td><td><?= h(eur((int) $o['shipping_cents'])) ?></td></tr>
        <tr class="total"><td>Total</td><td><?= h($total) ?></td></tr>
      </tbody></table>
      <p class="relay"><i data-icon="map-pin"></i><span><strong><?= h($o['relay_name']) ?></strong><br><?= h($o['relay_address']) ?><br><?= h($o['relay_postcode'] . ' ' . $o['relay_city']) ?></span></p>
    </aside>
  </div>
<?php elseif ($o['status'] === 'payee'): ?>
  <h1 style="font-size:44px;">Paiement reçu, merci !</h1>
  <p class="lead">Je prépare votre colis avec soin. Vous recevrez le numéro de suivi par e-mail dès l'expédition vers <strong><?= h($o['relay_name']) ?></strong>, <?= h($o['relay_city']) ?>.</p>
<?php elseif ($o['status'] === 'expediee'): ?>
  <h1 style="font-size:44px;">Votre colis est en route</h1>
  <p class="lead">Destination : <strong><?= h($o['relay_name']) ?></strong>, <?= h($o['relay_address'] . ', ' . $o['relay_postcode'] . ' ' . $o['relay_city']) ?>.</p>
  <?php if ($o['tracking'] !== ''): ?>
    <p class="lead">Numéro de suivi Mondial Relay : <strong class="mono"><?= h($o['tracking']) ?></strong> — <a href="https://www.mondialrelay.fr/suivi-de-colis/" target="_blank" rel="noopener">suivre le colis</a></p>
  <?php endif ?>
<?php else: ?>
  <h1 style="font-size:44px;">Commande annulée</h1>
  <p class="lead">Une question ? <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a></p>
<?php endif;

render_page("Commande {$o['ref']} — Les hormones d'Yvonne", (string) ob_get_clean());
