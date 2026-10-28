<?php
declare(strict_types=1);

// En-tête (barre de navigation) et pied de page modifiables depuis l'admin (/admin/entete).
// Le gabarit Astro (Base.astro) repère l'un et l'autre par <!--chrome:header-->…<!--/chrome:header-->
// et <!--chrome:footer-->…<!--/chrome:footer--> : tant qu'une configuration n'est pas enregistrée, le gabarit d'origine reste en place.
// Configurations rangées en JSON dans settings : « site_entete » et « site_pied ».

require_once __DIR__ . '/accueil.php';

const CHROME_HEADER_SETTING = 'site_entete';
const CHROME_FOOTER_SETTING = 'site_pied';
const CHROME_MAX_LINKS = 6;
const CHROME_HEADER_PADS = [8, 12, 16, 22, 28, 36, 48];
const CHROME_FOOTER_PADS = [20, 32, 44, 60, 80];

/** Couleurs de texte proposées (clé → valeur CSS). */
function chrome_text_colors(): array
{
    return [
        'cream' => ['Crème', 'var(--cream)'], 'navy' => ['Bleu foncé', 'var(--navy)'], 'peach' => ['Pêche', 'var(--peach)'],
        'terra' => ['Terracotta', 'var(--terra)'], 'olive' => ['Vert olive', 'var(--olive)'], 'white' => ['Blanc', '#fff'],
    ];
}

/** Icônes possibles pour les liens du pied de page : celles de l'accueil, plus le logo Instagram d'origine. */
function chrome_icons(): array
{
    return ['instagram' => 'Instagram (logo)'] + HOME_ICONS;
}

function chrome_header_default(): array
{
    return [
        'brand' => 'vanessa gandino', 'brand_url' => '/',
        'links' => [
            ['label' => 'le livre', 'url' => '/#livre', 'mobile' => false],
            ['label' => "l'autrice", 'url' => '/#autrice', 'mobile' => false],
        ],
        'cart' => true, 'cart_label' => 'je commande',
        'bg' => 'navy', 'fg' => '', 'accent' => 'peach', 'sticky' => true, 'pad' => 22,
    ];
}

function chrome_footer_default(): array
{
    return [
        'text' => 'Presse, libraires et partenariats — [contact@vanessagandino.fr](mailto:contact@vanessagandino.fr)',
        'links' => [
            ['icon' => 'instagram', 'url' => 'https://www.instagram.com/leshormonesdyvonne/', 'label' => 'Instagram'],
            ['icon' => 'mail', 'url' => 'mailto:contact@vanessagandino.fr', 'label' => 'E-mail'],
        ],
        'bg' => 'olive', 'fg' => '', 'pad' => 44,
    ];
}

function chrome_str($v, int $max): string
{
    return is_string($v) ? mb_substr(trim(preg_replace('/\s+/', ' ', $v)), 0, $max) : '';
}

function chrome_clean_header($in): array
{
    $d = chrome_header_default();
    $in = is_array($in) ? $in : [];
    $links = [];
    foreach (array_slice(is_array($in['links'] ?? null) ? array_values($in['links']) : [], 0, CHROME_MAX_LINKS) as $l) {
        if (!is_array($l)) continue;
        $label = chrome_str($l['label'] ?? '', 40);
        $url = home_clean_url(is_string($l['url'] ?? null) ? $l['url'] : '');
        if ($label !== '' && $url !== '') $links[] = ['label' => $label, 'url' => $url, 'mobile' => in_array($l['mobile'] ?? '', [true, '1', 'on'], true)];
    }
    $bg = is_string($in['bg'] ?? null) && home_color_valid($in['bg']) ? $in['bg'] : $d['bg'];
    $fg = is_string($in['fg'] ?? null) && isset(chrome_text_colors()[$in['fg']]) ? $in['fg'] : '';
    $accent = is_string($in['accent'] ?? null) && isset(chrome_text_colors()[$in['accent']]) ? $in['accent'] : $d['accent'];
    $pad = (int) ($in['pad'] ?? $d['pad']);
    return [
        'brand' => chrome_str($in['brand'] ?? '', 60) ?: $d['brand'],
        'brand_url' => home_clean_url(is_string($in['brand_url'] ?? null) ? $in['brand_url'] : '') ?: '/',
        'links' => $links,
        'cart' => in_array($in['cart'] ?? '', [true, '1', 'on'], true),
        'cart_label' => chrome_str($in['cart_label'] ?? '', 40) ?: $d['cart_label'],
        'bg' => $bg, 'fg' => $fg, 'accent' => $accent,
        'sticky' => in_array($in['sticky'] ?? '', [true, '1', 'on'], true),
        'pad' => in_array($pad, CHROME_HEADER_PADS, true) ? $pad : $d['pad'],
    ];
}

