<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';
require __DIR__ . '/../_prive/ebook.php';

require_admin();

function csv_cell($v): string
{
    $s = (string) $v;
    // Neutralise les formules à l'ouverture dans un tableur.
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
    return preg_match('/[";\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

$db = db();
$rows = [implode(';', ['ref', 'date', 'statut', 'prenom', 'nom', 'email', 'prix_eur', 'payee_le', 'telechargements', 'consentement_le'])];
foreach (list_ebook_orders($db) as $o) {
    $rows[] = implode(';', array_map('csv_cell', [
        $o['ref'],
        $o['created_at'],
        EBOOK_STATUS_LABELS[$o['status']] ?? $o['status'],
        $o['first_name'],
        $o['last_name'],
        $o['email'],
        number_format($o['price_cents'] / 100, 2, ',', ''),
        $o['paid_at'] ?? '',
        (int) $o['dl_count'],
        $o['consent_at'],
    ]));
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="livres-numeriques-' . date('Y-m-d') . '.csv"');
// BOM + ";" pour Excel en français.
echo "\u{FEFF}" . implode("\r\n", $rows);
