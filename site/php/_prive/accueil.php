<?php
declare(strict_types=1);

// Mise en page de l'accueil modifiable depuis l'admin (/admin/accueil).
// La page est une liste de sections : les 6 sections d'origine (générées au build, repérées par
// <!--sec:id-->…<!--/sec:id-->) et des blocs libres ajoutés dans l'admin (texte, photo, image + texte, colonnes, citation).
// Chaque section peut être déplacée, masquée, recolorée (fond, texte) et agrandie / réduite (espacement).
// La liste est rangée en JSON dans settings, clé « accueil_mise_en_page » ; sans elle, la page d'origine s'affiche telle quelle.

require_once __DIR__ . '/couleurs.php';
require_once __DIR__ . '/textes.php';
require_once __DIR__ . '/photos.php';

const HOME_SETTING = 'accueil_mise_en_page';

/** Sections d'origine, dans l'ordre d'origine. Leur contenu se règle dans « textes » et « photos ». */
const HOME_BUILTIN = [
    'hero' => 'Haut de page (titre, couverture)',
    'livre' => 'Le livre (3 colonnes)',
    'citation' => 'Citation',
    'commander' => 'Commander',
    'autrice' => 'L’autrice',
    'distribution' => 'Où le trouver',
];

/** Couleurs de fond / de texte proposées : nom → valeur CSS, et couleur pour juger du contraste. */
const HOME_COLORS = [
    'cream' => ['Crème', 'var(--cream)', '#f9f0da'],
    'sand' => ['Sable', 'var(--sand)', '#efe0be'],
    'navy' => ['Bleu foncé', 'var(--navy)', '#1b365d'],
    'olive' => ['Vert olive', 'var(--olive)', '#566b25'],
    'terra' => ['Terracotta', 'var(--terra)', '#b67233'],
    'peach' => ['Pêche', 'var(--peach)', '#f1975c'],
    'white' => ['Blanc', '#fff', '#ffffff'],
];

/** Espacement vertical d'une section (haut et bas), en pixels. */
const HOME_PADS = ['xs' => 20, 's' => 40, 'm' => 68, 'l' => 100, 'xl' => 140];
const HOME_PAD_LABELS = ['xs' => 'Très serré', 's' => 'Serré', 'm' => 'Normal', 'l' => 'Aéré', 'xl' => 'Très aéré'];

const HOME_MAX_SECTIONS = 40;

/**
 * Blocs libres. Champs : kind = text | area | select | image | url ; max = longueur maximale.
 * @return array<string, array{label: string, hint: string, fields: array<string, array>}>
 */
