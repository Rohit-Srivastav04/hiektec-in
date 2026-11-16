<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/view.php';

$page = (string) ($_GET['p'] ?? 'overview');
$allowed = ['overview', 'profile', 'users', 'plans', 'downloads', 'tokens', 'activity', 'proxy', 'trash', 'login', 'logout'];
if (!in_array($page, $allowed, true)) {
    $page = 'overview';
}

if ($page === 'logout') {
    auth_logout();
    redirect('login');
}

if ($page === 'login') {
    if (auth_session()) {
        redirect('overview');
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

auth_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require __DIR__ . '/pages/actions.php';
}

$file = __DIR__ . '/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
require $file;