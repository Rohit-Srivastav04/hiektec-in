<?php

declare(strict_types=1);

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    global $supabase;
    $res = $supabase->call('POST', '/auth/v1/token?grant_type=password', [
        'email' => $email,
        'password' => $password,
    ]);
    if (!$res['ok'] || !is_array($res['data']) || empty($res['data']['access_token'])) {
        $error = $res['error'] !== '' ? $res['error'] : 'Sign in failed';
    } else {
        $user = is_array($res['data']['user'] ?? null) ? $res['data']['user'] : [];
        if (empty($user['email_confirmed_at'])) {
            $error = 'Confirm your email before opening the admin panel.';
        } else {
            auth_store($res['data']);
            $check = sb()->call('POST', '/rest/v1/rpc/sa_is_admin', []);
            $isAdmin = $check['ok'] && $check['data'] === true;
            if (!$isAdmin) {
                auth_logout();
                $error = 'This account is not an SA Browser admin.';
            } else {
                session_regenerate_id(true);
                redirect('overview');
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · SA Browser Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=logo40">
  <link rel="icon" href="assets/logo.png" type="image/png">
</head>
<body>
  <div class="login-screen">
    <section class="login-brand">
      <img class="brand-logo" src="assets/logo.png" alt="SA Browser" width="40" height="40" style="width:40px;height:40px;max-width:40px;max-height:40px;object-fit:contain;display:block">
      <h1>SA Browser Admin</h1>
      <p>Accounts, plans, and install tokens for the desktop app. Customers never see this site.</p>
    </section>
    <section class="login-card-wrap">
      <form class="login-card" method="post" action="index.php?p=login">
        <h2>Sign in</h2>
        <p class="muted">Admin email only. The role lives in app metadata, not in the password.</p>
        <?= csrf_field() ?>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" value="<?= e($email) ?>" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
        <div style="margin-top:18px">
          <button class="btn copper" type="submit">Enter admin</button>
        </div>
      </form>
    </section>
  </div>
</body>
</html>