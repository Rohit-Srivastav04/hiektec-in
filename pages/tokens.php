<?php

declare(strict_types=1);

$error = '';
$tokens = [];
$emails = [];

try {
    $accounts = sb_rows('sa_accounts?select=id,email');
    foreach ($accounts as $account) {
        $emails[(string) $account['id']] = (string) ($account['email'] ?? '');
    }
    $tokens = sb_rows('sa_install_tokens?select=id,user_id,token_hint,label,created_at,expires_at,revoked,last_seen_at,used_at,use_count,max_uses&order=created_at.desc');
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Install tokens', 'tokens');
?>
<div class="page-head">
  <h1>Install tokens</h1>
  <p class="muted">One-time by default. First successful NSIS install sets <code>used_at</code> — a second install with the same token is rejected. Revoke anytime.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<div class="table-wrap">
  <table>
    <thead>
      <tr><th>User</th><th>Hint</th><th>Label</th><th>Uses</th><th>Last seen</th><th>Expires</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($tokens as $token): ?>
      <?php
        $used = !empty($token['used_at']) || ((int) ($token['use_count'] ?? 0) >= (int) ($token['max_uses'] ?? 1));
      ?>
      <tr>
        <td><?= e($emails[(string) $token['user_id']] ?? (string) $token['user_id']) ?></td>
        <td class="mono"><?= e((string) (($token['token_hint'] ?? '') !== '' ? $token['token_hint'] : 'issued earlier')) ?></td>
        <td><?= e((string) (($token['label'] ?? '') !== '' ? $token['label'] : '—')) ?></td>
        <td>
          <?= (int) ($token['use_count'] ?? 0) ?> / <?= (int) ($token['max_uses'] ?? 1) ?>
          <?php if ($used): ?> <?= pill('used') ?><?php endif; ?>
        </td>
        <td><?= e(when_label(isset($token['last_seen_at']) ? (string) $token['last_seen_at'] : null)) ?></td>
        <td><?= e(when_label(isset($token['expires_at']) ? (string) $token['expires_at'] : null)) ?></td>
        <td>
          <?php if (!empty($token['revoked'])): ?>
            <?= pill('revoked') ?>
          <?php else: ?>
            <form method="post" action="index.php?p=tokens">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="next" value="tokens">
              <input type="hidden" name="token_id" value="<?= e((string) $token['id']) ?>">
              <input type="hidden" name="user_id" value="<?= e((string) $token['user_id']) ?>">
              <input type="hidden" name="hint" value="<?= e((string) ($token['token_hint'] ?? '')) ?>">
              <button class="btn small danger" type="submit">Revoke</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$tokens): ?><p class="empty">No install tokens yet. Approve a download request or Issue token from Users.</p><?php endif; ?>
</div>
<?php layout_end(); ?>