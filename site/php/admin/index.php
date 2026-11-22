<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';

admin_session();

// ---- Connexion
if (!admin_logged_in()) {
    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_post();
        $error = admin_try_login((string) ($_POST['user'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($error === null) {
            header('Location: /admin/', true, 303);
            exit;
        }
    }
    admin_page('Connexion', '<form method="post" class="login panel">
        <h1>Administration</h1>' . csrf_field() .
        ($error ? '<p class="notice error">' . h($error) . '</p>' : '') . '
        <div class="field"><label for="user">Identifiant</label><input id="user" name="user" autocomplete="username" required></div>
        <div class="field"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="cta">Se connecter</button>
      </form>', $error ? 401 : 200);
}

// ---- Liste des commandes
$db = db();
$raw = (string) ($_GET['statut'] ?? '');
$filter = in_array($raw, STATUSES, true) ? $raw : ($raw === 'all' ? 'all' : 'todo');
$counts = count_orders($db);
$list = match ($filter) {
    'all' => list_orders($db),
    'todo' => array_values(array_filter(list_orders($db), fn($o) => in_array($o['status'], ['attente_paiement', 'payee'], true))),
    default => list_orders($db, $filter),
};

// Alerte si les données sont accessibles depuis le web (dossier sous la racine du site).
$warn = '';
$root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$data = realpath(cfg()['data_dir']);
if ($root && $data && str_starts_with($data . '/', rtrim($root, '/') . '/')) {
    $warn = '<p class="notice error">Attention : le dossier des commandes est dans le site web (' . h($data) . '). Déplacez-le hors de public_html.</p>';
}

$tab = fn(string $key, string $label) => '<a href="/admin' . ($key === 'todo' ? '' : '?statut=' . $key) . '"'
    . ($filter === $key ? ' class="on"' : '') . '>' . h($label) . '</a>';

ob_start();
?>
<h1>Commandes</h1>
<?= $warn ?>
<div class="tabs">
  <?= $tab('todo', 'À traiter (' . ($counts['attente_paiement'] + $counts['payee']) . ')') ?>
  <?php foreach (STATUSES as $s): ?><?= $tab($s, STATUS_LABELS[$s] . " ({$counts[$s]})") ?><?php endforeach ?>
  <?= $tab('all', 'Toutes') ?>
</div>
<?php if (!$list): ?>
  <p>Aucune commande ici.</p>
<?php else: ?>
  <table class="list">
    <thead><tr><th>Réf.</th><th class="opt">Date</th><th>Client</th><th>Qté</th><th>Total</th><th class="opt">Relais</th><th>Statut</th></tr></thead>
    <tbody>
    <?php foreach ($list as $o): ?>
      <tr>
        <td><a href="/admin/<?= h($o['ref']) ?>"><strong><?= h($o['ref']) ?></strong></a></td>
        <td class="opt"><?= h(fr_date($o['created_at'])) ?></td>
        <td><?= h($o['first_name'] . ' ' . $o['last_name']) ?></td>
        <td><?= (int) $o['qty'] ?></td>
        <td><?= h(eur((int) $o['total_cents'])) ?></td>
        <td class="opt"><?= h($o['relay_city']) ?></td>
        <td><span class="pill <?= h($o['status']) ?>"><?= h(STATUS_LABELS[$o['status']]) ?></span></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif;
admin_page('Commandes', (string) ob_get_clean());
