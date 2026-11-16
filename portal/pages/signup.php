<?php

declare(strict_types=1);

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        global $supabase;
        $res = $supabase->call('POST', '/auth/v1/signup', [
            'email' => $email,
            'password' => $password,
            'data' => [
                'display_name' => explode('@', $email)[0] ?? 'User',
                'app_product' => 'sa_browser',
            ],
        ]);
        if (!$res['ok']) {
            $error = $res['error'] !== '' ? $res['error'] : 'Sign up failed';
        } else {
            // Supabase may return a session only when confirm-email is off.
            if (is_array($res['data']) && !empty($res['data']['access_token'])) {
                $user = is_array($res['data']['user'] ?? null) ? $res['data']['user'] : [];
                if (!empty($user['email_confirmed_at'])) {
                    auth_store($res['data']);
                    session_regenerate_id(true);
                    flash_set('ok', 'Account created. Request install access on the next page.');
                    redirect('download');
                }
            }
            flash_set('ok', 'Account created. You can sign in now and request download access.');
            redirect('login');
        }
    }
}

portal_layout_start('Sign up');
?>
<section class="card auth-card">
  <h1>Create account</h1>
  <p class="muted"><strong>Sign up here → request access → admin approves → download → install with token.</strong> There is no public free download. Use the same Gmail you will use in SA Browser.</p>
  <ol class="steps compact">
    <li>Sign up (email auto-confirmed)</li>
    <li>Request install access</li>
    <li>Admin approves + sends one-time token</li>
    <li>Download Setup → login + enter token</li>
  </ol>
  <form method="post" action="index.php?p=signup">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" value="<?= e($email) ?>" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
    <label for="password2">Confirm password</label>
    <input id="password2" name="password2" type="password" autocomplete="new-password" required minlength="8">
    <?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <button class="btn" type="submit">Sign up</button>
  </form>
  <p class="muted" style="margin-top:16px">Already have an account? <a href="index.php?p=login">Sign in</a></p>
</section>
<?php portal_layout_end(); ?>