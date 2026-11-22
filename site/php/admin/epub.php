<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/ebook_admin.php';
require __DIR__ . '/../_prive/telechargement.php';

require_admin();
$db = db();
$maxBytes = ebook_max_upload_bytes();

/** Message affiché au prochain chargement : type 'ok' | 'error'. */
function epub_flash(string $type, string $text): void
{
    $_SESSION['epub_flash'][] = [$type, $text];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Envoi plus gros que post_max_size : PHP vide $_POST, le jeton CSRF serait « expiré » à tort.
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        epub_flash('error', 'Fichier trop lourd : ' . ebook_format_size($maxBytes) . ' maximum.');
        header('Location: /admin/epub', true, 303);
        exit;
    }
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    switch ($action) {
        case 'upload':
            $up = $_FILES['master'] ?? [];
            $err = $up['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                epub_flash('error', match ($err) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd : ' . ebook_format_size($maxBytes) . ' maximum.',
                    UPLOAD_ERR_NO_FILE => 'Aucun fichier choisi.',
                    default => 'Le dépôt a échoué, réessayez.',
                });
            } elseif (!is_uploaded_file((string) $up['tmp_name'])) {
                epub_flash('error', 'Fichier invalide.');
            } else {
                [$error, $warn] = ebook_install_master((string) $up['tmp_name'], null, $maxBytes);
                if ($error !== null) {
                    epub_flash('error', $error);
                } else {
                    epub_flash('ok', 'Fichier du livre enregistré.');
                    if ($warn !== null) epub_flash('error', $warn);
                }
            }
            break;

        case 'test':
            // Exemplaire de test : pas de commande, pas de compteur. La réponse est le fichier (pas de redirection).
            $tmp = null;
            try {
                $order = ['ref' => 'EB-TEST', 'first_name' => 'Exemplaire', 'last_name' => 'de test', 'email' => '',
                    'paid_at' => null, 'created_at' => now_iso()];
                $tmp = ebook_build_copy($db, $order, ebook_tmp_dir());
                $size = filesize($tmp);
                if ($size === false) throw new RuntimeException('Taille illisible.');
            } catch (Throwable $e) {
                if ($tmp !== null) @unlink($tmp);
                epub_flash('error', 'Test impossible : ' . (is_file(ebook_master_path()) ? $e->getMessage() : 'aucun fichier de livre déposé.'));
                header('Location: /admin/epub', true, 303);
                exit;
            }
            try {
                ebook_send_epub($tmp, $size);
            } finally {
                @unlink($tmp);
            }
            exit;

        case 'settings':
            $errors = [];
            $price = ebook_parse_price((string) ($_POST['price'] ?? ''));
            if ($price === null) $errors[] = 'Prix invalide : indiquez un montant entre 0,01 et 100 € (par exemple 4,99).';
            $cgv = trim((string) ($_POST['cgv'] ?? ''));
            if (!ebook_cgv_valid($cgv)) $errors[] = 'Adresse des CGV invalide : laissez vide ou commencez par https://.';
            $wantOpen = ($_POST['open'] ?? '') === '1';
            if ($wantOpen && !is_file(ebook_master_path())) $errors[] = 'Impossible d\'ouvrir la vente : déposez d\'abord le fichier du livre.';
            if ($errors) {
                foreach ($errors as $m) epub_flash('error', $m);
                epub_flash('error', 'Réglages non enregistrés.');
            } else {
                set_setting($db, 'ebook_price_cents', (string) $price);
                set_setting($db, 'ebook_cgv_url', $cgv);
                set_setting($db, 'ebook_vente_ouverte', $wantOpen ? '1' : '0');
                epub_flash('ok', 'Réglages enregistrés.');
            }
            break;

        case 'renew_preview':
            delete_setting($db, 'ebook_apercu_cle');
            ebook_preview_key($db);
            epub_flash('ok', 'Nouveau lien d\'aperçu créé : l\'ancien ne fonctionne plus.');
            break;

        default:
            epub_flash('error', 'Action inconnue.');
    }
    header('Location: /admin/epub', true, 303);
    exit;
}

