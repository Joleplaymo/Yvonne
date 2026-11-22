# Vente de l'epub « Les hormones d'Yvonne » — conception

Date : 2026-11-22 · Statut : conception validée, relecture builder intégrée — en attente d'approbation du document

## Intention

Vanessa vend aujourd'hui le livre papier sur `leshormonesdyvonne.vanessagandino.fr` (Astro statique + PHP 8.3/SQLite sur Hostinger mutualisé, paiement Revolut confirmé à la main). Elle veut vendre aussi la **version numérique (.epub) à 4,99 €**, en limitant au maximum le piratage, la fraude et les fuites de données.

Succès = un lecteur achète l'epub sans friction, le reçoit par mail après validation du paiement par Vanessa, et toute copie qui circule désigne son acheteur.

## Objectifs

- Achat de l'epub seul, indépendant du tunnel papier.
- Paiement Revolut existant, validation manuelle dans `/admin`, puis envoi automatique d'un lien de téléchargement.
- Fichier jamais exposé publiquement ; chaque exemplaire tatoué au nom de l'acheteur.
- Conformité : renonciation expresse au droit de rétractation (C. conso. L221-28 13°) recueillie, horodatée, confirmée par mail.

## Non-objectifs

- Pas de DRM Adobe/LCP.
- Pas de paiement automatique (Stripe écarté par choix : garder Revolut).
- Pas de panier mixte papier + epub.
- Pas de rédaction des CGV (texte fourni par Vanessa ; on prévoit seulement le lien).

## Anti-objectifs (échec même si « ça marche »)

- Le tunnel papier, la table `orders` ou l'admin existante cassent.
- Un epub non tatoué ou le fichier maître est téléchargeable par une URL devinable ou mise en cache par le CDN.
- Un lien de téléchargement est réutilisable indéfiniment ou fonctionne avant paiement.
- L'epub généré est invalide (refusé par Apple Books, Kobo, Calibre…).

## Contraintes constatées sur le serveur (inspection hPanel du 2026-11-22)

- Offre Premium Web Hosting, PHP 8.3.37. Extensions actives : `zip`, `pdo_sqlite`, `mbstring`, `curl`, `openssl`, `dom`, `fileinfo`.
- Arborescence : `boutique-data/` (config, base, photos) est à côté de `public_html/`, hors web. `.htaccess` de `boutique-data` = `Require all denied`.
- **CDN Hostinger actif** (`Server: hcdn`) : toute réponse de téléchargement doit être explicitement non cachable.
- En-têtes présents : nosniff, Referrer-Policy, X-Frame-Options DENY, `upgrade-insecure-requests`. Absents : HSTS. Présent à masquer : `X-Powered-By: PHP/8.3.37`.
- `config.php` et `commandes.sqlite` en 644.
- Disque : 0,05 Go / 20 Go. Sauvegardes Hostinger hebdomadaires.

## Approches envisagées

| Approche | Pour | Contre | Décision |
|---|---|---|---|
| **Stripe Checkout + webhook** | Livraison instantanée, zéro action manuelle | Nouveau prestataire, ~0,32 € de frais/vente, secret webhook à gérer | Écartée par Vanessa |
| **Revolut manuel, achat séparé** | Réutilise le flux connu, aucun nouveau prestataire, tunnel papier intact | Délai jusqu'à la validation | **Retenue** |
| Revolut manuel, epub dans le panier | Un seul paiement pour papier + epub | Touche panier, commande, totaux, mails, admin ; adresse/relais optionnels ; statuts mixtes | Écartée (risque de régression) |

Protection du contenu : DRM Adobe écarté (coût, friction lecteurs, contournable) au profit du **tatouage (« social DRM »)**.

## Conception retenue

### Parcours acheteur

1. Accueil : bouton « Version numérique · 4,99 € » → `/numerique`.
2. `/numerique` (page Astro statique, même style que `/commande`) : prénom, nom, email (deux fois ou confirmation visuelle — au choix du builder), case **obligatoire** :
   « Je demande l'accès au livre numérique dès la validation de mon paiement et je reconnais perdre mon droit de rétractation. »
   + lien CGV (URL en réglage, masqué si vide). Pot de miel anti-robots comme `/commande`.
