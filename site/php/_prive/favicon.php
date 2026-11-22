<?php
declare(strict_types=1);

// Favicon (petite icône de l'onglet et des résultats Google) modifiable depuis l'admin.
// Image envoyée → recadrée au carré → PNG aux tailles FAVICON_SIZES dans boutique-data/favicon/{taille}.png.
// Sans favicon personnalisé, le favicon d'origine du gabarit (/favicon.svg) reste en place.

const FAVICON_SIZES = [48, 180, 192];
const FAVICON_MAX_BYTES = 5 * 1024 * 1024;

function favicon_dir(): string
{
    return cfg()['data_dir'] . '/favicon';
}

function favicon_file(int $size): ?string
{
    if (!in_array($size, FAVICON_SIZES, true)) return null;
    $f = favicon_dir() . "/$size.png";
    return is_file($f) ? $f : null;
}

function favicon_is_custom(): bool
{
    return favicon_file(FAVICON_SIZES[0]) !== null;
}

/** Date du favicon personnalisé (pour renouveler l'adresse quand il change), 0 sinon. */
function favicon_version(): int
{
    $f = favicon_file(FAVICON_SIZES[0]);
    return $f !== null ? (int) filemtime($f) : 0;
}

function favicon_reset(): void
{
    foreach (FAVICON_SIZES as $s) {
        if ($f = favicon_file($s)) @unlink($f);
    }
}

/** Retourne null si tout va bien, sinon un message d'erreur pour l'utilisatrice. */
function favicon_save(array $upload): ?string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return "Le module GD de PHP n'est pas disponible sur cet hébergement.";
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return match ($upload['error'] ?? 0) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd pour l’hébergement.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier choisi.',
            default => 'Le téléversement a échoué, réessayez.',
        };
    }
    $tmp = (string) $upload['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') return 'Fichier invalide.';
    if (filesize($tmp) > FAVICON_MAX_BYTES) return 'Fichier trop lourd (5 Mo maximum).';

    $info = @getimagesize($tmp);
    if (!$info) return 'Ce fichier n’est pas une image (JPEG, PNG, WebP ou GIF).';
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1 || $w * $h > 25_000_000) return 'Image trop grande : réduisez-la avant envoi.';

    @ini_set('memory_limit', '256M');
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($tmp),
        default => false,
    };
    if (!$src) return 'Format non pris en charge (JPEG, PNG, WebP ou GIF).';

    // Recadrage carré au centre.
    $side = min($w, $h);
    $sx = intdiv($w - $side, 2);
    $sy = intdiv($h - $side, 2);

    $dir = favicon_dir();
    ensure_private_dir($dir);
    $written = [];
    foreach (FAVICON_SIZES as $size) {
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        $out = "$dir/$size.png.tmp";
        $ok = imagepng($dst, $out, 9);
        imagedestroy($dst);
        if (!$ok) {
            imagedestroy($src);
            foreach ($written as $f) @unlink($f);
            return "Impossible d'enregistrer l'icône sur le serveur.";
        }
        $written[] = $out;
    }
    imagedestroy($src);

    foreach ($written as $f) rename($f, substr($f, 0, -4));
    return null;
}

/** Balises <link> du favicon personnalisé (vide sans favicon personnalisé). */
function favicon_tags(): string
{
    if (!favicon_is_custom()) return '';
    $v = favicon_version();
    return '<link rel="icon" href="/favicon/48.png?v=' . $v . '" type="image/png" sizes="48x48">'
        . '<link rel="icon" href="/favicon/192.png?v=' . $v . '" type="image/png" sizes="192x192">'
        . '<link rel="apple-touch-icon" href="/favicon/180.png?v=' . $v . '">';
}

/** Remplace le favicon d'origine du gabarit par le favicon personnalisé, s'il existe. */
function favicon_apply(string $html): string
{
    $tags = favicon_tags();
    if ($tags === '') return $html;
    $html = (string) preg_replace('#<link\b[^>]*\brel="(?:shortcut )?icon"[^>]*>#i', '', $html);
    $pos = strpos($html, '</head>');
    return $pos === false ? $html : substr_replace($html, $tags, $pos, 0);
}
