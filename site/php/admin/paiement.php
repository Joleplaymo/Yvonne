<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require_once __DIR__ . '/../_prive/ebook.php';

require_admin();
$db = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    if ((string) ($_POST['action'] ?? '') === 'save') {
        $link = is_scalar($_POST['link'] ?? null) ? (string) $_POST['link'] : '';
        $ebookLink = is_scalar($_POST['ebook_link'] ?? null) ? (string) $_POST['ebook_link'] : '';
        try {
            pay_save($db, $link, $ebookLink, ebook_price_cents($db), client_ip());
            $_SESSION['pay_msg'] = ['ok', 'Liens enregistrés. Ouvrez le lien d’essai sur votre téléphone pour vérifier.'];
        } catch (InvalidArgumentException $e) {
            // On réaffiche la saisie pour qu'elle puisse être corrigée.
            $_SESSION['pay_msg'] = ['error', $e->getMessage()];
            $_SESSION['pay_form'] = ['link' => $link, 'ebook_link' => $ebookLink];
        }
    }
    header('Location: /admin/paiement', true, 303);
    exit;
}

[$msgType, $msg] = $_SESSION['pay_msg'] ?? [null, null];
$form = $_SESSION['pay_form'] ?? null;
unset($_SESSION['pay_msg'], $_SESSION['pay_form']);

$stored = trim((string) get_setting($db, PAY_LINK));
$storedEbook = trim((string) get_setting($db, PAY_LINK_EBOOK));
$storedCents = get_setting($db, PAY_LINK_EBOOK_CENTS);
$price = ebook_price_cents($db);
$status = pay_ebook_fixed_status($db, $price);
$updated = setting_updated_at($db, PAY_LINK) ?? setting_updated_at($db, PAY_LINK_EBOOK);
$linkValue = is_array($form) ? (string) ($form['link'] ?? '') : $stored;
$ebookValue = is_array($form) ? (string) ($form['ebook_link'] ?? '') : $storedEbook;
$test = pay_link_paper(100, 'TEST', $db);

ob_start();
?>
<h1>Paiement</h1>
<p>Liens Revolut proposés aux clients après leur commande.</p>
<?php if ($msg): ?><p class="notice<?= $msgType === 'error' ? ' error' : '' ?>"><?= h($msg) ?></p><?php endif ?>

<div class="detail">
  <section class="panel">
    <h2>Liens de paiement</h2>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <div class="field">
        <label for="link">Lien Revolut (livre papier)</label>
        <input id="link" name="link" type="text" maxlength="300" style="width:100%;" value="<?= h($linkValue) ?>">
        <?php if ($stored === ''): ?><span class="hint">Lien en vigueur : <?= h(cfg()['revolut_link_template']) ?> (valeur de config.php)</span><?php endif ?>
        <span class="hint">Ajoutez {amount} à l'endroit où le montant doit apparaître, par exemple https://revolut.me/votre-nom/{amount}EUR, puis testez. Si Revolut n'affiche pas le montant, retirez {amount}.</span>
      </div>
      <div class="field">
        <label for="ebook_link">Lien epub à montant fixe (facultatif)</label>
        <input id="ebook_link" name="ebook_link" type="text" maxlength="300" style="width:100%;" value="<?= h($ebookValue) ?>">
        <span class="hint">Créez dans l'appli Revolut un lien de paiement de <?= h(eur($price)) ?> puis collez-le ici. Laissez vide pour utiliser le lien ci-dessus.</span>
        <?php if ($status === 'stale'): ?>
          <p class="notice error">Ce lien a été créé pour <?= h(eur((int) $storedCents)) ?> alors que l'epub coûte <?= h(eur($price)) ?> : il n'est plus utilisé. Créez un nouveau lien dans Revolut au prix actuel et collez-le ici.</p>
        <?php endif ?>
      </div>
      <button class="cta">Enregistrer</button>
    </form>
    <?php if ($updated): ?><p class="hint">Modifié le <?= h(fr_date($updated, true)) ?>.</p><?php endif ?>
  </section>

  <section class="panel">
    <h2>Lien d'essai</h2>
    <p><a href="<?= h($test['url']) ?>" target="_blank" rel="noopener">Ouvrir le lien d'essai (1,00 €)</a></p>
    <p class="hint">Ouvrez-le sur votre téléphone : le montant de 1,00 € doit apparaître<?= $test['prefilled'] ? '' : ' (le lien en vigueur ne contient pas {amount} : le client devra saisir le montant)' ?>. Enregistrez avant de tester.</p>
    <p class="hint"><a href="/admin/epub">Prix de l'epub</a> · <a href="/admin">← Commandes</a></p>
  </section>
</div>
<?php
admin_page('Paiement', (string) ob_get_clean());
