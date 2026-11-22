<?php
declare(strict_types=1);

// Livre numérique (.epub) : commandes, jetons de téléchargement hachés, limites et réglages.
// Le tunnel papier (table orders) n'est jamais touché.

require_once __DIR__ . '/lib.php';

const EBOOK_STATUSES = ['attente_paiement', 'livree', 'annulee'];
const EBOOK_STATUS_LABELS = [
    'attente_paiement' => 'En attente de paiement',
    'livree' => 'Livrée',
    'annulee' => 'Annulée',
];
const EBOOK_CONSENT_TEXT = "Je demande l'accès au livre numérique dès la validation de mon paiement et je reconnais perdre mon droit de rétractation.";
const EBOOK_DL_DAYS = 7;
const EBOOK_DL_MAX = 5;
const EBOOK_ALERT_IPS = 3;

// ---------------------------------------------------------------- Réglages

function ebook_price_cents(PDO $db): int
{
    $v = get_setting($db, 'ebook_price_cents');
    return ($v !== null && ctype_digit($v) && (int) $v > 0) ? (int) $v : 499;
}

function ebook_sale_open(PDO $db): bool
{
    return get_setting($db, 'ebook_vente_ouverte') === '1';
}

function ebook_cgv_url(PDO $db): string
{
    return get_setting($db, 'ebook_cgv_url') ?? '';
}

/** Secret serveur (généré une fois), réservé aux signatures. */
function ebook_secret(PDO $db): string
{
    $s = get_setting($db, 'ebook_secret');
    if ($s === null || $s === '') {
        $s = bin2hex(random_bytes(32));
        set_setting($db, 'ebook_secret', $s);
    }
    return $s;
}

function ebook_master_path(): string
{
    return cfg()['data_dir'] . '/epub/maitre.epub';
}

// ---------------------------------------------------------------- Validation

/** @return array{ok: bool, errors: array<string,string>, value: array{first_name: string, last_name: string, email: string}} */
function validate_ebook_order(array $b): array
{
    $v = [
        'first_name' => clean_text($b['firstName'] ?? '', 80),
        'last_name' => clean_text($b['lastName'] ?? '', 80),
        'email' => mb_strtolower(clean_text($b['email'] ?? '', 160)),
    ];
    $e = [];
    if ($v['first_name'] === '') $e['firstName'] = 'Prénom requis.';
    if ($v['last_name'] === '') $e['lastName'] = 'Nom requis.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Adresse e-mail invalide.';
    $consent = $b['consent'] ?? null;
    if ($consent !== '1' && $consent !== true) $e['consent'] = 'Votre accord est nécessaire pour commander le livre numérique.';
    return ['ok' => !$e, 'errors' => $e, 'value' => $v];
}

// ---------------------------------------------------------------- Commandes

