# Lien de paiement Revolut réglable dans l'admin — plan de mise en œuvre

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** régler depuis `/admin/paiement` le lien Revolut (montant prérempli possible via `{amount}`) et un lien epub à montant fixe.

**Architecture:** nouveau module `php/_prive/paiement.php` : réglages `pay_link`, `pay_link_ebook`, `pay_link_ebook_cents` dans la table `settings`, repli sur `revolut_link_template` de config.php. Les pages `/merci`, `/numerique-merci` et les mails de commande passent par `pay_link_paper()` / `pay_link_ebook()`. Page admin sur le modèle de `php/admin/notifications.php`.

**Tech Stack:** PHP 8.3, SQLite, Astro (gabarit admin), tests PHP maison (`npm run test:php`, Docker).

**Spec:** `docs/superpowers/specs/2026-11-28-paiement-revolut-admin-design.md`

## Global Constraints

- Lien accepté : `https://` uniquement ; hôte `revolut.me`, `revolut.com` ou sous-domaine de `revolut.com` ; pas d'identifiant (`user@`), pas de port ; ≤ 300 caractères ; aucun espace ni caractère de contrôle.
- Lien Revolut : seuls `{amount}` et `{ref}` admis entre accolades. Lien epub fixe : aucune accolade.
- Champ vide → réglage supprimé → repli config.php (lien) ou plus de lien fixe (epub).
- Lien fixe epub utilisé seulement si le prix de la commande (`price_cents`) est égal à `pay_link_ebook_cents` ; sinon lien Revolut.
- Tout changement effectif d'un lien → mail « Lien de paiement modifié — Les hormones d'Yvonne » via `notify_admins()` (ancien, nouveau, date, IP).
- Toute erreur de lecture des réglages → repli config.php : le client voit toujours un lien.
- Textes de l'interface en français, ton des pages admin existantes ; tout affichage via `h()`.

## Review Focus

1. Lien piégé (`https://revolut.me.evil.com/`, `https://evil.com/?revolut.me`, `https://x@revolut.me/`, `javascript:`) → refusé, rien n'est enregistré.
2. Prix epub changé dans `/admin/epub` après création du lien fixe → le client ne reçoit jamais le lien fixe à l'ancien prix ; avertissement visible dans les deux pages admin.
3. Commande epub passée avant un changement de prix (même prix que le lien fixe) → le lien fixe s'applique encore à elle.
4. Base illisible au moment d'une commande → page et mail affichent le lien de config.php.
5. Enregistrement sans changement (mêmes valeurs, espaces autour) → pas de mail d'alerte.

---

### Task 1: Module paiement et branchement des pages et mails

**Files:**
- Create: `site/php/_prive/paiement.php`
- Modify: `site/php/_prive/mail.php` (`require_once __DIR__ . '/paiement.php';` en tête ; `mail_order_created` ~l.126, `mail_ebook_created` ~l.181)
- Modify: `site/php/merci.php` (l.20-22), `site/php/numerique-merci.php` (l.21-23 ; ajouter `require_once __DIR__ . '/_prive/paiement.php';`)
- Create: `site/tests/php/paiement_test.php` ; Modify: `site/scripts/test-php.mjs` (ajouter la suite)

