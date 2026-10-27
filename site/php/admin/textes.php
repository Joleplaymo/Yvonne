<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/textes.php';

require_admin();
$db = db();
$items = text_items();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        // Tous les champs du formulaire sont enregistrés d'un coup.
        $error = null;
        foreach ((array) ($_POST['t'] ?? []) as $key => $value) {
            if (!is_string($key) || !is_string($value) || !isset($items[$key])) continue;
            $error = text_save($db, $key, $value);
            if ($error !== null) {
                $error = $items[$key]['label'] . ' : ' . $error;
                break;
            }
        }
        $_SESSION['text_msg'] = $error ? ['error', $error] : ['ok', 'Textes enregistrés : ils sont sur le site en quelques minutes.'];
    } elseif ($action === 'reset' && isset($items[(string) ($_POST['key'] ?? '')])) {
        delete_setting($db, 'texte:' . $_POST['key']);
        $_SESSION['text_msg'] = ['ok', 'Texte d’origine rétabli.'];
    }
    header('Location: /admin/textes', true, 303);
    exit;
}

[$msgType, $msg] = $_SESSION['text_msg'] ?? [null, null];
unset($_SESSION['text_msg']);
$custom = text_overrides($db);

ob_start();
?>
<h1>Textes du site</h1>
<p>Modifiez les textes de la page d'accueil puis cliquez sur « Enregistrer » : ils apparaissent sur le site en quelques minutes.
  Pour passer à la ligne, appuyez sur Entrée. Pour mettre un mot en <strong>gras</strong>, entourez-le de deux étoiles : <code>**comme ça**</code>.
  Les photos se changent dans l'onglet « photos ».</p>
<?php if ($msg): ?><p class="notice<?= $msgType === 'error' ? ' error' : '' ?>"><?= h($msg) ?></p><?php endif ?>
<form method="post" id="textes"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <?php foreach (text_groups() as $g): ?>
    <section class="panel text-group">
      <h2><?= h($g['title']) ?></h2>
      <?php foreach ($g['items'] as $i): $isCustom = isset($custom[$i['key']]); ?>
        <div class="field">
          <label for="t-<?= h($i['key']) ?>"><?= h($i['label']) ?><?= $isCustom ? ' <span class="pill payee">modifié</span>' : '' ?></label>
          <textarea id="t-<?= h($i['key']) ?>" name="t[<?= h($i['key']) ?>]" rows="<?= (int) $i['rows'] ?>" maxlength="<?= TEXT_MAX_CHARS ?>"><?= h($custom[$i['key']] ?? $i['default']) ?></textarea>
          <?php if ($isCustom): ?>
            <button class="cta outline small" type="submit" form="reset-<?= h($i['key']) ?>">Rétablir l’original</button>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </section>
  <?php endforeach ?>
  <div class="save-bar"><button class="cta">Enregistrer les textes</button></div>
</form>
<?php foreach ($custom as $key => $_): if (!isset($items[$key])) continue; ?>
  <form method="post" id="reset-<?= h($key) ?>" data-confirm="Revenir au texte d’origine ?"><?= csrf_field() ?>
    <input type="hidden" name="action" value="reset"><input type="hidden" name="key" value="<?= h($key) ?>">
  </form>
<?php endforeach;
admin_page('Textes', (string) ob_get_clean());
