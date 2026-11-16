<?php

declare(strict_types=1);

$error = '';
$accounts = [];
$plans = [];
$query = trim((string) ($_GET['q'] ?? ''));
$issued = (string) ($_SESSION['issued_token'] ?? '');
unset($_SESSION['issued_token']);

try {
    $accounts = sb_rows('sa_accounts?select=id,email,display_name,admin_note,email_confirmed,status,ban_reason,force_wipe,wipe_profiles,created_at&order=created_at.desc');
    $plans = sb_rows('sa_plans?select=id,user_id,plan,status,expires_at,requested_at&order=requested_at.desc');
} catch (Throwable $err) {
    $error = $err->getMessage();
}

$live = [];
$pending = [];
foreach ($plans as $plan) {
    $uid = (string) $plan['user_id'];
    if (($plan['status'] ?? '') === 'pending') {
        $pending[$uid][] = $plan;
    }
    if (!isset($live[$uid]) && plan_is_live($plan)) {
        $live[$uid] = $plan;
    }
}

if ($query !== '') {
    $needle = strtolower($query);
    $accounts = array_values(array_filter($accounts, static function (array $account) use ($needle): bool {
        $hay = strtolower(($account['email'] ?? '') . ' ' . ($account['display_name'] ?? '') . ' ' . ($account['admin_note'] ?? ''));
        return str_contains($hay, $needle);
    }));
}

layout_start('Users', 'users');
?>
<div class="page-head">
  <h1>Users</h1>
  <p class="muted">Plans, one-time install tokens, block, ban, and remote wipe. Prefer <a href="index.php?p=downloads">Downloads</a> for approve + token. Desktop has no admin UI.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<?php if ($issued !== ''): ?>
  <div class="card" style="margin-top:16px">
    <strong>Copy this one-time token now</strong>
    <p class="muted">Plaintext is not stored. First NSIS install consumes it — second install with the same token fails.</p>
    <div class="token-box mono" id="issued-token"><?= e($issued) ?></div>
    <button class="btn copper small" type="button" onclick="navigator.clipboard.writeText(document.getElementById('issued-token').textContent)">Copy</button>
  </div>
<?php endif; ?>
<form class="toolbar" method="get" action="index.php">
  <input type="hidden" name="p" value="users">
  <input name="q" placeholder="Search email or name" value="<?= e($query) ?>">
  <button class="btn ghost" type="submit">Search</button>
</form>
<div class="table-wrap">
  <table>
    <thead>
      <tr><th>User</th><th>Status</th><th>Plan</th><th>Controls</th></tr>
    </thead>
    <tbody>
    <?php foreach ($accounts as $account): ?>
      <?php
        $uid = (string) $account['id'];
        $plan = $live[$uid] ?? null;
        $status = (string) ($account['status'] ?? 'active');
        $pendingFor = $pending[$uid] ?? [];
      ?>
      <tr>
        <td>
          <strong><?= e((string) (($account['display_name'] ?? '') !== '' ? $account['display_name'] : explode('@', (string) $account['email'])[0])) ?></strong>
          <div><?= e((string) $account['email']) ?></div>
          <div class="muted mono" style="font-size:11px"><?= e($uid) ?></div>
          <?php if (!empty($account['admin_note'])): ?><div class="muted"><?= e((string) $account['admin_note']) ?></div><?php endif; ?>
          <?php if (!empty($account['ban_reason'])): ?><div class="muted">Reason: <?= e((string) $account['ban_reason']) ?></div><?php endif; ?>
        </td>
        <td>
          <?= pill(!empty($account['email_confirmed']) ? 'confirmed' : 'unconfirmed') ?>
          <?= pill($status) ?>
          <?php if (!empty($account['force_wipe'])): ?><?= pill('wipe pending') ?><?php endif; ?>
        </td>
        <td>
          <?php if ($plan): ?>
            <?= pill('active') ?> <?= e((string) $plan['plan']) ?> until <?= e(day_label((string) ($plan['expires_at'] ?? ''))) ?>
          <?php else: ?>
            <?= pill('none') ?>
          <?php endif; ?>
          <?php foreach ($pendingFor as $p): ?>
            <div class="row" style="margin-top:6px">
              <span class="muted"><?= e((string) $p['plan']) ?> pending</span>
              <form method="post" action="index.php?p=users" class="row">
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="users">
                <input type="hidden" name="plan_id" value="<?= e((string) $p['id']) ?>">
                <input type="hidden" name="user_id" value="<?= e($uid) ?>">
                <input type="hidden" name="plan" value="<?= e((string) $p['plan']) ?>">
                <button class="btn small copper" name="action" value="approve">Approve</button>
                <button class="btn small danger" name="action" value="reject">Reject</button>
              </form>
            </div>
          <?php endforeach; ?>
        </td>
        <td>
          <form method="post" action="index.php?p=users" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="users">
            <input type="hidden" name="user_id" value="<?= e($uid) ?>">
            <button class="btn small" name="action" value="issue" <?= empty($account['email_confirmed']) || $status === 'banned' || $status === 'blocked' ? 'disabled' : '' ?> title="One-time install token">Issue token</button>
            <button class="btn small copper" name="action" value="grant_monthly">Give monthly</button>
            <button class="btn small copper" name="action" value="grant_yearly">Give yearly</button>
          </form>
          <form method="post" action="index.php?p=users" class="row" style="margin-top:8px">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="users">
            <input type="hidden" name="user_id" value="<?= e($uid) ?>">
            <input name="ban_reason" placeholder="Block / ban reason" value="<?= e((string) ($account['ban_reason'] ?? '')) ?>">
            <button class="btn small danger" name="action" value="block">Block</button>
            <button class="btn small danger" name="action" value="ban_wipe">Ban + wipe</button>
            <button class="btn small danger" name="action" value="brick_software" title="Deletes profiles AND breaks installed engine/app so SA Browser crashes">Kill software (crash)</button>
            <button class="btn small ghost" name="action" value="force_wipe">Force wipe + brick</button>
            <button class="btn small" name="action" value="unblock">Unblock / restore</button>
          </form>
          <form method="post" action="index.php?p=users" class="note-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="note">
            <input type="hidden" name="next" value="users">
            <input type="hidden" name="user_id" value="<?= e($uid) ?>">
            <input name="admin_note" placeholder="Admin note" value="<?= e((string) ($account['admin_note'] ?? '')) ?>">
            <button class="btn ghost small" type="submit">Save note</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$accounts): ?><p class="empty">No users match.</p><?php endif; ?>
</div>
<?php layout_end(); ?>