**Interfaces — Produces:**
- Constantes `PAY_LINK = 'pay_link'`, `PAY_LINK_EBOOK = 'pay_link_ebook'`, `PAY_LINK_EBOOK_CENTS = 'pay_link_ebook_cents'`.
- `pay_link_check(string $link, bool $fixed): ?string` → `null` si valide (ou vide), sinon message d'erreur en français.
- `pay_template(?PDO $db = null): string` → réglage `pay_link`, sinon `cfg()['revolut_link_template']` ; toute exception → config (avec `error_log`).
- `pay_link_paper(int $cents, string $ref, ?PDO $db = null): array{url: string, prefilled: bool}` → `revolut_link(pay_template(), …)`, `prefilled` = le modèle contient `{amount}`.
- `pay_link_ebook(int $cents, string $ref, ?PDO $db = null): array{url: string, prefilled: bool}` → lien fixe (`prefilled` true) si présent et `$cents === pay_link_ebook_cents`, sinon comme `pay_link_paper`.
- `pay_ebook_fixed_status(PDO $db, int $currentCents): string` → `'none'` | `'ok'` | `'stale'`.
- `pay_save(PDO $db, string $link, string $ebookLink, int $ebookCents, string $ip): void` → valide (InvalidArgumentException sinon, rien enregistré), enregistre ou supprime chaque réglage (lien epub vide → supprime `pay_link_ebook` et `pay_link_ebook_cents`) ; si une valeur effective change, envoie l'alerte via `notify_admins()` (défini dans mail.php, chargé par l'appelant).
- `paiement.php` fait `require_once __DIR__ . '/lib.php';` (comme `ebook.php`).

- [ ] **Step 1: tests** dans `paiement_test.php` (même squelette que `notifications_test.php` : DATA_DIR temporaire, `cfg_override(['mail_mode' => 'log', 'admin_email' => 'admin@exemple.test', 'revolut_link_template' => 'https://revolut.me/cfg', …])`, `error_log` vers fichier, `check()`) :
  - `pay_link_check` → `null` pour `https://revolut.me/v_gandino`, `https://revolut.me/x/{amount}EUR?r={ref}`, `https://checkout.revolut.com/pay/abc` (fixe) ; `''` → `null`.
  - `pay_link_check` → non null pour `http://revolut.me/x`, `https://revolut.me.evil.com/x`, `https://evil.com/?revolut.me`, `https://x@revolut.me/y`, `https://revolut.me:8443/x`, `javascript:alert(1)`, `https://revolut.me/x {amount}`, `"https://revolut.me/x\n"`, `https://revolut.me/{foo}`, 301 caractères ; et `https://revolut.me/x/{amount}` avec `$fixed = true`.
  - sans réglage : `pay_link_paper(3590, 'YV-0001', $db) === ['url' => 'https://revolut.me/cfg', 'prefilled' => false]`.
  - `pay_save($db, 'https://revolut.me/x/{amount}EUR', '', 499, '203.0.113.9')` → `pay_link_paper(3590, …)` = `['url' => 'https://revolut.me/x/35.90EUR', 'prefilled' => true]` ; journal contient `À: admin@exemple.test`, `Lien de paiement modifié`, `https://revolut.me/x/{amount}EUR`, `203.0.113.9`.
  - même `pay_save` répété avec espaces autour → aucun « Lien de paiement modifié » au journal.
  - `pay_save($db, 'https://revolut.me/x/{amount}EUR', 'https://checkout.revolut.com/pay/abc', 499, 'ip')` → `pay_link_ebook(499, 'EB-0001', $db) === ['url' => 'https://checkout.revolut.com/pay/abc', 'prefilled' => true]` ; `pay_link_ebook(599, …)['url'] === 'https://revolut.me/x/5.99EUR'` ; `pay_ebook_fixed_status($db, 499) === 'ok'`, `(…, 599) === 'stale'`.
  - lien sans `{amount}` + lien fixe à 499 → `pay_link_ebook(599, …)['prefilled'] === false`.
  - `pay_save` avec lien invalide → `InvalidArgumentException`, réglages inchangés.
  - `pay_save($db, '', '', 499, 'ip')` → `pay_link_paper` revient à `https://revolut.me/cfg` ; `pay_ebook_fixed_status($db, 499) === 'none'`.
  - `pay_link_paper(100, 'T', new PDO('sqlite::memory:'))['url'] === 'https://revolut.me/cfg'` (pas de table settings).
  - avec lien fixe à 499 : `mail_ebook_created($o)` (o.price_cents 499) → journal contient `https://checkout.revolut.com/pay/abc` ; `mail_order_created($o)` → journal contient le lien `pay_link` résolu.
