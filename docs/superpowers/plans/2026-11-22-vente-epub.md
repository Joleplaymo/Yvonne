# Vente de l'epub — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** vendre l'epub à 4,99 € par un tunnel séparé (commande → Revolut → validation admin → lien de téléchargement tatoué, limité et non cachable).

**Architecture :** nouveau module PHP `site/php/_prive/ebook.php` (données, jetons, réglages) + `site/php/_prive/epub.php` (validation et tatouage via `ZipArchive`), pages publiques rendues en PHP avec `render_page()` (pas de nouvelle page Astro), admin calquée sur `admin/commande.php`. Tables `ebook_orders` / `ebook_downloads` ajoutées dans `db()`, table `orders` intacte.

**Tech Stack :** PHP 8.3 (PDO SQLite, ZipArchive, DOMDocument), Apache `.htaccess`, Astro 7 (gabarits uniquement), tests PHP maison (`check()`), lancés par `npm run test:php` (Docker).

**Spec :** `docs/superpowers/specs/2026-11-22-vente-epub-design.md` — à lire avant de commencer.

Tous les chemins ci-dessous sont relatifs à `Yvonne/site/`. Commandes lancées depuis `Yvonne/site/`.

## Global Constraints

- PHP 8.3, `declare(strict_types=1);` en tête de chaque fichier PHP ; aucune dépendance Composer.
- Prix par défaut : **499 centimes** (réglage `ebook_price_cents`). Référence : `EB-%04d`.
- Lien de téléchargement : **7 jours**, **5 téléchargements** ; jeton = 32 octets aléatoires en base64url (43 caractères) ; seul `hash('sha256', $jeton)` est stocké.
- Limites de débit : création de commande `8 / 3600 s` par IP ; téléchargement `20 / 3600 s` par IP.
- Alerte admin si une commande est téléchargée depuis **plus de 3** IP distinctes (une seule fois par commande).
- Journal des téléchargements purgé après **12 mois**. Commandes et consentements conservés.
- Fichier maître : `<data_dir>/epub/maitre.epub`, dossier `0700`, fichier `0600`. Taille max de dépôt : `min(50 Mo, upload_max_filesize, post_max_size)`.
- Réponse de téléchargement : `Content-Type: application/epub+zip`, `Content-Disposition: attachment; filename="les-hormones-d-yvonne.epub"`, `Cache-Control: private, no-store, max-age=0`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`.
- Texte de consentement exact (enregistré tel quel en base et repris dans le mail) : « Je demande l'accès au livre numérique dès la validation de mon paiement et je reconnais perdre mon droit de rétractation. »
- Vente fermée par défaut (réglage `ebook_vente_ouverte` absent ou `'0'`) ; ouverture refusée sans fichier maître.
- Pages et mails en français, ton des mails existants (`mail.php`, signature « Vanessa »).
- Ne jamais modifier la table `orders` ni le tunnel papier, sauf `client_ip()` (tâche 1).
- **Avant la tâche 1 :** le dépôt `Yvonne/` est sur `feat/boutique-autonome` avec ~22 fichiers modifiés non commités. Demander à l'humain de les commiter ou de les mettre de côté, puis créer la branche `feat/vente-epub`.

## Review Focus

1. **Epub réel exporté par un logiciel du commerce** (OPF dans un sous-dossier `OEBPS/`, `href` relatifs, couverture déclarée par `properties="cover-image"` ou `<meta name="cover">`, guide EPUB 2) : la page d'exemplaire doit atterrir au bon endroit avec un `href` relatif correct → tests epub2 + epub3 avec OPF en sous-dossier (tâche 3).
2. **Nom d'acheteur contenant `<`, `&`, guillemets ou emoji** : le XHTML et l'OPF générés restent du XML bien formé → test `tatouage : nom à caractères spéciaux` (tâche 3).
3. **Lien ouvert deux fois en parallèle au 5ᵉ téléchargement** : jamais plus de 5 → incrément conditionnel en SQL dans une transaction, test `6e téléchargement refusé` (tâche 2).
4. **« Renvoyer un lien » pendant qu'un ancien lien circule** : l'ancien lien est mort immédiatement → test `ancien jeton invalide après renvoi` (tâche 2).
5. **Commande annulée après livraison** : le lien cesse de fonctionner → test `jeton refusé si annulée` (tâche 2).

---

### Task 1 : Durcissement et vraie IP derrière le CDN

**Files :**
- Modify : `php/.htaccess` (bloc `mod_headers`)
- Modify : `php/_prive/lib.php` (`cfg()`, `client_ip()`, `ensure_private_dir()`)
- Modify : `php/_prive/config.example.php`
- Create : `php/admin/diagnostic.php` (temporaire)
- Test : `tests/php/lib_test.php`

**Interfaces :**
- Produces : `client_ip(): string` (inchangée en signature) ; nouvelle clé de config `client_ip_header` (string, défaut `''`, ex. `'HTTP_X_REAL_IP'`) ; `secure_file(string $path): void` (chmod 0600 si le fichier existe, silencieux sinon).

- [ ] **Step 1 : tests qui échouent** dans `tests/php/lib_test.php` :
  - `client_ip : en-tête configuré prioritaire` — avec `client_ip_header = 'HTTP_X_REAL_IP'` et `$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.7'`, `REMOTE_ADDR = '10.0.0.1'` → `'203.0.113.7'`.
  - `client_ip : en-tête invalide ignoré` — valeur `'pas-une-ip'` → retombe sur `REMOTE_ADDR`.
  - `secure_file : 0600` — fichier temporaire créé en 0644, `secure_file()` → `fileperms() & 0777 === 0600`.
  (Pour modifier la config en test, exposer `cfg_override(array $values): void` dans `lib.php`, réservé aux tests, qui fusionne dans le cache statique de `cfg()`.)
