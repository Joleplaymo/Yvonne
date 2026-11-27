<?php
declare(strict_types=1);
// Tests des adresses de notification d'achat. Lancement : npm run test:php (Docker, PHP 8.3).

putenv('DATA_DIR=' . sys_get_temp_dir() . '/yv-test-notif-' . getmypid());
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

cfg_override(['mail_mode' => 'log', 'public_base_url' => 'https://exemple.test', 'admin_email' => 'admin@exemple.test']);
$logFile = sys_get_temp_dir() . '/yv-test-notif-mail-' . getmypid() . '.log';
ini_set('error_log', $logFile);
$readLog = static function () use ($logFile): string {
    $s = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    @unlink($logFile);
    return $s;
};

// --- Analyse
$p = notify_parse("a@x.fr\n\n  B@y.fr \nb@Y.fr");
check('parse : trim, vides et doublons', $p['ok'] === true && $p['emails'] === ['a@x.fr', 'B@y.fr']);
$p = notify_parse("a@x.fr\npas-un-mail");
check('parse : adresse invalide', $p['ok'] === false && str_contains($p['error'], 'pas-un-mail'));
check('parse : 4 adresses refusées', notify_parse("a@x.fr\nb@x.fr\nc@x.fr\nd@x.fr")['ok'] === false);
check('parse : vide accepté', notify_parse('') === ['ok' => true, 'emails' => []]);
check('parse : injection d\'en-tête refusée', notify_parse("a@x.fr\r\nBcc: z@z.fr")['ok'] === false);

// --- Réglage
$db = db();
check('sans réglage : config', notify_emails($db) === ['admin@exemple.test'] && notify_custom($db) === false);
$readLog();
notify_save($db, ['n1@x.fr', 'n2@x.fr'], '203.0.113.9');
$log = $readLog();
check('save : liste enregistrée', notify_emails($db) === ['n1@x.fr', 'n2@x.fr'] && notify_custom($db) === true);
check('save : alerte aux anciennes adresses',
    str_contains($log, 'À: admin@exemple.test') && str_contains($log, "Objet: Adresses de notification modifiées — Les hormones d'Yvonne")
    && str_contains($log, 'n1@x.fr') && str_contains($log, '203.0.113.9'));
notify_save($db, ['N1@x.fr', 'n2@x.fr'], '203.0.113.9');
check('save inchangé : pas d\'alerte', !str_contains($readLog(), 'modifiées'));

// --- Avis d'achat
$o = create_ebook_order($db, ['first_name' => 'Yvonne', 'last_name' => 'Dupont', 'email' => 'y@example.fr'], 499, '198.51.100.1');
$readLog();
mail_ebook_created($o);
$log = $readLog();
check('mail commande : envoyé aux deux adresses, pas à la config',
    str_contains($log, 'À: N1@x.fr') && str_contains($log, 'À: n2@x.fr') && !str_contains($log, 'À: admin@exemple.test'));
mail_ebook_share_alert($o, 4);
$log = $readLog();
check('alerte partage : mêmes destinataires',
    str_contains($log, 'À: N1@x.fr') && str_contains($log, 'À: n2@x.fr') && !str_contains($log, 'À: admin@exemple.test'));
check('notify_admins : nombre d\'envois', notify_admins('s', 'b') === 2);
$readLog();

// --- Garde d'en-tête
check('send_mail : CR/LF refusé', send_mail("a@x.fr\r\nBcc: z@z.fr", 's', 'b') === false);
$log = $readLog();
check('send_mail : rien au journal pour ce destinataire', !str_contains($log, 'À: a@x.fr') && !str_contains($log, 'Bcc'));

// --- Reply-To inchangé
check('Reply-To reste admin_email', in_array('Reply-To: admin@exemple.test', mail_headers(cfg()), true));

// --- Réinitialisation
notify_reset($db, 'ip');
$log = $readLog();
check('reset : retour à la config', notify_emails($db) === ['admin@exemple.test'] && notify_custom($db) === false);
check('reset : alerte envoyée aux anciennes adresses', str_contains($log, 'À: N1@x.fr') && str_contains($log, 'adresse de config.php : admin@exemple.test'));
notify_reset($db, 'ip');
check('reset sans changement : pas d\'alerte', !str_contains($readLog(), 'modifiées'));

check('table settings absente : config', notify_emails(new PDO('sqlite::memory:')) === ['admin@exemple.test']);

echo $fails === 0 ? "OK — $count vérifications.\n" : "$fails échec(s) sur $count.\n";
exit($fails === 0 ? 0 : 1);
