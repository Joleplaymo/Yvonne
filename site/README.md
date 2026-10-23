# vanessagandino — site + boutique (hors WordPress)

Landing « Les hormones d'Yvonne » + tunnel de vente : panier → point relais Mondial Relay → paiement par lien Revolut → suivi.
Un seul conteneur Node (Astro 7 + SQLite intégré à Node) derrière Caddy (HTTPS automatique).

```
site/
├─ src/pages/        index (landing), panier, commande, merci, admin/, api/orders
├─ src/lib/          pricing, validation, db (SQLite), mail, revolut, server (config)
├─ tests/            tests unitaires (vitest)
├─ Dockerfile        build + tests + image de prod
├─ docker-compose.yml  app + caddy
└─ .env.example      toute la configuration
```

## Le parcours client

1. **Landing** `/` : boutons « Je le veux », « Commander » → `/panier?ajouter=1`.
2. **Panier** `/panier` : quantité 1 à 6, frais de port calculés, total.
3. **Commande** `/commande` : coordonnées + point relais.
   - Sans `MR_BRAND` : lien vers le localisateur Mondial Relay + saisie manuelle (nom, n°, adresse).
   - Avec `MR_BRAND` : carte Mondial Relay, le client clique le repère du relais, tout se remplit.
4. **Paiement** `/merci?ref=YV-0007&t=…` : récap, bouton « Payer avec Revolut », référence à mettre dans le message.
   Le client reçoit le même lien par e-mail et peut revenir sur cette page pour suivre sa commande.

Le serveur recalcule toujours le total : le navigateur ne fixe jamais le prix.

## Le quotidien côté autrice : `/admin`

Identifiants : `ADMIN_USER` / `ADMIN_PASSWORD` du `.env`.

- Onglet **À traiter** : commandes en attente de paiement + payées à expédier.
- Revolut a reçu un paiement → ouvrir la commande (référence dans le message, sinon montant + nom) → **Paiement reçu** → mail auto au client.
- Préparer le colis : bloc **« Pour l'étiquette Mondial Relay »** → **Copier** → **Créer l'envoi** sur mondialrelay.fr.
- Saisir le n° de suivi → **Marquer expédiée** → mail auto avec le suivi.
- **Export CSV** (Excel FR) en haut de page pour la compta.

## Développement local

```bash
cd site
npm install
cp .env.example .env   # puis mettre au moins ADMIN_PASSWORD
npm run dev            # http://localhost:4321
npm test               # tests unitaires
npm run check          # typage
```

Sans `SMTP_URL`, les e-mails s'affichent dans le terminal au lieu d'être envoyés.
Pour essayer la carte Mondial Relay : `MR_BRAND="BDTEST  "` (compte de démo, avec 2 espaces).

## Mise en production sur l'hébergement Hostinger actuel (sans Docker)

Il faut un plan qui accepte les **applications Node.js** (Business Web Hosting ou Cloud, à vérifier dans hPanel).
Les plans d'entrée de gamme ne les acceptent pas : passer alors au plan supérieur ou à un VPS.

1. **Sauvegarder le WordPress.** Dans hPanel → Sites → *le site* → Sauvegardes : créer une sauvegarde complète.
   Puis WooCommerce → Commandes → exporter en CSV.
2. **Construire le paquet** (sur ce PC) :
   ```bash
   cd site
   npm run build
   npm run package:hostinger   # → deploy/hostinger.zip
   ```
3. **Créer l'application.** hPanel → *Ajouter un site* → **Application Node.js** → *Téléverser des fichiers* → `deploy/hostinger.zip`.
   - Version de Node : **22 ou 24** (minimum 22.13).
   - Commande de démarrage : `npm run start`.
   - Domaine : d'abord un sous-domaine de test (ex. `nouveau.vanessagandino.fr`) pour vérifier, puis `leshormonesdyvonne.vanessagandino.fr`.
     Ce domaine est aujourd'hui attaché au WordPress : il faudra l'en détacher (ou supprimer ce site WP, une fois la sauvegarde faite).
