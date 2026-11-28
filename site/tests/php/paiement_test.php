<?php
declare(strict_types=1);
// Tests du module paiement (liens Revolut réglables). Lancement : npm run test:php (Docker, PHP 8.3).

putenv('DATA_DIR=' . sys_get_temp_dir() . '/yv-test-paiement-' . getmypid());
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

cfg_override(['mail_mode' => 'log', 'public_base_url' => 'https://exemple.test', 'admin_email' => 'admin@exemple.test',
    'revolut_link_template' => 'https://revolut.me/cfg']);
$logFile = sys_get_temp_dir() . '/yv-test-paiement-mail-' . getmypid() . '.log';
ini_set('error_log', $logFile);
$readLog = static function () use ($logFile): string {
    $s = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    @unlink($logFile);
    return $s;
};

// --- Validation
foreach (['https://revolut.me/v_gandino', 'https://revolut.me/x/{amount}EUR?r={ref}', 'https://checkout.revolut.com/pay/abc', ''] as $ok) {
    check("check valide : $ok", pay_link_check($ok, false) === null);
}
check('check fixe valide', pay_link_check('https://checkout.revolut.com/pay/abc', true) === null);
check('check fixe vide', pay_link_check('', true) === null);
foreach (['http://revolut.me/x', 'https://revolut.me.evil.com/x', 'https://evil.com/?revolut.me', 'https://x@revolut.me/y',
    'https://revolut.me:8443/x', 'javascript:alert(1)', 'https://revolut.me/x {amount}', "https://revolut.me/x\n",
    'https://revolut.me/{foo}', "https://revolut.me/x\x00", "https://revolut.me/x\x1b[2J", "https://revolut.me/x\x7f", "https://revolut.me/x\xC2\xA0y", 'https://revolut.me/' . str_repeat('a', 300)] as $bad) {
    $err = pay_link_check($bad, false);
    check('check refusé : ' . substr(addcslashes($bad, "\n"), 0, 40), is_string($err) && $err !== '');
}
check('check fixe : {amount} refusé', is_string(pay_link_check('https://revolut.me/x/{amount}', true)));

// --- Sans réglage
$db = db();
check('sans réglage : config', pay_link_paper(3590, 'YV-0001', $db) === ['url' => 'https://revolut.me/cfg', 'prefilled' => false]);
check('sans réglage : statut none', pay_ebook_fixed_status($db, 499) === 'none');

// --- Enregistrement du lien papier
$readLog();
pay_save($db, 'https://revolut.me/x/{amount}EUR', '', 499, '203.0.113.9');
$log = $readLog();
check('save : lien papier prérempli', pay_link_paper(3590, 'YV-0001', $db) === ['url' => 'https://revolut.me/x/35.90EUR', 'prefilled' => true]);
check('save : alerte', str_contains($log, 'À: admin@exemple.test') && str_contains($log, 'Lien de paiement modifié')
    && str_contains($log, 'https://revolut.me/x/{amount}EUR') && str_contains($log, '203.0.113.9'));
pay_save($db, '  https://revolut.me/x/{amount}EUR  ', '  ', 499, '203.0.113.9');
check("save inchangé (espaces) : pas d'alerte", !str_contains($readLog(), 'Lien de paiement modifié'));

// --- Lien fixe epub
pay_save($db, 'https://revolut.me/x/{amount}EUR', 'https://checkout.revolut.com/pay/abc', 499, 'ip');
check('alerte sur lien epub', str_contains($readLog(), 'Lien de paiement modifié'));
check('epub 499 : lien fixe', pay_link_ebook(499, 'EB-0001', $db) === ['url' => 'https://checkout.revolut.com/pay/abc', 'prefilled' => true]);
check('epub 599 : repli sur lien papier', pay_link_ebook(599, 'EB-0001', $db)['url'] === 'https://revolut.me/x/5.99EUR');
check('statut ok', pay_ebook_fixed_status($db, 499) === 'ok');
check('statut stale', pay_ebook_fixed_status($db, 599) === 'stale');

// --- Ré-enregistrement du même lien fixe après changement de prix : les centimes restent
pay_save($db, 'https://revolut.me/x/{amount}EUR', 'https://checkout.revolut.com/pay/abc', 599, 'ip');
check('même lien fixe, prix 599 : reste stale', pay_ebook_fixed_status($db, 599) === 'stale'
    && pay_link_ebook(599, 'EB-0001', $db)['url'] !== 'https://checkout.revolut.com/pay/abc'
    && pay_ebook_fixed_status($db, 499) === 'ok');
