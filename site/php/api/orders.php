<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/lib.php';
require __DIR__ . '/../_prive/mail.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_out(405, ['message' => 'Méthode non autorisée.']);
}
if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) json_out(415, ['message' => 'Format non supporté.']);
if (!same_origin()) json_out(403, ['message' => 'Origine refusée.']);

try {
    $db = db();
    if (rate_limited($db, 'order:' . client_ip(), 8, 3600)) {
        json_out(429, ['message' => 'Trop de commandes en peu de temps. Réessayez plus tard ou écrivez-nous.']);
    }

    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 65536) json_out(413, ['message' => 'Requête trop volumineuse.']);
    $body = json_decode($raw, true);
    if (!is_array($body)) json_out(400, ['message' => 'Requête invalide.']);
    // Pot de miel anti-robots : un humain ne voit pas ce champ.
    if (trim((string) ($body['website'] ?? '')) !== '') json_out(400, ['message' => 'Requête invalide.']);

    $pricing = pricing($db);
    $r = validate_order($body, $pricing['maxQty']);
    if (!$r['ok']) json_out(422, ['errors' => $r['errors']]);

    $order = create_order($db, $r['value'], compute_totals($r['value']['qty'], $pricing));
    mail_order_created($order);

    json_out(201, ['ref' => $order['ref'], 'url' => '/merci?ref=' . rawurlencode($order['ref']) . '&t=' . $order['token']]);
} catch (Throwable $e) {
    error_log('[orders] ' . $e->getMessage());
    json_out(500, ['message' => 'Une erreur est survenue. Réessayez dans un instant ou écrivez à contact@vanessagandino.fr.']);
}