function home_block_types(): array
{
    $align = ['kind' => 'select', 'label' => 'Alignement', 'options' => ['left' => 'À gauche', 'center' => 'Centré', 'right' => 'À droite']];
    $btn = [
        'btn_label' => ['kind' => 'text', 'label' => 'Bouton : texte (vide = pas de bouton)', 'max' => 80],
        'btn_url' => ['kind' => 'url', 'label' => 'Bouton : lien (ex. /panier?ajouter=1, #commander, https://…)', 'max' => 300],
    ];
    return [
        'text' => ['label' => 'Texte', 'hint' => 'Un titre, un paragraphe et un bouton.', 'fields' => [
            'title' => ['kind' => 'text', 'label' => 'Titre', 'max' => 300],
            'body' => ['kind' => 'area', 'label' => 'Texte', 'max' => 3000, 'rows' => 6],
            'align' => $align,
        ] + $btn],
        'photo' => ['label' => 'Photo', 'hint' => 'Une grande photo, avec une légende possible.', 'fields' => [
            'image' => ['kind' => 'image', 'label' => 'Photo'],
            'alt' => ['kind' => 'text', 'label' => 'Description de la photo (pour les malvoyants)', 'max' => 200],
            'size' => ['kind' => 'select', 'label' => 'Taille', 'options' => ['s' => 'Petite', 'm' => 'Moyenne', 'l' => 'Grande', 'full' => 'Pleine largeur']],
            'align' => $align,
            'caption' => ['kind' => 'text', 'label' => 'Légende', 'max' => 300],
        ]],
        'split' => ['label' => 'Image + texte', 'hint' => 'Une photo à côté d’un titre, d’un texte et d’un bouton.', 'fields' => [
            'image' => ['kind' => 'image', 'label' => 'Photo'],
            'alt' => ['kind' => 'text', 'label' => 'Description de la photo (pour les malvoyants)', 'max' => 200],
            'side' => ['kind' => 'select', 'label' => 'Photo placée', 'options' => ['left' => 'À gauche', 'right' => 'À droite']],
            'size' => ['kind' => 'select', 'label' => 'Taille de la photo', 'options' => ['s' => 'Petite', 'm' => 'Moyenne', 'l' => 'Grande']],
            'title' => ['kind' => 'text', 'label' => 'Titre', 'max' => 300],
            'body' => ['kind' => 'area', 'label' => 'Texte', 'max' => 3000, 'rows' => 6],
        ] + $btn],
        'columns' => ['label' => 'Colonnes', 'hint' => 'Un titre et jusqu’à 3 colonnes (une colonne vide n’apparaît pas).', 'fields' => [
            'title' => ['kind' => 'text', 'label' => 'Titre de la section', 'max' => 300],
            'c1_title' => ['kind' => 'text', 'label' => 'Colonne 1 : titre', 'max' => 120],
            'c1_text' => ['kind' => 'area', 'label' => 'Colonne 1 : texte', 'max' => 800, 'rows' => 3],
            'c2_title' => ['kind' => 'text', 'label' => 'Colonne 2 : titre', 'max' => 120],
            'c2_text' => ['kind' => 'area', 'label' => 'Colonne 2 : texte', 'max' => 800, 'rows' => 3],
            'c3_title' => ['kind' => 'text', 'label' => 'Colonne 3 : titre', 'max' => 120],
            'c3_text' => ['kind' => 'area', 'label' => 'Colonne 3 : texte', 'max' => 800, 'rows' => 3],
        ]],
        'quote' => ['label' => 'Citation', 'hint' => 'Une grande phrase, avec son auteur et un bouton.', 'fields' => [
            'text' => ['kind' => 'area', 'label' => 'Citation', 'max' => 800, 'rows' => 4],
            'author' => ['kind' => 'text', 'label' => 'Auteur', 'max' => 120],
            'align' => $align,
        ] + $btn],
    ];
}

function home_new_id(): string
{
    return 'b' . bin2hex(random_bytes(3));
}

/** Emplacement de photo d'un bloc libre (voir photo_slot_valid dans photos.php). */
function home_image_slot(string $id): string
{
    return "img-$id";
}

/** 'navy', 'sand'… ou '#rrggbb' → valeur CSS ; null si vide ou invalide. */
function home_color_css(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    if (isset(HOME_COLORS[$v])) return HOME_COLORS[$v][1];
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

function home_color_valid(string $v): bool
{
    return isset(HOME_COLORS[$v]) || (bool) preg_match('/^#[0-9a-f]{6}$/', $v);
}

function home_color_hex(string $v): string
{
    return HOME_COLORS[$v][2] ?? $v;
}

/** Texte crème ou bleu foncé selon la couleur de fond (la plus lisible). */
function home_auto_fg(?string $bg): ?string
{
    if ($bg === null || !home_color_valid($bg)) return null;
    $hex = home_color_hex($bg);
    $onNavy = color_contrast('#f9f0da', $hex);
    $onCream = color_contrast('#1b365d', $hex);
    return $onNavy > $onCream ? 'cream' : 'navy';
}

/** Une section prête à enregistrer, ou null si elle n'a pas de sens. */
function home_clean_section(array $s): ?array
{
    $id = (string) ($s['id'] ?? '');
    $type = (string) ($s['type'] ?? '');
    $isBuiltin = isset(HOME_BUILTIN[$type]);
    if ($isBuiltin ? $id !== $type : (!isset(home_block_types()[$type]) || !preg_match('/^b[0-9a-f]{6}$/', $id))) return null;

    $bg = isset($s['bg']) && is_string($s['bg']) && home_color_valid($s['bg']) ? $s['bg'] : null;
    $fg = isset($s['fg']) && is_string($s['fg']) && in_array($s['fg'], ['cream', 'navy'], true) ? $s['fg'] : null;
    $pad = isset($s['pad']) && is_string($s['pad']) && isset(HOME_PADS[$s['pad']]) ? $s['pad'] : null;
    $out = ['id' => $id, 'type' => $type, 'visible' => ($s['visible'] ?? true) !== false, 'bg' => $bg, 'fg' => $fg, 'pad' => $pad, 'p' => []];
    if (!$isBuiltin) {
        foreach (home_block_types()[$type]['fields'] as $name => $f) {
            if ($f['kind'] === 'image') continue;
            $v = $s['p'][$name] ?? '';
            $out['p'][$name] = home_clean_value($f, is_string($v) ? $v : '');
        }
    }
    return $out;
}

function home_clean_value(array $f, string $v): string
{
    $v = trim(preg_replace('/\r\n?/', "\n", $v));
    if ($f['kind'] === 'select') return isset($f['options'][$v]) ? $v : (string) array_key_first($f['options']);
    if ($f['kind'] === 'url') $v = home_clean_url($v);
    return mb_substr($v, 0, (int) ($f['max'] ?? 300));
}

/** Liens autorisés : page du site, ancre, https, mailto. Le reste (javascript:…) est refusé. */
function home_clean_url(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('#^(/(?!/)|\#|https://|http://|mailto:)#i', $u) && !preg_match('/[\s"<>\x00-\x1f]/', $u)) return $u;
    return '';
}

