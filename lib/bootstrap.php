<?php

declare(strict_types=1);

session_name('SA_ADMIN');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path' => '/',
]);
session_start();

require __DIR__ . '/supabase.php';

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing admin/config.php. Copy config.example.php to config.php and set the Supabase anon key.';
    exit;
}

/** @var array{supabase_url:string,supabase_anon_key:string} $config */
$config = require $configPath;
$supabaseUrl = rtrim((string) ($config['supabase_url'] ?? ''), '/');
$supabaseKey = trim((string) ($config['supabase_anon_key'] ?? ''));
if ($supabaseUrl === '' || strlen($supabaseKey) < 20) {
    http_response_code(500);
    echo 'admin/config.php is missing the Supabase URL or anon key.';
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
    $session = $_SESSION['sa'] ?? null;
    return is_array($session) ? $session : null;
}

function auth_logout(): void
{
    unset($_SESSION['sa'], $_SESSION['issued_token']);
}

function auth_store(array $token): void
{
    $user = is_array($token['user'] ?? null) ? $token['user'] : [];
    $_SESSION['sa'] = [
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

/** @return list<array<string,mixed>> */
function sb_rows(string $path): array
{
    $res = sb()->call('GET', '/rest/v1/' . $path);
    if (!$res['ok']) {
        throw new RuntimeException($res['error'] !== '' ? $res['error'] : 'Could not load data');
    }
    return is_array($res['data']) ? $res['data'] : [];
}

function sb_write(string $method, string $path, array $body): void
{
    $res = sb()->call($method, '/rest/v1/' . $path, $body, ['Prefer: return=minimal']);
    if (!$res['ok']) {
        throw new RuntimeException($res['error'] !== '' ? $res['error'] : 'Save failed');
    }
}

function write_audit(string $action, ?string $targetUser, array $detail): void
{
    $session = auth_session();
    $actor = (string) ($session['user_id'] ?? '');
    if ($actor === '') {
        return;
    }
    try {
        sb_write('POST', 'sa_audit', [[
            'actor_id' => $actor,
            'action' => $action,
            'target_user' => $targetUser,
            'detail' => $detail,
        ]]);
    } catch (Throwable) {
        // The account change already saved. Activity can lag if sa_audit is missing.
    }
}

function new_install_token(): string
{
    return 'sa_' . bin2hex(random_bytes(24));
}

function token_hint(string $token): string
{
    return 'sa_…' . substr($token, -6);
}

function plan_is_live(array $plan): bool
{
    if (($plan['status'] ?? '') !== 'active') {
        return false;
    }
    if (empty($plan['expires_at'])) {
        return true;
    }
    return strtotime((string) $plan['expires_at']) > time();
}

function iso_after(string $modify): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d\TH:i:s\Z');
}

function day_label(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : '—';
}

function when_label(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i', $ts) : '—';
}