<?php
declare(strict_types=1);

// Livre numérique, côté administration : réglages (prix, CGV), dépôt du fichier maître.

require_once __DIR__ . '/ebook.php';
require_once __DIR__ . '/epub.php';

const EBOOK_MASTER_MAX_BYTES = 50 * 1024 * 1024;

/** « 4,99 », « 4.99 », « 4,99 € », « 5 » → centimes ; null si illisible, ≤ 0 ou > 100 €. */
function ebook_parse_price(string $raw): ?int
{
    $s = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', trim($raw));
    if (preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $s, $m) !== 1) return null;
    $cents = (int) $m[1] * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);
    return ($cents > 0 && $cents <= 10000) ? $cents : null;
}

/** Vide ou https://… uniquement. */
function ebook_cgv_valid(string $url): bool
{
    if ($url === '') return true;
    return preg_match('#^https://[^\s"<>]+$#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** Valeur php.ini (« 8M », « 2G », « 512K », « 1048576 », « -1 ») en octets ; 0 = illimité. */
function ebook_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return 0;
    $n = (int) $v;
    return match (strtolower($v[-1])) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Taille maximale du fichier maître : min(50 Mo, upload_max_filesize, post_max_size) ; 0 = limite ignorée. */
function ebook_max_upload_bytes(?string $uploadMax = null, ?string $postMax = null): int
{
    $limits = array_filter([
        EBOOK_MASTER_MAX_BYTES,
        ebook_ini_bytes($uploadMax ?? (string) ini_get('upload_max_filesize')),
        ebook_ini_bytes($postMax ?? (string) ini_get('post_max_size')),
    ], fn(int $b) => $b > 0);
    return min($limits);
}

function ebook_format_size(int $bytes): string
{
    return $bytes >= 1024 * 1024 ? number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' Mo' : number_format(max(1, (int) ceil($bytes / 1024)), 0, ',', ' ') . ' Ko';
}

/**
 * Valide puis installe un fichier déposé comme maître (copie dans un fichier temporaire du dossier cible, puis rename :
 * jamais de maître à moitié écrit). Renvoie [message d'erreur ou null, avertissement ou null].
 * @return array{0: ?string, 1: ?string}
 */
function ebook_install_master(string $tmp, ?string $dest = null, ?int $maxBytes = null): array
{
    $dest ??= ebook_master_path();
    $maxBytes ??= ebook_max_upload_bytes();
    $size = @filesize($tmp);
    if ($size === false || $size === 0) return ['Fichier vide ou illisible.', null];
    if ($size > $maxBytes) return ['Fichier trop lourd : ' . ebook_format_size($maxBytes) . ' maximum.', null];
    $check = epub_validate($tmp);
    if (!$check['ok']) return ['Ce fichier n\'est pas un epub valide : ' . implode(' ', $check['errors']), null];

    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return ['Dossier du livre impossible à créer.', null];
    @chmod($dir, 0700);
    $part = $dir . '/maitre-' . bin2hex(random_bytes(6)) . '.part';
    if (!@copy($tmp, $part)) {
        @unlink($part);
        return ['Enregistrement du fichier impossible.', null];
    }
    @chmod($part, 0600);
    if (!@rename($part, $dest)) {
        @unlink($part);
        return ['Enregistrement du fichier impossible.', null];
    }
    @chmod($dest, 0600);
    $warn = $check['layout'] === 'pre-paginated'
        ? 'Livre en mise en page fixe : la page d\'exemplaire sera placée à la fin.' : null;
    return [null, $warn];
}

/** Titre du maître en place (null si absent ou invalide). */
function ebook_master_info(): ?array
{
    $p = ebook_master_path();
    if (!is_file($p)) return null;
    $v = epub_validate($p);
    return ['title' => $v['title'], 'size' => (int) filesize($p), 'mtime' => (int) filemtime($p), 'ok' => $v['ok'], 'layout' => $v['layout']];
}
