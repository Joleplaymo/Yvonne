# vanessagandino — site + boutique (hors WordPress)

Landing « Les hormones d'Yvonne » + tunnel de vente : panier → point relais Mondial Relay → paiement par lien Revolut → suivi.

- **Pages publiques** : générées en HTML statique par Astro (`src/`).
- **Côté serveur** : PHP 8.3 + SQLite (`php/`). Il gère commandes, page de paiement, admin et e-mails.
- **Le même code tourne partout** : sur l'hébergement mutualisé Hostinger actuel (offre Premium, sans surcoût), ou en Docker (PHP-Apache + Caddy) sur un VPS.

```
site/
├─ src/pages/          index, panier, commande (statiques) + gabarit(-admin) pour PHP
├─ src/lib/pricing.ts  calcul affiché dans le navigateur (le serveur recalcule toujours)
├─ php/
│  ├─ .htaccess        routes (/merci, /admin, /api/*), HTTPS, protections
│  ├─ api/             config.php (tarifs), orders.php (création de commande)
│  ├─ merci.php        page de paiement / suivi client
│  ├─ admin/           connexion, liste, fiche commande, export CSV
│  └─ _prive/          lib.php, mail.php, admin.php, config.example.php (jamais servis)
├─ tests/              pricing.test.ts (vitest) + php/lib_test.php
├─ scripts/assemble.mjs  dist/ + php/ → build/public_html (+ deploy/hostinger.zip)
└─ Dockerfile, docker-compose.yml (prod), docker-compose.dev.yml (dev)
```

## Le parcours client

1. **Landing** `/` : « Je le veux », « Commander » → `/panier?ajouter=1`.
2. **Panier** `/panier` : 1 à 6 exemplaires, frais de port, total. Tarifs lus sur `/api/config`.
3. **Commande** `/commande` : coordonnées + point relais.
   - Sans code enseigne : saisie manuelle (nom, n°, adresse).
   - Avec `mr_brand` : carte Mondial Relay, un clic sur le repère remplit tout.
4. **Paiement** `/merci?ref=YV-0007&t=…` : récapitulatif, bouton Revolut, référence à indiquer. Le client reçoit le même lien par e-mail.

## Le quotidien côté autrice : `/admin`

- Connexion avec l'identifiant et le mot de passe de la configuration (10 essais par quart d'heure).
- **À traiter** : commandes en attente de paiement et commandes payées à expédier.
- Paiement reçu sur Revolut → **Paiement reçu** : le client reçoit un mail.
- Bloc **« Pour l'étiquette Mondial Relay »** → **Copier** → **Créer l'envoi** sur mondialrelay.fr.
- N° de suivi → **Marquer expédiée** : le client reçoit un mail avec le suivi.
- **Export CSV** (Excel FR).
- **Livraison** (`/admin/livraison`) : frais de port par paliers (« à partir de N livres → prix »), aperçu de ce que paie le client pour 1 à 6 livres. Les nouveaux frais s'appliquent immédiatement aux nouvelles commandes ; ils priment sur `shipping_table` de `config.php` (bouton pour y revenir).
- **Photos** (`/admin/photos`) : remplacer la couverture, le portrait, l'extrait ou le logo partenaire en choisissant un fichier (JPEG, PNG, WebP, GIF, 15 Mo max).
  L'image est redimensionnée, convertie et débarrassée de ses métadonnées automatiquement, puis visible sur le site en 5 minutes au plus.
  « Rétablir l'originale » revient à la photo livrée avec le site.
  Les photos personnalisées sont rangées dans `boutique-data/photos/` et survivent aux mises à jour du site.

- **Textes** (`/admin/textes`) : modifier les textes de l'accueil (titres, phrases, boutons, citations). Retour à la ligne avec Entrée, `**mot**` pour le gras. « Rétablir l'original » revient au texte livré avec le site.
  Les textes modifiés sont rangés dans la base (`commandes.sqlite`, table `settings`, clés `texte:…`). L'accueil est alors servi par `php/index.php`, qui remplace les textes repérés dans `_prive/accueil.html`.
  Pour rendre un nouveau texte modifiable : le déclarer dans `src/content/textes.json` et l'afficher avec `<T k="clé" />`.

- **Couleurs** (`/admin/couleurs`) : changer la palette de l'accueil, du panier, de la commande et des pages de paiement (6 couleurs, aperçu en direct, alerte si le contraste est faible). Les nuances proches (survol, fonds, textes atténués) se calculent seules. L'admin garde ses couleurs.
  Les couleurs modifiées sont rangées dans `settings` (clés `couleur:…`) et ajoutées aux pages sous forme de variables CSS. Accueil, panier et commande sont donc servis par PHP (`index.php`, `page.php`).

