# Lien de paiement Revolut réglable dans l'admin — conception courte

Date : 2026-11-28 · Statut : validée par l'utilisateur (« Oui, valide »)

## Intention

Régler depuis l'admin, sans toucher `config.php`, le lien Revolut affiché au client, et lui éviter de taper le montant : montant prérempli pour le papier si Revolut l'accepte, lien à montant fixe garanti pour l'epub.

## Aujourd'hui

`revolut_link_template` (config.php) sert aux pages `/merci`, `/numerique-merci` et aux mails de commande ; `revolut_link()` remplace `{amount}` (ex. `35.90`) et `{ref}`. Revolut ne documente aucun paramètre d'URL pour préremplir un montant sur `revolut.me` ; seuls les liens créés dans l'appli à montant fixe sont garantis.

## Objectifs

- Page `/admin/paiement`, deux champs :
  1. **Lien Revolut** (papier, et epub à défaut de lien fixe). `{amount}` et `{ref}` facultatifs ; aide : « ajoutez {amount} là où le montant doit aller, puis testez ».
  2. **Lien de paiement epub à montant fixe** (facultatif), créé dans l'appli Revolut ; enregistré avec le prix epub du moment.
- Bouton **Tester** : affiche (sans rien envoyer) le lien obtenu pour 1,00 € / référence `TEST`, à ouvrir sur téléphone.
- Lien fixe epub utilisé seulement si le prix epub actuel (`/admin/epub`) est égal au prix enregistré avec le lien ; sinon repli sur le lien Revolut et avertissement sur `/admin/paiement` et `/admin/epub`.
- Changement d'un des deux liens → mail « Lien de paiement modifié » aux adresses de notification (ancien, nouveau, date, IP).
- Réglage absent → `revolut_link_template` de config.php (comportement actuel inchangé).
- Pages et mails disent « montant déjà rempli » quand le lien contient `{amount}` ou quand le lien fixe epub s'applique.

## Non-objectifs

- Pas de confirmation automatique des paiements (revolut.me ne prévient pas le site).
- Pas de Stripe ni d'API Revolut Business.
- Pas de lien fixe pour le papier (total variable : quantité + port).

## Contraintes / sécurité

- Liens : `https://` uniquement, hôte `revolut.me` ou sous-domaine de `revolut.com`, ≤ 300 caractères, sans espace ni retour à la ligne ; seuls `{amount}` et `{ref}` admis entre accolades ; le lien fixe n'en contient aucun.
- Page derrière `require_admin()`, formulaires `check_post()` ; tout affichage via `h()`.
- Lecture de réglage en erreur → repli config.php (le client voit toujours un lien).

## Approches considérées

1. **Format à tester seulement** — écartée seule : montant epub non garanti.
2. **Format à tester + lien fixe epub** — retenue (choix de l'utilisateur) : montant garanti pour l'epub, prérempli pour le papier si l'essai réussit.
3. Lien fixe par total papier — écartée : trop de combinaisons quantité × port.

## Décisions laissées au constructeur

- Clés de réglage (`pay_link`, `pay_link_ebook`, `pay_link_ebook_cents`) et noms de fonctions.
- Lien « paiement » ajouté au menu admin, route `.htaccess`, section README, commentaire dans `config.example.php`.
