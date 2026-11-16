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
            $error = 'Email not confirmed yet. Try signing up again, or ask admin to confirm your account.';
        } else {
            auth_store($res['data']);
            session_regenerate_id(true);
            redirect('download');
        }
    }
}

portal_layout_start('Sign in');
?>
<section class="card auth-card">
  <h1>Sign in</h1>
  <p class="muted">Customer portal only — not the staff admin site. After sign-in: <strong>request access → wait for approval → download → install with your one-time token.</strong></p>
  <form method="post" action="index.php?p=login">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" value="<?= e($email) ?>" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <button class="btn" type="submit">Sign in</button>
  </form>
  <p class="muted" style="margin-top:16px">New here? <a href="index.php?p=signup">Create an account</a></p>
</section>
<?php portal_layout_end(); ?>