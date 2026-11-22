<?php
declare(strict_types=1);

// Livre numérique : fabrication de l'exemplaire tatoué d'un acheteur (logique de /telecharger, hors HTTP).

require_once __DIR__ . '/ebook.php';
require_once __DIR__ . '/epub.php';

const EBOOK_TMP_MAX_AGE = 3600;

function ebook_tmp_dir(): string
{
    return cfg()['data_dir'] . '/epub/tmp';
}

/** Crée le dossier temporaire (0700) et supprime les copies de plus d'une heure laissées par un arrêt brutal. */
function ebook_prepare_tmp_dir(string $tmpDir): void
{
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Dossier temporaire impossible à créer.');
    }
    foreach (glob($tmpDir . '/yv-*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - EBOOK_TMP_MAX_AGE) @unlink($f);
    }
}

function ebook_buyer(array $order): array
{
    $when = $order['paid_at'] ?: $order['created_at'];
    $date = '';
    if ($when) {
        $d = new DateTime((string) $when);
        $d->setTimezone(new DateTimeZone('Europe/Paris'));
        $date = $d->format('d/m/Y');
    }
    return [
        'name' => trim($order['first_name'] . ' ' . $order['last_name']),
        'email' => (string) $order['email'],
        'ref' => (string) $order['ref'],
        'date' => $date,
    ];
}

/** Fabrique l'exemplaire tatoué dans $tmpDir et renvoie son chemin (à supprimer par l'appelant). Lève RuntimeException. */
function ebook_build_copy(PDO $db, array $order, string $tmpDir, ?string $master = null): string
{
    $master ??= ebook_master_path();
    if (!is_file($master)) throw new RuntimeException('Epub maître absent.');
    ebook_prepare_tmp_dir($tmpDir);
    $tmp = tempnam($tmpDir, 'yv-');
    if ($tmp === false) throw new RuntimeException('Fichier temporaire impossible à créer.');
    chmod($tmp, 0600);
    try {
        epub_watermark($master, $tmp, ebook_buyer($order), ebook_secret($db));
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
    return $tmp;
}
