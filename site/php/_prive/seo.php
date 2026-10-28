<?php
declare(strict_types=1);

// Titre et description « web » de l'accueil (ce que Google et les aperçus de liens affichent), modifiables depuis /admin/referencement.
// Rangés dans settings : « seo_titre », « seo_description », « seo_image » (« 0 » = pas d'image dans les aperçus).
// Sans modification, le titre et la description du gabarit (Base.astro) restent en place.

const SEO_TITLE_DEFAULT = "Les hormones d'Yvonne — Vanessa Gandino";
const SEO_DESC_DEFAULT = "Les hormones d'Yvonne — LE livre qui met enfin les vrais mots (et de l'humour) sur ce que les hormones nous font vivre. Par Vanessa Gandino.";
const SEO_TITLE_MAX = 120;
const SEO_DESC_MAX = 400;

function seo_clean(string $v, int $max): string
{
    return mb_substr(trim((string) preg_replace('/\s+/', ' ', $v)), 0, $max);
}

/** Réglages actuels : titre, description (d'origine si vides), image dans les aperçus de liens. */
function seo_load(PDO $db): array
{
    $title = get_setting($db, 'seo_titre');
    $desc = get_setting($db, 'seo_description');
    return [
        'title' => $title !== null && $title !== '' ? $title : SEO_TITLE_DEFAULT,
        'description' => $desc !== null && $desc !== '' ? $desc : SEO_DESC_DEFAULT,
        'image' => get_setting($db, 'seo_image') !== '0',
        'custom' => $title !== null || $desc !== null || get_setting($db, 'seo_image') !== null,
    ];
}

/** Enregistre ; un texte vide ou identique à l'original revient à l'original. */
function seo_save(PDO $db, string $title, string $description, bool $image): void
{
    foreach ([['seo_titre', seo_clean($title, SEO_TITLE_MAX), SEO_TITLE_DEFAULT], ['seo_description', seo_clean($description, SEO_DESC_MAX), SEO_DESC_DEFAULT]] as [$key, $value, $default]) {
        if ($value === '' || $value === $default) delete_setting($db, $key);
        else set_setting($db, $key, $value);
    }
    if ($image) delete_setting($db, 'seo_image');
    else set_setting($db, 'seo_image', '0');
}

function seo_reset(PDO $db): void
{
    foreach (['seo_titre', 'seo_description', 'seo_image'] as $k) delete_setting($db, $k);
}

/** Titre, description et balises d'aperçu (Open Graph) de l'accueil. */
function seo_apply(string $html, PDO $db): string
{
    $s = seo_load($db);
    $base = cfg()['public_base_url'];
    $t = h($s['title']);
    $d = h($s['description']);
    $html = (string) preg_replace_callback('/<title>.*?<\/title>/s', fn() => "<title>$t</title>", $html, 1);
    $html = (string) preg_replace_callback('/<meta name="description" content="[^"]*"\s*\/?>/', fn() => "<meta name=\"description\" content=\"$d\">", $html, 1);
    $og = "<meta property=\"og:type\" content=\"website\"><meta property=\"og:locale\" content=\"fr_FR\"><meta property=\"og:site_name\" content=\"Vanessa Gandino\">"
        . "<meta property=\"og:title\" content=\"$t\"><meta property=\"og:description\" content=\"$d\"><meta property=\"og:url\" content=\"" . h($base . '/') . '">';
    if ($s['image']) {
        $og .= '<meta property="og:image" content="' . h($base . '/photo/couverture?w=960') . '"><meta name="twitter:card" content="summary_large_image">';
    } else {
        $og .= '<meta name="twitter:card" content="summary">';
    }
    $pos = strpos($html, '</head>');
    return $pos === false ? $html : substr_replace($html, $og, $pos, 0);
}
