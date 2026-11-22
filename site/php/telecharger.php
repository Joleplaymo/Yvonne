<?php
declare(strict_types=1);
require __DIR__ . '/_prive/lib.php';
require_once __DIR__ . '/_prive/telechargement.php';
require_once __DIR__ . '/_prive/mail.php';

// Le jeton est dans l'URL : aucune page de cette route ne doit être mise en cache ni fuiter en Referer.
function dl_page(int $status, string $title, string $text): never
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    render_page($title, '<h1 style="font-size:40px;">' . h($title) . '</h1><p class="lead">' . $text . '</p>', 'gabarit', $status);
}

function dl_not_valid(): never
{
    dl_page(404, 'Lien invalide ou expiré', 'Ce lien n\'est plus valide. Écrivez à <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a> pour en recevoir un nouveau.');
}

$db = db();
if (rate_limited($db, 'dl:' . client_ip(), 20, 3600)) {
    dl_page(429, 'Trop de tentatives', 'Veuillez réessayer dans un moment.');
}

$token = (string) ($_GET['j'] ?? '');
if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) dl_not_valid();

if (!is_file(ebook_master_path())) {
    error_log('telecharger: epub maître absent (' . ebook_master_path() . ')');
    dl_page(503, 'Téléchargement momentanément indisponible', 'Réessayez dans quelques minutes, ou écrivez à <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>.');
}

$order = ebook_consume_download($db, $token, client_ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($order === null) dl_not_valid();

// Préparation : un échec ici survient avant tout octet envoyé, le téléchargement compté est rendu.
$tmp = null;
try {
    $tmp = ebook_build_copy($db, $order, ebook_tmp_dir());
    $size = filesize($tmp);
    if ($size === false) throw new RuntimeException('Taille illisible.');
} catch (Throwable $e) {
    error_log('telecharger ' . $order['ref'] . ': ' . $e->getMessage());
    if ($tmp !== null) @unlink($tmp); // exit (dl_page) ne passe pas par finally
    try {
        ebook_refund_download($db, (int) $order['id']);
    } catch (Throwable $e2) {
        error_log('telecharger remboursement ' . $order['ref'] . ': ' . $e2->getMessage());
    }
    dl_page(500, 'Une erreur est survenue', 'Le téléchargement n\'a pas pu être préparé. Votre lien reste utilisable : réessayez dans un moment, ou écrivez à <a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>.');
}

// Envoi : pas de remboursement ici, les en-têtes sont partis.
try {
    if (!ebook_send_epub($tmp, $size)) error_log('telecharger ' . $order['ref'] . ': lecture impossible');
} finally {
    @unlink($tmp);
}

// Alerte de partage après l'envoi ; un échec d'e-mail ne doit rien casser.
try {
    $ips = ebook_distinct_ips($db, (int) $order['id']);
    if ($ips > EBOOK_ALERT_IPS && ebook_mark_alert_sent($db, (int) $order['id'])) mail_ebook_share_alert($order, $ips);
} catch (Throwable $e) {
    error_log('telecharger alerte: ' . $e->getMessage());
}
