<?php
declare(strict_types=1);
// Tests des commandes de livre numérique. Lancement : npm run test:php (Docker, PHP 8.3).

putenv('DATA_DIR=' . sys_get_temp_dir() . '/yv-test-ebook-' . getmypid());
require __DIR__ . '/../../php/_prive/ebook.php';

$fails = 0;
$count = 0;
function check(string $name, bool $cond): void
{
    global $fails, $count;
    $count++;
    if (!$cond) {
        $fails++;
        echo "ÉCHEC : $name\n";
    }
}

$db = db(':memory:');
$good = ['firstName' => ' Yvonne ', 'lastName' => 'Dupont', 'email' => 'Y@Example.fr', 'consent' => '1'];

// --- Validation
$noConsent = $good;
unset($noConsent['consent']);
check('consentement obligatoire', isset(validate_ebook_order($noConsent)['errors']['consent']));
check('consentement « 0 » refusé', isset(validate_ebook_order(['consent' => '0'] + $good)['errors']['consent']));
check('email invalide refusé', isset(validate_ebook_order(['email' => 'pas-un-email'] + $good)['errors']['email']));
$v = validate_ebook_order($good);
check('commande valide normalisée', $v['ok'] && $v['value'] === ['first_name' => 'Yvonne', 'last_name' => 'Dupont', 'email' => 'y@example.fr']);

// --- Création
$o = create_ebook_order($db, $v['value'], 499, '198.51.100.1');
check('création : ref EB-0001, prix 499, consentement horodaté',
    $o['ref'] === 'EB-0001' && $o['price_cents'] === 499 && $o['status'] === 'attente_paiement'
    && $o['consent_text'] === EBOOK_CONSENT_TEXT && $o['consent_ip'] === '198.51.100.1' && $o['consent_at'] !== '');
check('constantes', EBOOK_DL_DAYS === 7 && EBOOK_DL_MAX === 5 && EBOOK_ALERT_IPS === 3
    && EBOOK_CONSENT_TEXT === "Je demande l'accès au livre numérique dès la validation de mon paiement et je reconnais perdre mon droit de rétractation.");
check('comptage', count_ebook_orders($db)['attente_paiement'] === 1 && count(list_ebook_orders($db)) === 1);

// --- Jeton
check('jeton refusé avant paiement', ebook_consume_download($db, 'nimportequoi', '203.0.113.1', 'ua') === null);
$tok = ebook_deliver($db, 'EB-0001');
check('jeton : 43 caractères base64url', preg_match('/^[A-Za-z0-9_-]{43}$/', $tok) === 1);
$row = find_ebook_order($db, 'EB-0001');
check('jeton stocké haché', $row['dl_token_hash'] !== $tok && $row['dl_token_hash'] === hash('sha256', $tok));
check('livraison : livrée, payée, 5 max', $row['status'] === 'livree' && $row['paid_at'] !== null && $row['dl_max'] === 5 && $row['dl_count'] === 0);
$ok = 0;
for ($i = 0; $i < 5; $i++) if (ebook_consume_download($db, $tok, '203.0.113.1', 'ua') !== null) $ok++;
check('livraison : 5 téléchargements', $ok === 5);
check('6e téléchargement refusé', ebook_consume_download($db, $tok, '203.0.113.1', 'ua') === null);
check('6e refus : compteur inchangé', find_ebook_order($db, 'EB-0001')['dl_count'] === 5);

$db->exec("UPDATE ebook_orders SET dl_count = 0, dl_expires_at = '2000-01-01T00:00:00Z'");
check('jeton expiré refusé', ebook_consume_download($db, $tok, '203.0.113.1', 'ua') === null);

$tok2 = ebook_deliver($db, 'EB-0001');
check('ancien jeton invalide après renvoi', ebook_consume_download($db, $tok, '203.0.113.1', 'ua') === null && $tok2 !== $tok);
check('nouveau jeton fonctionne', ebook_consume_download($db, $tok2, '203.0.113.1', 'ua')['ref'] === 'EB-0001');
ebook_deliver($db, 'EB-0001');
check('renvoi : compteur remis à 0', find_ebook_order($db, 'EB-0001')['dl_count'] === 0);

$tok3 = ebook_deliver($db, 'EB-0001');
ebook_set_status($db, 'EB-0001', 'annulee');
check('jeton refusé si annulée', ebook_consume_download($db, $tok3, '203.0.113.1', 'ua') === null);
ebook_set_status($db, 'EB-0001', 'attente_paiement');
check('rouvrir : hash effacé', find_ebook_order($db, 'EB-0001')['dl_token_hash'] === null
    && ebook_consume_download($db, $tok3, '203.0.113.1', 'ua') === null);

// --- IP distinctes et alerte
$db->exec("DELETE FROM ebook_downloads");
$tok4 = ebook_deliver($db, 'EB-0001');
$id = (int) find_ebook_order($db, 'EB-0001')['id'];
foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4'] as $ip) ebook_consume_download($db, $tok4, $ip, 'ua');
check('IP distinctes', ebook_distinct_ips($db, $id) === 4 && count(ebook_downloads_of($db, $id)) === 4);
check('alerte unique', ebook_mark_alert_sent($db, $id) === true && ebook_mark_alert_sent($db, $id) === false);

// --- Purge
$db->exec("DELETE FROM ebook_downloads");
$ins = $db->prepare('INSERT INTO ebook_downloads (order_id, at, ip, user_agent) VALUES (?,?,?,?)');
$ins->execute([$id, gmdate('Y-m-d\TH:i:s\Z', time() - 400 * 86400), '203.0.113.9', 'ua']);
$ins->execute([$id, gmdate('Y-m-d\TH:i:s\Z', time() - 10 * 86400), '203.0.113.9', 'ua']);
check('purge 12 mois', ebook_purge_downloads($db) === 1 && count(ebook_downloads_of($db, $id)) === 1);
check('commande conservée après purge', find_ebook_order($db, 'EB-0001') !== null);

// --- Réglages
check('vente fermée par défaut', ebook_sale_open($db) === false);
set_setting($db, 'ebook_vente_ouverte', '1');
check('vente ouverte après réglage', ebook_sale_open($db) === true);
check('prix par défaut 499', ebook_price_cents($db) === 499);
set_setting($db, 'ebook_price_cents', '699');
check('prix réglable', ebook_price_cents($db) === 699);
$s1 = ebook_secret($db);
check('secret généré et stable', strlen($s1) === 64 && ebook_secret($db) === $s1);
check('chemin du maître', str_ends_with(ebook_master_path(), '/epub/maitre.epub'));

// --- Tunnel papier intact
$cols = array_column($db->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
check('table orders intacte', $cols === ['id', 'ref', 'token', 'created_at', 'status', 'qty', 'unit_cents', 'shipping_cents',
    'total_cents', 'first_name', 'last_name', 'email', 'phone', 'relay_id', 'relay_name', 'relay_address', 'relay_postcode',
    'relay_city', 'relay_country', 'note', 'tracking', 'admin_note', 'paid_at', 'shipped_at']);

echo $fails ? "\n$fails/$count test(s) en échec\n" : "$count tests ebook PHP OK\n";
exit($fails ? 1 : 0);
