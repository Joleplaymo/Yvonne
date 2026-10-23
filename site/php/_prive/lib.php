<?php
declare(strict_types=1);

// Cœur du backend : configuration, tarifs, validation, base SQLite, rendu des pages.
// Les règles de prix sont identiques à src/lib/pricing.ts (affichage navigateur) ;
// c'est toujours ce fichier qui fait foi.

const STATUSES = ['attente_paiement', 'payee', 'expediee', 'annulee'];
const STATUS_LABELS = [
    'attente_paiement' => 'En attente de paiement',
    'payee' => 'Payée — à expédier',
    'expediee' => 'Expédiée',
    'annulee' => 'Annulée',
];

// ---------------------------------------------------------------- Configuration

/** Dossier hors du site web : au-dessus du premier « public_html » (Hostinger), sinon parent du docroot. */
function default_data_dir(): string
{
    $root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/');
    $pos = strpos($root . '/', '/public_html/');
    $base = $pos !== false ? substr($root, 0, $pos) : dirname($root);
    return $base . '/boutique-data';
}

function cfg(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $env = static function (string $key, $default) {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    };
    $cfg = [
        'book_price_eur' => (float) $env('BOOK_PRICE_EUR', 10),
        'max_qty' => (int) $env('MAX_QTY', 6),
        'shipping_table' => (string) $env('SHIPPING_TABLE', '1:4.90,2:5.90,4:7.50'),
        'revolut_link_template' => (string) $env('REVOLUT_LINK_TEMPLATE', 'https://revolut.me/v_gandino'),
        'mr_brand' => (string) $env('MR_BRAND', ''),
        'public_base_url' => (string) $env('PUBLIC_BASE_URL', ''),
        'data_dir' => (string) $env('DATA_DIR', default_data_dir()),
        'trust_proxy' => (bool) $env('TRUST_PROXY', false),
        'mail_mode' => (string) $env('MAIL_MODE', 'log'),
        'smtp_host' => (string) $env('SMTP_HOST', ''),
        'smtp_port' => (int) $env('SMTP_PORT', 465),
        'smtp_user' => (string) $env('SMTP_USER', ''),
        'smtp_password' => (string) $env('SMTP_PASSWORD', ''),
        'mail_from' => (string) $env('MAIL_FROM', 'contact@vanessagandino.fr'),
        'mail_from_name' => (string) $env('MAIL_FROM_NAME', 'Vanessa Gandino'),
        'admin_email' => (string) $env('ADMIN_EMAIL', 'contact@vanessagandino.fr'),
        'admin_user' => (string) $env('ADMIN_USER', 'vanessa'),
        'admin_password' => (string) $env('ADMIN_PASSWORD', ''),
    ];
    // Fichier de config de l'hébergement (hors du site web), prioritaire sur les valeurs par défaut.
    $file = $cfg['data_dir'] . '/config.php';
    if (is_file($file)) {
        $local = require $file;
        if (is_array($local)) $cfg = array_merge($cfg, array_intersect_key($local, $cfg));
    }
    if ($cfg['public_base_url'] === '') {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $cfg['public_base_url'] = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $cfg['public_base_url'] = rtrim($cfg['public_base_url'], '/');

    // Journal : fichier hors du web (hébergement) ou sortie d'erreur (Docker : LOG_FILE=stderr).
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (getenv('LOG_FILE') !== 'stderr' && (is_dir($cfg['data_dir']) || @mkdir($cfg['data_dir'], 0750, true))) {
        ini_set('error_log', $cfg['data_dir'] . '/journal.log');
    }
    return $cfg;
}

// ---------------------------------------------------------------- Tarifs

/** "1:4.90,2:5.90,4:7.50" -> [[minQty, cents], …] triés. */
function parse_shipping_table(string $raw): array
{
    $tiers = [];
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $part) {
        $bits = explode(':', $part);
        if (count($bits) !== 2 || !ctype_digit($bits[0]) || (int) $bits[0] < 1 || !is_numeric($bits[1]) || (float) $bits[1] < 0) {
            throw new InvalidArgumentException("shipping_table invalide : \"$part\"");
        }
        $tiers[] = ['minQty' => (int) $bits[0], 'cents' => (int) round((float) $bits[1] * 100)];
    }
    usort($tiers, fn($a, $b) => $a['minQty'] <=> $b['minQty']);
    if (!$tiers || $tiers[0]['minQty'] !== 1) {
        throw new InvalidArgumentException('shipping_table doit commencer par un palier "1:"');
    }
    return $tiers;
}

