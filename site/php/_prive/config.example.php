<?php
// Configuration de la boutique.
//
// Hostinger : copier ce fichier en « config.php » dans le dossier de données (boutique-data/,
// à côté de public_html, hors du site web) puis remplir les valeurs.
// Docker : inutile, les variables du fichier .env sont utilisées.
//
// Seules les clés présentes ici remplacent les valeurs par défaut.
return [
    // --- Boutique ---
    'book_price_eur' => 10.00,
    'max_qty' => 6,
    // Frais de port Mondial Relay par quantité minimale : 1 livre 4,90 €, 2-3 livres 5,90 €, dès 4 livres 7,50 €.
    'shipping_table' => '1:4.90,2:5.90,4:7.50',
    // Lien Revolut. {amount} = montant (ex. 35.90), {ref} = référence (ex. YV-0007).
    'revolut_link_template' => 'https://revolut.me/v_gandino',
    // Code enseigne Mondial Relay pour la carte. Vide = saisie manuelle du point relais.
    'mr_brand' => '',

    // --- Site ---
    'public_base_url' => 'https://leshormonesdyvonne.vanessagandino.fr',

    // --- E-mails ---
    // 'smtp' (recommandé) | 'mail' (fonction mail() de l'hébergeur) | 'log' (aucun envoi, journal PHP)
    'mail_mode' => 'smtp',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_user' => 'contact@vanessagandino.fr',
    'smtp_password' => '',
    'mail_from' => 'contact@vanessagandino.fr',
    'mail_from_name' => 'Vanessa Gandino',
    'admin_email' => 'contact@vanessagandino.fr',

    // --- Administration (/admin) ---
    'admin_user' => 'vanessa',
    'admin_password' => '', // long et unique ; vide = admin désactivée
];
