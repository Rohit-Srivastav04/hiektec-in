<?php

declare(strict_types=1);

$session = auth_require();
$error = '';
$adminOk = false;
try {
    $check = sb()->call('POST', '/rest/v1/rpc/sa_is_admin', []);
    $adminOk = $check['ok'] && $check['data'] === true;
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Profile', 'profile');
?>
<div class="page-head">
  <h1>Profile</h1>
  <p class="muted">Admin account connected to Supabase. Desktop plan requests and grants use this same project.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<div class="grid-2">
  <div class="card">
    <h3>Signed-in admin</h3>
    <p><strong>Email</strong><br><?= e((string) ($session['email'] ?? '')) ?></p>
    <p><strong>User id</strong><br><span class="mono"><?= e((string) ($session['user_id'] ?? '')) ?></span></p>
    <p><strong>Role check</strong><br><?= $adminOk ? pill('active') : pill('none') ?> <?= $adminOk ? 'sa_is_admin = true' : 'admin role missing in app_metadata' ?></p>
  </div>
  <div class="card">
    <h3>How the three pieces connect</h3>
    <p><strong>1. Admin panel (hiektec.in)</strong> — approve download requests, plans, issue one-time tokens, ban/wipe.</p>
    <p><strong>2. Customer portal</strong> — signup/login → request download → download only after approve.</p>
    <p><strong>3. Supabase</strong> — <code>sa_accounts</code>, <code>sa_plans</code>, <code>sa_download_requests</code>, <code>sa_install_tokens</code> (one-time).</p>
    <p><strong>4. SA Browser desktop</strong> — NSIS consumes token on first install; app re-checks without consuming.</p>
    <a class="btn small copper" href="index.php?p=plans&status=pending">Open pending plan requests</a>
  </div>
</div>
<?php layout_end(); ?>