- [ ] **Step 2 :** `npm run test:php` → ÉCHEC sur ces 3 tests.
- [ ] **Step 3 : implémenter.** `client_ip()` : si `client_ip_header` non vide et `$_SERVER[header]` passe `filter_var(..., FILTER_VALIDATE_IP)` (première entrée si liste), la renvoyer ; sinon logique actuelle. `ensure_private_dir()` appelle `secure_file()` sur `config.php`, `commandes.sqlite`, `-wal`, `-shm`. `config.example.php` : documenter `client_ip_header` et `trust_proxy`.
- [ ] **Step 4 : `.htaccess`** dans `mod_headers` : `Header always set Strict-Transport-Security "max-age=31536000"` et `Header always unset X-Powered-By` + `Header unset X-Powered-By`.
- [ ] **Step 5 : diagnostic.** `php/admin/diagnostic.php` : `require_admin()`, affiche en `<pre>` échappé `REMOTE_ADDR` et toutes les clés `$_SERVER` commençant par `HTTP_X_` ou `HTTP_CF_` ou contenant `FORWARDED`/`REAL_IP`, plus la valeur actuelle de `client_ip()`. Route `.htaccess` : `RewriteRule ^admin/diagnostic$ admin/diagnostic.php [L]`.
- [ ] **Step 6 :** `npm run test:php` → tous OK.
- [ ] **Step 7 : commit** `feat(site): harden headers, configurable client IP header, admin IP diagnostic`.
- [ ] **Step 8 (humain, après déploiement) :** ouvrir `/admin/diagnostic` depuis un téléphone en 4G. Si `REMOTE_ADDR` ≠ IP du téléphone, renseigner `client_ip_header` dans `boutique-data/config.php` avec la clé qui contient la bonne IP. Vérifier `curl -sI https://leshormonesdyvonne.vanessagandino.fr/` : HSTS présent, `X-Powered-By` absent (sinon hPanel → Options PHP → `expose_php` Off). Le diagnostic est supprimé à la tâche 7.

### Task 2 : Données de vente numérique (`ebook.php`)

**Files :**
- Create : `php/_prive/ebook.php`
- Modify : `php/_prive/lib.php` (`db()` : créer les tables)
- Create : `tests/php/ebook_test.php`
- Modify : `scripts/test-php.mjs` (ajouter `tests/php/ebook_test.php` à la liste)

