<?php
declare(strict_types=1);
// Tests unitaires du backend PHP. Lancement : npm run test:php (Docker, PHP 8.3).

$_SERVER['DOCUMENT_ROOT'] = '/home/u1/domains/vanessagandino.fr/public_html/leshormonesdyvonne';
putenv('DATA_DIR=' . sys_get_temp_dir() . '/yv-test-' . getmypid());
require __DIR__ . '/../../php/_prive/lib.php';

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

// --- Dossier de données hors du web
check('data dir au-dessus de public_html', default_data_dir() === '/home/u1/domains/vanessagandino.fr/boutique-data');

// --- Tarifs (mêmes cas que tests/pricing.test.ts)
$tiers = parse_shipping_table('4:7.5, 1:4.9');
check('paliers triés', $tiers === [['minQty' => 1, 'cents' => 490], ['minQty' => 4, 'cents' => 750]]);
check('palier 1 obligatoire', throws(fn() => parse_shipping_table('2:5')));
check('palier invalide', throws(fn() => parse_shipping_table('1:abc')));
check('table vide', throws(fn() => parse_shipping_table('')));
$p = ['unitCents' => 1000, 'maxQty' => 6, 'shipping' => parse_shipping_table('1:4.90,2:5.90,4:7.50')];
$t = compute_totals(3, $p);
check('totaux 3 livres', $t['subtotal_cents'] === 3000 && $t['shipping_cents'] === 590 && $t['total_cents'] === 3590);
check('port 6 livres', compute_totals(6, $p)['shipping_cents'] === 750);
check('quantité 0 refusée', throws(fn() => compute_totals(0, $p)));
check('quantité 7 refusée', throws(fn() => compute_totals(7, $p)));
check('format euros', eur(3590) === '35,90 €');
check('lien revolut', revolut_link('https://revolut.me/x/{amount}EUR?r={ref}', 3590, 'YV-0001') === 'https://revolut.me/x/35.90EUR?r=YV-0001');
check('lien revolut simple', revolut_link('https://revolut.me/x', 3590, 'YV-0001') === 'https://revolut.me/x');

// --- Validation
$good = ['qty' => 2, 'firstName' => ' Yvonne ', 'lastName' => 'Dupont', 'email' => 'Y@Example.fr', 'phone' => '06 12 34 56 78',
    'relayId' => '012345', 'relayName' => 'Tabac du Centre', 'relayAddress' => '12 rue de la Paix',
    'relayPostcode' => '75002', 'relayCity' => 'Paris', 'relayCountry' => 'fr', 'note' => ''];
$r = validate_order($good, 6);
check('commande valide', $r['ok'] && $r['value']['first_name'] === 'Yvonne' && $r['value']['email'] === 'y@example.fr' && $r['value']['relay_country'] === 'FR');
check('qty en texte acceptée', validate_order(['qty' => '2'] + $good, 6)['ok']);
check('code relais facultatif', validate_order(['relayId' => ''] + $good, 6)['ok']);
$bad = validate_order(['qty' => 9], 6);
$keys = array_keys($bad['errors'] ?? []);
sort($keys);
check('toutes les erreurs listées', !$bad['ok'] && $keys === ['email', 'firstName', 'lastName', 'phone', 'qty', 'relayAddress', 'relayCity', 'relayName', 'relayPostcode']);
check('qty décimale refusée', !validate_order(['qty' => 1.5] + $good, 6)['ok']);

// --- Base de données
$db = db(':memory:');
$in = $r['value'];
$a = create_order($db, $in, compute_totals(2, $p));
$b = create_order($db, $in, compute_totals(2, $p));
check('références séquentielles', $a['ref'] === 'YV-0001' && $b['ref'] === 'YV-0002');
check('jeton 32 hex', (bool) preg_match('/^[0-9a-f]{32}$/', $a['token']));
check('statut initial', $a['status'] === 'attente_paiement' && (int) $a['total_cents'] === 2590);
set_status($db, 'YV-0001', 'payee');
$s = set_status($db, 'YV-0001', 'expediee', 'TEST123');
check('expédiée avec suivi', $s['tracking'] === 'TEST123' && $s['paid_at'] !== null && $s['shipped_at'] !== null);
check('compteurs', count_orders($db) === ['attente_paiement' => 1, 'payee' => 0, 'expediee' => 1, 'annulee' => 0]);
check('filtre statut', array_column(list_orders($db, 'expediee'), 'ref') === ['YV-0001']);
$limited = false;
for ($i = 0; $i < 4; $i++) $limited = rate_limited($db, 'k', 3, 60);
check('limite de débit', $limited && !rate_limited($db, 'autre', 3, 60));
check('échappement HTML', h('<a href="x">&') === '&lt;a href=&quot;x&quot;&gt;&amp;');

echo $fails ? "\n$fails/$count test(s) en échec\n" : "$count tests PHP OK\n";
exit($fails ? 1 : 0);
