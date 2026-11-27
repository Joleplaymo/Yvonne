<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';

require_admin();
$db = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $raw = is_scalar($_POST['emails'] ?? null) ? (string) $_POST['emails'] : '';
        $r = notify_parse($raw);
        if ($r['ok']) {
            try {
                notify_save($db, $r['emails'], client_ip());
                $_SESSION['notif_msg'] = $r['emails'] === []
                    ? ['ok', 'Retour à l’adresse du fichier de configuration.']
                    : ['ok', 'Adresses enregistrées : les prochains avis d’achat leur seront envoyés.'];
            } catch (InvalidArgumentException $e) {
                $_SESSION['notif_msg'] = ['error', $e->getMessage()];
                $_SESSION['notif_form'] = $raw;
            }
        } else {
            // On réaffiche la saisie pour qu'elle puisse être corrigée.
            $_SESSION['notif_msg'] = ['error', $r['error']];
            $_SESSION['notif_form'] = $raw;
        }
    } elseif ($action === 'test') {
        if (rate_limited($db, 'notify-test', 5, 600)) {
            $_SESSION['notif_msg'] = ['error', 'Trop de mails de test. Réessayez dans 10 minutes.'];
        } else {
            $n = notify_admins(
                'Test des notifications — Les hormones d\'Yvonne',
                "Ceci est un mail de test.\n\nLes notifications du site (nouvelles commandes, alertes) arrivent bien à cette adresse."
            );
            $m = count(notify_emails($db));
            $_SESSION['notif_msg'] = ['ok', "Mail de test envoyé à $n adresse(s) sur $m."];
        }
    } elseif ($action === 'reset') {
        notify_reset($db, client_ip());
        $_SESSION['notif_msg'] = ['ok', 'Retour à l’adresse du fichier de configuration.'];
    }
    header('Location: /admin/notifications', true, 303);
    exit;
}

[$msgType, $msg] = $_SESSION['notif_msg'] ?? [null, null];
$form = $_SESSION['notif_form'] ?? null;
unset($_SESSION['notif_msg'], $_SESSION['notif_form']);

$custom = notify_custom($db);
$emails = notify_emails($db);
$updated = setting_updated_at($db, NOTIFY_SETTING);
$text = is_string($form) ? $form : ($custom ? implode("\n", $emails) : '');

ob_start();
?>
<h1>Notifications</h1>
<p>Adresses qui reçoivent un mail à chaque nouvelle commande et à chaque alerte du site. Une adresse par ligne, trois au maximum.</p>
<?php if ($msg): ?><p class="notice<?= $msgType === 'error' ? ' error' : '' ?>"><?= h($msg) ?></p><?php endif ?>

<div class="detail">
  <section class="panel">
    <h2>Adresses</h2>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <textarea name="emails" rows="4" maxlength="1000" aria-label="Adresses de notification, une par ligne" style="width:100%;"><?= h($text) ?></textarea>
      <p class="hint">Laissez vide pour utiliser l'adresse du fichier de configuration. Les réponses des clients continuent d'arriver à l'adresse de <code>config.php</code>.</p>
      <button class="cta">Enregistrer</button>
    </form>
    <?php if ($custom): ?>
      <form method="post" data-confirm="Revenir à l'adresse du fichier de configuration ?" style="margin-top:14px;"><?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <button class="cta outline">Revenir à l'adresse de config.php</button>
      </form>
      <p class="hint">Modifié le <?= h(fr_date($updated, true)) ?>.</p>
    <?php endif ?>
  </section>

  <section class="panel">
    <h2>Adresses en vigueur</h2>
    <?php if ($emails): ?>
      <ul>
      <?php foreach ($emails as $e): ?>
        <li><?= h($e) ?><?= $custom ? '' : ' (valeur de config.php)' ?></li>
      <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="hint">Aucune adresse configurée.</p>
    <?php endif ?>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <button class="cta outline">Envoyer un mail de test</button>
    </form>
    <p class="hint">Le mail de test part vers toutes les adresses ci-dessus (5 essais maximum toutes les 10 minutes). Quand la liste change, les anciennes adresses reçoivent une alerte.</p>
  </section>
</div>
<?php
admin_page('Notifications', (string) ob_get_clean());
