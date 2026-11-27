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
│  ├─ numerique*.php, telecharger.php  vente et téléchargement de l'epub
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
- **Notifications** (`/admin/notifications`) : jusqu'à 3 adresses qui reçoivent les avis d'achat et alertes (une par ligne). Sans réglage, on retombe sur `admin_email` de `config.php` (bouton pour y revenir). Bouton « Envoyer un mail de test » (5 par 10 minutes). Quand la liste change, les anciennes adresses reçoivent une alerte. Le `Reply-To` des mails clients reste l'`admin_email` de `config.php`.
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
  Même page : **favicon** (image envoyée, recadrée au carré, rangée en PNG 48/180/192 dans `boutique-data/favicon/`, servie par `/favicon/{taille}.png` et `/favicon.ico` ; `favicon_apply` remplace le `<link rel="icon">` d'origine, `/favicon.svg`, tant qu'aucun n'est envoyé).
- Dans l'éditeur d'accueil, chaque section, ligne, colonne et élément se replie / se déplie (boutons « Tout déplier », « Tout replier », « Sections ouvertes, détails repliés » ; l'état est retenu pendant la session).

- **Référencement** (`/admin/referencement`) : titre et description de l'accueil tels que Google et les aperçus de liens (WhatsApp, Facebook…) les affichent, avec aperçu Google et compteur de caractères ; option pour montrer ou non une image dans les aperçus, et photo de partage au choix (emplacement `partage` des photos, `og:image` = cette photo, sinon la couverture). `php/index.php` remplace `<title>` et la meta description du gabarit et ajoute les balises Open Graph (settings : `seo_titre`, `seo_description`, `seo_image`).

## Livre numérique (epub à 4,99 €)

Vente du livre en `.epub`, sans panier : page `/numerique`, paiement par lien Revolut comme le papier, lien de téléchargement personnel envoyé par mail. Le code est dans `php/_prive/ebook.php` (commandes `EB-xxxx`), `epub.php` (tatouage) et `php/numerique.php`, `php/numerique-merci.php`, `php/telecharger.php`.

Tout se règle dans **`/admin/epub`** :

- **Déposer le fichier** : envoyer l'epub de référence (le « master »). Il est rangé dans `boutique-data/`, hors du site web. Chaque client reçoit une copie tatouée à son nom (nom et e-mail cachés dans le fichier) ; le master n'est jamais servi tel quel.
- **Prix** : 4,99 € par défaut, modifiable.
- **CGV** : l'adresse de la page des conditions de vente, affichée sous le formulaire avec la mention « En commandant, vous acceptez les conditions générales de vente ». Seule la case de renonciation au droit de rétractation est à cocher ; son consentement (texte, date, IP) est conservé avec la commande.
- **Lien d'aperçu et commande test** : tant que la vente est fermée, `/numerique?apercu=<clé>` (lien affiché dans `/admin/epub`) montre la page et permet de passer une vraie commande pour tout tester. **Tester le tatouage** produit un epub d'essai à ouvrir sur une liseuse.
- **Ouvrir la vente** : interrupteur. La vente ne s'ouvre que si le fichier est déposé. Le bouton « Version numérique » apparaît alors dans la section du haut (le hero) de l'accueil par défaut.

### Au quotidien

- **Le lien** ouvre une page « Votre livre numérique est prêt » avec un bouton ; seul le clic sur ce bouton compte un téléchargement (les aperçus de liens et scanners de courrier, qui ne font que lire la page, ne comptent pas).
- **Valider un paiement** : paiement reçu sur Revolut → `/admin/EB-xxxx` → **Paiement reçu — envoyer le lien**. Le client reçoit un mail avec son lien (valable 7 jours, 5 téléchargements).
- **Renvoyer un lien** : sur la fiche, **Renvoyer un nouveau lien** (l'ancien cesse de marcher). À utiliser aussi si le client dit ne pas avoir reçu le mail, ou si son lien est expiré ou épuisé (le client voit « Lien invalide ou expiré » et est invité à écrire).
- **Le mail n'est pas parti** : la fiche affiche « l'e-mail n'a pas pu être envoyé » (la commande est quand même livrée). Vérifier `smtp_password`, puis **Renvoyer un nouveau lien**.
- **Annuler / Réactiver** une commande (« Réactiver (en attente de paiement) » révoque le lien), et une **note** privée par commande.
- **Alerte de partage** : si un même lien est téléchargé depuis beaucoup d'adresses IP différentes, vous recevez un mail d'alerte (une seule fois par commande). Un lien partagé se coupe en renvoyant un nouveau lien.
- **Export CSV** : `/admin/export-numerique.csv` (Excel FR), à garder pour la comptabilité.

### Bouton d'accueil

Si l'accueil n'a jamais été enregistré dans `/admin/accueil`, le bouton « Version numérique » s'ajoute et se retire tout seul avec l'ouverture de la vente. Si la mise en page est personnalisée, rien ne s'ajoute : dans `/admin/accueil`, ajouter à l'endroit voulu un élément **« Boutons »** avec le texte **« Version numérique »** et l'adresse **`/numerique`** (style « contour » conseillé), à enregistrer quand la vente est ouverte.

### Conservation des données

Commandes et consentements sont gardés (obligations comptables et preuve du consentement). Le journal des téléchargements (IP, date) est purgé automatiquement après 12 mois.

### Diagnostic IP (une seule fois, après la mise en ligne)

Derrière le CDN de Hostinger, `REMOTE_ADDR` n'est pas l'IP du visiteur. Pour trouver le bon en-tête :

1. Connecté à `/admin`, ouvrir `/admin/diagnostic` **depuis un téléphone en 4G** (pas le wifi).
2. Repérer la ligne qui contient votre IP publique (par ex. `HTTP_X_REAL_IP`). Ce doit être un en-tête à une seule valeur posé par le CDN, **jamais** `HTTP_X_FORWARDED_FOR` (le visiteur peut en choisir la première valeur).
3. Mettre cette clé dans `client_ip_header` de `boutique-data/config.php`, puis rouvrir `/admin/diagnostic` : `client_ip()` doit afficher votre IP.
4. **Vérifier que le CDN écrase un en-tête falsifié** : toujours connecté en admin, rouvrir `/admin/diagnostic` avec un en-tête forgé, par ex. `curl -H 'X-Real-IP: 1.2.3.4' -b <cookie> https://…/admin/diagnostic` (ou une extension de navigateur). Si la valeur forgée apparaît telle quelle, ne configurez pas `client_ip_header` avec cet en-tête.
5. **Supprimer ensuite `php/admin/diagnostic.php` et la ligne `admin/diagnostic` de `php/.htaccess`**, puis republier.

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

### Avant d'ouvrir la vente du livre numérique

- [ ] Changer le mot de passe admin (`admin_password`).
- [ ] Diagnostic IP fait, puis `diagnostic.php` supprimé (voir plus haut).
- [ ] Ouvrir l'epub tatoué (« Tester le tatouage ») avec `epubcheck`, ou dans Calibre, Apple Books et Kobo.
- [ ] Commande réelle à 4,99 € via le lien d'aperçu, jusqu'à la réception du mail et du fichier.
- [ ] Depuis l'extérieur : `curl -sI https://<site>/telecharger/<jeton>` (HEAD, ne compte jamais) doit montrer pour la page `Cache-Control: no-store` (qui doit survivre au CDN : une 2e requête ne doit pas venir du cache), `X-Robots-Tag`, `Referrer-Policy` et `Strict-Transport-Security`. Pour les en-têtes du fichier (`application/epub+zip`, `Content-Disposition`, `Cache-Control: private, no-store, max-age=0`), faire un vrai téléchargement depuis un navigateur, ou `curl -s -D- -o /dev/null -X POST https://<site>/telecharger/<jeton>` : cela compte 1 téléchargement.
- [ ] CGV en ligne et adresse renseignée dans `/admin/epub`.
- [ ] Statut TVA vérifié (franchise en base ou TVA à facturer sur le numérique).
