<?php

declare(strict_types=1);

$error = '';
$requests = [];
$emails = [];
$statusFilter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
$issued = (string) ($_SESSION['issued_token'] ?? '');
unset($_SESSION['issued_token']);

try {
    $accounts = sb_rows('sa_accounts?select=id,email');
    foreach ($accounts as $account) {
        $emails[(string) $account['id']] = (string) ($account['email'] ?? '');
    }
    $path = 'sa_download_requests?select=id,user_id,email,status,note,admin_note,created_at,reviewed_at,install_token_id&order=created_at.desc';
    if ($statusFilter !== 'all') {
        $path .= '&status=eq.' . rawurlencode($statusFilter);
    }
    $requests = sb_rows($path);
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Download requests', 'downloads');
?>
<div class="page-head">
  <h1>Download requests</h1>
  <p class="muted">Users request install access on the portal. Approve → enables download + issues a <strong>one-time</strong> install token (copy once).</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<?php if ($issued !== ''): ?>
  <div class="card" style="margin-top:16px">
    <strong>Copy this one-time install token now</strong>
    <p class="muted">Shown once. First successful NSIS install consumes it — second PC with the same token will be rejected.</p>
    <div class="token-box mono" id="issued-token"><?= e($issued) ?></div>
    <button class="btn copper small" type="button" onclick="navigator.clipboard.writeText(document.getElementById('issued-token').textContent)">Copy</button>
  </div>
<?php endif; ?>
<form class="toolbar" method="get" action="index.php">
  <input type="hidden" name="p" value="downloads">
  <select name="status">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn ghost" type="submit">Filter</button>
</form>
<div class="table-wrap">
  <table>
    <thead>
      <tr><th>User</th><th>Status</th><th>Requested</th><th>Note</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($requests as $req): ?>
      <?php
        $uid = (string) $req['user_id'];
        $st = (string) ($req['status'] ?? 'pending');
        $email = (string) (($req['email'] ?? '') !== '' ? $req['email'] : ($emails[$uid] ?? $uid));
      ?>
      <tr>
        <td>
          <strong><?= e($email) ?></strong>
          <div class="muted mono" style="font-size:11px"><?= e($uid) ?></div>
        </td>
        <td><?= pill($st) ?></td>
        <td><?= e(when_label(isset($req['created_at']) ? (string) $req['created_at'] : null)) ?></td>
        <td>
          <?php if (!empty($req['note'])): ?><div><?= e((string) $req['note']) ?></div><?php endif; ?>
          <?php if (!empty($req['admin_note'])): ?><div class="muted">Admin: <?= e((string) $req['admin_note']) ?></div><?php endif; ?>
        </td>
        <td>
          <?php if ($st === 'pending'): ?>
            <form method="post" action="index.php?p=downloads" class="row">
              <?= csrf_field() ?>
              <input type="hidden" name="next" value="downloads">
              <input type="hidden" name="request_id" value="<?= e((string) $req['id']) ?>">
              <input type="hidden" name="user_id" value="<?= e($uid) ?>">
              <input name="admin_note" placeholder="Optional admin note">
              <button class="btn small copper" name="action" value="approve_download" title="Approve download and issue one-time token">Approve + token</button>
              <button class="btn small danger" name="action" value="reject_download">Reject</button>
            </form>
          <?php elseif ($st === 'approved'): ?>
            <form method="post" action="index.php?p=downloads" class="row">
              <?= csrf_field() ?>
              <input type="hidden" name="next" value="downloads">
              <input type="hidden" name="user_id" value="<?= e($uid) ?>">
              <input type="hidden" name="request_id" value="<?= e((string) $req['id']) ?>">
              <button class="btn small" name="action" value="issue_for_download" title="Issue another one-time token for this user">Re-issue token</button>
            </form>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$requests): ?><p class="empty">No download requests<?= $statusFilter !== 'all' ? ' with status ' . e($statusFilter) : '' ?>.</p><?php endif; ?>
</div>
<?php layout_end(); ?>