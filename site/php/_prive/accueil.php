<?php
declare(strict_types=1);

// Page d'accueil entièrement paramétrable depuis l'admin (/admin/accueil).
//
// La page est une suite de SECTIONS. Une section contient des LIGNES, une ligne des COLONNES, une colonne des ÉLÉMENTS
// (titre, texte, image, boutons, icône, image + légende, espace). Tout se déplace, s'ajoute, se supprime et se règle
// (couleurs, tailles, espacements, alignements).
// La mise en page est rangée en JSON dans settings (clé « accueil_mise_en_page »). Tant qu'elle n'a pas été enregistrée,
// la page d'origine (home_default_layout) s'affiche, avec les textes de /admin/textes.
// Un élément peut pointer vers un texte du catalogue (clé) : il suit alors /admin/textes tant qu'on ne l'a pas réécrit ici.

require_once __DIR__ . '/couleurs.php';
require_once __DIR__ . '/textes.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/ebook.php';

const HOME_SETTING = 'accueil_mise_en_page';
const HOME_LIMITS = ['sections' => 40, 'rows' => 12, 'cols' => 6, 'els' => 15];

const HOME_SIZES = [13, 14, 15, 16, 17, 18, 19, 20, 22, 24, 26, 30, 34, 38, 44, 52, 64, 72, 82];
const HOME_SPACES = [0, 6, 8, 12, 14, 16, 18, 20, 24, 26, 30, 34, 36, 40, 44, 52, 64, 80];
const HOME_GAPS = [0, 16, 18, 24, 32, 40, 52, 56, 64];
const HOME_PADS = [0, 20, 40, 60, 68, 72, 100, 140];

/** Fonds proposés : nom → [libellé, valeur CSS, code pour juger du contraste]. */
const HOME_COLORS = [
    'cream' => ['Crème', 'var(--cream)', '#f9f0da'],
    'sand' => ['Sable', 'var(--sand)', '#efe0be'],
    'navy' => ['Bleu foncé', 'var(--navy)', '#1b365d'],
    'olive' => ['Vert olive', 'var(--olive)', '#566b25'],
    'terra' => ['Terracotta', 'var(--terra)', '#b67233'],
    'peach' => ['Pêche', 'var(--peach)', '#f1975c'],
    'white' => ['Blanc', '#fff', '#ffffff'],
];

/** Couleurs de texte proposées : nom → [libellé, valeur CSS]. */
const HOME_TEXT_COLORS = [
    '' => ['Automatique', null],
    'cream' => ['Crème', 'var(--cream)'],
    'navy' => ['Bleu foncé', 'var(--navy)'],
    'peach' => ['Pêche', 'var(--peach)'],
    'terra' => ['Terracotta', 'var(--terra)'],
    'olive' => ['Vert olive', 'var(--olive)'],
    'muted' => ['Crème atténué', 'var(--muted-on-navy)'],
    'faint' => ['Crème pâle', 'var(--faint-on-navy)'],
];

const HOME_ICONS = [
    'package' => 'Colis', 'truck' => 'Camion', 'globe' => 'Globe', 'store' => 'Boutique', 'book-open' => 'Livre ouvert',
    'mail' => 'Enveloppe', 'heart' => 'Cœur', 'star' => 'Étoile', 'gift' => 'Cadeau', 'sparkles' => 'Étincelles',
    'users' => 'Personnes', 'calendar' => 'Calendrier', 'phone' => 'Téléphone', 'map-pin' => 'Repère', 'shopping-cart' => 'Panier',
    'quote' => 'Guillemets', 'feather' => 'Plume', 'flower-2' => 'Fleur', 'smile' => 'Sourire', 'check' => 'Coche',
];

function home_new_id(): string
{
    return 'b' . bin2hex(random_bytes(3));
}

function home_id_valid(string $id): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9]{2,11}$/', $id);
}

/** Libellés « 24 px » pour une liste de nombres. */
function home_px_options(array $values, string $zero = 'Aucun'): array
{
    $o = [];
    foreach ($values as $v) $o[$v] = $v === 0 ? $zero : "$v px";
    return $o;
}

