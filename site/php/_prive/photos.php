<?php
declare(strict_types=1);

// Photos remplaçables depuis l'admin. Chaque emplacement existe en 2 largeurs (écran normal / haute densité).
// Photo personnalisée : boutique-data/photos/{slot}-{largeur}.{webp|jpg}
// Photo d'origine (générée au build) : _prive/photos/{slot}-{largeur}.webp

const PHOTO_SLOTS = [
    'couverture' => ['label' => 'Couverture du livre', 'hint' => 'Accueil (2 fois) et panier. Format portrait 2:3.'],
    'portrait' => ['label' => "Portrait de l'autrice", 'hint' => 'Section « Vanessa Gandino ». Format portrait 3:4.'],
    'extrait' => ['label' => 'Extrait (4ᵉ de couverture)', 'hint' => 'Fenêtre « Lire un extrait ».'],
    'partenaire' => ['label' => 'Logo partenaire (Le Garde-Manger)', 'hint' => 'Petite vignette carrée, section « Où le trouver ».'],
];
const PHOTO_WIDTHS = [480, 960];
const PHOTO_MAX_BYTES = 15 * 1024 * 1024;
const PHOTO_MAX_PIXELS = 40_000_000;

/** Emplacements fixes (PHOTO_SLOTS) et photos des éléments de l'accueil (img-{id} : voir accueil.php). */
function photo_slot_valid(string $slot): bool
{
    return isset(PHOTO_SLOTS[$slot]) || (bool) preg_match('/^img-[a-z][a-z0-9]{2,11}$/', $slot);
}

/** Date de la photo personnalisée (pour renouveler l'adresse quand elle change), 0 sinon. */
function photo_version(string $slot): int
{
    $f = photo_file($slot, PHOTO_WIDTHS[0]);
    return $f !== null ? (int) filemtime($f) : 0;
}

function photo_dir(): string
{
    return cfg()['data_dir'] . '/photos';
}

/** Fichier à servir pour un emplacement et une largeur : personnalisé s'il existe, sinon l'original. */
function photo_file(string $slot, int $width): ?string
{
    if (!photo_slot_valid($slot)) return null;
    $w = in_array($width, PHOTO_WIDTHS, true) ? $width : PHOTO_WIDTHS[0];
    foreach (['webp', 'jpg'] as $ext) {
        $f = photo_dir() . "/$slot-$w.$ext";
        if (is_file($f)) return $f;
    }
    $default = __DIR__ . "/photos/$slot-$w.webp";
    return is_file($default) ? $default : null;
}

function photo_is_custom(string $slot): bool
{
    return (bool) glob(photo_dir() . "/$slot-*.{webp,jpg}", GLOB_BRACE);
}

/** Copie la photo personnalisée d'un emplacement vers un autre (duplication d'une section). */
function photo_copy(string $from, string $to): void
{
    if (!photo_slot_valid($to)) return;
    photo_reset($to);
    foreach (glob(photo_dir() . "/$from-*.{webp,jpg}", GLOB_BRACE) ?: [] as $f) {
        @copy($f, photo_dir() . '/' . $to . substr(basename($f), strlen($from)));
    }
}

function photo_reset(string $slot): void
{
    foreach (glob(photo_dir() . "/$slot-*.{webp,jpg}", GLOB_BRACE) ?: [] as $f) @unlink($f);
}

/**
 * Valide puis ré-encode l'image envoyée (supprime métadonnées et contenu caché), aux largeurs PHOTO_WIDTHS.
 * Retourne null si tout va bien, sinon un message d'erreur pour l'utilisatrice.
 */
function photo_save(string $slot, array $upload): ?string
{
    if (!photo_slot_valid($slot)) return 'Emplacement inconnu.';
    if (!function_exists('imagecreatetruecolor')) return "Le module GD de PHP n'est pas disponible sur cet hébergement.";
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return match ($upload['error'] ?? 0) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd pour l’hébergement.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier choisi.',
            default => 'Le téléversement a échoué, réessayez.',
        };
    }
    $tmp = (string) $upload['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') return 'Fichier invalide.';
    if (filesize($tmp) > PHOTO_MAX_BYTES) return 'Fichier trop lourd (15 Mo maximum).';

    $info = @getimagesize($tmp);
    if (!$info) return 'Ce fichier n’est pas une image.';
    [$w, $h, $type] = $info;
    if ($w * $h > PHOTO_MAX_PIXELS) return 'Image trop grande (40 mégapixels maximum) : réduisez-la avant envoi.';

    @ini_set('memory_limit', '512M');
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($tmp),
        default => false,
    };
    if (!$src) return 'Format non pris en charge (JPEG, PNG, WebP ou GIF).';

    // Photos de téléphone : appliquer l'orientation EXIF avant d'effacer les métadonnées.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($tmp)['Orientation'] ?? 1);
        $rotated = match ($o) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => null };
        if ($rotated) {
            imagedestroy($src);
            $src = $rotated;
            [$w, $h] = [imagesx($src), imagesy($src)];
        }
    }

    $dir = photo_dir();
    ensure_private_dir($dir);
    $webp = function_exists('imagewebp');
    $ext = $webp ? 'webp' : 'jpg';
    $written = [];
    foreach (PHOTO_WIDTHS as $target) {
        $tw = min($target, $w);
        $th = (int) round($h * $tw / $w);
        $dst = imagecreatetruecolor($tw, $th);
        // Fond crème pour les zones transparentes (PNG), comme le site.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 0xF9, 0xF0, 0xDA));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $out = "$dir/$slot-$target.$ext.tmp";
        $ok = $webp ? imagewebp($dst, $out, 82) : imagejpeg($dst, $out, 85);
        imagedestroy($dst);
        if (!$ok) {
            imagedestroy($src);
            foreach ($written as $f) @unlink($f);
            return "Impossible d'enregistrer l'image sur le serveur.";
        }
        $written[] = $out;
    }
    imagedestroy($src);

    photo_reset($slot);
    foreach ($written as $f) rename($f, substr($f, 0, -4));
    return null;
}
