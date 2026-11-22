<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require_once __DIR__ . '/_prive/mail.php';
require_once __DIR__ . '/_prive/ebook.php';

// Commande du livre numérique : formulaire classique (sans JavaScript).
// Protections : même origine, pot de miel, limite de débit. Vente fermée par défaut ; le lien d'aperçu
// /numerique?apercu=<clé> (clé secrète, voir l'admin) permet de commander quand même (commande test réelle).

/** @param array<string,string> $values @param array<string,string> $errors */
function ebook_form_page(PDO $db, array $values, array $errors, int $status = 200, string $preview = ''): never
{
    $price = eur(ebook_price_cents($db));
    $cgv = ebook_cgv_url($db);
    $err = static fn(string $k): string => isset($errors[$k]) ? '<span class="err" role="alert">' . h($errors[$k]) . '</span>' : '';
    $inv = static fn(string $k): string => isset($errors[$k]) ? ' invalid' : '';
    $val = static fn(string $k): string => h($values[$k] ?? '');

    ob_start();
    ?>
<ol class="steps" aria-label="Étapes de la commande">
  <li class="current" aria-current="step"><b>1</b>Coordonnées</li><li><b>2</b>Paiement</li><li><b>3</b>Téléchargement</li>
</ol>
<h1 style="font-size:44px;">Le livre numérique</h1>
<p class="lead">« Les hormones d'Yvonne » au format .epub, pour votre liseuse ou votre téléphone : <strong><?= h($price) ?></strong>. Pas de colis, pas de frais de port : vous recevez votre lien de téléchargement par e-mail après le paiement.</p>

<form method="post" action="/numerique" class="checkout" novalidate style="margin-top:28px;">
  <div class="panel" style="max-width:640px;">
    <?php if ($errors): ?><div class="notice error" role="alert" style="margin-bottom:18px;">Merci de corriger les champs signalés ci-dessous.</div><?php endif ?>
    <div class="form-grid">
      <div class="field<?= $inv('firstName') ?>"><label for="firstName">Prénom</label><input id="firstName" name="firstName" autocomplete="given-name" required maxlength="80" value="<?= $val('firstName') ?>" /><?= $err('firstName') ?></div>
      <div class="field<?= $inv('lastName') ?>"><label for="lastName">Nom</label><input id="lastName" name="lastName" autocomplete="family-name" required maxlength="80" value="<?= $val('lastName') ?>" /><?= $err('lastName') ?></div>
      <div class="field full<?= $inv('email') ?>"><label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="160" value="<?= $val('email') ?>" /><?= $err('email') ?></div>
    </div>
    <div class="field<?= $inv('consent') ?>" style="margin-top:18px;">
      <label for="consent" style="display:flex; gap:10px; align-items:flex-start; font-weight:600;">
        <input id="consent" name="consent" type="checkbox" value="1" required style="width:auto; margin-top:4px;"<?= ($values['consent'] ?? '') === '1' ? ' checked' : '' ?> />
        <span><?= h(EBOOK_CONSENT_TEXT) ?></span>
      </label>
      <?= $err('consent') ?>
    </div>
    <?php if ($cgv !== ''): ?><p class="hint">En commandant, vous acceptez les conditions générales de vente. <a href="<?= h($cgv) ?>" target="_blank" rel="noopener">Lire les conditions générales de vente</a>.</p><?php endif ?>
    <?php if ($preview !== ''): ?><input type="hidden" name="apercu" value="<?= h($preview) ?>" /><?php endif ?>
    <div class="hp" aria-hidden="true"><label for="website">Ne pas remplir</label><input id="website" name="website" tabindex="-1" autocomplete="off" /></div>
    <button class="cta terra" type="submit" style="width:100%; margin-top:22px;">Commander le livre numérique — <?= h($price) ?></button>
    <p class="hint">Vous recevrez le lien de paiement Revolut par e-mail. Le livre est personnalisé à votre nom.</p>
  </div>
</form>
<?php
    render_page("Le livre numérique — Les hormones d'Yvonne", (string) ob_get_clean(), 'gabarit', $status);
}

function ebook_message_page(string $title, string $html, int $status): never
{
    render_page($title, '<h1 style="font-size:40px;">' . h($title) . '</h1>' . $html, 'gabarit', $status);
}

try {
    $db = db();
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $given = (string) ($post ? ($_POST['apercu'] ?? '') : ($_GET['apercu'] ?? ''));
    $previewOk = ebook_preview_allowed($db, $given);
    $preview = $previewOk ? $given : '';
    $open = ebook_sale_open($db) || $previewOk;

    if (!$post) {
        if (!$open) {
            ebook_message_page('Bientôt disponible', '<p class="lead">Le livre numérique arrive très bientôt. En attendant, vous pouvez commander le livre papier : <a href="/panier?ajouter=1">je commande</a>.</p>', 200);
        }
        ebook_form_page($db, [], [], 200, $preview);
    }

    if (!same_origin()) ebook_message_page('Requête refusée', '<p class="lead">Origine refusée. Rechargez la page et réessayez.</p>', 403);
    if (!$open) ebook_message_page('Bientôt disponible', '<p class="lead">Le livre numérique n\'est pas encore en vente.</p>', 403);
    if (rate_limited($db, 'ebook-order:' . client_ip(), 8, 3600)) {
        ebook_message_page('Trop de commandes', '<p class="lead">Trop de commandes en peu de temps. Réessayez plus tard ou écrivez à <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>.</p>', 429);
    }
    // Pot de miel anti-robots : un humain ne voit pas ce champ.
    if (trim((string) ($_POST['website'] ?? '')) !== '') ebook_message_page('Requête invalide', '<p class="lead">Requête invalide.</p>', 400);

    $r = validate_ebook_order($_POST);
    if (!$r['ok']) {
        ebook_form_page($db, ['firstName' => $r['value']['first_name'], 'lastName' => $r['value']['last_name'], 'email' => $r['value']['email'],
            'consent' => ($_POST['consent'] ?? '') === '1' ? '1' : ''], $r['errors'], 422, $preview);
    }

    $order = create_ebook_order($db, $r['value'], ebook_price_cents($db), client_ip());
    mail_ebook_created($order);
    header('Location: /numerique/merci?ref=' . rawurlencode($order['ref']) . '&t=' . $order['token'], true, 303);
    exit;
} catch (Throwable $e) {
    error_log('[numerique] ' . $e->getMessage());
    ebook_message_page('Une erreur est survenue', '<p class="lead">Réessayez dans un instant ou écrivez à <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>.</p>', 500);
}