/** Types d'éléments et réglages de chacun. kind : text | area | select | check | url | image. @return array<string, array> */
function home_el_specs(): array
{
    static $specs = null;
    if ($specs !== null) return $specs;
    $align = ['kind' => 'select', 'label' => 'Alignement', 'options' => ['left' => 'À gauche', 'center' => 'Centré', 'right' => 'À droite'], 'default' => 'left'];
    $size = fn(string $def) => ['kind' => 'select', 'label' => 'Taille du texte', 'options' => home_px_options(HOME_SIZES), 'default' => $def];
    $color = ['kind' => 'select', 'label' => 'Couleur du texte', 'options' => array_map(fn($c) => $c[0], HOME_TEXT_COLORS), 'default' => ''];
    $mt = ['kind' => 'select', 'label' => 'Espace au-dessus', 'options' => home_px_options(HOME_SPACES), 'default' => '16'];
    $btn = fn(int $n) => [
        "b{$n}_label" => ['kind' => 'text', 'label' => "Bouton $n : texte (vide = pas de bouton)", 'max' => 80],
        "b{$n}_url" => ['kind' => 'url', 'label' => "Bouton $n : lien (/panier?ajouter=1, #commander, https://…)", 'max' => 300],
        "b{$n}_style" => ['kind' => 'select', 'label' => "Bouton $n : style", 'options' => ['solid' => 'Plein (vert)', 'terra' => 'Plein (terracotta)', 'outline' => 'Contour'], 'default' => $n === 1 ? 'solid' : 'outline'],
    ];
    $specs = [
        'heading' => ['label' => 'Titre', 'fields' => [
            'text' => ['kind' => 'area', 'label' => 'Texte', 'max' => 400, 'rows' => 2],
            'level' => ['kind' => 'select', 'label' => 'Niveau', 'options' => ['h1' => 'Titre principal', 'h2' => 'Titre de section', 'h3' => 'Sous-titre'], 'default' => 'h2'],
            'size' => $size('38'),
            'big' => ['kind' => 'check', 'label' => 'Style affiche (lettres serrées, très gras)'],
            'color' => $color, 'align' => $align, 'mt' => $mt,
        ]],
        'text' => ['label' => 'Paragraphe', 'fields' => [
            'text' => ['kind' => 'area', 'label' => 'Texte (**gras**, [lien](https://…))', 'max' => 3000, 'rows' => 4],
            'size' => $size('17'),
            'weight' => ['kind' => 'select', 'label' => 'Épaisseur', 'options' => ['400' => 'Normal', '600' => 'Demi-gras', '700' => 'Gras', '800' => 'Très gras', '900' => 'Noir'], 'default' => '400'],
            'italic' => ['kind' => 'check', 'label' => 'Italique'],
            'color' => $color, 'align' => $align,
            'maxw' => ['kind' => 'select', 'label' => 'Largeur maximale', 'options' => ['' => 'Pleine largeur', '26ch' => 'Très étroite', '34ch' => 'Étroite', '46ch' => 'Moyenne', '62ch' => 'Large'], 'default' => ''],
            'pos' => ['kind' => 'select', 'label' => 'Position du bloc', 'options' => ['left' => 'À gauche', 'center' => 'Au centre', 'right' => 'À droite'], 'default' => 'left'],
            'mt' => $mt,
        ]],
        'image' => ['label' => 'Image', 'fields' => [
            'image' => ['kind' => 'image', 'label' => 'Photo'],
            'alt' => ['kind' => 'text', 'label' => 'Description de la photo (pour les malvoyants)', 'max' => 200],
            'width' => ['kind' => 'select', 'label' => 'Largeur', 'options' => ['100' => '100 %', '90' => '90 %', '80' => '80 %', '70' => '70 %', '60' => '60 %', '50' => '50 %', '40' => '40 %', '30' => '30 %'], 'default' => '100'],
            'ratio' => ['kind' => 'select', 'label' => 'Format (recadrage)', 'options' => ['auto' => 'Format de la photo', '2/3' => 'Portrait 2:3', '3/4' => 'Portrait 3:4', '1/1' => 'Carré', '4/3' => 'Paysage 4:3', '16/9' => 'Large 16:9'], 'default' => 'auto'],
            'radius' => ['kind' => 'select', 'label' => 'Coins', 'options' => ['0' => 'Droits', '8' => 'Légèrement arrondis', '12' => 'Arrondis', '24' => 'Très arrondis', '999' => 'Ronds'], 'default' => '12'],
            'shadow' => ['kind' => 'select', 'label' => 'Ombre', 'options' => ['none' => 'Aucune', 'soft' => 'Douce', 'strong' => 'Marquée'], 'default' => 'none'],
            'align' => $align, 'mt' => $mt,
        ]],
        'buttons' => ['label' => 'Boutons', 'fields' => $btn(1) + $btn(2) + $btn(3) + ['align' => $align, 'mt' => $mt]],
        'icon' => ['label' => 'Icône (avec texte)', 'fields' => [
            'icon' => ['kind' => 'select', 'label' => 'Icône', 'options' => HOME_ICONS, 'default' => 'package'],
            'look' => ['kind' => 'select', 'label' => 'Présentation', 'options' => ['box' => 'Carré vert', 'circle' => 'Pastille ronde', 'plain' => 'Icône simple'], 'default' => 'box'],
            'text' => ['kind' => 'area', 'label' => 'Texte (peut rester vide)', 'max' => 400, 'rows' => 2],
            'size' => $size('17'),
            'weight' => ['kind' => 'select', 'label' => 'Épaisseur', 'options' => ['400' => 'Normal', '700' => 'Gras', '900' => 'Noir'], 'default' => '400'],
            'color' => $color, 'mt' => $mt,
        ]],
        'imagetext' => ['label' => 'Petite image + légende (lien)', 'fields' => [
            'image' => ['kind' => 'image', 'label' => 'Photo'],
            'alt' => ['kind' => 'text', 'label' => 'Description de la photo', 'max' => 200],
            'text' => ['kind' => 'text', 'label' => 'Nom', 'max' => 120],
            'sub' => ['kind' => 'text', 'label' => 'Sous le nom', 'max' => 120],
            'url' => ['kind' => 'url', 'label' => 'Lien', 'max' => 300],
            'isz' => ['kind' => 'select', 'label' => 'Taille de l’image', 'options' => ['32' => '32 px', '44' => '44 px', '64' => '64 px'], 'default' => '44'],
            'mt' => $mt,
        ]],
        'spacer' => ['label' => 'Espace vide', 'fields' => [
            'height' => ['kind' => 'select', 'label' => 'Hauteur', 'options' => home_px_options([8, 16, 24, 32, 48, 64, 96, 120]), 'default' => '32'],
        ]],
    ];
    return $specs;
}

