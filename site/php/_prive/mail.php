<?php
declare(strict_types=1);

// Envoi des e-mails : SMTP (SSL 465 ou STARTTLS 587), mail() de l'hébergeur, ou journal seulement.
// Une panne d'envoi ne fait jamais échouer une commande : elle est déjà enregistrée.

function send_mail(string $to, string $subject, string $body): bool
{
    if (preg_match('/[\r\n]/', $to)) {
        error_log('[mail] destinataire refusé (retour à la ligne) : envoi annulé');
        return false;
    }
    $c = cfg();
    try {
        return match ($c['mail_mode']) {
            'smtp' => smtp_send($c, $to, $subject, $body),
            'mail' => mail($to, mime_header($subject), chunk_split(base64_encode($body)), implode("\r\n", mail_headers($c))),
            default => mail_log($to, $subject, $body),
        };
    } catch (Throwable $e) {
        error_log("[mail] échec d'envoi à $to : " . $e->getMessage());
        return false;
    }
}

function mail_log(string $to, string $subject, string $body): bool
{
    error_log("[mail non envoyé — mail_mode=log] À: $to | Objet: $subject\n$body");
    return true;
}

function mime_header(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function mail_headers(array $c): array
{
    return [
        'From: ' . mime_header($c['mail_from_name']) . ' <' . $c['mail_from'] . '>',
        'Reply-To: ' . ($c['admin_email'] !== '' ? $c['admin_email'] : $c['mail_from']),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
}

function smtp_send(array $c, string $to, string $subject, string $body): bool
{
    $port = (int) $c['smtp_port'];
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("connexion $remote : $errstr");
    stream_set_timeout($fp, 15);

    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = static function (?string $line, array $ok) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $reply = $read();
        if (!in_array((int) substr($reply, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP ' . trim($reply ?: 'sans réponse'));
        }
        return $reply;
    };

    try {
        $cmd(null, [220]);
        $ehlo = 'EHLO ' . (parse_url($c['public_base_url'], PHP_URL_HOST) ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($port === 587) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS');
            $cmd($ehlo, [250]);
        }
        if ($c['smtp_user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($c['smtp_user']), [334]);
            $cmd(base64_encode($c['smtp_password']), [235]);
        }
        $cmd('MAIL FROM:<' . $c['mail_from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $domain = substr(strrchr($c['mail_from'], '@') ?: '@localhost', 1);
        $headers = array_merge([
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
        ], mail_headers($c));
        $message = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        $cmd($message . "\r\n.", [250]);
        $cmd('QUIT', [221]);
    } finally {
        fclose($fp);
    }
    return true;
}

// ---------------------------------------------------------------- Modèles

function mail_recap(array $o): string
{
    return implode("\n", [
        "Commande {$o['ref']}",
        "{$o['qty']} × « Les hormones d'Yvonne » — " . eur($o['qty'] * $o['unit_cents']),
        'Livraison Mondial Relay — ' . eur($o['shipping_cents']),
        'Total : ' . eur($o['total_cents']),
        '',
        'Point relais :',
        $o['relay_name'] . ($o['relay_id'] !== '' ? " (n° {$o['relay_id']})" : ''),
        $o['relay_address'],
        "{$o['relay_postcode']} {$o['relay_city']} — {$o['relay_country']}",
    ]);
}

function mail_order_created(array $o): void
{
    $c = cfg();
    $pay = revolut_link($c['revolut_link_template'], $o['total_cents'], $o['ref']);
    send_mail($o['email'], "Votre commande {$o['ref']} — Les hormones d'Yvonne", "Bonjour {$o['first_name']},

Merci pour votre commande ! Elle sera expédiée dès réception du paiement.

" . mail_recap($o) . "

Pour régler " . eur($o['total_cents']) . " avec Revolut :
$pay
Merci d'indiquer la référence {$o['ref']} dans le message du paiement.

Suivre votre commande : " . order_page_url($o) . "

Hormonalement vôtre,
Vanessa");
    notify_admins("Nouvelle commande {$o['ref']} — " . eur($o['total_cents']), mail_recap($o) . "

Client : {$o['first_name']} {$o['last_name']} — {$o['email']} — {$o['phone']}
" . ($o['note'] !== '' ? "Message : {$o['note']}\n" : '') . "
Admin : {$c['public_base_url']}/admin/{$o['ref']}");
}

function mail_order_paid(array $o): void
{
    send_mail($o['email'], "Paiement reçu — commande {$o['ref']}", "Bonjour {$o['first_name']},

Votre paiement de " . eur($o['total_cents']) . " est bien arrivé. Je prépare votre colis avec soin !
Vous recevrez un e-mail avec le numéro de suivi dès l'expédition.

Suivre votre commande : " . order_page_url($o) . "

Vanessa");
}

function mail_order_shipped(array $o): void
{
    $tracking = $o['tracking'] !== ''
        ? "\nNuméro de suivi Mondial Relay : {$o['tracking']}\nSuivi : https://www.mondialrelay.fr/suivi-de-colis/\n"
        : '';
    send_mail($o['email'], "Votre colis est parti — commande {$o['ref']}", "Bonjour {$o['first_name']},

Votre colis est en route vers :
{$o['relay_name']}, {$o['relay_address']}, {$o['relay_postcode']} {$o['relay_city']}
$tracking
Mondial Relay vous préviendra par SMS/e-mail à son arrivée.

Bonne lecture !
Vanessa");
}

// ---------------------------------------------------------------- Livre numérique

function mail_ebook_created(array $o): void
{
    $c = cfg();
    $pay = revolut_link($c['revolut_link_template'], (int) $o['price_cents'], $o['ref']);
    $price = eur((int) $o['price_cents']);
    send_mail($o['email'], "Votre commande {$o['ref']} — Les hormones d'Yvonne (livre numérique)", "Bonjour {$o['first_name']},

Merci pour votre commande du livre numérique « Les hormones d'Yvonne » (format .epub) !
Votre lien de téléchargement sera envoyé à {$o['email']} dès que j'aurai reçu votre paiement (sous 24 h).
Adresse erronée ? Répondez à ce message en indiquant la référence {$o['ref']}.

Commande {$o['ref']} — $price

Pour régler $price avec Revolut :
$pay
Merci d'indiquer la référence {$o['ref']} dans le message du paiement.

Votre accord, enregistré le " . fr_date($o['consent_at'], true) . " :
« {$o['consent_text']} »

Suivre votre commande : " . ebook_order_page_url($o) . "

Hormonalement vôtre,
Vanessa");
    notify_admins("Nouvelle commande numérique {$o['ref']} — $price", "Commande {$o['ref']} — livre numérique — $price

Client : {$o['first_name']} {$o['last_name']} — {$o['email']}
Consentement : " . fr_date($o['consent_at'], true) . " (IP {$o['consent_ip']})

Admin : {$c['public_base_url']}/admin/{$o['ref']}");
}

/** Renvoie false si l'envoi a échoué (la commande est déjà livrée : l'admin peut renvoyer un lien). */
function mail_ebook_delivered(array $o, string $token): bool
{
    return send_mail($o['email'], "Votre livre numérique est prêt — commande {$o['ref']}", "Bonjour {$o['first_name']},

Votre paiement est bien arrivé, merci ! Voici votre lien (il ouvre une page avec un bouton « Télécharger le livre ») :
" . ebook_download_url($token) . "

Ce lien est personnel : il est valable " . EBOOK_DL_DAYS . " jours et permet " . EBOOK_DL_MAX . " téléchargements.
Le fichier est marqué à votre nom, merci de ne pas le partager.
Un souci ? Répondez simplement à ce message.

Bonne lecture !
Vanessa");
}

function mail_ebook_share_alert(array $o, int $ips): void
{
    $c = cfg();
    notify_admins("Alerte partage possible — {$o['ref']}", "Le lien de téléchargement de la commande {$o['ref']} ({$o['first_name']} {$o['last_name']}, {$o['email']}) a été utilisé depuis $ips adresses IP différentes.

C'est peut-être un partage du lien. Vous pouvez le vérifier ou annuler la commande dans l'admin :
{$c['public_base_url']}/admin/{$o['ref']}");
}

// ---------------------------------------------------------------- Adresses de notification

const NOTIFY_SETTING = 'notify_emails';
const NOTIFY_MAX = 3;

/** Analyse une saisie (une adresse par ligne) : liste nettoyée, sans doublon (insensible à la casse). */
function notify_parse(string $raw): array
{
    $emails = [];
    $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strlen($line) > 254 || !filter_var($line, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => "Adresse invalide : $line"];
        }
        $k = strtolower($line);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $emails[] = $line;
    }
    if (count($emails) > NOTIFY_MAX) {
        return ['ok' => false, 'error' => 'Trois adresses au maximum.'];
    }
    return ['ok' => true, 'emails' => $emails];
}

function notify_config_emails(): array
{
    $a = cfg()['admin_email'];
    return $a !== '' ? [$a] : [];
}

/** Adresses effectives : réglage s'il existe, sinon config.php. Toute erreur → config. */
function notify_emails(?PDO $db = null): array
{
    try {
        $raw = get_setting($db ?? db(), NOTIFY_SETTING);
        if ($raw !== null) {
            $p = notify_parse($raw);
            if ($p['ok'] && $p['emails'] !== []) return $p['emails'];
        }
    } catch (Throwable $e) {
        error_log('[mail] lecture notify_emails impossible, repli config.php : ' . $e->getMessage());
    }
    return notify_config_emails();
}

function notify_custom(PDO $db): bool
{
    $raw = get_setting($db, NOTIFY_SETTING);
    if ($raw === null) return false;
    $p = notify_parse($raw);
    return $p['ok'] && $p['emails'] !== [];
}

function notify_save(PDO $db, array $emails, string $ip): void
{
    $p = notify_parse(implode("\n", $emails));
    if (!$p['ok']) throw new InvalidArgumentException($p['error']);
    $emails = $p['emails'];
    $old = notify_emails($db);
    if ($emails === []) {
        notify_reset($db, $ip);
        return;
    }
    set_setting($db, NOTIFY_SETTING, implode("\n", $emails));
    notify_change_alert($old, notify_emails($db), false, $ip);
}

function notify_reset(PDO $db, string $ip): void
{
    $old = notify_emails($db);
    delete_setting($db, NOTIFY_SETTING);
    notify_change_alert($old, notify_emails($db), true, $ip);
}

/** Prévient les anciennes adresses d'un changement (aucun mail si la liste effective est identique). */
function notify_change_alert(array $old, array $new, bool $reset, string $ip): void
{
    $norm = static fn(array $l): array => array_map('strtolower', $l);
    if ($norm($old) === $norm($new)) return;
    $list = $new === [] ? 'aucune' : implode(', ', $new);
    $what = $reset ? 'adresse de config.php : ' . $list : $list;
    $body = "Les adresses qui reçoivent les avis d'achat ont été modifiées.

Nouvelles adresses : $what
Date : " . fr_date(now_iso(), true) . "
IP : $ip

Si ce n'est pas vous, vérifiez l'administration du site.";
    foreach ($old as $to) {
        send_mail($to, "Adresses de notification modifiées — Les hormones d'Yvonne", $body);
    }
}

/** Envoie un avis à chaque adresse de notification ; renvoie le nombre d'envois réussis. */
function notify_admins(string $subject, string $body): int
{
    $n = 0;
    foreach (notify_emails() as $to) {
        if (send_mail($to, $subject, $body)) $n++;
    }
    return $n;
}
