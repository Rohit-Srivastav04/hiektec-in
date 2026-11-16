<?php

declare(strict_types=1);

$session = auth_require();
$error = '';
$status = null;

$res = sb()->call('POST', '/rest/v1/rpc/sa_my_download_status', []);
if (!$res['ok'] || !is_array($res['data'])) {
    $error = $res['error'] !== '' ? $res['error'] : 'Could not load download status. Ask admin to apply sa-download-flow.sql.';
} else {
    $status = $res['data'];
}

$reqStatus = is_array($status) ? (string) ($status['status'] ?? '') : '';
$canDownload = is_array($status) && !empty($status['can_download']);
$hasRequest = is_array($status) && !empty($status['has_request']);
$planOk = is_array($status) && !empty($status['plan_ok']);
$emailOk = is_array($status) && !empty($status['email_confirmed']);
$accountStatus = is_array($status) ? (string) ($status['account_status'] ?? 'active') : 'active';
$dlUrl = installer_url();

portal_layout_start('Dashboard');
?>
<section class="card">
  <h1>Your dashboard</h1>
  <p class="muted">Signed in as <strong><?= e((string) ($session['email'] ?? '')) ?></strong>.</p>
  <p class="muted">Yahan se install access request bhejo. Admin approve karega, phir download button unlock hoga. Installer baad me admin upload karega — abhi sirf request/approval flow live hai.</p>

  <?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <ol class="steps">
    <li class="<?= $emailOk ? 'done' : '' ?>">Confirm email<?= $emailOk ? ' ✓' : ' — check inbox' ?></li>
    <li class="<?= $hasRequest ? 'done' : '' ?>">Request install access</li>
    <li class="<?= $reqStatus === 'approved' ? 'done' : ($reqStatus === 'rejected' ? 'bad' : '') ?>">
      Admin approval
      <?php if ($reqStatus === 'pending'): ?> — <em>pending</em><?php endif; ?>
      <?php if ($reqStatus === 'approved'): ?> — <em>approved</em><?php endif; ?>
      <?php if ($reqStatus === 'rejected'): ?> — <em>rejected</em><?php endif; ?>
    </li>
    <li class="<?= $planOk ? 'done' : '' ?>">Active plan (admin Give monthly/yearly)</li>
    <li class="<?= $canDownload ? 'done' : '' ?>">Download installer</li>
    <li>Run Setup → enter one-time install token (first use only)</li>
  </ol>

  <?php if ($accountStatus === 'banned' || $accountStatus === 'blocked'): ?>
    <div class="error">Account is <?= e($accountStatus) ?>. Download is disabled.</div>
  <?php elseif (!$emailOk): ?>
    <div class="error">Confirm your email before requesting download access.</div>
  <?php elseif (!$hasRequest || $reqStatus === 'rejected'): ?>
    <form method="post" action="index.php?p=download" class="request-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request_download">
      <label for="note">Optional note for admin</label>
      <input id="note" name="note" maxlength="200" placeholder="e.g. Windows 11 laptop">
      <button class="btn" type="submit"><?= $reqStatus === 'rejected' ? 'Request again' : 'Request install access' ?></button>
    </form>
    <?php if ($reqStatus === 'rejected'): ?>
      <p class="muted">Previous request was rejected<?= !empty($status['admin_note']) ? ': ' . e((string) $status['admin_note']) : '.' ?></p>
    <?php endif; ?>
  <?php elseif ($reqStatus === 'pending'): ?>
    <div class="okbox">Request is pending. Admin will approve it and issue your one-time install token (sent/shared separately).</div>
  <?php elseif ($reqStatus === 'approved'): ?>
    <?php if (!$planOk): ?>
      <div class="error">Download approved, but you need an active plan. Ask admin to Give monthly/yearly on hiektec.in.</div>
    <?php elseif ($dlUrl === ''): ?>
      <div class="error">Installer URL is not configured on the portal yet.</div>
    <?php elseif ($canDownload): ?>
      <a class="btn download" href="<?= e($dlUrl) ?>">Download installer</a>
      <p class="muted" style="margin-top:12px">Agar file abhi server pe nahi hai to link 404 de sakti hai — admin Setup.exe upload karega. Download ke baad Setup me <strong>one-time install token</strong> paste karo (ek token = ek install).</p>
    <?php else: ?>
      <div class="error">Download not available yet.</div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php portal_layout_end(); ?>