// ------------------------------------------------------------------ Couleurs de section

function home_color_valid(string $v): bool
{
    return isset(HOME_COLORS[$v]) || (bool) preg_match('/^#[0-9a-f]{6}$/', $v);
}

function home_color_css(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    if (isset(HOME_COLORS[$v])) return HOME_COLORS[$v][1];
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

/** Texte crème ou bleu foncé selon le fond (le plus lisible). */
function home_auto_fg(?string $bg): ?string
{
    if ($bg === null || !home_color_valid($bg)) return null;
    $hex = HOME_COLORS[$bg][2] ?? $bg;
    return color_contrast('#f9f0da', $hex) > color_contrast('#1b365d', $hex) ? 'cream' : 'navy';
}

/** Liens autorisés : page du site, ancre, http(s), mailto. Le reste (javascript:…) est refusé. */
function home_clean_url(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    return preg_match('#^(/(?!/)|\#|https?://|mailto:)#i', $u) && !preg_match('/[\s"<>\x00-\x1f]/', $u) ? $u : '';
}

// ------------------------------------------------------------------ Nettoyage (tout ce qui vient du formulaire ou de la base)

function home_clean_value(array $f, $v): string
{
    $v = is_scalar($v) ? (string) $v : '';
    switch ($f['kind']) {
        case 'check':
            return in_array($v, ['1', 'on', 'true'], true) ? '1' : '';
        case 'select':
            return array_key_exists($v, $f['options']) ? $v : (string) ($f['default'] ?? array_key_first($f['options']));
        case 'url':
            return mb_substr(home_clean_url($v), 0, (int) ($f['max'] ?? 300));
        default:
            $v = trim(preg_replace('/\r\n?/', "\n", $v));
            if ($f['kind'] === 'text') $v = preg_replace('/\s*\n\s*/', ' ', $v);
            return mb_substr($v, 0, (int) ($f['max'] ?? 300));
    }
}

function home_clean_el($e): ?array
{
    if (!is_array($e)) return null;
    $type = (string) ($e['type'] ?? '');
    $spec = home_el_specs()[$type] ?? null;
    if ($spec === null) return null;
    $id = (string) ($e['id'] ?? '');
    if (!home_id_valid($id)) $id = home_new_id();
    $in = is_array($e['p'] ?? null) ? $e['p'] : [];
    $p = [];
    foreach ($spec['fields'] as $name => $f) {
        if ($f['kind'] !== 'image') $p[$name] = home_clean_value($f, $in[$name] ?? ($f['kind'] === 'check' ? '' : ($f['default'] ?? '')));
    }
    if (isset($spec['fields']['image'])) {
        $slot = is_string($in['slot'] ?? null) ? $in['slot'] : '';
        $p['slot'] = photo_slot_valid($slot) ? $slot : home_image_slot($id);
    }
    $key = is_string($in['key'] ?? null) ? $in['key'] : '';
    if ($key !== '' && isset(text_items()[$key]) && isset($spec['fields']['text'])) $p['key'] = $key;
    return ['id' => $id, 'type' => $type, 'p' => $p];
}

function home_clean_col($c): ?array
{
    if (!is_array($c)) return null;
    $els = [];
    foreach (array_slice(is_array($c['els'] ?? null) ? array_values($c['els']) : [], 0, HOME_LIMITS['els']) as $e) {
        if ($el = home_clean_el($e)) $els[] = $el;
    }
    $w = (int) ($c['w'] ?? 1);
    return [
        'w' => min(20, max(1, $w)),
        'card' => in_array($c['card'] ?? '', [true, '1', 'on'], true),
        'm_last' => in_array($c['m_last'] ?? '', [true, '1', 'on'], true),
        'els' => $els,
    ];
}

function home_clean_row($r): ?array
{
    if (!is_array($r)) return null;
    $cols = [];
    foreach (array_slice(is_array($r['cols'] ?? null) ? array_values($r['cols']) : [], 0, HOME_LIMITS['cols']) as $c) {
        if ($col = home_clean_col($c)) $cols[] = $col;
    }
    $gap = (int) ($r['gap'] ?? 32);
    $mt = (int) ($r['mt'] ?? 0);
    return [
        'gap' => in_array($gap, HOME_GAPS, true) ? $gap : 32,
        'mt' => in_array($mt, HOME_SPACES, true) ? $mt : 0,
        'valign' => in_array($r['valign'] ?? '', ['start', 'center', 'end'], true) ? $r['valign'] : 'start',
        'cols' => $cols,
    ];
}

function home_clean_section($s): ?array
{
    if (!is_array($s)) return null;
    $id = (string) ($s['id'] ?? '');
    if (!home_id_valid($id)) $id = home_new_id();
    $rows = [];
    foreach (array_slice(is_array($s['rows'] ?? null) ? array_values($s['rows']) : [], 0, HOME_LIMITS['rows']) as $r) {
        if ($row = home_clean_row($r)) $rows[] = $row;
    }
    $bg = is_string($s['bg'] ?? null) && home_color_valid($s['bg']) ? $s['bg'] : null;
    $fg = in_array($s['fg'] ?? '', ['cream', 'navy'], true) ? $s['fg'] : null;
    $pad = isset($s['pad']) && $s['pad'] !== '' && in_array((int) $s['pad'], HOME_PADS, true) ? (int) $s['pad'] : null;
    $anchor = is_string($s['anchor'] ?? null) ? $s['anchor'] : '';
    return [
        'id' => $id,
        'name' => mb_substr(trim(is_string($s['name'] ?? null) ? $s['name'] : ''), 0, 60),
        'visible' => ($s['visible'] ?? true) !== false && ($s['visible'] ?? true) !== '',
        'bg' => $bg, 'fg' => $fg, 'pad' => $pad,
        'anchor' => preg_match('/^[a-z0-9-]{1,30}$/', $anchor) ? $anchor : '',
        'rows' => $rows,
    ];
}

// ------------------------------------------------------------------ Stockage

function home_layout(PDO $db, ?array $texts = null): array
{
    $stored = get_setting($db, HOME_SETTING);
    if ($stored !== null) {
        $data = json_decode($stored, true);
        if (is_array($data) && ($data['v'] ?? 0) === 2 && is_array($data['sections'] ?? null)) {
            $list = [];
            foreach (array_slice($data['sections'], 0, HOME_LIMITS['sections']) as $s) {
                if ($c = home_clean_section($s)) $list[] = $c;
            }
            return $list;
        }
    }
    return home_default_layout($texts ?? text_overrides($db), ebook_sale_open($db));
}

function home_layout_custom(PDO $db): bool
{
    return get_setting($db, HOME_SETTING) !== null;
}

function home_layout_save(PDO $db, array $layout): void
{
    $clean = [];
    foreach (array_slice($layout, 0, HOME_LIMITS['sections']) as $s) {
        if ($c = home_clean_section($s)) $clean[] = $c;
    }
    set_setting($db, HOME_SETTING, json_encode(['v' => 2, 'sections' => $clean], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function home_layout_reset(PDO $db): void
{
    delete_setting($db, HOME_SETTING);
}

/** Emplacement de photo propre à un élément (voir photo_slot_valid dans photos.php). */
function home_image_slot(string $elementId): string
{
    return "img-$elementId";
}

/** Emplacements de photo propres aux éléments d'une section (ceux qu'on peut supprimer avec elle). @return list<string> */
function home_section_slots(array $section): array
{
    $slots = [];
    foreach ($section['rows'] as $r) foreach ($r['cols'] as $c) foreach ($c['els'] as $e) {
        if (isset($e['p']['slot']) && $e['p']['slot'] === home_image_slot($e['id'])) $slots[] = $e['p']['slot'];
    }
    return $slots;
}

// ------------------------------------------------------------------ Page d'origine

/** La page d'accueil telle qu'elle était avant l'éditeur, avec les textes de /admin/textes. */
function home_default_layout(array $texts = [], bool $ebook = false): array
{
    $t = fn(string $k) => $texts[$k] ?? (text_items()[$k]['default'] ?? '');
    $n = 0;
    $el = function (string $type, array $p) use (&$n): array {
        return ['id' => 'd' . str_pad((string) ++$n, 3, '0', STR_PAD_LEFT), 'type' => $type, 'p' => $p];
    };
    $heading = fn(string $key, array $p = []) => $el('heading', $p + ['key' => $key, 'text' => '']);
    $text = fn(string $key, array $p = []) => $el('text', $p + ['key' => $key, 'text' => '']);
    $lit = fn(string $s, array $p = []) => $el('text', $p + ['text' => $s]);
    $col = fn(int $w, array $els, array $o = []) => ['w' => $w, 'els' => $els] + $o;
    $row = fn(array $cols, array $o = []) => ['cols' => $cols] + $o + ['gap' => 32, 'valign' => 'start', 'mt' => 0];
    $buy = '/panier?ajouter=1';

    $sections = [
        ['id' => 'hero', 'name' => 'Haut de page', 'bg' => 'navy', 'pad' => 72, 'anchor' => '', 'rows' => [
            $row([
                $col(5, [
                    $heading('hero_titre', ['level' => 'h1', 'size' => '82', 'big' => '1', 'mt' => '0']),
                    $text('hero_accroche', ['size' => '20', 'maxw' => '34ch', 'mt' => '30']),
                    $el('buttons', ['b1_label' => $t('bouton_principal'), 'b1_url' => $buy, 'b2_label' => $t('bouton_extrait'), 'b2_url' => '#extrait', 'b2_style' => 'outline', 'mt' => '34']
                        + ($ebook ? ['b3_label' => 'Version numérique', 'b3_url' => '/numerique', 'b3_style' => 'outline'] : [])),
                ]),
                $col(3, [$el('image', ['slot' => 'couverture', 'alt' => "Couverture du livre Les hormones d'Yvonne", 'ratio' => '2/3', 'radius' => '0', 'shadow' => 'strong', 'mt' => '0'])]),
            ], ['gap' => 56, 'valign' => 'center']),
        ]],
        ['id' => 'livre', 'name' => 'Le livre', 'pad' => 72, 'anchor' => 'livre', 'rows' => [
            $row([$col(1, [$heading('livre_titre', ['size' => '52', 'mt' => '0'])])]),
            $row(array_map(fn(int $i) => $col(1, [
                $text("pilier{$i}_titre", ['weight' => '900', 'size' => '19', 'color' => 'terra', 'mt' => '0']),
                $text("pilier{$i}_texte", ['mt' => '8']),
            ]), [1, 2, 3]), ['mt' => 44]),
        ]],
        ['id' => 'citation', 'name' => 'Citation', 'bg' => 'navy', 'anchor' => '', 'rows' => [
            $row([$col(1, [$heading('hashtag', ['size' => '44', 'big' => '1', 'color' => 'peach', 'mt' => '0'])])]),
            $row([$col(1, [$text('citation', ['size' => '30', 'weight' => '800', 'maxw' => '26ch', 'pos' => 'right', 'mt' => '0'])])], ['mt' => 36]),
            $row([$col(1, [$el('buttons', ['b1_label' => $t('citation_bouton'), 'b1_url' => $buy, 'align' => 'right', 'mt' => '0'])])], ['mt' => 32]),
        ]],
        ['id' => 'commander', 'name' => 'Commander', 'anchor' => 'commander', 'rows' => [
            $row([
                $col(11, [$el('image', ['slot' => 'couverture', 'alt' => "Couverture du livre Les hormones d'Yvonne", 'ratio' => '2/3', 'radius' => '0', 'shadow' => 'soft', 'mt' => '0'])], ['m_last' => true]),
                $col(20, [
                    $heading('commander_titre', ['size' => '38', 'mt' => '0']),
                    $el('icon', ['icon' => 'package', 'look' => 'box', 'key' => 'commander_livraison', 'text' => '', 'mt' => '14']),
                    $el('buttons', ['b1_label' => $t('commander_bouton'), 'b1_url' => $buy, 'b2_label' => $t('commander_bouton_ou'), 'b2_url' => '#distribution', 'b2_style' => 'outline', 'mt' => '26']),
                    $el('icon', ['icon' => 'truck', 'look' => 'plain', 'key' => 'commander_rapide', 'text' => '', 'size' => '15', 'weight' => '700', 'color' => 'olive', 'mt' => '26']),
                ]),
            ], ['gap' => 56, 'valign' => 'center']),
        ]],
        ['id' => 'autrice', 'name' => 'L’autrice', 'bg' => 'navy', 'anchor' => 'autrice', 'rows' => [
            $row([
                $col(1, [$el('image', ['slot' => 'portrait', 'alt' => 'Portrait de Vanessa Gandino', 'ratio' => '3/4', 'radius' => '0', 'mt' => '0'])]),
                $col(2, [
                    $heading('autrice_nom', ['size' => '52', 'big' => '1', 'mt' => '0']),
                    $text('autrice_intro', ['size' => '18', 'mt' => '24']),
                    $text('autrice_citation', ['italic' => '1', 'maxw' => '46ch', 'color' => 'muted', 'mt' => '20']),
                    $text('autrice_signature', ['color' => 'peach', 'mt' => '22']),
                ]),
            ], ['gap' => 52, 'valign' => 'center']),
        ]],
        ['id' => 'distribution', 'name' => 'Où le trouver', 'bg' => 'navy', 'anchor' => 'distribution', 'rows' => [
            $row([$col(1, [
                $heading('dist_titre', ['size' => '40', 'mt' => '0']),
                $text('dist_intro', ['maxw' => '46ch', 'color' => 'muted', 'mt' => '12']),
            ])]),
            $row([
                $col(1, [
                    $el('icon', ['icon' => 'globe', 'look' => 'circle', 'mt' => '0']),
                    $heading('dist_ligne_titre', ['level' => 'h3', 'size' => '22', 'mt' => '14']),
                    $lit($t('dist_ligne_texte') . ' [' . $t('dist_ligne_lien') . '](' . $buy . ')', ['size' => '15', 'color' => 'muted', 'mt' => '8']),
                ], ['card' => true]),
                $col(1, [
                    $el('icon', ['icon' => 'store', 'look' => 'circle', 'mt' => '0']),
                    $heading('dist_concept_titre', ['level' => 'h3', 'size' => '22', 'mt' => '14']),
                    $text('dist_concept_texte', ['size' => '15', 'color' => 'muted', 'mt' => '8']),
                    $el('imagetext', ['slot' => 'partenaire', 'alt' => '', 'text' => $t('dist_partenaire_nom'), 'sub' => $t('dist_partenaire_lien'), 'url' => 'https://www.instagram.com/legardemanger_', 'isz' => '44', 'mt' => '16']),
                ], ['card' => true]),
                $col(1, [
                    $el('icon', ['icon' => 'book-open', 'look' => 'circle', 'mt' => '0']),
                    $heading('dist_librairies_titre', ['level' => 'h3', 'size' => '22', 'mt' => '14']),
                    $text('dist_librairies_texte', ['size' => '15', 'color' => 'muted', 'mt' => '8']),
                ], ['card' => true]),
            ], ['gap' => 18, 'mt' => 38]),
            $row([$col(1, [$lit($t('dist_pro') . ' [contact@vanessagandino.fr](mailto:contact@vanessagandino.fr)', ['size' => '15', 'color' => 'faint', 'mt' => '0'])])], ['mt' => 26]),
        ]],
    ];
    return array_map(fn($s) => home_clean_section($s), $sections);
}

/** Nouvelle section prête à remplir. $kind : vide | texte | photo | image_texte | colonnes | citation. */
function home_preset(string $kind): array
{
    $el = function (string $type, array $p = []): array {
        $e = home_clean_el(['type' => $type, 'p' => $p]);
        return $e;
    };
    $col = fn(array $els, int $w = 1) => ['w' => $w, 'els' => $els];
    $rows = match ($kind) {
        'texte' => [['cols' => [$col([
            $el('heading', ['text' => 'Votre titre', 'size' => '40', 'mt' => '0']),
            $el('text', ['text' => 'Votre texte…', 'mt' => '14', 'maxw' => '62ch']),
        ])]]],
        'photo' => [['cols' => [$col([$el('image', ['width' => '70', 'mt' => '0'])])]]],
        'image_texte' => [['gap' => 52, 'valign' => 'center', 'cols' => [
            $col([$el('image', ['mt' => '0'])], 3),
            $col([
                $el('heading', ['text' => 'Votre titre', 'size' => '38', 'mt' => '0']),
                $el('text', ['text' => 'Votre texte…', 'mt' => '14']),
                $el('buttons', ['b1_label' => 'En savoir plus', 'b1_url' => '/panier?ajouter=1', 'mt' => '24']),
            ], 5),
        ]]],
        'colonnes' => [
            ['cols' => [$col([$el('heading', ['text' => 'Votre titre', 'size' => '40', 'mt' => '0'])])]],
            ['gap' => 32, 'mt' => 36, 'cols' => array_map(fn(int $i) => $col([
                $el('text', ['text' => "Colonne $i", 'weight' => '900', 'size' => '19', 'color' => 'terra', 'mt' => '0']),
                $el('text', ['text' => 'Votre texte…', 'mt' => '8']),
            ]), [1, 2, 3])],
        ],
        'citation' => [['cols' => [$col([
            $el('text', ['text' => '« Votre citation. »', 'size' => '30', 'weight' => '800', 'maxw' => '26ch', 'mt' => '0']),
            $el('text', ['text' => 'Auteur', 'weight' => '700', 'mt' => '18']),
        ])]]],
        default => [['cols' => [$col([])]]],
    };
    return home_clean_section(['id' => home_new_id(), 'name' => 'Nouvelle section', 'visible' => true, 'rows' => $rows]);
}

// ------------------------------------------------------------------ Rendu

/** Texte d'un élément : le sien, sinon celui du catalogue (/admin/textes). */
function home_el_text(array $p, array $texts): string
{
    if (($p['text'] ?? '') !== '') return $p['text'];
    $k = $p['key'] ?? '';
    return $k !== '' ? ($texts[$k] ?? (text_items()[$k]['default'] ?? '')) : '';
}

/** Texte brut → HTML : échappé, [lien](adresse), **gras**, retours à la ligne. */
function home_rich(string $raw): string
{
    $s = h(preg_replace('/\r\n?/', "\n", $raw));
    $s = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/', function (array $m): string {
        $url = home_clean_url(htmlspecialchars_decode($m[2], ENT_QUOTES));
        return $url === '' ? $m[0] : '<a href="' . h($url) . '">' . $m[1] . '</a>';
    }, $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $s);
    return str_replace("\n", '<br />', $s);
}

function home_icon_svg(string $name, int $size): string
{
    static $icons = null;
    $icons ??= is_file(__DIR__ . '/icons.json') ? (json_decode((string) file_get_contents(__DIR__ . '/icons.json'), true) ?: []) : [];
    $svg = $icons[$name] ?? '';
    if ($svg === '') return '';
    $svg = preg_replace(['/width="24"/', '/height="24"/', '/stroke-width="2"/'], ["width=\"$size\"", "height=\"$size\"", 'stroke-width="2.75"'], $svg);
    return str_replace('<svg', '<svg aria-hidden="true" focusable="false"', $svg);
}

function home_style(array $s): string
{
    $css = '';
    if ($bg = home_color_css($s['bg'])) $css .= "background:$bg;";
    $fg = $s['fg'] ?? home_auto_fg($s['bg']);
    if ($fg) $css .= "color:var(--$fg);";
    if ($s['pad'] !== null) $css .= "padding-top:{$s['pad']}px;padding-bottom:{$s['pad']}px;";
    return $css;
}

function home_render_el(array $e, array $texts, int &$images): string
{
    $p = $e['p'];
    $mt = (int) ($p['mt'] ?? 0);
    $style = $mt > 0 ? "margin-top:{$mt}px;" : '';
    $textColor = function () use ($p): string {
        $c = HOME_TEXT_COLORS[$p['color'] ?? ''][1] ?? null;
        return $c ? "color:$c;" : '';
    };
    $fs = fn(int $size) => 'font-size:calc(var(--fs)*1px);--fs:' . $size . ';';
    $big = fn(int $size) => $size >= 30 ? ' fs-big' : '';
    $pos = fn(string $v) => match ($v) { 'right' => 'margin-left:auto;', 'center' => 'margin-left:auto;margin-right:auto;', default => '' };

    switch ($e['type']) {
        case 'heading':
            $text = home_el_text($p, $texts);
            if ($text === '') return '';
            $tag = in_array($p['level'], ['h1', 'h2', 'h3'], true) ? $p['level'] : 'h2';
            $size = (int) $p['size'];
            return "<$tag class=\"he he-h" . ($p['big'] === '1' ? ' bigword' : '') . $big($size) . '" style="' . h($style . $fs($size) . $textColor() . "text-align:{$p['align']};") . '">' . home_rich($text) . "</$tag>";
        case 'text':
            $text = home_el_text($p, $texts);
            if ($text === '') return '';
            $size = (int) $p['size'];
            $css = $style . $fs($size) . $textColor() . "font-weight:{$p['weight']};text-align:{$p['align']};" . $pos($p['pos'])
                . ($p['italic'] === '1' ? 'font-style:italic;' : '') . ($p['maxw'] !== '' ? "max-width:{$p['maxw']};" : '');
            return '<p class="he he-t' . $big($size) . '" style="' . h($css) . '">' . home_rich($text) . '</p>';
        case 'image':
            $slot = $p['slot'];
            if (photo_file($slot, 480) === null) return '';
            $src = fn(int $w) => '/photo/' . $slot . '?w=' . $w . '&amp;v=' . photo_version($slot);
            $ratio = $p['ratio'] !== 'auto';
            $css = $style . "width:{$p['width']}%;" . ($ratio ? 'aspect-ratio:' . $p['ratio'] . ';' : '') . "border-radius:{$p['radius']}px;"
                . match ($p['shadow']) { 'soft' => 'box-shadow:0 18px 44px rgba(14,48,73,.22);', 'strong' => 'box-shadow:0 24px 60px rgba(0,0,0,.35);', default => '' }
                . $pos($p['align']);
            $eager = $images++ === 0;
            return '<figure class="he he-img' . ($ratio ? ' has-ratio' : '') . '" style="' . h($css) . '"><img src="' . $src(480) . '" srcset="' . $src(480) . ' 480w, ' . $src(960)
                . ' 960w" sizes="(max-width: 760px) 90vw, 480px" alt="' . h($p['alt']) . '" loading="' . ($eager ? 'eager' : 'lazy') . '"' . ($eager ? ' fetchpriority="high"' : '') . ' decoding="async"></figure>';
        case 'buttons':
            $out = '';
            foreach ([1, 2, 3] as $i) {
                $label = $p["b{$i}_label"];
                $url = $p["b{$i}_url"];
                if ($label === '' || $url === '') continue;
                $cls = 'cta' . match ($p["b{$i}_style"]) { 'terra' => ' terra', 'outline' => ' outline', default => '' };
                $out .= $url === '#extrait'
                    ? '<button type="button" class="' . $cls . '" data-open-extract>' . h($label) . '</button>'
                    : '<a class="' . $cls . '" href="' . h($url) . '">' . h($label) . '</a>';
            }
            if ($out === '') return '';
            $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$p['align']] ?? 'flex-start';
            return '<div class="he cta-row" style="' . h($style . "justify-content:$justify;") . '">' . $out . '</div>';
        case 'icon':
            $text = home_el_text($p, $texts);
            $look = $p['look'];
            $svg = home_icon_svg($p['icon'], $look === 'box' ? 23 : ($look === 'circle' ? 26 : 22));
            $size = (int) $p['size'];
            $wrap = $look === 'box' ? "<span class=\"box\">$svg</span>" : ($look === 'circle' ? "<span class=\"ic\">$svg</span>" : $svg);
            $css = $style . ($text !== '' ? $fs($size) . $textColor() . "font-weight:{$p['weight']};" : '');
            return '<div class="he he-ic ic-' . $look . '" style="' . h($css) . '">' . $wrap . ($text !== '' ? '<span>' . home_rich($text) . '</span>' : '') . '</div>';
        case 'imagetext':
            $slot = $p['slot'];
            $has = photo_file($slot, 480) !== null;
            if ($p['text'] === '' && !$has) return '';
            $img = $has ? '<img src="/photo/' . $slot . '?w=480&amp;v=' . photo_version($slot) . '" alt="' . h($p['alt']) . '" width="' . (int) $p['isz'] . '" height="' . (int) $p['isz'] . '" loading="lazy" decoding="async" style="width:' . (int) $p['isz'] . 'px;height:' . (int) $p['isz'] . 'px">' : '';
            $label = '<span>' . h($p['text']) . ($p['sub'] !== '' ? '<br><small>' . h($p['sub']) . '</small>' : '') . '</span>';
            $tag = $p['url'] !== '' ? 'a href="' . h($p['url']) . '"' . (str_starts_with($p['url'], 'http') ? ' target="_blank" rel="noopener"' : '') : 'div';
            return "<$tag class=\"he he-it\" style=\"" . h($style) . "\">$img$label</" . explode(' ', $tag)[0] . '>';
        case 'spacer':
            return '<div class="he" style="height:' . (int) $p['height'] . 'px" aria-hidden="true"></div>';
    }
    return '';
}

function home_render_section(array $s, array $texts, int &$images): string
{
    $rows = '';
    foreach ($s['rows'] as $r) {
        if (!$r['cols']) continue;
        $cols = '';
        $tracks = [];
        foreach ($r['cols'] as $c) {
            $tracks[] = $c['w'] . 'fr';
            $inner = '';
            foreach ($c['els'] as $e) $inner .= home_render_el($e, $texts, $images);
            $cols .= '<div class="hc' . ($c['card'] ? ' hc-card' : '') . ($c['m_last'] ? ' m-last' : '') . '">' . $inner . '</div>';
        }
        $css = '--cols:' . implode(' ', $tracks) . ';--gap:' . $r['gap'] . 'px;' . ($r['mt'] > 0 ? 'margin-top:' . $r['mt'] . 'px;' : '');
        $rows .= '<div class="hr n' . count($r['cols']) . ' va-' . $r['valign'] . '" style="' . $css . '">' . $cols . '</div>';
    }
    $style = home_style($s);
    return '<section class="section hs"' . ($s['anchor'] !== '' ? ' id="' . h($s['anchor']) . '"' : '') . ($style !== '' ? ' style="' . h($style) . '"' : '') . '>' . $rows . '</section>';
}

function home_render(array $layout, array $texts): string
{
    $out = '';
    $images = 0;
    foreach ($layout as $s) {
        if ($s['visible']) $out .= home_render_section($s, $texts, $images);
    }
    return $out;
}

/** Remplace le repère <!--home--> de la page générée par les sections. Sans repère, la page est rendue telle quelle. */
function home_apply(string $html, array $layout, array $texts = []): string
{
    return str_replace('<!--home-->', home_render($layout, $texts), $html);
}