**Interfaces :**
- Consumes : `db()`, `get_setting()/set_setting()`, `now_iso()`, `clean_text()`, `rate_limited()`.
- Produces (tout dans `ebook.php`, qui `require_once` `lib.php`) :
  - constantes `EBOOK_STATUSES = ['attente_paiement','livree','annulee']`, `EBOOK_STATUS_LABELS` (« En attente de paiement », « Livrée », « Annulée »), `EBOOK_CONSENT_TEXT` (texte exact des Global Constraints), `EBOOK_DL_DAYS = 7`, `EBOOK_DL_MAX = 5`, `EBOOK_ALERT_IPS = 3`.
  - `ebook_price_cents(PDO $db): int` (réglage, défaut 499) ; `ebook_sale_open(PDO $db): bool` ; `ebook_cgv_url(PDO $db): string` ; `ebook_secret(PDO $db): string` (réglage `ebook_secret`, généré `bin2hex(random_bytes(32))` au premier appel).
  - `ebook_master_path(): string` (`cfg()['data_dir'].'/epub/maitre.epub'`).
  - `validate_ebook_order(array $b): array` → `['ok'=>bool,'errors'=>array<string,string>,'value'=>['first_name','last_name','email']]` ; erreurs si champ vide, email invalide, `consent` ≠ `'1'`/`true`.
  - `create_ebook_order(PDO $db, array $in, int $priceCents, string $ip): array` (même schéma de ref que `create_order`, `consent_text = EBOOK_CONSENT_TEXT`, `consent_at = now_iso()`).
  - `find_ebook_order(PDO $db, string $ref): ?array`, `list_ebook_orders(PDO $db, ?string $status = null): array`, `count_ebook_orders(PDO $db): array`.
  - `ebook_deliver(PDO $db, string $ref): string` → passe en `livree`, `paid_at` si vide, émet un **nouveau** jeton (remplace l'ancien hash, `dl_count = 0`, `dl_expires_at = +7 j`, `dl_max = 5`), renvoie le jeton en clair. Sert aussi à « Renvoyer un lien ».
  - `ebook_set_status(PDO $db, string $ref, string $status): void` (annuler / rouvrir ; rouvrir = `attente_paiement` et efface le hash).
  - `ebook_set_admin_note(PDO $db, string $ref, string $note): void`.
  - `ebook_consume_download(PDO $db, string $token, string $ip, string $ua): ?array` → commande si autorisée (et compte le téléchargement), sinon `null`. Un seul `UPDATE … SET dl_count = dl_count + 1 WHERE dl_token_hash = ? AND status = 'livree' AND dl_count < dl_max AND dl_expires_at > ?` dans une transaction, puis `INSERT` dans `ebook_downloads` ; `null` si 0 ligne modifiée.
  - `ebook_distinct_ips(PDO $db, int $orderId): int` ; `ebook_mark_alert_sent(PDO $db, int $orderId): bool` (vrai seulement la première fois).
  - `ebook_downloads_of(PDO $db, int $orderId): array` ; `ebook_purge_downloads(PDO $db, int $olderThanDays = 365): int`.
- Schéma (dans `db()`) : `ebook_orders(id INTEGER PK AUTOINCREMENT, ref TEXT UNIQUE NOT NULL, token TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime…), status TEXT NOT NULL DEFAULT 'attente_paiement', price_cents INTEGER NOT NULL, first_name, last_name, email TEXT NOT NULL, consent_text TEXT NOT NULL, consent_at TEXT NOT NULL, consent_ip TEXT NOT NULL, paid_at TEXT, admin_note TEXT NOT NULL DEFAULT '', dl_token_hash TEXT UNIQUE, dl_expires_at TEXT, dl_count INTEGER NOT NULL DEFAULT 0, dl_max INTEGER NOT NULL DEFAULT 5, alert_sent_at TEXT)` ; `ebook_downloads(order_id INTEGER NOT NULL, at TEXT NOT NULL, ip TEXT NOT NULL, user_agent TEXT NOT NULL)` + index `(order_id)` et `(at)`.

- [ ] **Step 1 : tests qui échouent** (`tests/php/ebook_test.php`, même harnais que `lib_test.php`, base `db(':memory:')` passée explicitement) :
  - `consentement obligatoire` — `validate_ebook_order([... sans consent])['errors']` a la clé `consent`.
  - `email invalide refusé`.
  - `création : ref EB-0001, prix 499, consentement horodaté` — `consent_text === EBOOK_CONSENT_TEXT`, `consent_ip === '198.51.100.1'`.
  - `jeton refusé avant paiement` — `ebook_consume_download` sur une commande non livrée → `null`.
  - `livraison : 5 téléchargements puis refus` — 5 appels non-null, le 6ᵉ `null` (`6e téléchargement refusé`).
  - `jeton expiré refusé` — forcer `dl_expires_at` dans le passé en SQL → `null`.
  - `ancien jeton invalide après renvoi` — `ebook_deliver` deux fois, le premier jeton → `null`, le second fonctionne, compteur remis à 0.
  - `jeton refusé si annulée`.
  - `jeton stocké haché` — la colonne `dl_token_hash` ≠ jeton, = `hash('sha256', jeton)` ; jeton de 43 caractères `[A-Za-z0-9_-]`.
  - `IP distinctes et alerte unique` — 4 téléchargements depuis 4 IP → `ebook_distinct_ips === 4`, `ebook_mark_alert_sent` vrai puis faux.
  - `purge 12 mois` — une ligne datée de 400 jours supprimée, une de 10 jours gardée.
  - `vente fermée par défaut` — `ebook_sale_open` faux, puis vrai après `set_setting('ebook_vente_ouverte','1')`.
  - `table orders intacte` — `PRAGMA table_info(orders)` inchangé (mêmes colonnes qu'avant).
- [ ] **Step 2 :** `npm run test:php` → ÉCHEC (fonctions absentes).
- [ ] **Step 3 : implémenter** `ebook.php` et les tables dans `db()`.
- [ ] **Step 4 :** `npm run test:php` → OK.
- [ ] **Step 5 : commit** `feat(site): ebook orders, hashed download tokens and limits`.

### Task 3 : Validation et tatouage de l'epub (`epub.php`)

**Files :**
- Create : `php/_prive/epub.php`
- Create : `tests/php/epub_test.php` (+ ajout dans `scripts/test-php.mjs`)

**Interfaces :**
- Produces :
  - `epub_validate(string $path): array` → `['ok'=>bool, 'errors'=>string[], 'layout'=>'reflowable'|'pre-paginated', 'title'=>string]`. Erreurs : pas un ZIP, premier fichier ≠ `mimetype` ou contenu ≠ `application/epub+zip`, `META-INF/container.xml` absent ou OPF introuvable, `META-INF/encryption.xml` présent.
  - `epub_watermark(string $master, string $dest, array $buyer, string $secret): void` — `$buyer = ['name'=>string,'email'=>string,'ref'=>string,'date'=>string]` ; lève `RuntimeException` si le maître est invalide.
  - `epub_fixture(string $dest, int $version = 3, bool $opfInSubdir = true, bool $fixed = false): void` — fabrique un epub minimal valide pour les tests et le bouton « Tester le tatouage » (couverture + 2 chapitres).

- [ ] **Step 1 : tests qui échouent** :
  - `fixture valide (epub 2 et 3)` — `epub_validate(...)['ok']`.
  - `zip quelconque refusé`, `epub chiffré refusé` (fixture + `META-INF/encryption.xml`).
  - `tatouage : mimetype premier et non compressé` — `ZipArchive::statIndex(0)` : `name === 'mimetype'`, `comp_method === ZipArchive::CM_STORE`.
  - `tatouage : page ajoutée après la couverture` — dans le spine de l'OPF résultat, l'`idref` de `exemplaire.xhtml` est en 2ᵉ position (epub 3 et epub 2, OPF en sous-dossier `OEBPS/`), et le fichier existe au chemin résolu depuis l'OPF.
  - `tatouage : mise en page fixe → page en fin` — fixture `fixed = true` → dernière position du spine ; `epub_validate()['layout'] === 'pre-paginated'`.
  - `tatouage : nom à caractères spéciaux` — nom `Zoé <b>&"O'Hara" 🌸` → `exemplaire.xhtml` et OPF chargent sans erreur avec `DOMDocument::loadXML`, et le texte du nom est présent tel quel.
  - `tatouage : métadonnées` — l'OPF contient `EB-0007` dans `dc:rights`.
  - `tatouage : marque cachée` — chaque XHTML du spine (hors exemplaire) contient le commentaire `<!-- yv:` + `hash_hmac('sha256', 'EB-0007', $secret)` tronqué à 16 caractères + ` -->`.
  - `tatouage : maître inchangé` — `sha1_file` du maître identique avant/après.
- [ ] **Step 2 :** `npm run test:php` → ÉCHEC.
- [ ] **Step 3 : implémenter.** Lire le chemin de l'OPF dans `container.xml` (`rootfile/@full-path`), charger avec `DOMDocument`. Couverture = premier `itemref` dont l'item a `properties` contenant `cover`/est référencé par `<meta name="cover">`/`<reference type="cover">`, sinon le premier `itemref` du spine ; insérer après. Écrire l'archive de sortie entrée par entrée : `mimetype` en premier avec `setCompressionName('mimetype', ZipArchive::CM_STORE)`, puis les autres (OPF et XHTML modifiés), puis `exemplaire.xhtml` (XHTML 1.1 / XHTML5 selon version, texte via `htmlspecialchars(ENT_XML1)`). Contenu de la page : « Exemplaire personnel de {name} ({email}) — commande {ref} — {date}. Ce livre vous est destiné : merci de ne pas le diffuser. »
- [ ] **Step 4 :** `npm run test:php` → OK. Ouvrir une fois un exemplaire tatoué de la fixture avec `epubcheck` si disponible (`java -jar epubcheck.jar`), sinon Calibre — aucun « ERROR ».
- [ ] **Step 5 : commit** `feat(site): epub validation and per-buyer watermark`.

### Task 4 : Tunnel public (commande, paiement, mails)

**Files :**
- Create : `php/numerique.php` (GET formulaire / POST création), `php/numerique-merci.php`
- Modify : `php/_prive/mail.php` (3 modèles)
- Modify : `php/.htaccess` (routes)
- Test : `tests/php/ebook_test.php` (mails en mode `log`)

**Interfaces :**
- Consumes : tâche 2 (`validate_ebook_order`, `create_ebook_order`, `find_ebook_order`, `ebook_sale_open`, `ebook_price_cents`, `ebook_cgv_url`), `admin_session()`/`admin_logged_in()`, `render_page()`, `revolut_link()`, `same_origin()`, `rate_limited()`, `client_ip()`.
- Produces : `ebook_order_page_url(array $o): string` (`/numerique/merci?ref=…&t=…`, dans `ebook.php`) ; `mail_ebook_created(array $o): void`, `mail_ebook_delivered(array $o, string $token): void`, `mail_ebook_share_alert(array $o, int $ips): void` ; `ebook_download_url(string $token): string` (`public_base_url . '/telecharger/' . $token`).
- Routes : `^numerique$ → numerique.php [L,QSA]`, `^numerique/merci$ → numerique-merci.php [L,QSA]`.

- [ ] **Step 1 : tests qui échouent** (capture des mails : `cfg_override(['mail_mode' => 'log'])`, `ini_set('error_log', <fichier temporaire>)`, puis lecture de ce fichier — `mail_log()` y écrit destinataire, sujet et corps) :
  - `mail commande : consentement et date` — le corps contient `EBOOK_CONSENT_TEXT`, la date FR de `consent_at`, le lien Revolut et la ref.
  - `mail livraison : lien, 7 jours, 5 téléchargements` — contient `ebook_download_url($token)`, « 7 jours » et « 5 ».
  - `mail alerte : ref et nombre d'IP`.
- [ ] **Step 2 :** `npm run test:php` → ÉCHEC.
- [ ] **Step 3 : `numerique.php`.** GET : si vente fermée et pas admin connecté → page « Bientôt disponible » (200). Sinon formulaire (prénom, nom, email, case consentement avec `EBOOK_CONSENT_TEXT`, lien CGV si réglé, pot de miel `website`, prix lu par `ebook_price_cents`), même classes CSS que `/commande` (`.field`, `.cta`, `.panel`). POST (formulaire classique, pas de JS) : `same_origin()` sinon 403 ; fermé et pas admin → 403 ; `rate_limited('ebook-order:'.client_ip(), 8, 3600)` → 429 ; pot de miel rempli → 400 ; erreurs → réafficher le formulaire avec les valeurs saisies et les messages ; succès → `create_ebook_order`, `mail_ebook_created`, redirection 303 vers `ebook_order_page_url`. Pas de `Cache-Control` public (render_page met déjà `no-store`).
- [ ] **Step 4 : `numerique-merci.php`.** Calque de `merci.php` (ref `^EB-\d{4,}$`, `hash_equals` sur `token`, 404 sinon). États : attente (bouton Revolut, copie de la ref, « Votre lien de téléchargement arrive par e-mail sous 24 h après réception du paiement »), livrée (« Votre lien a été envoyé à {email} »), annulée. Étapes `Steps` : Coordonnées → Paiement → Téléchargement.
- [ ] **Step 5 : mails** dans `mail.php` (texte brut, même style que `mail_order_created`). `mail_ebook_created` envoie aussi un mail à `admin_email` (« Nouvelle commande numérique EB-… »).
- [ ] **Step 6 :** `npm run test:php` → OK. `npm run build && npm run assemble && npm run php` puis, sur `http://localhost:8080` : vente fermée → « Bientôt disponible » ; connecté admin → commande passe, mail visible dans Mailpit (`:8025`), page `/numerique/merci` affiche le bouton Revolut.
- [ ] **Step 7 : commit** `feat(site): public ebook order flow and emails`.

### Task 5 : Téléchargement `/telecharger/<jeton>`

**Files :**
- Create : `php/telecharger.php`
- Modify : `php/.htaccess` : `RewriteRule ^telecharger/([A-Za-z0-9_-]{43})$ telecharger.php?j=$1 [L]`

**Interfaces :**
- Consumes : `ebook_consume_download`, `ebook_distinct_ips`, `ebook_mark_alert_sent`, `mail_ebook_share_alert`, `epub_watermark`, `ebook_master_path`, `ebook_secret`, `rate_limited`, `client_ip`.

- [ ] **Step 1 : implémenter** `telecharger.php` : `rate_limited('dl:'.client_ip(), 20, 3600)` → page 429 ; jeton au mauvais format → page « Lien invalide ou expiré » 404 ; `ebook_consume_download` `null` → même page 404 (texte : « Ce lien n'est plus valide. Écrivez à contact@vanessagandino.fr pour en recevoir un nouveau. ») ; maître absent → 503 + `error_log`. Sinon : `tempnam()` dans `<data_dir>/epub/tmp/` (créé en 0700), `epub_watermark`, en-têtes des Global Constraints + `Content-Length`, `readfile`, `unlink` dans un `finally`. Après envoi : si `ebook_distinct_ips > EBOOK_ALERT_IPS` et `ebook_mark_alert_sent` → `mail_ebook_share_alert`. Échec du tatouage → 500 + `error_log` et **décrément non nécessaire** (accepté : l'admin renvoie un lien).
- [ ] **Step 2 : vérifier en local** (`npm run php`) : livrer une commande test via SQL ou l'admin (tâche 6 si déjà faite, sinon `ebook_deliver` depuis `php -r`), télécharger 5 fois avec `curl -sD- -o /tmp/x.epub http://localhost:8080/telecharger/<jeton>` → 200 + en-têtes exacts ; 6ᵉ → 404 ; fichier ouvert dans Calibre avec la page d'exemplaire.
- [ ] **Step 3 : commit** `feat(site): watermarked, limited, non-cacheable ebook download`.

### Task 6 : Administration

**Files :**
- Create : `php/admin/epub.php` (fichier maître + réglages), `php/admin/numerique.php` (fiche `EB-…`)
- Modify : `php/admin/index.php` (section « Livres numériques »), `src/layouts/Admin.astro` (lien « livre numérique » → `/admin/epub`), `php/.htaccess` (`^admin/epub$`, `^admin/(EB-[0-9]+)$ → admin/numerique.php?ref=$1`)

**Interfaces :**
- Consumes : tâches 2, 3, 4 ; `require_admin()`, `check_post()`, `csrf_field()`, `admin_page()`, `fr_date()`, `eur()`.

- [ ] **Step 1 : `/admin/epub`.**
  - Dépôt du maître (`enctype multipart`, `check_post()`) : taille ≤ `min(50 Mo, upload_max_filesize, post_max_size)` (affichée), `epub_validate` OK, copie atomique (`tmp` + `rename`) vers `ebook_master_path()` en 0600, dossier 0700. Si `layout === 'pre-paginated'` : avertissement « mise en page fixe : la page d'exemplaire sera placée à la fin ».
  - Affichage : titre, taille, date du maître en place.
  - Bouton « Tester le tatouage » : télécharge un exemplaire au nom « Exemplaire de test », ref `EB-TEST`, mêmes en-têtes que la tâche 5.
  - Réglages : prix (euros → centimes, > 0), URL des CGV (vide ou `https://…`), case « Vente ouverte » — enregistrement refusé avec message si aucun maître.
- [ ] **Step 2 : `/admin/EB-xxxx`** (calque de `admin/commande.php`) : client, prix, statut, consentement (texte, date, IP), lien de suivi client, historique des téléchargements (date, IP, navigateur) et compteur `dl_count/dl_max`, expiration. Actions POST : `deliver` (« Paiement reçu — envoyer le lien », ou « Renvoyer un nouveau lien » si déjà livrée) → `ebook_deliver` + `mail_ebook_delivered` ; `cancel` ; `reopen` ; `note`. Refuser `deliver` si le maître est absent (message).
- [ ] **Step 3 : `/admin`** : sous la liste papier, section « Livres numériques » (à traiter = `attente_paiement`, lien « toutes »), compteurs via `count_ebook_orders`. Appeler `ebook_purge_downloads($db)` à chaque affichage de la liste (purge opportuniste).
- [ ] **Step 3b : export** `/admin/export-numerique.csv` (route `^admin/export-numerique\.csv$`), même format Excel FR que `admin/export.php` : ref, date, statut, prénom, nom, email, prix, payée le, téléchargements, consentement le. Lien à côté de « export CSV » dans la section numérique.
- [ ] **Step 4 : vérifier en local** : parcours complet admin connecté → commande → « Paiement reçu » → mail Mailpit → téléchargement → historique visible ; dépôt d'un zip non epub refusé ; ouverture de la vente refusée sans maître.
- [ ] **Step 5 :** `npm test && npm run test:php && npm run check` → OK.
- [ ] **Step 6 : commit** `feat(site): admin pages for ebook sales`.

### Task 7 : Bouton d'accueil, documentation, nettoyage

**Files :**
- Modify : `php/_prive/accueil.php` (`home_default_layout(array $texts = [], bool $ebook = false)` : si `$ebook`, 3ᵉ bouton `b3_label = 'Version numérique'`, `b3_url = '/numerique'`, `b3_style = 'outline'` dans le premier élément `buttons` ; l'appel ligne ~281 passe `ebook_sale_open($db)`)
- Modify : `README.md` (section « Livre numérique » : dépôt, prix, ouverture, validation des paiements, renvoi de lien, ajout manuel du bouton si la mise en page d'accueil est personnalisée, diagnostic IP, conservation des données)
- Delete : `php/admin/diagnostic.php` et sa route (une fois l'étape 8 de la tâche 1 faite par l'humain ; sinon laisser et le signaler)
- Test : `tests/php/accueil_test.php`

- [ ] **Step 1 : tests qui échouent** : `accueil : bouton numérique si vente ouverte` (layout par défaut avec `$ebook = true` contient `/numerique`) et `accueil : pas de bouton si fermée`.
- [ ] **Step 2 :** `npm run test:php` → ÉCHEC.
- [ ] **Step 3 : implémenter** ; rédiger la section README.
- [ ] **Step 4 :** `npm test && npm run test:php && npm run check && npm run package:hostinger` → OK, `deploy/hostinger.zip` produit.
- [ ] **Step 5 : commit** `feat(site): homepage ebook button and docs`.
- [ ] **Step 6 (humain) — mise en ligne** : déployer le zip ; tâche 1 étape 8 ; déposer le vrai epub ; « Tester le tatouage » et ouvrir dans Apple Books / Kobo / Calibre ; commande réelle à 4,99 € en étant connecté (vente fermée) ; `curl -sI` sur le lien de téléchargement depuis l'extérieur : `Cache-Control: private, no-store` présent derrière le CDN, deuxième requête non servie par le cache ; CGV en ligne ; ouvrir la vente.
