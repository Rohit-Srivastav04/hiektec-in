<?php

function layout_start(string $title, string $page): void
{
    $session = auth_session();
    $email = (string) ($session['email'] ?? '');
    $nav = [
        'overview' => 'Dashboard',
        'profile' => 'Profile',
        'users' => 'Users',
        'plans' => 'Plans',
        'downloads' => 'Downloads',
        'tokens' => 'Install tokens',
        'proxy' => 'Proxies',
        'activity' => 'Activity',
        'trash' => 'Trash',
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . e($title) . ' · SA Browser Admin</title>';
    echo '<link rel="stylesheet" href="assets/admin.css">';
    echo '<link rel="icon" href="assets/logo.png" type="image/png">';
    echo '</head><body><div class="shell"><aside class="side">';
    echo '<div class="brand-lockup"><img class="brand-logo" src="assets/logo.png" alt="SA Browser" width="40" height="40" style="width:40px;height:40px;max-width:40px;max-height:40px;object-fit:contain"><div><strong>SA Browser</strong><span>Admin</span></div></div>';
    echo '<nav class="nav">';
    foreach ($nav as $id => $label) {
        $class = $id === $page ? ' class="active"' : '';
        echo '<a' . $class . ' href="index.php?p=' . e($id) . '">' . e($label) . '</a>';
    }
    echo '</nav><div class="side-foot"><div>' . e($email) . '</div>';
    echo '<a class="signout" href="index.php?p=logout">Sign out</a></div></aside><main class="main">';
    $flash = flash_take();
    if ($flash) {
        $class = ($flash['kind'] ?? '') === 'error' ? 'error' : 'okbox';
        echo '<div class="' . $class . '">' . e((string) ($flash['message'] ?? '')) . '</div>';
    }
}

function layout_end(): void
{
    echo '</main></div></body></html>';
}

function pill(string $status): string
{
    $class = match ($status) {
        'active', 'confirmed', 'approved' => 'ok',
        'pending', 'wipe pending' => 'warn',
        'rejected', 'unconfirmed', 'revoked', 'banned', 'blocked', 'none', 'used' => 'bad',
        default => '',
    };
    return '<span class="pill ' . $class . '">' . e($status) . '</span>';
}