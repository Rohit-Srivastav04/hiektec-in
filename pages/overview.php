<?php

declare(strict_types=1);

$error = '';
$counts = ['users' => 0, 'pending' => 0, 'live' => 0, 'tokens' => 0, 'downloads' => 0];
$pending = [];
$downloadPending = [];

try {
    $accounts = sb_rows('sa_accounts?select=id,email&order=created_at.desc');
    $plans = sb_rows('sa_plans?select=id,user_id,plan,status,requested_at,expires_at&order=requested_at.desc');
    $tokens = sb_rows('sa_install_tokens?select=id,revoked,used_at,use_count,max_uses');
    $dlReqs = [];
    try {
        $dlReqs = sb_rows('sa_download_requests?select=id,user_id,email,status,created_at&status=eq.pending&order=created_at.desc&limit=8');
    } catch (Throwable) {
        $dlReqs = [];
    }
    $emails = [];
    foreach ($accounts as $account) {
        $emails[(string) $account['id']] = (string) ($account['email'] ?? '');
    }
    foreach ($plans as $plan) {
        if (($plan['status'] ?? '') === 'pending') {
            $uid = (string) $plan['user_id'];
            $plan['email'] = $emails[$uid] ?? $uid;
            $pending[] = $plan;
        }
        if (plan_is_live($plan)) {
            $counts['live']++;
        }
    }
    $counts['users'] = count($accounts);
    $counts['pending'] = count($pending);
    foreach ($tokens as $token) {
        $used = !empty($token['used_at']) || ((int) ($token['use_count'] ?? 0) >= (int) ($token['max_uses'] ?? 1));
        if (empty($token['revoked']) && !$used) {
            $counts['tokens']++;
        }
    }
    foreach ($dlReqs as $req) {
        $uid = (string) $req['user_id'];
        $req['email'] = (string) (($req['email'] ?? '') !== '' ? $req['email'] : ($emails[$uid] ?? $uid));
        $downloadPending[] = $req;
    }
    $counts['downloads'] = count($downloadPending);
    $pending = array_slice($pending, 0, 6);
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Dashboard', 'overview');
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <p class="muted">Strict flow: portal signup → request download → you approve (+ one-time token) → user downloads → NSIS consumes token.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<div class="stats">
  <div class="stat"><span>Users</span><b><?= (int) $counts['users'] ?></b></div>
  <div class="stat"><span>Pending plans</span><b><?= (int) $counts['pending'] ?></b></div>
  <div class="stat"><span>Pending downloads</span><b><?= (int) $counts['downloads'] ?></b></div>
  <div class="stat"><span>Unused tokens</span><b><?= (int) $counts['tokens'] ?></b></div>
</div>
<div class="grid-2">
  <div class="card">
    <div class="spread">
      <h3>Download / install requests</h3>
      <a class="btn ghost small" href="index.php?p=downloads&status=pending">All pending</a>
    </div>
    <?php if (!$downloadPending): ?><p class="empty">No pending download requests from the portal.</p><?php endif; ?>
    <?php foreach ($downloadPending as $req): ?>
      <div class="spread" style="padding:10px 0;border-top:1px solid var(--line)">
        <div>
          <strong><?= e((string) $req['email']) ?></strong>
          <div class="muted">requested <?= e(day_label((string) ($req['created_at'] ?? ''))) ?></div>
        </div>
        <form method="post" action="index.php?p=overview" class="row">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="overview">
          <input type="hidden" name="request_id" value="<?= e((string) $req['id']) ?>">
          <input type="hidden" name="user_id" value="<?= e((string) $req['user_id']) ?>">
          <button class="btn small copper" name="action" value="approve_download">Approve + token</button>
          <button class="btn small danger" name="action" value="reject_download">Reject</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="spread">
      <h3>Plan requests from software</h3>
      <a class="btn ghost small" href="index.php?p=plans&status=pending">All pending</a>
    </div>
    <?php if (!$pending): ?><p class="empty">No pending plan requests.</p><?php endif; ?>
    <?php foreach ($pending as $plan): ?>
      <div class="spread" style="padding:10px 0;border-top:1px solid var(--line)">
        <div>
          <strong><?= e((string) $plan['email']) ?></strong>
          <div class="muted"><?= e((string) $plan['plan']) ?> · requested <?= e(day_label((string) ($plan['requested_at'] ?? ''))) ?></div>
        </div>
        <form method="post" action="index.php?p=overview" class="row">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="overview">
          <input type="hidden" name="plan_id" value="<?= e((string) $plan['id']) ?>">
          <input type="hidden" name="user_id" value="<?= e((string) $plan['user_id']) ?>">
          <input type="hidden" name="plan" value="<?= e((string) $plan['plan']) ?>">
          <button class="btn small copper" name="action" value="approve">Approve</button>
          <button class="btn small danger" name="action" value="reject">Reject</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="card" style="margin-top:16px">
  <h3>How access works</h3>
  <p>Portal signup/login → Request install access → Admin Approves (issues one-time token) → Download installer → NSIS asks for token (consumes on first success) → Desktop unlocks while plan is active.</p>
  <a class="btn small" href="index.php?p=downloads">Open downloads</a>
  <a class="btn small ghost" href="index.php?p=users">Go to users</a>
</div>
<?php layout_end(); ?>