<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/lib.php';

// Tarifs et code Mondial Relay pour l'affichage du panier et de la page commande.
try {
    json_out(200, pricing() + ['mrBrand' => cfg()['mr_brand']]);
} catch (Throwable $e) {
    error_log('[config] ' . $e->getMessage());
    json_out(500, ['message' => 'Configuration invalide.']);
}
