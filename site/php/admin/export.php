<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';

require_admin();

const COLUMNS = ['ref', 'created_at', 'status', 'qty', 'total_cents', 'shipping_cents', 'first_name', 'last_name', 'email',
    'phone', 'relay_id', 'relay_name', 'relay_address', 'relay_postcode', 'relay_city', 'relay_country',
    'paid_at', 'shipped_at', 'tracking', 'note', 'admin_note'];

function csv_cell($v): string
{
    $s = (string) $v;
    // Neutralise les formules à l'ouverture dans un tableur.
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
    return preg_match('/[";\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

$rows = [implode(';', array_map(fn($c) => str_replace('_cents', '_eur', $c), COLUMNS))];
foreach (list_orders(db()) as $o) {
    $rows[] = implode(';', array_map(function ($c) use ($o) {
        if ($c === 'status') return csv_cell(STATUS_LABELS[$o['status']]);
        if (str_ends_with($c, '_cents')) return csv_cell(number_format($o[$c] / 100, 2, ',', ''));
        return csv_cell($o[$c]);
    }, COLUMNS));
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="commandes-' . date('Y-m-d') . '.csv"');
// BOM + ";" pour Excel en français.
echo "\u{FEFF}" . implode("\r\n", $rows);