- [ ] **Step 2:** ajouter la suite à `test-php.mjs` ; `npm run test:php` depuis `site/` → échec (fonctions absentes).
- [ ] **Step 3:** implémenter `paiement.php` ; dans `mail.php`, `merci.php`, `numerique-merci.php`, remplacer `revolut_link(cfg()['revolut_link_template'], …)` et `str_contains($template, '{amount}')` par `pay_link_paper()` / `pay_link_ebook()` et leur clé `prefilled`. `ebook_test.php:151` doit rester vert (sans réglage, même lien qu'avant).
- [ ] **Step 4:** `npm run test:php` → « Toutes les suites PHP passent. »
- [ ] **Step 5:** commit `feat(site): admin-configurable Revolut payment links`.

### Task 2: Page `/admin/paiement` et avertissement epub

**Files:**
- Create: `site/php/admin/paiement.php` (modèle : `php/admin/notifications.php`)
- Modify: `site/php/admin/epub.php` (avertissement si `pay_ebook_fixed_status` = `'stale'`), `site/php/.htaccess` (route `^admin/paiement$` après notifications), `site/src/layouts/Admin.astro` (lien « paiement » après « notifications »), `site/README.md` (section admin + section Revolut l.143-144), `site/php/_prive/config.example.php` (commentaire : `/admin/paiement` peut remplacer `revolut_link_template`)

**Interfaces — Consumes:** fonctions de la Task 1 ; `ebook_price_cents($db)` (ebook.php) ; `client_ip()`, `setting_updated_at()` (lib.php).

- [ ] **Step 1:** page : `require_admin()` ; POST → `check_post()`, action `save` : champs `link` et `ebook_link` (text, maxlength 300, `is_scalar`), `pay_save($db, $link, $ebookLink, ebook_price_cents($db), client_ip())` ; `InvalidArgumentException` → message + saisie réaffichée via session ; succès → « Liens enregistrés. Ouvrez le lien d'essai sur votre téléphone pour vérifier. » ; redirection 303 vers `/admin/paiement`. GET :
  - champ lien pré-rempli si réglage, sinon vide avec « Lien en vigueur : … (valeur de config.php) » ; aide « Ajoutez {amount} à l'endroit où le montant doit apparaître, par exemple https://revolut.me/votre-nom/{amount}EUR, puis testez. Si Revolut n'affiche pas le montant, retirez {amount}. » ;
  - lien d'essai : `pay_link_paper(100, 'TEST', $db)['url']`, `target="_blank" rel="noopener"`, libellé « Ouvrir le lien d'essai (1,00 €) » ;
  - champ lien epub avec « Créez dans l'appli Revolut un lien de paiement de <prix epub actuel> puis collez-le ici. » ; si `'stale'` : avertissement « Ce lien a été créé pour X € alors que l'epub coûte Y € : il n'est plus utilisé. » ;
  - date de modification (`setting_updated_at`) si réglage présent.
- [ ] **Step 2:** `epub.php` : si `pay_ebook_fixed_status($db, $price) === 'stale'`, afficher près du prix « Le lien de paiement epub à montant fixe ne correspond plus à ce prix : mettez-le à jour dans Paiement. » avec lien `/admin/paiement`.
- [ ] **Step 3:** `php -l` sur les deux pages (`docker compose -f docker-compose.dev.yml run --rm --no-deps -v "$PWD:/app" -w /app --entrypoint php php -l php/admin/paiement.php`) → « No syntax errors ».
- [ ] **Step 4:** `npm run build` → succès ; `npm run test:php` → toutes les suites passent.
- [ ] **Step 5:** essai réel en local (serveur de dev Docker + Mailpit) : connexion admin, lien avec `{amount}`, lien invalide, lien fixe epub, changement de prix epub → avertissements ; vérifier `/merci` et `/numerique-merci` d'une commande test et le mail d'alerte dans Mailpit.
- [ ] **Step 6:** commit `feat(site): admin page for Revolut payment links`.
