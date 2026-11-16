<?php

declare(strict_types=1);

$action = (string) ($_POST['action'] ?? '');
$next = (string) ($_POST['next'] ?? 'overview');
if (!in_array($next, ['overview', 'profile', 'users', 'plans', 'downloads', 'tokens', 'activity', 'proxy', 'trash'], true)) {
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
    } elseif ($action === 'issue' || $action === 'issue_for_download') {
        $userId = (string) ($_POST['user_id'] ?? '');
        $requestId = (string) ($_POST['request_id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Missing user');
        }
        $accounts = sb_rows('sa_accounts?select=id,email,email_confirmed,status&id=eq.' . rawurlencode($userId) . '&limit=1');
        if (!$accounts) {
            throw new RuntimeException('User not found');
        }
        $acc = $accounts[0];
        if (empty($acc['email_confirmed'])) {
            throw new RuntimeException('Cannot issue token: email not confirmed');
        }
        if (($acc['status'] ?? '') === 'banned' || ($acc['status'] ?? '') === 'blocked') {
            throw new RuntimeException('Cannot issue token: account is ' . (string) $acc['status']);
        }
        $token = new_install_token();
        $session = auth_session();
        $row = [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'token_hint' => token_hint($token),
            'label' => 'desktop',
            'expires_at' => iso_after('+1 year'),
            'created_by' => $session['user_id'] ?? null,
            'max_uses' => 1,
            'use_count' => 0,
            'used_at' => null,
        ];
        if ($requestId !== '') {
            $row['download_request_id'] = $requestId;
        }
        $ins = sb()->call('POST', '/rest/v1/sa_install_tokens', [$row], [
            'Prefer: return=representation',
        ]);
        if (!$ins['ok'] || !is_array($ins['data']) || empty($ins['data'][0]['id'])) {
            throw new RuntimeException($ins['error'] !== '' ? $ins['error'] : 'Could not create token');
        }
        $tokenId = (string) $ins['data'][0]['id'];
        if ($requestId !== '') {
            sb_write('PATCH', 'sa_download_requests?id=eq.' . rawurlencode($requestId), [
                'install_token_id' => $tokenId,
                'updated_at' => iso_after('+0 seconds'),
            ]);
        }
        write_audit('token.issue', $userId, [
            'hint' => token_hint($token),
            'label' => 'desktop',
            'max_uses' => 1,
            'request_id' => $requestId !== '' ? $requestId : null,
        ]);
        $_SESSION['issued_token'] = $token;
        flash_set('ok', 'One-time install token created. Copy it now — plaintext is not stored. First install consumes it.');
        $next = $action === 'issue_for_download' ? 'downloads' : 'users';
    } elseif ($action === 'approve_download') {
        $requestId = (string) ($_POST['request_id'] ?? '');
        $userId = (string) ($_POST['user_id'] ?? '');
        $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
        if ($requestId === '' || $userId === '') {
            throw new RuntimeException('Missing download request');
        }
        $accounts = sb_rows('sa_accounts?select=id,email,email_confirmed,status&id=eq.' . rawurlencode($userId) . '&limit=1');
        if (!$accounts) {
            throw new RuntimeException('User not found');
        }
        $acc = $accounts[0];
        if (empty($acc['email_confirmed'])) {
            throw new RuntimeException('Cannot approve: user email not confirmed');
        }
        if (($acc['status'] ?? '') === 'banned' || ($acc['status'] ?? '') === 'blocked') {
            throw new RuntimeException('Cannot approve: account is ' . (string) $acc['status']);
        }
        $session = auth_session();
        $token = new_install_token();
        $ins = sb()->call('POST', '/rest/v1/sa_install_tokens', [[
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'token_hint' => token_hint($token),
            'label' => 'download',
            'expires_at' => iso_after('+1 year'),
            'created_by' => $session['user_id'] ?? null,
            'max_uses' => 1,
            'use_count' => 0,
            'used_at' => null,
            'download_request_id' => $requestId,
        ]], ['Prefer: return=representation']);
        if (!$ins['ok'] || !is_array($ins['data']) || empty($ins['data'][0]['id'])) {
            throw new RuntimeException($ins['error'] !== '' ? $ins['error'] : 'Could not issue token');
        }
        $tokenId = (string) $ins['data'][0]['id'];
        sb_write('PATCH', 'sa_download_requests?id=eq.' . rawurlencode($requestId), [
            'status' => 'approved',
            'reviewed_at' => iso_after('+0 seconds'),
            'reviewed_by' => $session['user_id'] ?? null,
            'admin_note' => $adminNote === '' ? null : $adminNote,
            'install_token_id' => $tokenId,
            'updated_at' => iso_after('+0 seconds'),
        ]);
        write_audit('download.approve', $userId, [
            'request_id' => $requestId,
            'hint' => token_hint($token),
            'max_uses' => 1,
        ]);
        $_SESSION['issued_token'] = $token;
        flash_set('ok', 'Download approved. One-time install token issued — copy it now and send to the user.');
        $next = 'downloads';
    } elseif ($action === 'reject_download') {
        $requestId = (string) ($_POST['request_id'] ?? '');
        $userId = (string) ($_POST['user_id'] ?? '');
        $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
        if ($requestId === '') {
            throw new RuntimeException('Missing download request');
        }
        $session = auth_session();
        sb_write('PATCH', 'sa_download_requests?id=eq.' . rawurlencode($requestId), [
            'status' => 'rejected',
            'reviewed_at' => iso_after('+0 seconds'),
            'reviewed_by' => $session['user_id'] ?? null,
            'admin_note' => $adminNote === '' ? null : $adminNote,
            'updated_at' => iso_after('+0 seconds'),
        ]);
        write_audit('download.reject', $userId !== '' ? $userId : null, ['request_id' => $requestId]);
        flash_set('ok', 'Download request rejected.');
        $next = 'downloads';
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
        $password = trim((string) ($_POST['password'] ?? ''));
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $httpPort = (int) ($_POST['http_port'] ?? 8080);
        $socksPort = (int) ($_POST['socks_port'] ?? 1080);
        $enabled = !empty($_POST['enabled']);
        $clearPassword = !empty($_POST['clear_password']);
        $clearApiKey = !empty($_POST['clear_api_key']);
        if ($host === '' || $username === '' || $httpPort < 1 || $socksPort < 1) {
            throw new RuntimeException('Host, username, and ports are required');
        }
        // AnyIP: normalize dead IP / wrong ports that cause Socket closed / ECONNREFUSED.
        if ($providerId === 'anyip') {
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) || stripos($host, 'anyip') === false) {
                $host = 'portal-as.anyip.io';
            }
            if ($httpPort === 1000 || $httpPort === 1002) {
                $httpPort = 1080;
            }
            if ($socksPort === 1000 || $socksPort === 1002) {
                $socksPort = 1080;
            }
            if ($httpPort < 1) {
                $httpPort = 1080;
            }
            if ($socksPort < 1) {
                $socksPort = 1080;
            }
            // Never allow API key to be saved as gateway password.
            if ($password !== '' && $apiKey !== '' && hash_equals($password, $apiKey)) {
                throw new RuntimeException('Gateway password cannot be the same as the API key. Paste the proxy password from AnyIP → Proxies.');
            }
        }
        // Blank secret fields = keep existing. Explicit clear_* checkboxes wipe.
        $existing = sb_rows('sa_proxy_providers?select=password,api_key&id=eq.' . rawurlencode($providerId) . '&limit=1');
        $existingPassword = (string) (($existing[0]['password'] ?? '') ?? '');
        $existingApiKey = (string) (($existing[0]['api_key'] ?? '') ?? '');
        $nextPassword = $clearPassword ? '' : ($password !== '' ? $password : $existingPassword);
        $nextApiKey = $clearApiKey ? '' : ($apiKey !== '' ? $apiKey : $existingApiKey);
        if ($enabled && strlen($nextPassword) < 3) {
            throw new RuntimeException('Gateway password is required when the provider is enabled. Paste it from the proxy dashboard (not the API key).');
        }
        $row = [
            'enabled' => $enabled,
            'host' => $host,
            'username' => $username,
            'http_port' => $httpPort,
            'socks_port' => $socksPort,
            'password' => $nextPassword,
            'api_key' => $nextApiKey,
            'updated_at' => iso_after('+0 seconds'),
        ];
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
                'password' => $nextPassword,
                'http_port' => $httpPort,
                'socks_port' => $socksPort,
                'updated_at' => iso_after('+0 seconds'),
            ];
            sb()->call('POST', '/rest/v1/sa_proxy_settings?on_conflict=id', [$legacy], [
                'Prefer: resolution=merge-duplicates,return=minimal',
            ]);
        }
        write_audit('proxy.save', null, [
            'provider' => $providerId,
            'host' => $host,
            'username' => $username,
            'enabled' => $enabled,
            'has_password' => strlen($nextPassword) >= 3,
            'has_api_key' => strlen($nextApiKey) >= 3,
            'password_cleared' => $clearPassword,
            'api_key_cleared' => $clearApiKey,
        ]);
        $passNote = strlen($nextPassword) >= 3 ? ' Gateway password saved.' : ' Gateway password missing.';
        $keyNote = strlen($nextApiKey) >= 3 ? ' API key saved.' : ' API key empty (optional).';
        flash_set('ok', strtoupper($providerId) . ' saved.' . $passNote . $keyNote . ' Desktop app picks it up on next Launch Center load.');
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