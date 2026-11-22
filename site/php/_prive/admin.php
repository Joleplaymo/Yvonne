<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/mail.php';

// Session d'administration : cookie strict, jeton CSRF, limite de tentatives de connexion.

function admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('yv_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

function admin_logged_in(): bool
{
    return ($_SESSION['admin'] ?? false) === true && ($_SESSION['exp'] ?? 0) > time();
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_post(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if (!same_origin() || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Requête refusée (jeton expiré) : rechargez la page.');
    }
}

/** Vérifie identifiant + mot de passe, avec 10 essais max par quart d'heure et par IP. */
function admin_try_login(string $user, string $password): ?string
{
    $c = cfg();
    if ($c['admin_password'] === '') return 'Admin désactivée : définir admin_password dans la configuration.';
    if (rate_limited(db(), 'login:' . client_ip(), 10, 900)) return 'Trop de tentatives. Réessayez dans 15 minutes.';
    $ok = hash_equals(hash('sha256', $c['admin_user']), hash('sha256', $user))
        & hash_equals(hash('sha256', $c['admin_password']), hash('sha256', $password));
    if (!$ok) {
        usleep(400000);
        return 'Identifiant ou mot de passe incorrect.';
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['exp'] = time() + 8 * 3600;
    return null;
}

function require_admin(): void
{
    admin_session();
    if (!admin_logged_in()) {
        header('Location: /admin/');
        exit;
    }
}

function admin_page(string $title, string $content, int $status = 200): never
{
    render_page($title, $content, 'gabarit-admin', $status);
}