$flash = $_SESSION['epub_flash'] ?? [];
unset($_SESSION['epub_flash']);
$master = ebook_master_info();
$open = ebook_sale_open($db);
$price = ebook_price_cents($db);
$previewUrl = cfg()['public_base_url'] . '/numerique?apercu=' . ebook_preview_key($db);

ob_start();
?>
<h1>Livre numérique</h1>
<?php foreach ($flash as [$type, $text]): ?>
  <p class="notice<?= $type === 'ok' ? '' : ' error' ?>"><?= h($text) ?></p>
<?php endforeach ?>
<p>Vente : <strong><?= $open ? 'ouverte' : 'fermée' ?></strong> · <a href="/admin">← Commandes</a></p>

<div class="detail">
  <section class="panel">
    <h2>Fichier du livre (.epub)</h2>
    <?php if ($master === null): ?>
      <p class="notice error">Aucun fichier déposé : la vente ne peut pas être ouverte.</p>
    <?php else: ?>
      <p><strong><?= h($master['title'] !== '' ? $master['title'] : 'Sans titre') ?></strong><br>
        <?= h(ebook_format_size($master['size'])) ?> · déposé le <?= h(fr_date(gmdate('Y-m-d\TH:i:s\Z', $master['mtime']), true)) ?></p>
      <?php if (!$master['ok']): ?><p class="notice error">Ce fichier n'est plus un epub valide : déposez-le à nouveau.</p><?php endif ?>
      <?php if ($master['layout'] === 'pre-paginated'): ?><p class="hint">Mise en page fixe : la page d'exemplaire est placée à la fin.</p><?php endif ?>
      <form method="post" class="act"><?= csrf_field() ?>
        <input type="hidden" name="action" value="test">
        <button class="cta outline">Tester le tatouage</button>
        <span class="hint">Télécharge un exemplaire au nom « Exemplaire de test » (rien n'est compté).</span>
      </form>
    <?php endif ?>
    <form method="post" enctype="multipart/form-data" class="act"><?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <div class="field"><label for="master"><?= $master === null ? 'Déposer le livre' : 'Remplacer le livre' ?></label>
        <input id="master" type="file" name="master" accept=".epub,application/epub+zip" required></div>
      <button class="cta">Envoyer</button>
    </form>
    <p class="hint">Taille maximale : <?= h(ebook_format_size($maxBytes)) ?>. Le fichier est vérifié avant d'être enregistré.</p>
  </section>

  <section class="panel">
    <h2>Réglages</h2>
    <form method="post" class="home-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="settings">
      <div class="field"><label for="price">Prix (€)</label><input id="price" name="price" inputmode="decimal" required value="<?= h(number_format($price / 100, 2, ',', '')) ?>"></div>
      <div class="field"><label for="cgv">Adresse des conditions générales de vente</label><input id="cgv" name="cgv" type="url" placeholder="https://…" value="<?= h(ebook_cgv_url($db)) ?>"><span class="hint">Facultatif, doit commencer par https://</span></div>
      <label class="check"><input type="checkbox" name="open" value="1"<?= $open ? ' checked' : '' ?>> Vente ouverte au public</label>
      <button class="cta">Enregistrer</button>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Lien d'aperçu</h2>
  <p>Tant que la vente est fermée, ce lien permet de passer une vraie commande de test à <?= h(eur($price)) ?> avant l'ouverture. Ne le partagez pas.</p>
  <pre id="preview-link"><?= h($previewUrl) ?></pre>
  <div class="cta-row">
    <button class="cta outline" type="button" data-copy-from="#preview-link">Copier</button>
    <form method="post" data-confirm="Créer un nouveau lien ? L'ancien ne fonctionnera plus."><?= csrf_field() ?>
      <input type="hidden" name="action" value="renew_preview">
      <button class="cta outline">Nouveau lien d'aperçu</button>
    </form>
  </div>
</section>
<?php
admin_page('Livre numérique', (string) ob_get_clean());
