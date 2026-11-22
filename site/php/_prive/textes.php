<?php
declare(strict_types=1);

// Textes de l'accueil modifiables depuis l'admin. Le catalogue (clés, libellés, textes d'origine) est
// src/content/textes.json, copié dans _prive/ au build. Un texte modifié est rangé dans la table
// settings sous la clé « texte:{clé} » ; sans modification, le texte d'origine du site s'affiche.

const TEXT_MAX_CHARS = 1500;

/** @return list<array{title: string, items: list<array{key: string, label: string, rows: int, default: string}>}> */
function text_groups(): array
{
    static $groups = null;
    if ($groups === null) {
        $file = is_file(__DIR__ . '/textes.json') ? __DIR__ . '/textes.json' : __DIR__ . '/../../src/content/textes.json';
        $groups = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR)['groups'];
    }
    return $groups;
}

/** @return array<string, array{key: string, label: string, rows: int, default: string}> */
function text_items(): array
{
    $items = [];
    foreach (text_groups() as $g) foreach ($g['items'] as $i) $items[$i['key']] = $i;
    return $items;
}

/** Texte brut → HTML : échappé, **gras**, retours à la ligne. Identique à renderText() de src/lib/texte.ts. */
function text_render(string $raw): string
{
    $s = h(preg_replace('/\r\n?/', "\n", $raw));
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $s);
    return str_replace("\n", '<br />', $s);
}

/** Textes personnalisés : clé → texte. */
function text_overrides(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT key, value FROM settings WHERE key LIKE 'texte:%'") as $row) {
        $out[substr((string) $row['key'], 6)] = (string) $row['value'];
    }
    return $out;
}

/** Enregistre un texte ; vide ou identique à l'original = retour à l'original. Retourne un message d'erreur ou null. */
function text_save(PDO $db, string $key, string $value): ?string
{
    $items = text_items();
    if (!isset($items[$key])) return 'Texte inconnu.';
    $value = trim(preg_replace('/\r\n?/', "\n", $value));
    if (mb_strlen($value) > TEXT_MAX_CHARS) return 'Texte trop long (' . TEXT_MAX_CHARS . ' caractères maximum).';
    if ($value === '' || $value === $items[$key]['default']) {
        delete_setting($db, "texte:$key");
    } else {
        set_setting($db, "texte:$key", $value);
    }
    return null;
}

/** Remplace, dans la page d'accueil générée, chaque texte repéré par <!--t:clé-->…<!--/t:clé--> par sa version personnalisée. */
function text_apply(string $html, array $overrides): string
{
    if (!$overrides) return $html;
    return preg_replace_callback('/<!--t:([a-z0-9_]+)-->(.*?)<!--\/t:\1-->/s', function (array $m) use ($overrides): string {
        return isset($overrides[$m[1]]) ? text_render($overrides[$m[1]]) : $m[0];
    }, $html);
}
