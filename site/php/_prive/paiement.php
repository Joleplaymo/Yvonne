<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

// Lien de paiement Revolut réglable depuis l'admin (table settings), avec repli sur config.php.
// Une panne de lecture du réglage ne doit jamais priver le client d'un lien de paiement.

const PAY_LINK = 'pay_link';
const PAY_LINK_EBOOK = 'pay_link_ebook';
const PAY_LINK_EBOOK_CENTS = 'pay_link_ebook_cents';
const PAY_LINK_MAX = 300;

/**
 * Valide un lien Revolut. Renvoie null si valide (ou vide), sinon un message d'erreur.
 * $fixed : lien à montant fixe, qui ne contient aucun jeton entre accolades.
 */
function pay_link_check(string $link, bool $fixed): ?string
{
    if ($link === '') return null;
    if (strlen($link) > PAY_LINK_MAX) return 'Le lien est trop long (300 caractères au maximum).';
    if (!preg_match('~^https://(?:revolut\.me|(?:[a-z0-9-]+\.)*revolut\.com)(?:[/?#][!-}\~]*)?$~Di', $link)) {
        return 'Le lien doit commencer par https:// et pointer vers revolut.me ou revolut.com, sans espace.';
    }
    if ($fixed) {
        if (str_contains($link, '{') || str_contains($link, '}')) {
            return 'Un lien à montant fixe ne contient ni {amount} ni {ref}.';
        }
        return null;
    }
    $rest = str_replace(['{amount}', '{ref}'], '', $link);
    if (str_contains($rest, '{') || str_contains($rest, '}')) {
        return 'Seuls {amount} et {ref} sont admis entre accolades.';
    }
    return null;
}

/** Modèle de lien effectif : réglage s'il est valide, sinon config.php. Toute erreur → config. */
function pay_template(?PDO $db = null): string
{
    try {
        $v = get_setting($db ?? db(), PAY_LINK);
        if ($v !== null && $v !== '' && pay_link_check($v, false) === null) return $v;
    } catch (Throwable $e) {
        error_log('[paiement] lecture du lien impossible, repli config.php : ' . $e->getMessage());
    }
    return cfg()['revolut_link_template'];
}

/** @return array{url: string, prefilled: bool} */
function pay_link_paper(int $cents, string $ref, ?PDO $db = null): array
{
    $template = pay_template($db);
    return ['url' => revolut_link($template, $cents, $ref), 'prefilled' => str_contains($template, '{amount}')];
}

/** Lien fixe epub si présent et fait pour ce prix, sinon comme le papier. @return array{url: string, prefilled: bool} */
function pay_link_ebook(int $cents, string $ref, ?PDO $db = null): array
{
    try {
        $db ??= db();
        $fixed = get_setting($db, PAY_LINK_EBOOK);
        $stored = get_setting($db, PAY_LINK_EBOOK_CENTS);
        if ($fixed !== null && $fixed !== '' && pay_link_check($fixed, true) === null
            && $stored !== null && (string) $cents === $stored) {
            return ['url' => $fixed, 'prefilled' => true];
        }
    } catch (Throwable $e) {
        error_log('[paiement] lecture du lien epub impossible, repli : ' . $e->getMessage());
    }
    return pay_link_paper($cents, $ref, $db);
}

/** 'none' : pas de lien fixe ; 'ok' : fait pour le prix actuel ; 'stale' : prix changé depuis. */
function pay_ebook_fixed_status(PDO $db, int $currentCents): string
{
    $fixed = get_setting($db, PAY_LINK_EBOOK);
    if ($fixed === null || $fixed === '' || pay_link_check($fixed, true) !== null) return 'none';
    return get_setting($db, PAY_LINK_EBOOK_CENTS) === (string) $currentCents ? 'ok' : 'stale';
}

/**
 * Enregistre les liens (vide = suppression, retour à config.php). InvalidArgumentException si invalide :
 * rien n'est enregistré. Les centimes du lien fixe ne sont réécrits que si le lien change : un lien déjà
 * enregistré reste « périmé » après un changement de prix tant qu'un nouveau lien n'est pas collé.
 */
function pay_save(PDO $db, string $link, string $ebookLink, int $ebookCents, string $ip): void
{
    $link = trim($link);
    $ebookLink = trim($ebookLink);
    if (($e = pay_link_check($link, false)) !== null) throw new InvalidArgumentException($e);
    if (($e = pay_link_check($ebookLink, true)) !== null) throw new InvalidArgumentException($e);

    $old = ['paper' => pay_template($db), 'ebook' => trim((string) get_setting($db, PAY_LINK_EBOOK))];

    // Transaction : le lien fixe et ses centimes sont écrits ensemble ou pas du tout.
    $db->beginTransaction();
    try {
        if ($link === '') delete_setting($db, PAY_LINK);
        else set_setting($db, PAY_LINK, $link);

        if ($ebookLink === '') {
            delete_setting($db, PAY_LINK_EBOOK);
            delete_setting($db, PAY_LINK_EBOOK_CENTS);
        } elseif ($ebookLink !== $old['ebook']) {
            set_setting($db, PAY_LINK_EBOOK, $ebookLink);
            set_setting($db, PAY_LINK_EBOOK_CENTS, (string) $ebookCents);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $new = ['paper' => pay_template($db), 'ebook' => trim((string) get_setting($db, PAY_LINK_EBOOK))];
    // notify_admins() est défini dans mail.php : l'appelant doit l'avoir chargé.
    if ($old !== $new) pay_change_alert($old, $new, $ip);
}

/** Prévient les adresses de notification d'un changement de lien. */
function pay_change_alert(array $old, array $new, string $ip): void
{
    $none = static fn(string $s): string => $s === '' ? '(aucun)' : $s;
    notify_admins("Lien de paiement modifié — Les hormones d'Yvonne", "Les liens de paiement Revolut ont été modifiés.

Lien Revolut
  Avant : {$none($old['paper'])}
  Après : {$none($new['paper'])}

Lien epub à montant fixe
  Avant : {$none($old['ebook'])}
  Après : {$none($new['ebook'])}

Date : " . fr_date(now_iso(), true) . "
IP : $ip

Si ce n'est pas vous, vérifiez l'administration du site.");
}
