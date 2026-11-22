<?php
declare(strict_types=1);
// TEMPORAIRE (supprimé à la tâche 7) : repérer l'en-tête qui porte la vraie IP du visiteur derrière le CDN.
require __DIR__ . '/../_prive/admin.php';

require_admin();

$lines = ['REMOTE_ADDR = ' . ($_SERVER['REMOTE_ADDR'] ?? '(absent)')];
foreach ($_SERVER as $key => $value) {
    if (!is_string($value)) continue;
    if (str_starts_with($key, 'HTTP_X_') || str_starts_with($key, 'HTTP_CF_') || str_contains($key, 'FORWARDED') || str_contains($key, 'REAL_IP')) {
        $lines[] = "$key = $value";
    }
}
$lines[] = '';
$lines[] = 'client_ip() = ' . client_ip();

admin_page('Diagnostic IP', '<h1>Diagnostic IP</h1><pre>' . h(implode("\n", $lines)) . '</pre>');