- **Accueil** (`/admin/accueil`) : page d'accueil entièrement paramétrable. Une section contient des lignes, une ligne des colonnes, une colonne des éléments (titre, paragraphe, image, boutons, icône, image + légende, espace). Tout s'ajoute, se supprime, se déplace et se règle (textes, photos, tailles, couleurs, espacements, alignements, fond de chaque section).
  La mise en page est rangée en JSON dans `settings` (clé `accueil_mise_en_page`) et composée par `php/index.php` à l'emplacement `<!--home-->` de `index.astro`. Tant qu'elle n'est pas enregistrée, la page d'origine (`home_default_layout` dans `php/_prive/accueil.php`) s'affiche, avec les textes de `/admin/textes`. Un texte réécrit dans l'éditeur ne suit plus `/admin/textes`.
  « Rétablir la mise en page d'origine » efface tout. L'aperçu local (`npm run dev`) n'affiche pas ces sections : il faut `npm run assemble` puis `npm run php`.

- **En-tête / pied** (`/admin/entete`) : barre du haut (nom avec police et couleur au choix, jusqu'à 6 liens (police et couleur au choix) avec visibilité sur téléphone, lien panier, couleurs, hauteur, barre fixe ou non) et pied de page (texte et liens avec police et couleur au choix, jusqu'à 6 icônes-liens, couleurs, hauteur) sur toutes les pages publiques.
  `Base.astro` repère les deux par `<!--chrome:header-->` / `<!--chrome:footer-->` ; sans configuration enregistrée (`settings`, clés `site_entete` et `site_pied`), le gabarit d'origine reste en place.
- Dans l'éditeur d'accueil, chaque section, ligne, colonne et élément se replie / se déplie (boutons « Tout déplier », « Tout replier », « Sections ouvertes, détails repliés » ; l'état est retenu pendant la session).

- **Référencement** (`/admin/referencement`) : titre et description de l'accueil tels que Google et les aperçus de liens (WhatsApp, Facebook…) les affichent, avec aperçu Google et compteur de caractères ; option pour montrer ou non la couverture dans les aperçus. `php/index.php` remplace `<title>` et la meta description du gabarit et ajoute les balises Open Graph (settings : `seo_titre`, `seo_description`, `seo_image`).

## Mise en ligne sur Hostinger (offre actuelle)

1. **Paquet** (sur ce PC) : `cd site && npm install && npm run package:hostinger` → `deploy/hostinger.zip`.
2. **Sauvegarde du WordPress** : hPanel → Sauvegardes. Puis WooCommerce → Commandes → export CSV.
3. **Racine du site** : vider le dossier du sous-domaine (`public_html/…` du site), puis y téléverser et décompresser `hostinger.zip` avec le gestionnaire de fichiers.
4. **Configuration** : dans le dossier parent de `public_html`, le dossier `boutique-data/` est créé à la première visite.
   Y créer `config.php` à partir de `_prive/config.example.php`, puis remplir au minimum `admin_password` et `smtp_password` (mot de passe de la boîte contact@).
   Ce dossier est hors du site web : commandes (`commandes.sqlite`), configuration et journal (`journal.log`) n'y sont pas accessibles depuis Internet.
5. **Vérifier** : accueil, `/panier`, une commande test, réception des 2 e-mails, `/admin`.

Mise à jour du site : refaire 1, puis téléverser le zip dans `public_html` et l'extraire en écrasant les fichiers existants. `boutique-data/` (commandes, config, photos) n'est jamais touché.
Sauvegarde : télécharger régulièrement `boutique-data/commandes.sqlite` (les sauvegardes Hostinger le couvrent aussi).

## Développement local

```bash
npm install
npm run build && npm run assemble     # build/public_html
npm run php                           # PHP + Apache sur :8080, e-mails dans Mailpit (:8025)
npm run dev                           # Astro sur :4321 (relaie /api, /merci, /admin vers :8080)
npm test && npm run test:php && npm run check
```

Le `.env` de dev contient `ADMIN_PASSWORD`, et `MR_BRAND="BDTEST  "` pour essayer la carte de démo.

## Version Docker (VPS)

```bash
cp .env.example .env && nano .env      # DOMAIN, ADMIN_PASSWORD, SMTP_PASSWORD, tarifs…
docker compose up -d --build           # Caddy obtient le certificat HTTPS
```

Les tests tournent pendant le build. Sauvegarde : `./scripts/backup.sh` (cron conseillé).

## Points à connaître

- **Revolut** : `revolut.me` ne prévient pas le site d'un paiement. La confirmation reste manuelle (bouton dans l'admin).
  Si votre lien accepte un montant prérempli (à tester, par ex. `https://revolut.me/v_gandino/{amount}EUR`), mettez-le dans `revolut_link_template`.
- **Mondial Relay** : la carte demande un code enseigne (compte pro). Sans lui, la saisie manuelle fonctionne.
- **E-mails** : la boîte Hostinger `contact@vanessagandino.fr` sert d'expéditeur SMTP (`smtp.hostinger.com:465`).

## Avant d'ouvrir la boutique

- [ ] Frais de port réels (page `/admin/livraison`).
- [ ] CGV, mentions légales, politique de confidentialité (obligatoires pour la vente en ligne).
- [ ] Commande test de bout en bout avec un vrai paiement Revolut de faible montant.
