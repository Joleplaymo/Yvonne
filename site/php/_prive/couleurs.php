<?php
declare(strict_types=1);

// Couleurs du site modifiables depuis l'admin : accueil, panier, commande (livraison) et pages de paiement (facturation).
// Les valeurs d'origine sont celles de src/styles/global.css. Une couleur modifiée est rangée dans la table
// settings sous la clé « couleur:{nom} » et injectée dans chaque page publique sous forme de variables CSS.
// L'administration garde ses couleurs d'origine, pour rester lisible quoi qu'il arrive.

const COLORS = [
    'navy' => ['label' => 'Bleu foncé', 'hint' => 'Fonds foncés (haut de page, citation, autrice, pied de page de la boutique) et textes.', 'default' => '#1b365d'],
    'cream' => ['label' => 'Crème', 'hint' => 'Fond des pages et texte sur les fonds foncés.', 'default' => '#f9f0da'],
    'olive' => ['label' => 'Vert olive', 'hint' => 'Boutons, bandeau du bas de page, numéros d’étapes.', 'default' => '#566b25'],
    'terra' => ['label' => 'Terracotta', 'hint' => 'Liens, titres de colonnes, petits accents.', 'default' => '#b67233'],
    'peach' => ['label' => 'Pêche', 'hint' => 'Accents sur fond bleu (mot-dièse, signature).', 'default' => '#f1975c'],
    'danger' => ['label' => 'Rouge d’erreur', 'hint' => 'Messages d’erreur des formulaires de commande.', 'default' => '#b3261e'],
];

/** Couleurs qui se déduisent d'une autre quand elle change : nuance au survol, fonds, textes atténués. */
const COLOR_DERIVED = [
    'olive' => ['--olive-dark' => 'color-mix(in srgb, var(--olive) 78%, #000)'],
    'terra' => ['--terra-dark' => 'color-mix(in srgb, var(--terra) 80%, #000)'],
    'cream' => [
        '--cream-2' => 'color-mix(in srgb, var(--cream) 92%, #000)',
        '--sand' => 'color-mix(in srgb, var(--cream) 88%, #000)',
        '--muted-on-navy' => 'color-mix(in srgb, var(--cream) 86%, var(--navy))',
        '--faint-on-navy' => 'color-mix(in srgb, var(--cream) 70%, var(--navy))',
    ],
    'navy' => [
        '--muted-on-navy' => 'color-mix(in srgb, var(--cream) 86%, var(--navy))',
        '--faint-on-navy' => 'color-mix(in srgb, var(--cream) 70%, var(--navy))',
    ],
];

function color_valid(string $v): bool
{
    return (bool) preg_match('/^#[0-9a-f]{6}$/', $v);
}

/** Couleurs personnalisées : nom → #rrggbb. */
function color_overrides(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT key, value FROM settings WHERE key LIKE 'couleur:%'") as $row) {
        $name = substr((string) $row['key'], 8);
        if (isset(COLORS[$name]) && color_valid((string) $row['value'])) $out[$name] = (string) $row['value'];
    }
    return $out;
}

/** Enregistre une couleur ; vide ou identique à l'originale = retour à l'originale. Retourne un message d'erreur ou null. */
function color_save(PDO $db, string $name, string $value): ?string
{
    if (!isset(COLORS[$name])) return 'Couleur inconnue.';
    $value = strtolower(trim($value));
    if ($value === '' || $value === COLORS[$name]['default']) {
        delete_setting($db, "couleur:$name");
        return null;
    }
    if (!color_valid($value)) return 'Couleur invalide (attendu : #rrggbb).';
    set_setting($db, "couleur:$name", $value);
    return null;
}

/** Bloc <style> à ajouter aux pages publiques ; chaîne vide si rien n'est personnalisé. */
function theme_style(array $overrides): string
{
    if (!$overrides) return '';
    $vars = [];
    foreach ($overrides as $name => $hex) {
        $vars["--$name"] = $hex;
        foreach (COLOR_DERIVED[$name] ?? [] as $k => $v) $vars[$k] = $v;
    }
    $css = '';
    foreach ($vars as $k => $v) $css .= "$k:$v;";
    return "<style id=\"theme\">:root{{$css}}</style>";
}

/** Insère la feuille de couleurs juste avant </head> (donc après la feuille de style du site). */
function theme_inject(string $html): string
{
    try {
        $style = theme_style(color_overrides(db()));
    } catch (Throwable $e) {
        error_log('couleurs : ' . $e->getMessage());
        return $html;
    }
    if ($style === '') return $html;
    $pos = strpos($html, '</head>');
    return $pos === false ? $html : substr_replace($html, $style, $pos, 0);
}

function color_luminance(string $hex): float
{
    $c = array_map(function (string $p): float {
        $v = hexdec($p) / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function color_contrast(string $a, string $b): float
{
    [$x, $y] = [color_luminance($a), color_luminance($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** Associations de couleurs trop peu lisibles, en français. @return list<string> */
function color_warnings(array $overrides): array
{
    $c = array_map(fn($x) => $x['default'], COLORS);
    $c = array_replace($c, $overrides);
    $pairs = [
        ['cream', 'navy', 4.5, 'Le texte crème sur le bleu foncé'],
        ['navy', 'cream', 4.5, 'Le texte bleu foncé sur le fond crème'],
        ['cream', 'olive', 4.5, 'Le texte des boutons (crème sur vert olive)'],
        ['peach', 'navy', 3.0, 'La pêche sur le bleu foncé'],
        ['terra', 'cream', 3.0, 'Les liens terracotta sur le fond crème'],
    ];
    $out = [];
    foreach ($pairs as [$fg, $bg, $min, $what]) {
        if (color_contrast($c[$fg], $c[$bg]) < $min) $out[] = "$what manque de contraste : difficile à lire.";
    }
    return $out;
}
