<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require_once __DIR__ . '/_prive/ebook.php';

$ref = (string) ($_GET['ref'] ?? '');
$token = (string) ($_GET['t'] ?? '');
$order = null;
if (preg_match('/^EB-\d{4,}$/', $ref)) {
    $found = find_ebook_order(db(), $ref);
    if ($found && $token !== '' && hash_equals($found['token'], $token)) $order = $found;
}

if (!$order) {
    render_page('Commande introuvable', '<h1 style="font-size:40px;">Commande introuvable</h1>
      <p class="lead">Le lien est peut-être incomplet. Retrouvez le bon lien dans l\'e-mail de confirmation, ou écrivez à
      <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>.</p>', 'gabarit', 404);
}

$o = $order;
$template = cfg()['revolut_link_template'];
$pay = revolut_link($template, (int) $o['price_cents'], $o['ref']);
$amountInLink = str_contains($template, '{amount}');
$price = eur((int) $o['price_cents']);
$waiting = $o['status'] === 'attente_paiement';
$delivered = $o['status'] === 'livree';

ob_start();
?>
<ol class="steps" aria-label="Étapes de la commande">
  <li><b>1</b>Coordonnées</li><li<?= $waiting ? ' class="current" aria-current="step"' : '' ?>><b>2</b>Paiement</li><li<?= $delivered ? ' class="current" aria-current="step"' : '' ?>><b>3</b>Téléchargement</li>
</ol>
<p class="ref">Commande <strong><?= h($o['ref']) ?></strong> · <?= h(EBOOK_STATUS_LABELS[$o['status']] ?? $o['status']) ?></p>

<?php if ($waiting): ?>
  <div class="pay-grid">
    <div>
      <h1 style="font-size:44px;">Merci <?= h($o['first_name']) ?> !<br>Dernière étape : le paiement.</h1>
      <ol class="howto">
        <li>Cliquez sur le bouton ci-dessous : Revolut s'ouvre<?= $amountInLink ? ' avec le montant déjà rempli' : '' ?>.</li>
        <?php if (!$amountInLink): ?><li>Saisissez le montant exact : <strong><?= h($price) ?></strong>.</li><?php endif ?>
        <li>Dans le message du paiement, indiquez la référence <strong class="mono"><?= h($o['ref']) ?></strong>.</li>
        <li>Votre lien de téléchargement arrive par e-mail sous 24 h après réception du paiement.</li>
      </ol>
      <div class="cta-row" style="margin-top:28px;">
        <a class="cta terra" href="<?= h($pay) ?>" target="_blank" rel="noopener">Payer <?= h($price) ?> avec Revolut</a>
        <button class="cta outline" type="button" data-copy="<?= h($o['ref']) ?>"><i data-icon="copy"></i> <span>Copier la référence</span></button>
      </div>
      <p class="hint">Un souci pour payer ? <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a></p>
    </div>
    <aside class="panel">
      <table class="totals"><tbody>
        <tr><td>Les hormones d'Yvonne, livre numérique (.epub)</td><td><?= h($price) ?></td></tr>
        <tr class="total"><td>Total</td><td><?= h($price) ?></td></tr>
      </tbody></table>
    </aside>
  </div>
<?php elseif ($delivered): ?>
  <h1 style="font-size:44px;">Paiement reçu, merci !</h1>
  <p class="lead">Votre lien a été envoyé à <strong><?= h($o['email']) ?></strong>. Pensez à regarder dans vos courriers indésirables.</p>
  <p class="hint">Un souci ? <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a></p>
<?php else: ?>
  <h1 style="font-size:44px;">Commande annulée</h1>
  <p class="lead">Une question ? <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a></p>
<?php endif;

render_page("Commande {$o['ref']} — Les hormones d'Yvonne", (string) ob_get_clean());