/** Mise en page actuelle : sections enregistrées, complétées par les sections d'origine manquantes. @return list<array> */
function home_layout(PDO $db): array
{
    $stored = get_setting($db, HOME_SETTING);
    $list = [];
    if ($stored !== null) {
        $data = json_decode($stored, true);
        foreach (is_array($data) ? $data : [] as $s) {
            $c = is_array($s) ? home_clean_section($s) : null;
            if ($c !== null && !isset($list[$c['id']])) $list[$c['id']] = $c;
        }
    }
    foreach (HOME_BUILTIN as $id => $_) {
        $list[$id] ??= ['id' => $id, 'type' => $id, 'visible' => true, 'bg' => null, 'fg' => null, 'pad' => null, 'p' => []];
    }
    return array_values($list);
}

function home_layout_custom(PDO $db): bool
{
    return get_setting($db, HOME_SETTING) !== null;
}

function home_layout_save(PDO $db, array $layout): void
{
    $clean = [];
    foreach (array_slice($layout, 0, HOME_MAX_SECTIONS) as $s) {
        if ($c = home_clean_section($s)) $clean[] = $c;
    }
    set_setting($db, HOME_SETTING, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function home_layout_reset(PDO $db): void
{
    delete_setting($db, HOME_SETTING);
}

/** Attribut style d'une section : fond, texte et espacement choisis. */
function home_style(array $s, bool $auto): string
{
    $css = '';
    if ($bg = home_color_css($s['bg'])) $css .= "background:$bg;";
    $fg = $s['fg'] ?? ($auto ? home_auto_fg($s['bg']) : null);
    if ($fg) $css .= 'color:var(--' . $fg . ');';
    if ($s['pad']) $css .= 'padding-top:' . HOME_PADS[$s['pad']] . 'px;padding-bottom:' . HOME_PADS[$s['pad']] . 'px;';
    return $css;
}

/** Ajoute le style choisi à la balise <section> d'une section d'origine. */
function home_style_builtin(string $fragment, array $s): string
{
    $css = home_style($s, true);
    if ($css === '') return $fragment;
    return (string) preg_replace_callback('/<section\b([^>]*)>/', function (array $m) use ($css): string {
        if (preg_match('/\sstyle="([^"]*)"/', $m[1])) {
            return '<section' . preg_replace('/\sstyle="([^"]*)"/', ' style="$1;' . $css . '"', $m[1], 1) . '>';
        }
        return '<section' . $m[1] . ' style="' . $css . '">';
    }, $fragment, 1);
}

function home_button(array $p, string $class = 'cta'): string
{
    if (($p['btn_label'] ?? '') === '' || ($p['btn_url'] ?? '') === '') return '';
    return '<div class="cta-row blk-cta"><a class="' . $class . '" href="' . h($p['btn_url']) . '">' . h($p['btn_label']) . '</a></div>';
}

function home_image(string $id, array $p, string $sizes): string
{
    $slot = home_image_slot($id);
    if (!photo_is_custom($slot)) return '';
    $src = fn(int $w) => '/photo/' . $slot . '?w=' . $w . '&amp;v=' . photo_version($slot);
    return '<img src="' . $src(480) . '" srcset="' . $src(480) . ' 480w, ' . $src(960) . ' 960w" sizes="' . h($sizes)
        . '" alt="' . h($p['alt'] ?? '') . '" loading="lazy" decoding="async">';
}

/** HTML d'un bloc libre. */
function home_render_block(array $s): string
{
    $p = $s['p'];
    $id = $s['id'];
    $style = home_style($s, true);
    $open = '<section id="' . h($id) . '" class="section blk blk-' . h($s['type']) . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '') . '>';
    $title = fn(string $k = 'title') => ($p[$k] ?? '') !== '' ? '<h2 class="blk-title">' . text_render($p[$k]) . '</h2>' : '';
    $body = fn(string $k = 'body') => ($p[$k] ?? '') !== '' ? '<p class="blk-body">' . text_render($p[$k]) . '</p>' : '';

    switch ($s['type']) {
        case 'text':
            $inner = $title() . $body() . home_button($p);
            return "$open<div class=\"blk-inner align-" . h($p['align'] ?? 'left') . "\">$inner</div></section>";
        case 'photo':
            $img = home_image($id, $p, '(max-width: 760px) 90vw, 760px');
            $cap = ($p['caption'] ?? '') !== '' ? '<figcaption>' . text_render($p['caption']) . '</figcaption>' : '';
            if ($img === '' && $cap === '') return '';
            return "$open<figure class=\"blk-figure sz-" . h($p['size'] ?? 'm') . ' align-' . h($p['align'] ?? 'left') . "\">$img$cap</figure></section>";
        case 'split':
            $img = home_image($id, $p, '(max-width: 760px) 90vw, 480px');
            $fig = $img !== '' ? "<figure class=\"blk-figure\">$img</figure>" : '';
            $text = '<div class="blk-text">' . $title() . $body() . home_button($p) . '</div>';
            return "$open<div class=\"grid-2 blk-split-grid side-" . h($p['side'] ?? 'left') . ' sz-' . h($p['size'] ?? 'm') . ($fig === '' ? ' no-fig' : '') . "\">$fig$text</div></section>";
        case 'columns':
            $cols = '';
            $n = 0;
            foreach ([1, 2, 3] as $i) {
                if (($p["c{$i}_title"] ?? '') === '' && ($p["c{$i}_text"] ?? '') === '') continue;
                $n++;
                $cols .= '<div class="blk-col">' . (($p["c{$i}_title"] ?? '') !== '' ? '<span>' . text_render($p["c{$i}_title"]) . '</span>' : '') . $body("c{$i}_text") . '</div>';
            }
            return "$open{$title()}" . ($n ? "<div class=\"blk-cols n$n\">$cols</div>" : '') . '</section>';
        case 'quote':
            $by = ($p['author'] ?? '') !== '' ? '<p class="blk-author">' . text_render($p['author']) . '</p>' : '';
            $q = ($p['text'] ?? '') !== '' ? '<blockquote class="blk-quote">' . text_render($p['text']) . '</blockquote>' : '';
            return "$open<div class=\"blk-inner align-" . h($p['align'] ?? 'left') . "\">$q$by" . home_button($p) . '</div></section>';
    }
    return '';
}

/**
 * Remplace, dans la page d'accueil générée, l'ensemble des sections d'origine par la mise en page choisie.
 * Sans personnalisation, ou si les repères manquent, la page est rendue telle quelle.
 */
function home_apply(string $html, array $layout): string
{
    if (!preg_match_all('/<!--sec:([a-z]+)-->(.*?)<!--\/sec:\1-->/s', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $html;
    $fragments = [];
    foreach ($m as $hit) $fragments[$hit[1][0]] = $hit[2][0];
    $start = $m[0][0][1];
    $last = $m[count($m) - 1][0];
    $end = $last[1] + strlen($last[0]);

    $out = '';
    foreach ($layout as $s) {
        if (!$s['visible']) continue;
        if (isset(HOME_BUILTIN[$s['type']])) {
            if (isset($fragments[$s['type']])) $out .= home_style_builtin($fragments[$s['type']], $s);
        } else {
            $out .= home_render_block($s);
        }
    }
    return substr($html, 0, $start) . $out . substr($html, $end);
}
