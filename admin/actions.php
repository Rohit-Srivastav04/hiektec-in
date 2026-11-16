<?php

declare(strict_types=1);

$action = (string) ($_POST['action'] ?? '');
$next = (string) ($_POST['next'] ?? 'overview');
if (!in_array($next, ['overview', 'profile', 'users', 'plans', 'tokens', 'activity', 'proxy', 'trash'], true)) {
    $next = 'overview';
}

try {
    if ($action === 'approve') {
        $planId = (string) ($_POST['plan_id'] ?? '');
        $userId = (string) ($_POST['user_id'] ?? '');
        $kind = (string) ($_POST['plan'] ?? 'monthly');
        if (!in_array($kind, ['monthly', 'yearly'], true) || $planId === '' || $userId === '') {
            throw new RuntimeException('Invalid plan');
        }
        $expires = iso_after($kind === 'yearly' ? '+12 months' : '+1 month');
        $session = auth_session();
        sb_write('PATCH', 'sa_plans?id=eq.' . rawurlencode($planId), [
            'status' => 'active',
            'approved_at' => iso_after('+0 seconds'),
            'approved_by' => $session['user_id'] ?? null,
            'expires_at' => $expires,
        ]);
        write_audit('plan.approve', $userId, ['plan_id' => $planId, 'plan' => $kind, 'expires_at' => $expires]);
        flash_set('ok', 'Plan approved until ' . day_label($expires) . '.');
    } elseif ($action === 'reject') {
        $planId = (string) ($_POST['plan_id'] ?? '');
        $userId = (string) ($_POST['user_id'] ?? '');
        $kind = (string) ($_POST['plan'] ?? '');
        if ($planId === '' || $userId === '') {
            throw new RuntimeException('Invalid plan');
        }
        sb_write('PATCH', 'sa_plans?id=eq.' . rawurlencode($planId), ['status' => 'rejected']);
        write_audit('plan.reject', $userId, ['plan_id' => $planId, 'plan' => $kind]);
        flash_set('ok', 'Plan rejected.');
    } elseif ($action === 'issue') {
        $userId = (string) ($_POST['user_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        $token = new_install_token();
        $session = auth_session();
        sb_write('POST', 'sa_install_tokens', [[
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'token_hint' => token_hint($token),
            'label' => 'desktop',
            'expires_at' => iso_after('+1 year'),
            'created_by' => $session['user_id'] ?? null,
        ]]);
        write_audit('token.issue', $userId, ['hint' => token_hint($token), 'label' => 'desktop']);
        $_SESSION['issued_token'] = $token;
        flash_set('ok', 'Install token created. Copy it now. It is not stored in plain text.');
        $next = 'users';
    } elseif ($action === 'revoke') {
        $tokenId = (string) ($_POST['token_id'] ?? '');
        $userId = (string) ($_POST['user_id'] ?? '');
        $hint = (string) ($_POST['hint'] ?? '');
        if ($tokenId === '') {
            throw new RuntimeException('Missing token');
        }
        sb_write('PATCH', 'sa_install_tokens?id=eq.' . rawurlencode($tokenId), ['revoked' => true]);
        write_audit('token.revoke', $userId !== '' ? $userId : null, ['token_id' => $tokenId, 'hint' => $hint]);
        flash_set('ok', 'Token revoked.');
    } elseif ($action === 'proxy' || $action === 'proxy_provider') {
        $providerId = trim((string) ($_POST['provider_id'] ?? 'anyip'));
        $allowedProviders = ['anyip', 'hproxy', 'iproyal', 'oxylabs', 'smartproxy'];
        if (!in_array($providerId, $allowedProviders, true)) {
            throw new RuntimeException('Unknown provider');
        }
        $host = trim((string) ($_POST['host'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $apiKey = (string) ($_POST['api_key'] ?? '');
        $httpPort = (int) ($_POST['http_port'] ?? 8080);
        $socksPort = (int) ($_POST['socks_port'] ?? 1080);
        $enabled = !empty($_POST['enabled']);
        if ($host === '' || $username === '' || $httpPort < 1 || $socksPort < 1) {
            throw new RuntimeException('Host, username, and ports are required');
        }
        $row = [
            'enabled' => $enabled,
            'host' => $host,
            'username' => $username,
            'http_port' => $httpPort,
            'socks_port' => $socksPort,
            'updated_at' => iso_after('+0 seconds'),
        ];
        if (trim($password) !== '') {
            $row['password'] = $password;
        }
        if (trim($apiKey) !== '') {
            $row['api_key'] = $apiKey;
        }
        $res = sb()->call('PATCH', '/rest/v1/sa_proxy_providers?id=eq.' . rawurlencode($providerId), $row, [
            'Prefer: return=minimal',
        ]);
        if (!$res['ok']) {
            throw new RuntimeException($res['error'] !== '' ? $res['error'] : 'Could not save provider');
        }
        // Keep legacy single-row table in sync for AnyIP.
        if ($providerId === 'anyip') {
            $legacy = [
                'id' => 1,
                'host' => $host,
                'username' => $username,
                'http_port' => $httpPort,
                'socks_port' => $socksPort,
                'updated_at' => iso_after('+0 seconds'),
            ];
            if (trim($password) !== '') {
                $legacy['password'] = $password;
            }
            sb()->call('POST', '/rest/v1/sa_proxy_settings?on_conflict=id', [$legacy], [
                'Prefer: resolution=merge-duplicates,return=minimal',
            ]);
        }
        write_audit('proxy.save', null, ['provider' => $providerId, 'host' => $host, 'username' => $username, 'enabled' => $enabled]);
        flash_set('ok', strtoupper($providerId) . ' saved. Desktop app will fetch it on the next Launch Center load.');
        $next = 'proxy';
    } elseif ($action === 'note') {
        $userId = (string) ($_POST['user_id'] ?? '');
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        sb_write('PATCH', 'sa_accounts?id=eq.' . rawurlencode($userId), [
            'admin_note' => $note === '' ? null : $note,
        ]);
        write_audit('user.note', $userId, []);
        flash_set('ok', 'Note saved.');
    } elseif ($action === 'block') {
        $userId = (string) ($_POST['user_id'] ?? '');
        $reason = trim((string) ($_POST['ban_reason'] ?? 'Blocked by admin'));
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        sb_write('PATCH', 'sa_accounts?id=eq.' . rawurlencode($userId), [
            'status' => 'blocked',
            'ban_reason' => $reason,
            'blocked_at' => iso_after('+0 seconds'),
            'force_wipe' => false,
        ]);
        sb()->call('PATCH', '/rest/v1/sa_install_tokens?user_id=eq.' . rawurlencode($userId), ['revoked' => true], [
            'Prefer: return=minimal',
        ]);
        write_audit('user.block', $userId, ['reason' => $reason]);
        flash_set('ok', 'User blocked. Desktop app will lock on next check.');
        $next = 'users';
    } elseif ($action === 'ban_wipe') {
        $userId = (string) ($_POST['user_id'] ?? '');
        $reason = trim((string) ($_POST['ban_reason'] ?? 'Banned by admin'));
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        sb_write('PATCH', 'sa_accounts?id=eq.' . rawurlencode($userId), [
            'status' => 'banned',
            'ban_reason' => $reason,
            'banned_at' => iso_after('+0 seconds'),
            'force_wipe' => true,
            'wipe_profiles' => true,
            'wipe_backend' => true,
            'wipe_requested_at' => iso_after('+0 seconds'),
        ]);
        sb()->call('PATCH', '/rest/v1/sa_install_tokens?user_id=eq.' . rawurlencode($userId), ['revoked' => true], [
            'Prefer: return=minimal',
        ]);
        sb()->call('PATCH', '/rest/v1/sa_plans?user_id=eq.' . rawurlencode($userId) . '&status=eq.active', [
            'status' => 'rejected',
        ], ['Prefer: return=minimal']);
        write_audit('user.ban_wipe', $userId, ['reason' => $reason, 'wipe_backend' => true]);
        flash_set('ok', 'User banned. Desktop will wipe data AND brick the installed software.');
        $next = 'users';
    } elseif ($action === 'force_wipe' || $action === 'brick_software') {
        $userId = (string) ($_POST['user_id'] ?? '');
        $reason = trim((string) ($_POST['ban_reason'] ?? ($action === 'brick_software' ? 'Admin killed software install' : 'Remote wipe requested')));
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        sb_write('PATCH', 'sa_accounts?id=eq.' . rawurlencode($userId), [
            'ban_reason' => $reason,
            'force_wipe' => true,
            'wipe_profiles' => true,
            'wipe_backend' => true,
            'wipe_requested_at' => iso_after('+0 seconds'),
        ]);
        sb()->call('PATCH', '/rest/v1/sa_install_tokens?user_id=eq.' . rawurlencode($userId), ['revoked' => true], [
            'Prefer: return=minimal',
        ]);
        write_audit($action === 'brick_software' ? 'user.brick_software' : 'user.force_wipe', $userId, [
            'reason' => $reason,
            'wipe_backend' => true,
        ]);
        flash_set(
            'ok',
            'Kill switch queued. On next desktop poll (~5s): profiles/tokens deleted AND installed engine/app.asar bricked so the app crashes.'
        );
        $next = 'users';
    } elseif ($action === 'unblock') {
        $userId = (string) ($_POST['user_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        sb_write('PATCH', 'sa_accounts?id=eq.' . rawurlencode($userId), [
            'status' => 'active',
            'ban_reason' => null,
            'blocked_at' => null,
            'banned_at' => null,
            'force_wipe' => false,
            'wipe_profiles' => false,
            'wipe_backend' => false,
        ]);
        write_audit('user.unblock', $userId, []);
        flash_set('ok', 'User restored to active. They need a fresh install + new token after a brick.');
        $next = 'users';
    } elseif ($action === 'grant_monthly' || $action === 'grant_yearly') {
        $userId = (string) ($_POST['user_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        $kind = $action === 'grant_yearly' ? 'yearly' : 'monthly';
        $months = $kind === 'yearly' ? 12 : 1;
        $rpc = sb()->call('POST', '/rest/v1/rpc/sa_admin_grant_plan', [
            'p_user_id' => $userId,
            'p_plan' => $kind,
            'p_months' => $months,
        ]);
        if ($rpc['ok'] && is_array($rpc['data']) && !empty($rpc['data']['ok'])) {
            $expires = (string) ($rpc['data']['expires_at'] ?? iso_after($kind === 'yearly' ? '+12 months' : '+1 month'));
            write_audit('plan.grant', $userId, ['plan' => $kind, 'expires_at' => $expires, 'via' => 'rpc']);
            flash_set('ok', 'Granted ' . $kind . ' plan until ' . day_label($expires) . '.');
            $next = 'users';
        } else {
            // Fallback: approve newest pending row, else insert active (needs sa_plans_admin_insert).
            $expires = iso_after($kind === 'yearly' ? '+12 months' : '+1 month');
            $session = auth_session();
            $pending = sb_rows(
                'sa_plans?select=id&user_id=eq.' . rawurlencode($userId) .
                '&status=eq.pending&plan=eq.' . rawurlencode($kind) .
                '&order=requested_at.desc&limit=1'
            );
            if ($pending) {
                sb_write('PATCH', 'sa_plans?id=eq.' . rawurlencode((string) $pending[0]['id']), [
                    'status' => 'active',
                    'approved_at' => iso_after('+0 seconds'),
                    'approved_by' => $session['user_id'] ?? null,
                    'expires_at' => $expires,
                ]);
            } else {
                sb()->call('PATCH', '/rest/v1/sa_plans?user_id=eq.' . rawurlencode($userId) . '&status=eq.active', [
                    'status' => 'rejected',
                ], ['Prefer: return=minimal']);
                sb_write('POST', 'sa_plans', [[
                    'user_id' => $userId,
                    'plan' => $kind,
                    'status' => 'active',
                    'approved_at' => iso_after('+0 seconds'),
                    'approved_by' => $session['user_id'] ?? null,
                    'expires_at' => $expires,
                ]]);
            }
            sb_write('PATCH', 'sa_accounts?id=eq.' . rawurlencode($userId), [
                'status' => 'active',
                'force_wipe' => false,
            ]);
            write_audit('plan.grant', $userId, ['plan' => $kind, 'expires_at' => $expires, 'via' => 'fallback']);
            flash_set('ok', 'Granted ' . $kind . ' plan until ' . day_label($expires) . '.');
            $next = 'users';
        }
    } else {
        throw new RuntimeException('Unknown action');
    }
} catch (Throwable $err) {
    flash_set('error', $err->getMessage());
}

redirect($next);