/** Paliers en vigueur : réglage de l'admin (/admin/livraison) s'il existe, sinon la configuration. */
function shipping_table_source(?PDO $db = null): string
{
    return get_setting($db ?? db(), 'shipping_table') ?? cfg()['shipping_table'];
}

function pricing(?PDO $db = null): array
{
    $c = cfg();
    return [
        'unitCents' => (int) round($c['book_price_eur'] * 100),
        'maxQty' => $c['max_qty'],
        'shipping' => parse_shipping_table(shipping_table_source($db)),
    ];
}

/** Paliers -> "1:4.90,2:5.90" (format stocké). */
function shipping_table_string(array $tiers): string
{
    return implode(',', array_map(fn($t) => $t['minQty'] . ':' . number_format($t['cents'] / 100, 2, '.', ''), $tiers));
}

/**
 * Lit les lignes du formulaire de livraison (« à partir de N livres » / « prix »).
 * Les lignes entièrement vides sont ignorées ; le prix accepte la virgule.
 * @return array{ok: bool, tiers?: array, error?: string}
 */
function shipping_from_form(array $minQtys, array $prices, int $maxQty): array
{
    $tiers = [];
    $seen = [];
    foreach (array_values($minQtys) as $i => $rawQty) {
        $rawPrice = $prices[$i] ?? '';
        $q = is_scalar($rawQty) ? trim((string) $rawQty) : '';
        $p = str_replace([',', '€', ' ', "\u{00A0}"], ['.', '', '', ''], is_scalar($rawPrice) ? trim((string) $rawPrice) : '');
        if ($q === '' && $p === '') continue;
        if (!ctype_digit($q) || (int) $q < 1 || (int) $q > $maxQty) {
            return ['ok' => false, 'error' => "Ligne " . ($i + 1) . " : le nombre de livres doit être entre 1 et $maxQty."];
        }
        if (!is_numeric($p) || (float) $p < 0 || (float) $p >= 100) {
            return ['ok' => false, 'error' => "Ligne " . ($i + 1) . " : prix invalide (ex. 4,90)."];
        }
        if (isset($seen[(int) $q])) {
            return ['ok' => false, 'error' => "Le palier « à partir de $q livre(s) » apparaît deux fois."];
        }
        $seen[(int) $q] = true;
        $tiers[] = ['minQty' => (int) $q, 'cents' => (int) round((float) $p * 100)];
    }
    usort($tiers, fn($a, $b) => $a['minQty'] <=> $b['minQty']);
    if (!$tiers || $tiers[0]['minQty'] !== 1) {
        return ['ok' => false, 'error' => 'Il faut un palier « à partir de 1 livre ».'];
    }
    return ['ok' => true, 'tiers' => $tiers];
}

function compute_totals(int $qty, array $p): array
{
    if ($qty < 1 || $qty > $p['maxQty']) throw new InvalidArgumentException("Quantité invalide (1 à {$p['maxQty']})");
    $shipping = $p['shipping'][0]['cents'];
    foreach ($p['shipping'] as $tier) if ($qty >= $tier['minQty']) $shipping = $tier['cents'];
    $subtotal = $qty * $p['unitCents'];
    return ['qty' => $qty, 'unit_cents' => $p['unitCents'], 'subtotal_cents' => $subtotal,
        'shipping_cents' => $shipping, 'total_cents' => $subtotal + $shipping];
}

