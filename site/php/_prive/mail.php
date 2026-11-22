<?php
declare(strict_types=1);

// Envoi des e-mails : SMTP (SSL 465 ou STARTTLS 587), mail() de l'hébergeur, ou journal seulement.
// Une panne d'envoi ne fait jamais échouer une commande : elle est déjà enregistrée.

function send_mail(string $to, string $subject, string $body): bool
{
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
    if ($c['admin_email'] !== '') {
        send_mail($c['admin_email'], "Nouvelle commande {$o['ref']} — " . eur($o['total_cents']), mail_recap($o) . "

Client : {$o['first_name']} {$o['last_name']} — {$o['email']} — {$o['phone']}
" . ($o['note'] !== '' ? "Message : {$o['note']}\n" : '') . "
Admin : {$c['public_base_url']}/admin/{$o['ref']}");
    }
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
    if ($c['admin_email'] !== '') {
        send_mail($c['admin_email'], "Nouvelle commande numérique {$o['ref']} — $price", "Commande {$o['ref']} — livre numérique — $price

Client : {$o['first_name']} {$o['last_name']} — {$o['email']}
Consentement : " . fr_date($o['consent_at'], true) . " (IP {$o['consent_ip']})

Admin : {$c['public_base_url']}/admin/{$o['ref']}");
    }
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
    if ($c['admin_email'] === '') return;
    send_mail($c['admin_email'], "Alerte partage possible — {$o['ref']}", "Le lien de téléchargement de la commande {$o['ref']} ({$o['first_name']} {$o['last_name']}, {$o['email']}) a été utilisé depuis $ips adresses IP différentes.

C'est peut-être un partage du lien. Vous pouvez le vérifier ou annuler la commande dans l'admin :
{$c['public_base_url']}/admin/{$o['ref']}");
}
