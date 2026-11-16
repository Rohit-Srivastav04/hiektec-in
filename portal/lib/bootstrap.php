<?php

declare(strict_types=1);

session_name('SA_PORTAL');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path' => '/portal/',
]);
session_start();

require __DIR__ . '/supabase.php';

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing portal/config.php. Copy config.example.php to config.php.';
    exit;
}

/** @var array{supabase_url:string,supabase_anon_key:string,installer_url?:string,admin_url?:string,site_name?:string} $config */
$config = require $configPath;
$supabaseUrl = rtrim((string) ($config['supabase_url'] ?? ''), '/');
$supabaseKey = trim((string) ($config['supabase_anon_key'] ?? ''));
$installerUrl = trim((string) ($config['installer_url'] ?? ''));
$siteName = trim((string) ($config['site_name'] ?? 'SA Browser'));
if ($supabaseUrl === '' || strlen($supabaseKey) < 20) {
    http_response_code(500);
    echo 'portal/config.php is missing the Supabase URL or anon key.';
    exit;
}

$supabase = new Supabase($supabaseUrl, $supabaseKey);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $page, array $query = []): never
{
    $query['p'] = $page;
    header('Location: index.php?' . http_build_query($query));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    $real = (string) ($_SESSION['csrf'] ?? '');
    if ($real === '' || !hash_equals($real, $sent)) {
        http_response_code(400);
        echo 'Bad request';
        exit;
    }
}

function flash_set(string $kind, string $message): void
{
    $_SESSION['flash'] = ['kind' => $kind, 'message' => $message];
}

function flash_take(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function auth_session(): ?array
{
    $session = $_SESSION['sa_portal'] ?? null;
    return is_array($session) ? $session : null;
}

function auth_logout(): void
{
    unset($_SESSION['sa_portal']);
}

function auth_store(array $token): void
{
    $user = is_array($token['user'] ?? null) ? $token['user'] : [];
    $_SESSION['sa_portal'] = [
        'access_token' => (string) ($token['access_token'] ?? ''),
        'refresh_token' => (string) ($token['refresh_token'] ?? ''),
        'expires_at' => time() + (int) ($token['expires_in'] ?? 3600),
        'email' => (string) ($user['email'] ?? ''),
        'user_id' => (string) ($user['id'] ?? ''),
        'email_confirmed' => !empty($user['email_confirmed_at']),
    ];
}

function sb(): Supabase
{
    global $supabase;
    $session = auth_session();
    if (!$session) {
        return $supabase;
    }
    if (($session['expires_at'] ?? 0) < time() + 60) {
        $refreshed = $supabase->call('POST', '/auth/v1/token?grant_type=refresh_token', [
            'refresh_token' => $session['refresh_token'],
        ]);
        if (!$refreshed['ok'] || !is_array($refreshed['data'])) {
            auth_logout();
            flash_set('error', 'Session expired. Sign in again.');
            redirect('login');
        }
        auth_store($refreshed['data']);
        $session = auth_session();
    }
    return $supabase->withJwt((string) ($session['access_token'] ?? ''));
}

function auth_require(): array
{
    $session = auth_session();
    if (!$session || ($session['access_token'] ?? '') === '') {
        redirect('login');
    }
    return $session;
}

function installer_url(): string
{
    global $installerUrl;
    return $installerUrl;
}

function site_name(): string
{
    global $siteName;
    return $siteName;
}

function portal_layout_start(string $title): void
{
    $session = auth_session();
    $email = (string) ($session['email'] ?? '');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . e($title) . ' · ' . e(site_name()) . '</title>';
    echo '<link rel="stylesheet" href="assets/portal.css">';
    echo '<link rel="icon" href="assets/logo.png" type="image/png">';
    echo '</head><body><header class="top">';
    echo '<a class="brand" href="index.php?p=download"><img src="assets/logo.png" alt="" width="36" height="36"><span>' . e(site_name()) . '</span></a>';
    echo '<nav>';
    if ($email !== '') {
        echo '<span class="muted">' . e($email) . '</span>';
        echo '<a href="index.php?p=download">Dashboard</a>';
        echo '<a href="index.php?p=logout">Sign out</a>';
    } else {
        echo '<a href="index.php?p=login">Sign in</a>';
        echo '<a class="btn-link" href="index.php?p=signup">Sign up</a>';
    }
    echo '</nav></header><main class="wrap">';
    $flash = flash_take();
    if ($flash) {
        $class = ($flash['kind'] ?? '') === 'error' ? 'error' : 'okbox';
        echo '<div class="' . $class . '">' . e((string) ($flash['message'] ?? '')) . '</div>';
    }
}

function portal_layout_end(): void
{
    echo '</main><footer class="foot">Sign up → request access → admin approves → download → install with one-time token. Staff use the separate admin site.</footer></body></html>';
}