function create_ebook_order(PDO $db, array $in, int $priceCents, string $ip): array
{
    $token = bin2hex(random_bytes(16));
    $db->beginTransaction();
    $db->prepare('INSERT INTO ebook_orders (ref, token, price_cents, first_name, last_name, email, consent_text, consent_at, consent_ip)
        VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute(['tmp-' . $token, $token, $priceCents, $in['first_name'], $in['last_name'], $in['email'],
            EBOOK_CONSENT_TEXT, now_iso(), $ip]);
    $id = (int) $db->lastInsertId();
    $ref = sprintf('EB-%04d', $id);
    $db->prepare('UPDATE ebook_orders SET ref = ? WHERE id = ?')->execute([$ref, $id]);
    $db->commit();
    return find_ebook_order($db, $ref);
}

function find_ebook_order(PDO $db, string $ref): ?array
{
    $st = $db->prepare('SELECT * FROM ebook_orders WHERE ref = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

function list_ebook_orders(PDO $db, ?string $status = null): array
{
    if ($status === null) return $db->query('SELECT * FROM ebook_orders ORDER BY id DESC')->fetchAll();
    $st = $db->prepare('SELECT * FROM ebook_orders WHERE status = ? ORDER BY id DESC');
    $st->execute([$status]);
    return $st->fetchAll();
}

function count_ebook_orders(PDO $db): array
{
    $out = array_fill_keys(EBOOK_STATUSES, 0);
    foreach ($db->query('SELECT status, COUNT(*) AS n FROM ebook_orders GROUP BY status') as $r) $out[$r['status']] = (int) $r['n'];
    return $out;
}

/** Livre la commande : nouveau jeton (l'ancien est invalidé), renvoyé en clair une seule fois. Sert aussi à renvoyer un lien. */
function ebook_deliver(PDO $db, string $ref): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + EBOOK_DL_DAYS * 86400);
    $st = $db->prepare("UPDATE ebook_orders SET status = 'livree', paid_at = COALESCE(paid_at, ?), dl_token_hash = ?,
        dl_count = 0, dl_expires_at = ?, dl_max = ? WHERE ref = ?");
    $st->execute([now_iso(), hash('sha256', $token), $expires, EBOOK_DL_MAX, $ref]);
    if ($st->rowCount() !== 1) throw new RuntimeException("Commande introuvable : $ref");
    return $token;
}

/** Annuler ou rouvrir (rouvrir = attente de paiement, le lien est révoqué). Livrer passe par ebook_deliver(). */
function ebook_set_status(PDO $db, string $ref, string $status): void
{
    if (!in_array($status, ['annulee', 'attente_paiement'], true)) throw new InvalidArgumentException("Statut invalide : $status");
    if ($status === 'attente_paiement') {
        $db->prepare('UPDATE ebook_orders SET status = ?, dl_token_hash = NULL, dl_expires_at = NULL, dl_count = 0 WHERE ref = ?')->execute([$status, $ref]);
    } else {
        $db->prepare('UPDATE ebook_orders SET status = ? WHERE ref = ?')->execute([$status, $ref]);
    }
}

function ebook_set_admin_note(PDO $db, string $ref, string $note): void
{
    $db->prepare('UPDATE ebook_orders SET admin_note = ? WHERE ref = ?')->execute([mb_substr($note, 0, 2000), $ref]);
}

// ---------------------------------------------------------------- Téléchargements

/** Autorise et compte un téléchargement ; null si le jeton est inconnu, expiré, épuisé ou la commande non livrée. */
function ebook_consume_download(PDO $db, string $token, string $ip, string $ua): ?array
{
    $hash = hash('sha256', $token);
    $now = now_iso();
    $db->beginTransaction();
    try {
        $st = $db->prepare("UPDATE ebook_orders SET dl_count = dl_count + 1
            WHERE dl_token_hash = ? AND status = 'livree' AND dl_count < dl_max AND dl_expires_at > ?");
        $st->execute([$hash, $now]);
        if ($st->rowCount() !== 1) {
            $db->commit();
            return null;
        }
        $st = $db->prepare('SELECT * FROM ebook_orders WHERE dl_token_hash = ?');
        $st->execute([$hash]);
        $order = $st->fetch();
        $db->prepare('INSERT INTO ebook_downloads (order_id, at, ip, user_agent) VALUES (?,?,?,?)')
            ->execute([$order['id'], $now, mb_substr($ip, 0, 64), mb_substr($ua, 0, 300)]);
        $db->commit();
        return $order;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Rend un téléchargement compté mais jamais servi (échec avant envoi) : compteur -1 et dernière ligne du journal retirée. */
function ebook_refund_download(PDO $db, int $orderId): void
{
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE ebook_orders SET dl_count = dl_count - 1 WHERE id = ? AND dl_count > 0')->execute([$orderId]);
        $db->prepare('DELETE FROM ebook_downloads WHERE rowid = (SELECT MAX(rowid) FROM ebook_downloads WHERE order_id = ?)')
            ->execute([$orderId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function ebook_distinct_ips(PDO $db, int $orderId): int
{
    $st = $db->prepare('SELECT COUNT(DISTINCT ip) FROM ebook_downloads WHERE order_id = ?');
    $st->execute([$orderId]);
    return (int) $st->fetchColumn();
}

/** Vrai seulement la première fois (une alerte par commande). */
function ebook_mark_alert_sent(PDO $db, int $orderId): bool
{
    $st = $db->prepare('UPDATE ebook_orders SET alert_sent_at = ? WHERE id = ? AND alert_sent_at IS NULL');
    $st->execute([now_iso(), $orderId]);
    return $st->rowCount() === 1;
}

function ebook_downloads_of(PDO $db, int $orderId): array
{
    $st = $db->prepare('SELECT * FROM ebook_downloads WHERE order_id = ? ORDER BY at DESC');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** Purge du journal des téléchargements (les commandes et consentements sont conservés). */
function ebook_purge_downloads(PDO $db, int $olderThanDays = 365): int
{
    $st = $db->prepare('DELETE FROM ebook_downloads WHERE at < ?');
    $st->execute([gmdate('Y-m-d\TH:i:s\Z', time() - $olderThanDays * 86400)]);
    return $st->rowCount();
}

// ---------------------------------------------------------------- Liens publics

function ebook_order_page_url(array $o): string
{
    return cfg()['public_base_url'] . '/numerique/merci?ref=' . rawurlencode($o['ref']) . '&t=' . $o['token'];
}

function ebook_download_url(string $token): string
{
    return cfg()['public_base_url'] . '/telecharger/' . $token;
}

// ---------------------------------------------------------------- Aperçu (vente fermée)

/** Clé secrète du lien d'aperçu /numerique?apercu=… ; générée au premier appel, stable ensuite. */
function ebook_preview_key(PDO $db): string
{
    $existing = get_setting($db, 'ebook_apercu_cle');
    if ($existing !== null && $existing !== '') return $existing;
    $db->prepare('INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, ?)')
        ->execute(['ebook_apercu_cle', bin2hex(random_bytes(32)), now_iso()]);
    return (string) get_setting($db, 'ebook_apercu_cle');
}

/** Vérifie une clé d'aperçu sans jamais en générer. */
function ebook_preview_allowed(PDO $db, string $given): bool
{
    if ($given === '') return false;
    $stored = get_setting($db, 'ebook_apercu_cle');
    if ($stored === null || $stored === '') return false;
    return hash_equals($stored, $given);
}
