<?php

declare(strict_types=1);

$error = '';
$banned = [];
$rejected = [];
$emails = [];

try {
    $accounts = sb_rows('sa_accounts?select=id,email,status,ban_reason,banned_at,blocked_at,force_wipe&order=created_at.desc');
    foreach ($accounts as $account) {
        $emails[(string) $account['id']] = (string) ($account['email'] ?? '');
        $st = (string) ($account['status'] ?? 'active');
        if (in_array($st, ['banned', 'blocked'], true) || !empty($account['force_wipe'])) {
            $banned[] = $account;
        }
    }
    $plans = sb_rows('sa_plans?select=id,user_id,plan,status,requested_at,expires_at&status=eq.rejected&order=requested_at.desc&limit=80');
    foreach ($plans as $plan) {
        $plan['email'] = $emails[(string) $plan['user_id']] ?? (string) $plan['user_id'];
        $rejected[] = $plan;
    }
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Trash', 'trash');
?>
<div class="page-head">
  <h1>Trash</h1>
  <p class="muted">Banned / blocked accounts and rejected plan requests. Restore users from here or Users.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="margin:18px 0">
  <h3>Banned / blocked / wipe queued</h3>
  <?php if (!$banned): ?><p class="empty">Trash is empty for accounts.</p><?php endif; ?>
  <?php foreach ($banned as $account): ?>
    <div class="spread" style="padding:12px 0;border-top:1px solid var(--line)">
      <div>
        <strong><?= e((string) ($account['email'] ?? '')) ?></strong>
        <div class="muted"><?= e((string) ($account['status'] ?? '')) ?><?php if (!empty($account['ban_reason'])): ?> · <?= e((string) $account['ban_reason']) ?><?php endif; ?></div>
      </div>
      <form method="post" action="index.php?p=trash" class="row">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="trash">
        <input type="hidden" name="user_id" value="<?= e((string) $account['id']) ?>">
        <button class="btn small" name="action" value="unblock">Restore</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr><th>Rejected plan</th><th>Plan</th><th>Requested</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rejected as $plan): ?>
      <tr>
        <td><?= e((string) $plan['email']) ?></td>
        <td><?= e((string) $plan['plan']) ?></td>
        <td><?= e(day_label((string) ($plan['requested_at'] ?? ''))) ?></td>
        <td>
          <form method="post" action="index.php?p=trash" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="trash">
            <input type="hidden" name="plan_id" value="<?= e((string) $plan['id']) ?>">
            <input type="hidden" name="user_id" value="<?= e((string) $plan['user_id']) ?>">
            <input type="hidden" name="plan" value="<?= e((string) $plan['plan']) ?>">
            <button class="btn small copper" name="action" value="approve">Approve anyway</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rejected): ?><p class="empty">No rejected plans.</p><?php endif; ?>
</div>
<?php layout_end(); ?>