4. **Variables d'environnement** (dans les réglages de l'application) : reprendre celles de `.env.example`, et en plus :
   - `DATA_DIR` = un dossier **hors du dossier de l'application** (chemin absolu visible dans le gestionnaire de fichiers, par ex. `/home/uXXXX/boutique-data`). Sans cela, un redéploiement effacerait les commandes.
   - `PUBLIC_BASE_URL=https://leshormonesdyvonne.vanessagandino.fr`
   - `SMTP_URL` avec la boîte mail Hostinger : `smtps://contact%40vanessagandino.fr:MOTDEPASSE@smtp.hostinger.com:465`
     (`@` du login écrit `%40` ; caractères spéciaux du mot de passe à encoder de la même façon).
   - `ADMIN_PASSWORD` : long et unique.
   - Si le site est servi sur un autre domaine que `leshormonesdyvonne.vanessagandino.fr`, reconstruire avec `SITE_DOMAINS=ce-domaine` avant l'étape 2. Sinon les formulaires de l'admin sont refusés (protection anti-CSRF).
5. **Vérifier** : page d'accueil, une commande test, `/admin`, réception des 2 e-mails.
6. **Sauvegardes** : télécharger régulièrement `commandes.sqlite` depuis `DATA_DIR` (gestionnaire de fichiers). Les sauvegardes Hostinger couvrent aussi ce dossier.

Mise à jour du site : refaire les étapes 2-3 (nouveau zip). Les commandes restent dans `DATA_DIR`.

## Mise en production (VPS avec Docker)

1. Un VPS (Debian/Ubuntu, 1 Go de RAM suffit) avec Docker installé.
2. DNS : un enregistrement **A** de `leshormonesdyvonne.vanessagandino.fr` (et `www.`) vers l'IP du VPS.
3. Sur le serveur :
   ```bash
   git clone <repo> /opt/vanessagandino && cd /opt/vanessagandino/site
   cp .env.example .env && nano .env      # DOMAIN, ADMIN_PASSWORD, SMTP_URL, tarifs…
   docker compose up -d --build
   ```
4. Caddy obtient le certificat HTTPS tout seul au premier accès (ports 80 et 443 ouverts).
5. Sauvegarde quotidienne : `crontab -e` →
   `0 3 * * * cd /opt/vanessagandino/site && ./scripts/backup.sh`
   puis copier `backups/` ailleurs (autre machine, stockage objet…).

Mise à jour : `git pull && docker compose up -d --build`. Les tests tournent pendant le build : s'ils échouent, le site en place reste en ligne.
Changement de prix, de frais de port ou de lien Revolut : modifier `.env` puis `docker compose up -d` (pas de rebuild).
Changement de `DOMAIN` : `docker compose up -d --build` (le domaine est figé au build pour la sécurité des formulaires).

## Points à connaître

- **Revolut** : `revolut.me` n'envoie aucune notification au site, donc la confirmation du paiement reste manuelle (bouton dans l'admin).
  Si le lien accepte un montant prérempli (à tester avec votre compte, par ex. `https://revolut.me/v_gandino/{amount}EUR`), mettez-le dans `REVOLUT_LINK_TEMPLATE` : la page de paiement s'adapte.
  Étape suivante possible : API Revolut Business (Merchant), avec un lien par commande et une confirmation automatique.
- **Mondial Relay** : la carte exige un code enseigne. Il s'obtient en ouvrant un compte pro chez Mondial Relay.
  Sans ce code, la saisie manuelle fonctionne.
- **E-mails** : prendre un SMTP transactionnel (Brevo, Mailjet, OVH…) avec SPF/DKIM sur le domaine, sinon les mails tombent en spam.
- **Données** : un seul fichier SQLite dans le volume Docker `data` (`/data/commandes.sqlite`).

## Avant d'ouvrir la boutique

- [ ] Frais de port réels dans `SHIPPING_TABLE` (valeurs par défaut = exemples).
- [ ] CGV, mentions légales, politique de confidentialité (obligatoires pour la vente en ligne en France) : pages à rédiger puis lier depuis le pied de page et la page commande.
- [ ] SMTP configuré, plus une commande test de bout en bout avec un vrai paiement Revolut de faible montant.
- [ ] Bascule DNS depuis WordPress, puis exporter les anciennes commandes WooCommerce (CSV) pour archive.
