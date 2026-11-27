# Adresses de notification dans l'admin — plan de mise en œuvre

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** choisir depuis `/admin/notifications` les 1 à 3 adresses qui reçoivent les avis d'achat.

**Architecture:** réglage `notify_emails` dans la table `settings` (comme `shipping_table`), lu par une fonction unique `notify_emails()` avec repli sur `admin_email` de config.php ; les trois avis passent par `notify_admins()`. Une page admin PHP sur le modèle de `php/admin/livraison.php`.

**Tech Stack:** PHP 8.3, SQLite, Astro (gabarit admin), tests PHP maison (`npm run test:php`, Docker).

**Spec:** `docs/superpowers/specs/2026-11-27-notifications-admin-design.md`

## Global Constraints

- 3 adresses maximum ; chacune `FILTER_VALIDATE_EMAIL` et ≤ 254 caractères ; doublons (insensible à la casse) retirés.
- Réglage absent ou vide → `[cfg()['admin_email']]` (ou `[]` si celui-ci est vide).
- `Reply-To` des mails clients inchangé (`admin_email` de config.php).
- Mail de test : 5 maximum par 10 minutes (`rate_limited($db, 'notify-test', 5, 600)`).
- Textes de l'interface en français, ton des pages admin existantes.

## Review Focus

1. Une adresse invalide parmi plusieurs → rien n'est enregistré, message d'erreur, saisie réaffichée.
2. Base illisible au moment d'une commande → l'avis part quand même vers config.php.
3. Destinataire contenant `\r` ou `\n` → `send_mail` refuse (retourne false), aucun envoi.
4. Enregistrement sans changement réel (mêmes adresses, autre casse/ordre d'espaces) → pas de mail « modifiées ».
5. Lignes vides / espaces autour des adresses → ignorés, pas une erreur.

---

### Task 1: Réglage, envoi groupé et alerte de changement

**Files:**
- Modify: `site/php/_prive/mail.php` (send_mail ; trois avis lignes ~137, ~200, ~229 ; nouvelles fonctions)
- Create: `site/tests/php/notifications_test.php`
- Modify: `site/scripts/test-php.mjs` (ajouter la suite)

**Interfaces — Produces:**
- `notify_parse(string $raw): array` → `['ok' => true, 'emails' => list<string>]` ou `['ok' => false, 'error' => string]`
- `notify_emails(?PDO $db = null): list<string>` (réglage, sinon config ; toute exception → config)
- `notify_custom(PDO $db): bool`
- `notify_save(PDO $db, list<string> $emails, string $ip): void` (enregistre ; si la liste change, envoie « Adresses de notification modifiées » aux anciennes adresses)
- `notify_reset(PDO $db, string $ip): void` (supprime le réglage ; même alerte si la liste effective change)
- `notify_admins(string $subject, string $body): int` (envoie à chaque adresse, renvoie le nombre d'envois réussis)
- Clé de réglage : constante `NOTIFY_SETTING = 'notify_emails'`

- [ ] **Step 1: tests** dans `notifications_test.php` (même squelette que `ebook_test.php` : DATA_DIR temporaire, `mail_mode=log`, `error_log` vers fichier, `check()`), assertions :
  - `notify_parse("a@x.fr\n\n  B@y.fr \nb@Y.fr")` → ok, `['a@x.fr','B@y.fr']`
  - `notify_parse("a@x.fr\npas-un-mail")` → `ok === false`, erreur contient `pas-un-mail`
  - `notify_parse("a@x.fr\nb@x.fr\nc@x.fr\nd@x.fr")` → `ok === false`
  - `notify_parse("")` → ok, `[]` ; `notify_parse("a@x.fr\r\nBcc: z@z.fr")` → `ok === false`
  - sans réglage : `notify_emails($db) === ['admin@exemple.test']`, `notify_custom($db) === false`
  - `notify_save($db, ['n1@x.fr','n2@x.fr'], '203.0.113.9')` → `notify_emails` renvoie ces deux ; journal contient `À: admin@exemple.test`, `Adresses de notification modifiées`, `n1@x.fr`, `203.0.113.9`
  - même `notify_save` répété avec `['N1@x.fr','n2@x.fr']` → aucun mail « modifiées » dans le journal
  - `mail_ebook_created($o)` → journal contient `À: n1@x.fr` et `À: n2@x.fr` et pas `À: admin@exemple.test` ; idem `mail_ebook_share_alert`
  - `send_mail("a@x.fr\r\nBcc: z@z.fr", 's', 'b') === false` et rien au journal pour ce destinataire
  - `notify_reset($db, 'ip')` → `notify_emails($db) === ['admin@exemple.test']`, alerte envoyée à `n1@x.fr`
  - `notify_emails(new PDO('sqlite::memory:'))` (pas de table settings) → `['admin@exemple.test']`
  - `Reply-To` : `mail_headers(cfg())` contient toujours `Reply-To: admin@exemple.test` après `notify_save`
- [ ] **Step 2:** ajouter la suite à `test-php.mjs`, lancer `npm run test:php` depuis `site/` → échec (fonctions absentes).
- [ ] **Step 3:** implémenter dans `mail.php` ; remplacer les trois `if ($c['admin_email'] !== '') send_mail($c['admin_email'], …)` par `notify_admins(…)` ; garde `\r`/`\n` en tête de `send_mail` (log + false).
- [ ] **Step 4:** `npm run test:php` → « Toutes les suites PHP passent. »
- [ ] **Step 5:** commit `feat(site): configurable purchase notification emails`.

### Task 2: Page `/admin/notifications`

**Files:**
- Create: `site/php/admin/notifications.php` (modèle : `php/admin/livraison.php`)
- Modify: `site/php/.htaccess` (route `^admin/notifications$`), `site/src/layouts/Admin.astro` (lien « notifications » après « livraison »), `site/README.md` (section admin)

**Interfaces — Consumes:** fonctions de la Task 1.

- [ ] **Step 1:** page : `require_admin()` ; POST → `check_post()`, actions `save` (`notify_parse` du textarea `emails`, maxlength 1000 ; erreur → message + saisie réaffichée via session ; ok → `notify_save` avec `client_ip()`, ou `notify_reset` si liste vide), `test` (`rate_limited` → message ; sinon `notify_admins('Test des notifications — Les hormones d\'Yvonne', …)` et message « Mail de test envoyé à N adresse(s) sur M »), `reset` (`notify_reset`) ; redirection 303 vers `/admin/notifications`. GET : textarea pré-rempli si réglage, adresses en vigueur avec mention « (valeur de config.php) » sinon, date de modification (`setting_updated_at`), note sur le Reply-To inchangé. Tout affichage via `h()`.
- [ ] **Step 2:** `php -l` sur la page (`docker compose -f docker-compose.dev.yml run --rm --no-deps -v "$PWD:/app" -w /app --entrypoint php php -l php/admin/notifications.php`) → « No syntax errors ».
- [ ] **Step 3:** `npm run build` → succès ; `npm run test:php` → toutes les suites passent.
- [ ] **Step 4:** essai réel en local (serveur de dev Docker) : connexion admin, enregistrer 2 adresses, une invalide, test, retour config ; vérifier messages et journal.
- [ ] **Step 5:** commit `feat(site): admin page for notification emails`.
