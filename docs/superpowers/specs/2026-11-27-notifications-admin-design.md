# Adresses de notification réglables dans l'admin — conception courte

Date : 2026-11-27 · Statut : validée par l'utilisateur (« Oui, valide »)

## Intention

Pouvoir choisir depuis l'admin, sans toucher `config.php` sur le serveur, la ou les adresses qui reçoivent les avis d'achat.

## Aujourd'hui

`admin_email` (config.php, défaut `contact@vanessagandino.fr`) reçoit trois avis : « Nouvelle commande » (papier), « Nouvelle commande numérique », « Alerte partage possible ». La même valeur sert de `Reply-To` des mails clients.

## Objectifs

- Page `/admin/notifications` : 1 à 3 adresses (une par ligne), Enregistrer, Envoyer un mail de test, Revenir à config.php.
- Les trois avis partent vers ces adresses ; champ vide ou réglage absent → `admin_email` de config.php (les avis ne s'arrêtent jamais par erreur).
- Changement des adresses → mail « Adresses de notification modifiées » aux anciennes adresses (date, IP, nouvelles adresses).

## Non-objectifs

- Le `Reply-To` des mails clients ne change pas : il reste `admin_email` de config.php.
- Pas de choix par type d'avis, pas de désactivation des avis.

## Contraintes / sécurité

- Chaque adresse validée (`FILTER_VALIDATE_EMAIL`, ≤ 254 caractères), doublons retirés, 3 maximum ; saisie refusée entière si une adresse est invalide (message, saisie réaffichée).
- `send_mail` refuse un destinataire contenant un retour à la ligne (défense en profondeur contre l'injection SMTP/en-têtes).
- Mail de test limité (5 par 10 minutes) pour ne pas servir de relais.
- Page derrière `require_admin()`, formulaires avec `check_post()` (CSRF + même origine), comme les autres pages.

## Approches considérées

1. **Réglage en base (`settings`, clé `notify_emails`) + page dédiée** — retenue : même modèle que les frais de port, effet immédiat, retour à config.php possible.
2. Écrire dans `config.php` depuis l'admin — écartée : fichier de secrets, droits d'écriture à ouvrir, risque de le casser.
3. Champ ajouté à une page existante (Livraison) — écartée : mélange de sujets, moins trouvable.

## Décisions de l'implémenteur (à vérifier en relecture)

- Stockage : adresses séparées par `\n` dans `settings.notify_emails`.
- Lecture en cas d'erreur de base → repli sur config.php (l'avis part quand même).
- Lien « notifications » ajouté au menu admin (`src/layouts/Admin.astro`), route dans `php/.htaccess`, section README.