check("même lien fixe : pas d'alerte", !str_contains($readLog(), 'Lien de paiement modifié'));
// Nouveau lien fixe : les centimes sont réécrits
pay_save($db, 'https://revolut.me/x/{amount}EUR', 'https://checkout.revolut.com/pay/def', 599, 'ip');
check('nouveau lien fixe : centimes mis à jour', pay_ebook_fixed_status($db, 599) === 'ok'
    && pay_link_ebook(599, 'EB-0001', $db)['url'] === 'https://checkout.revolut.com/pay/def');
$readLog();

// --- Lien papier sans {amount}
pay_save($db, 'https://revolut.me/x', 'https://checkout.revolut.com/pay/abc', 499, 'ip');
$readLog();
check('sans {amount}, lien fixe périmé : prefilled false', pay_link_ebook(599, 'EB-0001', $db)['prefilled'] === false);

// --- Lien invalide
$threw = false;
try { pay_save($db, 'http://evil.test/x', '', 499, 'ip'); } catch (InvalidArgumentException) { $threw = true; }
check('save invalide : exception, rien changé', $threw && pay_link_paper(100, 'T', $db)['url'] === 'https://revolut.me/x' && $readLog() === '');
$threw = false;
try { pay_save($db, 'https://revolut.me/ok', 'https://revolut.me/x/{amount}', 499, 'ip'); } catch (InvalidArgumentException) { $threw = true; }
check('save lien fixe invalide : exception, rien changé', $threw && pay_link_paper(100, 'T', $db)['url'] === 'https://revolut.me/x');

// --- Ligne corrompue en base : statut none, repli sur le modèle
set_setting($db, PAY_LINK_EBOOK, 'http://evil.test');
set_setting($db, PAY_LINK_EBOOK_CENTS, '499');
check('lien fixe corrompu : statut none et repli', pay_ebook_fixed_status($db, 499) === 'none'
    && pay_link_ebook(499, 'EB-0001', $db)['url'] === pay_template($db));
$readLog();

// --- Réinitialisation
pay_save($db, '', '', 499, 'ip');
$log = $readLog();
check('vide : retour à la config', pay_link_paper(3590, 'YV-0001', $db)['url'] === 'https://revolut.me/cfg' && pay_ebook_fixed_status($db, 499) === 'none');
check('vide : alerte', str_contains($log, 'Lien de paiement modifié'));
check('vide : réglages supprimés', get_setting($db, PAY_LINK) === null && get_setting($db, PAY_LINK_EBOOK) === null && get_setting($db, PAY_LINK_EBOOK_CENTS) === null);

// --- Table settings absente
check('table absente : config', pay_link_paper(100, 'T', new PDO('sqlite::memory:'))['url'] === 'https://revolut.me/cfg');

// --- Mails
pay_save($db, 'https://revolut.me/x/{amount}EUR', 'https://checkout.revolut.com/pay/abc', 499, 'ip');
$readLog();
$eo = create_ebook_order($db, ['first_name' => 'Yvonne', 'last_name' => 'Dupont', 'email' => 'y@example.fr'], 499, '198.51.100.1');
$readLog();
mail_ebook_created($eo);
check('mail epub : lien fixe', str_contains($readLog(), 'https://checkout.revolut.com/pay/abc'));
$po = create_order($db, ['qty' => 1, 'first_name' => 'Yvonne', 'last_name' => 'Dupont', 'email' => 'y@example.fr', 'phone' => '0612345678',
    'relay_id' => '1', 'relay_name' => 'Tabac', 'relay_address' => '1 rue', 'relay_postcode' => '75002', 'relay_city' => 'Paris', 'relay_country' => 'FR', 'note' => ''],
    ['qty' => 1, 'unit_cents' => 1000, 'shipping_cents' => 490, 'total_cents' => 1490]);
$readLog();
mail_order_created($po);
check('mail papier : lien résolu', str_contains($readLog(), 'https://revolut.me/x/14.90EUR'));

echo $fails === 0 ? "OK — $count vérifications.\n" : "$fails échec(s) sur $count.\n";
exit($fails === 0 ? 0 : 1);
