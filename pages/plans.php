<?php

declare(strict_types=1);

$error = '';
$plans = [];
$emails = [];
$filter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'active', 'rejected', 'all'], true)) {
    $filter = 'pending';
}

try {
    $accounts = sb_rows('sa_accounts?select=id,email');
    foreach ($accounts as $account) {
        $emails[(string) $account['id']] = (string) ($account['email'] ?? '');
    }
    $path = 'sa_plans?select=id,user_id,plan,status,requested_at,expires_at&order=requested_at.desc';
    if ($filter !== 'all') {
        $path .= '&status=eq.' . rawurlencode($filter);
    }
    $plans = sb_rows($path);
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Plans', 'plans');
?>
<div class="page-head">
  <h1>Plans</h1>
  <p class="muted">Monthly lasts 1 month from approval. Yearly lasts 12 months. The desktop app blocks access when the date passes.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<div class="toolbar filters">
  <?php foreach (['pending', 'active', 'rejected', 'all'] as $item): ?>
    <a class="<?= $filter === $item ? 'on' : '' ?>" href="index.php?p=plans&status=<?= e($item) ?>"><?= e($item) ?></a>
  <?php endforeach; ?>
</div>
<div class="table-wrap">
  <table>
    <thead>
      <tr><th>User</th><th>Plan</th><th>Status</th><th>Requested</th><th>Expires</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($plans as $plan): ?>
      <tr>
        <td><?= e($emails[(string) $plan['user_id']] ?? (string) $plan['user_id']) ?></td>
        <td><?= e((string) $plan['plan']) ?></td>
        <td><?= pill((string) $plan['status']) ?></td>
        <td><?= e(day_label((string) ($plan['requested_at'] ?? ''))) ?></td>
        <td><?= e(day_label((string) ($plan['expires_at'] ?? ''))) ?></td>
        <td>
          <?php if (($plan['status'] ?? '') === 'pending'): ?>
            <form method="post" action="index.php?p=plans" class="row">
              <?= csrf_field() ?>
              <input type="hidden" name="next" value="plans">
              <input type="hidden" name="plan_id" value="<?= e((string) $plan['id']) ?>">
              <input type="hidden" name="user_id" value="<?= e((string) $plan['user_id']) ?>">
              <input type="hidden" name="plan" value="<?= e((string) $plan['plan']) ?>">
              <button class="btn small copper" name="action" value="approve">Approve</button>
              <button class="btn small danger" name="action" value="reject">Reject</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$plans): ?><p class="empty">Nothing in this list.</p><?php endif; ?>
</div>
<?php layout_end(); ?>