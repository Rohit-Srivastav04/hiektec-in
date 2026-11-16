<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$page = (string) ($_GET['p'] ?? 'download');
$allowed = ['download', 'login', 'signup', 'logout'];
if (!in_array($page, $allowed, true)) {
    $page = 'download';
}

if ($page === 'logout') {
    auth_logout();
    flash_set('ok', 'Signed out.');
    redirect('login');
}

if ($page === 'login' || $page === 'signup') {
    if (auth_session()) {
        redirect('download');
    }
    require __DIR__ . '/pages/' . $page . '.php';
    exit;
}

auth_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'request_download') {
        $note = trim((string) ($_POST['note'] ?? ''));
        $res = sb()->call('POST', '/rest/v1/rpc/sa_request_download', [
            'p_note' => $note === '' ? null : $note,
        ]);
        if (!$res['ok'] || !is_array($res['data']) || empty($res['data']['ok'])) {
            $reason = is_array($res['data']) ? (string) ($res['data']['reason'] ?? '') : '';
            $msg = match ($reason) {
                'email' => 'Confirm your Gmail/email first, then request again.',
                'banned', 'blocked' => 'This account cannot request download access.',
                'auth' => 'Sign in again.',
                default => ($res['error'] !== '' ? $res['error'] : 'Could not create download request.'),
            };
            flash_set('error', $msg);
        } else {
            $exists = !empty($res['data']['exists']);
            flash_set('ok', $exists
                ? 'You already have a pending request. Wait for admin approval.'
                : 'Download request sent. An admin will approve it, then you can download.');
        }
    }
    redirect('download');
}

require __DIR__ . '/pages/download.php';