function chrome_clean_footer($in): array
{
    $d = chrome_footer_default();
    $in = is_array($in) ? $in : [];
    $links = [];
    foreach (array_slice(is_array($in['links'] ?? null) ? array_values($in['links']) : [], 0, CHROME_MAX_LINKS) as $l) {
        if (!is_array($l)) continue;
        $icon = (string) ($l['icon'] ?? '');
        $url = home_clean_url(is_string($l['url'] ?? null) ? $l['url'] : '');
        if (isset(chrome_icons()[$icon]) && $url !== '') $links[] = ['icon' => $icon, 'url' => $url, 'label' => chrome_str($l['label'] ?? '', 40) ?: chrome_icons()[$icon]];
    }
    $text = is_string($in['text'] ?? null) ? mb_substr(trim(preg_replace('/\r\n?/', "\n", $in['text'])), 0, 600) : '';
    $bg = is_string($in['bg'] ?? null) && home_color_valid($in['bg']) ? $in['bg'] : $d['bg'];
    $fg = is_string($in['fg'] ?? null) && isset(chrome_text_colors()[$in['fg']]) ? $in['fg'] : '';
    $pad = (int) ($in['pad'] ?? $d['pad']);
    return ['text' => $text, 'links' => $links, 'bg' => $bg, 'fg' => $fg, 'pad' => in_array($pad, CHROME_FOOTER_PADS, true) ? $pad : $d['pad']];
}

/** Configuration enregistrée, ou null : le gabarit d'origine reste alors en place. */
function chrome_load(PDO $db, string $part): ?array
{
    $raw = get_setting($db, $part === 'header' ? CHROME_HEADER_SETTING : CHROME_FOOTER_SETTING);
    if ($raw === null) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    return $part === 'header' ? chrome_clean_header($data) : chrome_clean_footer($data);
}

function chrome_save(PDO $db, string $part, array $cfg): void
{
    $clean = $part === 'header' ? chrome_clean_header($cfg) : chrome_clean_footer($cfg);
    set_setting($db, $part === 'header' ? CHROME_HEADER_SETTING : CHROME_FOOTER_SETTING, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function chrome_reset(PDO $db, string $part): void
{
    delete_setting($db, $part === 'header' ? CHROME_HEADER_SETTING : CHROME_FOOTER_SETTING);
}

/** Couleur de texte effective : choisie, sinon automatique selon le fond. */
function chrome_fg_css(array $c): string
{
    $colors = chrome_text_colors();
    if ($c['fg'] !== '') return $colors[$c['fg']][1];
    return 'var(--' . (home_auto_fg($c['bg']) ?? 'cream') . ')';
}

function chrome_render_header(array $c): string
{
    $accent = chrome_text_colors()[$c['accent']][1];
    $style = '--nav-bg:' . home_color_css($c['bg']) . ';--nav-fg:' . chrome_fg_css($c) . ';--nav-accent:' . $accent . ';padding-top:' . $c['pad'] . 'px;padding-bottom:' . $c['pad'] . 'px;';
    $out = '<nav class="navlite' . ($c['sticky'] ? '' : ' static') . '" aria-label="Navigation principale" style="' . h($style) . '">'
        . '<a class="brand" href="' . h($c['brand_url']) . '">' . h($c['brand']) . '</a>';
    foreach ($c['links'] as $i => $l) {
        $cls = trim(($i === 0 ? 'push ' : '') . ($l['mobile'] ? '' : 'hide-sm'));
        $out .= '<a' . ($cls !== '' ? ' class="' . $cls . '"' : '') . ' href="' . h($l['url']) . '">' . h($l['label']) . '</a>';
    }
    if ($c['cart']) {
        $out .= '<a class="order' . ($c['links'] ? '' : ' push') . '" href="/panier">' . h($c['cart_label']) . ' <span class="cart-badge" data-cart-badge hidden></span></a>';
    }
    return $out . '</nav>';
}

function chrome_render_footer(array $c): string
{
    $style = 'background:' . home_color_css($c['bg']) . ';color:' . chrome_fg_css($c) . ';--foot-fg:' . chrome_fg_css($c) . ';padding-top:' . $c['pad'] . 'px;padding-bottom:' . $c['pad'] . 'px;';
    $out = '<footer class="footer" style="' . h($style) . '">';
    if ($c['text'] !== '') $out .= '<span style="font-size:15px;opacity:.9;">' . home_rich($c['text']) . '</span>';
    if ($c['links']) {
        $out .= '<div class="links">';
        foreach ($c['links'] as $l) {
            $inner = $l['icon'] === 'instagram'
                ? '<img src="/instagram.webp" alt="" width="40" height="40" loading="lazy" decoding="async">'
                : home_icon_svg($l['icon'], 24);
            $out .= '<a href="' . h($l['url']) . '"' . (str_starts_with($l['url'], 'http') ? ' target="_blank" rel="noopener"' : '') . ' aria-label="' . h($l['label']) . '">' . $inner . '</a>';
        }
        $out .= '</div>';
    }
    return $out . '</footer>';
}

/** Remplace l'en-tête et le pied d'origine par ceux de l'admin (s'ils existent), puis retire les repères. */
function chrome_apply(string $html, PDO $db): string
{
    foreach (['header' => 'chrome_render_header', 'footer' => 'chrome_render_footer'] as $part => $render) {
        $cfg = chrome_load($db, $part);
        if ($cfg !== null) {
            $html = (string) preg_replace_callback('/<!--chrome:' . $part . '-->.*?<!--\/chrome:' . $part . '-->/s', fn() => $render($cfg), $html, 1);
        }
    }
    return $html;
}

/** Dernière étape de toutes les pages publiques : en-tête, pied de page, couleurs. */
function site_finish(string $html): string
{
    try {
        $html = chrome_apply($html, db());
    } catch (Throwable $e) {
        error_log('en-tête / pied : ' . $e->getMessage());
    }
    return theme_inject((string) preg_replace('/<!--\/?chrome:(header|footer)-->/', '', $html));
}