3. `POST /api/ebook-orders` (JSON) → crée la commande `EB-0001`, renvoie l'URL de la page de paiement.
4. Page de paiement/suivi `/numerique/merci?ref=EB-0001&t=<token>` : montant, bouton Revolut (`revolut_link()` existant), référence à indiquer, délai annoncé (« lien envoyé sous 24 h après réception du paiement »).
5. Mail « commande créée » : récapitulatif, lien Revolut, **texte exact du consentement donné + date** (support durable, L221-13).
6. Après validation : mail « votre livre est prêt » avec lien `/telecharger/<jeton>`, durée de validité et nombre de téléchargements restants.

### Côté admin

- `/admin` : section « Livres numériques » (à traiter : en attente de paiement), même style que la liste papier. Compteurs séparés.
- `/admin/EB-0001` : détails, consentement (texte, date, IP), historique des téléchargements (date, IP), actions :
  - **Paiement reçu** → statut `livree`, génère jeton, envoie mail.
  - **Renvoyer un lien** → nouveau jeton (l'ancien est invalidé), compteur remis à zéro, mail.
  - **Annuler** / **Rouvrir**, note interne (comme le papier).
- `/admin/epub` : dépôt du fichier maître (validation : ZIP lisible, premier fichier `mimetype` = `application/epub+zip`, `META-INF/container.xml` présent, taille ≤ 50 Mo), affichage de la date/taille du fichier en place, bouton « Tester le tatouage » qui télécharge un exemplaire factice. Réglage du prix (défaut 499 centimes) et de l'URL des CGV.
- Export CSV : colonnes epub ajoutées dans un export séparé ou un onglet — au choix du builder.

### Données

Nouvelle table dans `commandes.sqlite` (créée par `db()` comme les autres, `CREATE TABLE IF NOT EXISTS`) :

```
ebook_orders(
  id, ref UNIQUE ('EB-%04d'), token (accès page de suivi, 32 hex),
  created_at, status ('attente_paiement'|'livree'|'annulee'),
  price_cents, first_name, last_name, email,
  consent_text, consent_at, consent_ip,
  paid_at, admin_note,
  dl_token_hash (sha256 hex, NULL tant que non livré), dl_expires_at, dl_count, dl_max
)
ebook_downloads(order_id, at, ip, user_agent)
```

La table `orders` n'est pas modifiée.

### Fichiers

- Maître : `boutique-data/epub/maitre.epub`, dossier 0700, fichier 0600.
- Exemplaires tatoués : générés à la demande dans un fichier temporaire, streamés puis supprimés (pas de stockage des copies). Le builder peut choisir un cache `boutique-data/epub/ventes/EB-xxxx.epub` s'il mesure que la génération est lente ; dans ce cas même droits et purge à l'expiration.

### Téléchargement `/telecharger/<jeton>`

- Jeton : 32 octets aléatoires en base64url ; seul `sha256(jeton)` est stocké. Comparaison par recherche du hash.
- Refus (page HTML neutre, sans révéler la raison exacte à un tiers) si : jeton inconnu, commande non `livree`, expiré (7 jours), `dl_count ≥ dl_max` (5). Message à l'acheteur : contacter `contact@` pour un nouveau lien.
- Limite de débit : par IP (ex. 20/heure) via `rate_limited()` existant.
- Réponse : `Content-Type: application/epub+zip`, `Content-Disposition: attachment; filename="les-hormones-d-yvonne.epub"`, `Cache-Control: private, no-store, max-age=0`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`.
- Incrément `dl_count` et ligne `ebook_downloads` dans la même transaction, avant l'envoi.
- Alerte mail à l'admin si une commande est téléchargée depuis plus de 3 IP distinctes.

### Tatouage

Copie du maître via `ZipArchive`, en respectant le format EPUB :
- `mimetype` premier fichier, **non compressé** (`CM_STORE`), sans champ extra.
- Ajout d'une page XHTML `exemplaire.xhtml` : « Exemplaire personnel de Prénom Nom (email) — commande EB-0007 — date. Merci de ne pas le diffuser. » Déclarée dans le manifeste de l'OPF et insérée dans le spine **juste après la couverture** (ou en première position si pas de couverture identifiable).
- Métadonnées OPF : `dc:rights` / `meta` avec la référence de commande.
- Marque discrète : commentaire XML contenant un identifiant (HMAC de la référence avec une clé de `config.php`) dans chaque fichier XHTML du contenu.
- Texte échappé (XML) ; gestion EPUB 2 et EPUB 3 (chemin de l'OPF lu dans `META-INF/container.xml`).
- Les epubs chiffrés (`META-INF/encryption.xml`) sont refusés au dépôt.
- Le livre de Vanessa est **redistribuable (reflowable)** : la page d'exemplaire se place après la couverture. Garde-fou : si l'OPF déclare `rendition:layout` = `pre-paginated` (mise en page fixe), la page est ajoutée **en fin de spine** pour ne pas décaler les doubles pages, et l'admin le signale au dépôt.

### Durcissement général du site

- `.htaccess` : `Strict-Transport-Security: max-age=31536000` ; `Header unset X-Powered-By` + `expose_php = Off` via hPanel (Options PHP) si l'en-tête persiste derrière le CDN.
- Règle explicite : `^telecharger/` et `^numerique/merci` jamais cachés (en-têtes PHP suffisent, vérifier derrière hcdn).
- `chmod 600` sur `config.php`, `commandes.sqlite` (et `-wal`/`-shm`) — fait par `ensure_private_dir()` ou documenté.
- Hors code, à faire par l'humain : changer le mot de passe admin (présent en clair dans `TODO.md` du vault), archiver puis supprimer `ancien-site/`.

### Tests

- PHP (`tests/php/ebook_test.php`, lancé par `npm run test:php`) : validation de commande et consentement obligatoire ; génération/expiration/compteur des jetons ; refus avant paiement ; tatouage → archive valide (`mimetype` premier + stocké, OPF parsable, page ajoutée au spine, nom présent), epub 2 et 3.
- Un epub de test minimal est généré par le test lui-même (pas de vrai livre dans le dépôt).
- Vérification manuelle avant ouverture : commande réelle à 4,99 €, ouverture du fichier tatoué dans Calibre / Apple Books / une liseuse, en-têtes de réponse vus depuis l'extérieur (CDN).

## Décisions issues de la relecture builder (choix de Claude, à corriger si besoin)

1. **IP réelle derrière le CDN hcdn.** Avant tout code dépendant de l'IP, déployer un diagnostic temporaire (protégé par la session admin) qui affiche `REMOTE_ADDR` et les en-têtes `X-Forwarded-For` / `X-Real-IP` / équivalent hcdn, puis le supprimer. Si `REMOTE_ADDR` est une IP du CDN : activer `trust_proxy` dans `boutique-data/config.php` et ne lire l'en-tête que si `REMOTE_ADDR` appartient au CDN (ou au minimum prendre la dernière entrée de `X-Forwarded-For`, comportement actuel). Les limites de débit et `consent_ip` en dépendent ; corriger aussi pour le tunnel papier.
2. **Bouton d'accueil.** Le code ajoute le bouton à la mise en page par défaut (`home_default_layout`). Si une mise en page personnalisée est déjà enregistrée en production, ne pas la modifier par script : Vanessa ajoute un élément « bouton » vers `/numerique` dans `/admin/accueil` (procédure dans le README). Le libellé du bouton ne contient pas le prix ; le prix affiché sur `/numerique`, la page de paiement et les mails vient du réglage.
3. **Interrupteur d'ouverture.** Réglage `ebook_vente_ouverte` (défaut : fermé). Fermé : `/numerique` affiche « bientôt disponible », `POST /api/ebook-orders` répond 403, le bouton par défaut est masqué. Une session admin connectée peut quand même commander (pour la commande test réelle à 4,99 €). Ouverture refusée tant qu'aucun fichier maître n'est déposé.
4. **Conservation des données.** Commandes et preuve de consentement conservées (pièces comptables, 10 ans). Journal des téléchargements (IP, user-agent) purgé automatiquement après 12 mois, de façon opportuniste lors d'une requête admin (pas de cron). À reprendre dans la politique de confidentialité.
5. **Limite de dépôt.** La taille maximale acceptée par `/admin/epub` = min(50 Mo, `upload_max_filesize`, `post_max_size`) lue à l'exécution et affichée ; si trop basse, augmenter via hPanel → Options PHP.

## Laissé au builder

- Découpage exact des fichiers PHP (nouveau `_prive/ebook.php` recommandé), nommage des routes internes.
- Présentation des pages publiques (reprendre `Base.astro`, `Steps.astro`, `global.css`).
- Format de l'export CSV epub.
- Texte des mails (ton de Vanessa, cf. mails existants « Hormonalement vôtre »).

## Prérequis humains avant ouverture

- Fichier `.epub` final fourni par Vanessa (non chiffré).
- CGV + mentions légales + politique de confidentialité en ligne (vente à distance de contenu numérique).
- Statut TVA vérifié (franchise en base ou TVA 5,5 % livre numérique) ; prix unique du livre numérique : même prix partout.
