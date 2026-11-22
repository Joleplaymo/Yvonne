<?php
declare(strict_types=1);
// Tests des commandes de livre numérique. Lancement : npm run test:php (Docker, PHP 8.3).

putenv('DATA_DIR=' . sys_get_temp_dir() . '/yv-test-ebook-' . getmypid());
require __DIR__ . '/../../php/_prive/ebook.php';
require __DIR__ . '/../../php/_prive/mail.php';

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

function throws(callable $fn): bool
{
    try { $fn(); return false; } catch (Throwable) { return true; }
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
$tok = ebook_deliver($db, 'EB-0001');
$db->exec("UPDATE ebook_orders SET status = 'attente_paiement'");
check('jeton refusé avant paiement', ebook_consume_download($db, $tok, '203.0.113.1', 'ua') === null);
$tok = ebook_deliver($db, 'EB-0001');
check('livraison : ref inconnue refusée', throws(fn() => ebook_deliver($db, 'EB-9999')));
check('set_status livree refusé', throws(fn() => ebook_set_status($db, 'EB-0001', 'livree')));
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
@unlink(ebook_master_path());
check('réglage ouvert sans maître : vente fermée', ebook_sale_setting($db) === true && ebook_sale_open($db) === false);
@mkdir(dirname(ebook_master_path()), 0700, true);
file_put_contents(ebook_master_path(), 'x');
check('réglage ouvert avec maître : vente ouverte', ebook_sale_open($db) === true);
set_setting($db, 'ebook_vente_ouverte', '0');
check('réglage fermé avec maître : vente fermée', ebook_sale_open($db) === false);
set_setting($db, 'ebook_vente_ouverte', '1');
check('prix par défaut 499', ebook_price_cents($db) === 499);
set_setting($db, 'ebook_price_cents', '699');
check('prix réglable', ebook_price_cents($db) === 699);
$s1 = ebook_secret($db);
check('secret généré et stable', strlen($s1) === 64 && ebook_secret($db) === $s1);
check('chemin du maître', str_ends_with(ebook_master_path(), '/epub/maitre.epub'));

// --- E-mails (mode journal : mail_log() écrit dans error_log)
cfg_override(['mail_mode' => 'log', 'public_base_url' => 'https://exemple.test', 'admin_email' => 'admin@exemple.test']);
$logFile = sys_get_temp_dir() . '/yv-test-ebook-mail-' . getmypid() . '.log';
ini_set('error_log', $logFile);
$readLog = static function () use ($logFile): string {
    $s = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    @unlink($logFile);
    return $s;
};
$mo = find_ebook_order($db, 'EB-0001');

check('URL page commande', ebook_order_page_url($mo) === 'https://exemple.test/numerique/merci?ref=EB-0001&t=' . $mo['token']);
check('URL téléchargement', ebook_download_url('abc_-9') === 'https://exemple.test/telecharger/abc_-9');

$readLog();
mail_ebook_created($mo);
$log = $readLog();
check('mail commande : consentement et date',
    str_contains($log, EBOOK_CONSENT_TEXT) && str_contains($log, fr_date($mo['consent_at'], true))
    && str_contains($log, revolut_link(cfg()['revolut_link_template'], 499, 'EB-0001')) && str_contains($log, 'EB-0001')
    && str_contains($log, 'À: ' . $mo['email']) && str_contains($log, ebook_order_page_url($mo)));
check('mail commande : copie à l\'admin', str_contains($log, 'À: admin@exemple.test') && str_contains($log, 'Nouvelle commande numérique EB-0001'));

$sent = mail_ebook_delivered($mo, 'TOKEN123');
check('mail livraison : renvoie true (mode log)', $sent === true);
$log = $readLog();
check('mail livraison : lien, 7 jours, 5 téléchargements',
    str_contains($log, ebook_download_url('TOKEN123')) && str_contains($log, '7 jours') && str_contains($log, '5')
    && str_contains($log, 'À: ' . $mo['email']));

mail_ebook_share_alert($mo, 4);
$log = $readLog();
check('mail alerte : ref et nombre d\'IP', str_contains($log, 'EB-0001') && str_contains($log, '4 adresses IP')
    && str_contains($log, 'À: admin@exemple.test'));

// --- Aperçu (vente fermée)
check('aperçu : pas de clé générée par la vérification', ebook_preview_allowed($db, 'abc') === false && get_setting($db, 'ebook_apercu_cle') === null);
$pk = ebook_preview_key($db);
check('aperçu : clé valide acceptée', preg_match('/^[0-9a-f]{64}$/', $pk) === 1 && ebook_preview_allowed($db, $pk));
check('aperçu : clé vide/fausse refusée', !ebook_preview_allowed($db, '') && !ebook_preview_allowed($db, str_repeat('0', 64)) && !ebook_preview_allowed($db, $pk . 'x'));
check('aperçu : clé stable', ebook_preview_key($db) === $pk);

// --- Remboursement d'un téléchargement non servi
$tokR = ebook_deliver($db, 'EB-0001');
ebook_consume_download($db, $tokR, '203.0.113.5', 'ua');
ebook_consume_download($db, $tokR, '203.0.113.6', 'ua');
$idR = (int) find_ebook_order($db, 'EB-0001')['id'];
$logBefore = count(ebook_downloads_of($db, $idR));
ebook_refund_download($db, $idR);
$logIps = array_column(ebook_downloads_of($db, $idR), 'ip');
check('remboursement : compteur décrémenté et journal retiré',
    find_ebook_order($db, 'EB-0001')['dl_count'] === 1 && count($logIps) === $logBefore - 1
    && !in_array('203.0.113.6', $logIps, true) && in_array('203.0.113.5', $logIps, true));
ebook_refund_download($db, $idR);
ebook_refund_download($db, $idR);
check('remboursement : jamais sous zéro', find_ebook_order($db, 'EB-0001')['dl_count'] === 0);

// --- Tunnel papier intact
$cols = array_column($db->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
check('table orders intacte', $cols === ['id', 'ref', 'token', 'created_at', 'status', 'qty', 'unit_cents', 'shipping_cents',
    'total_cents', 'first_name', 'last_name', 'email', 'phone', 'relay_id', 'relay_name', 'relay_address', 'relay_postcode',
    'relay_city', 'relay_country', 'note', 'tracking', 'admin_note', 'paid_at', 'shipped_at']);

echo $fails ? "\n$fails/$count test(s) en échec\n" : "$count tests ebook PHP OK\n";
exit($fails ? 1 : 0);
