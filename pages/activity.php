<?php

declare(strict_types=1);

$error = '';
$rows = [];
$emails = [];
$labels = [
    'plan.approve' => 'Approved plan',
    'plan.reject' => 'Rejected plan',
    'plan.grant' => 'Granted plan',
    'token.issue' => 'Issued install token',
    'token.revoke' => 'Revoked install token',
    'download.approve' => 'Approved download + token',
    'download.reject' => 'Rejected download request',
    'user.note' => 'Updated note',
    'user.block' => 'Blocked user',
    'user.ban_wipe' => 'Banned + wipe',
    'user.unblock' => 'Unblocked user',
    'user.force_wipe' => 'Force wipe',
    'user.brick_software' => 'Brick software',
    'proxy.save' => 'Saved proxy',
];

try {
    $accounts = sb_rows('sa_accounts?select=id,email');
    foreach ($accounts as $account) {
        $emails[(string) $account['id']] = (string) ($account['email'] ?? '');
    }
    $rows = sb_rows('sa_audit?select=id,actor_id,action,target_user,detail,created_at&order=created_at.desc&limit=200');
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Activity', 'activity');
?>
<div class="page-head">
  <h1>Activity</h1>
  <p class="muted">Approvals, rejections, token issue, and revoke. The plaintext token is never written here.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<div class="table-wrap">
  <table>
    <thead>
      <tr><th>When</th><th>Action</th><th>User</th><th>Detail</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <?php
        $action = (string) ($row['action'] ?? '');
        $detail = $row['detail'] ?? null;
        $detailText = is_array($detail) ? json_encode($detail) : '';
        $target = (string) ($row['target_user'] ?? '');
      ?>
      <tr>
        <td><?= e(when_label((string) ($row['created_at'] ?? ''))) ?></td>
        <td><?= e($labels[$action] ?? $action) ?></td>
        <td><?= e($target !== '' ? ($emails[$target] ?? $target) : '—') ?></td>
        <td class="mono"><?= e($detailText !== '' && $detailText !== '[]' && $detailText !== '{}' ? $detailText : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows && $error === ''): ?><p class="empty">No admin actions yet.</p><?php endif; ?>
</div>
<?php layout_end(); ?>