function eur(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function revolut_link(string $template, int $totalCents, string $ref): string
{
    return strtr($template, ['{amount}' => number_format($totalCents / 100, 2, '.', ''), '{ref}' => rawurlencode($ref)]);
}

// ---------------------------------------------------------------- Validation

function clean_text($raw, int $max): string
{
    if (!is_string($raw)) return '';
    $s = trim((string) preg_replace('/\s+/u', ' ', $raw));
    return mb_substr($s, 0, $max);
}

/** @return array{ok: bool, value?: array, errors?: array} */
function validate_order(array $b, int $maxQty): array
{
    $qtyRaw = $b['qty'] ?? null;
    $qty = is_int($qtyRaw) || (is_string($qtyRaw) && ctype_digit($qtyRaw)) ? (int) $qtyRaw : 0;
    $v = [
        'qty' => $qty,
        'first_name' => clean_text($b['firstName'] ?? '', 80),
        'last_name' => clean_text($b['lastName'] ?? '', 80),
        'email' => mb_strtolower(clean_text($b['email'] ?? '', 160)),
        'phone' => clean_text($b['phone'] ?? '', 20),
        'relay_id' => clean_text($b['relayId'] ?? '', 20),
        'relay_name' => clean_text($b['relayName'] ?? '', 120),
        'relay_address' => clean_text($b['relayAddress'] ?? '', 200),
        'relay_postcode' => clean_text($b['relayPostcode'] ?? '', 10),
        'relay_city' => clean_text($b['relayCity'] ?? '', 80),
        'relay_country' => strtoupper(clean_text($b['relayCountry'] ?? '', 2)) ?: 'FR',
        'note' => is_string($b['note'] ?? null) ? mb_substr(trim($b['note']), 0, 1000) : '',
    ];
    $e = [];
    if ($qty < 1 || $qty > $maxQty) $e['qty'] = "Entre 1 et $maxQty exemplaires.";
    if ($v['first_name'] === '') $e['firstName'] = 'Prénom requis.';
    if ($v['last_name'] === '') $e['lastName'] = 'Nom requis.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Adresse e-mail invalide.';
    if (!preg_match('/^\+?[\d\s.\-()]{9,20}$/', $v['phone']) || strlen((string) preg_replace('/\D/', '', $v['phone'])) < 9) {
        $e['phone'] = 'Numéro de téléphone invalide (Mondial Relay envoie un SMS à l’arrivée du colis).';
    }
    if ($v['relay_name'] === '') $e['relayName'] = 'Nom du point relais requis.';
    if ($v['relay_address'] === '') $e['relayAddress'] = 'Adresse du point relais requise.';
    if (!preg_match('/^[0-9A-Z\- ]{4,10}$/i', $v['relay_postcode'])) $e['relayPostcode'] = 'Code postal invalide.';
    if ($v['relay_city'] === '') $e['relayCity'] = 'Ville requise.';
    if (!in_array($v['relay_country'], ['FR', 'BE', 'LU', 'ES', 'NL', 'PT', 'DE', 'AT', 'IT'], true)) $e['relayCountry'] = 'Pays non desservi.';
    if ($v['relay_id'] !== '' && !preg_match('/^[0-9A-Z\-]{1,20}$/i', $v['relay_id'])) $e['relayId'] = 'Code relais invalide.';
    return $e ? ['ok' => false, 'errors' => $e] : ['ok' => true, 'value' => $v];
}

// ---------------------------------------------------------------- Base de données

function db(?string $file = null): PDO
{
    static $pdo = null;
    $isDefault = $file === null;
    if ($pdo !== null && $isDefault) return $pdo;
    if ($isDefault) {
        $dir = cfg()['data_dir'];
        ensure_private_dir($dir);
        $file = $dir . '/commandes.sqlite';
    }
    $conn = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $conn->exec('PRAGMA busy_timeout = 5000');
    if ($file !== ':memory:') $conn->exec('PRAGMA journal_mode = WAL');
    $conn->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS orders (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          ref TEXT NOT NULL UNIQUE,
          token TEXT NOT NULL,
          created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
          status TEXT NOT NULL DEFAULT 'attente_paiement',
          qty INTEGER NOT NULL, unit_cents INTEGER NOT NULL, shipping_cents INTEGER NOT NULL, total_cents INTEGER NOT NULL,
          first_name TEXT NOT NULL, last_name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL,
          relay_id TEXT NOT NULL DEFAULT '', relay_name TEXT NOT NULL, relay_address TEXT NOT NULL,
          relay_postcode TEXT NOT NULL, relay_city TEXT NOT NULL, relay_country TEXT NOT NULL DEFAULT 'FR',
          note TEXT NOT NULL DEFAULT '', tracking TEXT NOT NULL DEFAULT '', admin_note TEXT NOT NULL DEFAULT '',
          paid_at TEXT, shipped_at TEXT
        );
        CREATE INDEX IF NOT EXISTS orders_status ON orders(status);
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS hits (key TEXT NOT NULL, at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS hits_key ON hits(key, at);
        SQL);
    if ($isDefault) $pdo = $conn;
    return $conn;
}

/** Crée le dossier de données et le verrouille au cas où il se retrouverait sous le site web. */
function ensure_private_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer le dossier de données : $dir");
    }
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function create_order(PDO $db, array $in, array $t): array
{
    $token = bin2hex(random_bytes(16));
    $st = $db->prepare('INSERT INTO orders (ref, token, qty, unit_cents, shipping_cents, total_cents,
        first_name, last_name, email, phone, relay_id, relay_name, relay_address, relay_postcode, relay_city,
        relay_country, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $db->beginTransaction();
    $st->execute(['tmp-' . $token, $token, $t['qty'], $t['unit_cents'], $t['shipping_cents'], $t['total_cents'],
        $in['first_name'], $in['last_name'], $in['email'], $in['phone'], $in['relay_id'], $in['relay_name'],
        $in['relay_address'], $in['relay_postcode'], $in['relay_city'], $in['relay_country'], $in['note']]);
    $id = (int) $db->lastInsertId();
    $ref = sprintf('YV-%04d', $id);
    $db->prepare('UPDATE orders SET ref = ? WHERE id = ?')->execute([$ref, $id]);
    $db->commit();
    return find_order($db, $ref);
}

function find_order(PDO $db, string $ref): ?array
{
    $st = $db->prepare('SELECT * FROM orders WHERE ref = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

function list_orders(PDO $db, ?string $status = null): array
{
    if ($status === null) return $db->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll();
    $st = $db->prepare('SELECT * FROM orders WHERE status = ? ORDER BY id DESC');
    $st->execute([$status]);
    return $st->fetchAll();
}

function count_orders(PDO $db): array
{
    $out = array_fill_keys(STATUSES, 0);
    foreach ($db->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $r) $out[$r['status']] = (int) $r['n'];
    return $out;
}

function set_status(PDO $db, string $ref, string $status, string $tracking = ''): ?array
{
    $now = now_iso();
    if ($status === 'payee') {
        $db->prepare('UPDATE orders SET status = ?, paid_at = COALESCE(paid_at, ?) WHERE ref = ?')->execute([$status, $now, $ref]);
    } elseif ($status === 'expediee') {
        $db->prepare('UPDATE orders SET status = ?, shipped_at = ?, tracking = ?, paid_at = COALESCE(paid_at, ?) WHERE ref = ?')
            ->execute([$status, $now, $tracking, $now, $ref]);
    } else {
        $db->prepare('UPDATE orders SET status = ? WHERE ref = ?')->execute([$status, $ref]);
    }
    return find_order($db, $ref);
}

function set_admin_note(PDO $db, string $ref, string $note): void
{
    $db->prepare('UPDATE orders SET admin_note = ? WHERE ref = ?')->execute([mb_substr($note, 0, 2000), $ref]);
}

// ---------------------------------------------------------------- Réglages modifiables depuis l'admin

function get_setting(PDO $db, string $key): ?string
{
    $st = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function set_setting(PDO $db, string $key, string $value): void
{
    $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
        ->execute([$key, $value, now_iso()]);
}

function delete_setting(PDO $db, string $key): void
{
    $db->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
}

function setting_updated_at(PDO $db, string $key): ?string
{
    $st = $db->prepare('SELECT updated_at FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/** Limite par clé : vrai si plus de $max événements dans la fenêtre. */
function rate_limited(PDO $db, string $key, int $max, int $windowSec): bool
{
    $now = time();
    $db->prepare('DELETE FROM hits WHERE at < ?')->execute([$now - 86400]);
    $db->prepare('INSERT INTO hits (key, at) VALUES (?, ?)')->execute([$key, $now]);
    $st = $db->prepare('SELECT COUNT(*) FROM hits WHERE key = ? AND at >= ?');
    $st->execute([$key, $now - $windowSec]);
    return (int) $st->fetchColumn() > $max;
}

function client_ip(): string
{
    if (cfg()['trust_proxy'] && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim(end($parts));
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Refuse les requêtes envoyées depuis un autre site (protection CSRF de base). */
function same_origin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return true; // anciens navigateurs : le jeton CSRF de l'admin prend le relais
    return parse_url($origin, PHP_URL_HOST) === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
}

// ---------------------------------------------------------------- Rendu

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_page(string $title, string $content, string $template = 'gabarit', int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $html = (string) file_get_contents(__DIR__ . "/$template.html");
    echo str_replace(['%%TITLE%%', '<!--%%CONTENT%%-->'], [h($title), $content], $html);
    exit;
}

function json_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function order_page_url(array $o): string
{
    return cfg()['public_base_url'] . '/merci?ref=' . rawurlencode($o['ref']) . '&t=' . $o['token'];
}

function fr_date(?string $iso, bool $long = false): string
{
    if (!$iso) return '—';
    $d = new DateTime($iso);
    $d->setTimezone(new DateTimeZone('Europe/Paris'));
    return $long ? $d->format('d/m/Y à H:i') : $d->format('d/m/y H:i');
}
