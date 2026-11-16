<?php

declare(strict_types=1);

$error = '';
$providers = [];

try {
    $providers = sb_rows('sa_proxy_providers?select=id,label,enabled,host,http_port,socks_port,username,password,api_key,notes,sort_order&order=sort_order.asc');
} catch (Throwable $err) {
    $error = $err->getMessage();
}

layout_start('Proxies', 'proxy');
?>
<div class="page-head">
  <h1>Proxy APIs</h1>
  <p class="muted">Enable a provider, set gateway user/pass, and paste its API key. SA Browser fetches these from Supabase for launch + checking.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

<div style="display:grid;gap:14px;margin-top:18px;max-width:820px">
<?php foreach ($providers as $row): ?>
  <?php
    $id = (string) ($row['id'] ?? '');
    $hasPassword = trim((string) ($row['password'] ?? '')) !== '';
    $hasApiKey = trim((string) ($row['api_key'] ?? '')) !== '';
    $enabled = !empty($row['enabled']);
  ?>
  <form class="card" method="post" action="index.php?p=proxy">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="proxy_provider">
    <input type="hidden" name="next" value="proxy">
    <input type="hidden" name="provider_id" value="<?= e($id) ?>">
    <div class="spread">
      <div>
        <h3 style="margin:0"><?= e((string) ($row['label'] ?? $id)) ?></h3>
        <p class="muted" style="margin:6px 0 0"><?= e((string) ($row['notes'] ?? '')) ?></p>
      </div>
      <label class="row" style="gap:6px;font-size:13px;font-weight:600">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Enabled
      </label>
    </div>
    <div class="row" style="margin-top:12px;align-items:flex-start">
      <div style="flex:1">
        <label>Username</label>
        <input name="username" value="<?= e((string) ($row['username'] ?? '')) ?>" placeholder="gateway username" required>
      </div>
      <div style="flex:1">
        <label>Password / gateway secret</label>
        <input name="password" type="password" placeholder="<?= $hasPassword ? 'Saved. Leave blank to keep it.' : 'password' ?>" autocomplete="new-password">
      </div>
    </div>
    <div style="margin-top:4px">
      <label>API key</label>
      <input name="api_key" type="password" placeholder="<?= $hasApiKey ? 'Saved. Leave blank to keep it.' : 'Provider API key for checks / balance' ?>" autocomplete="new-password">
      <p class="muted" style="margin:6px 0 0;font-size:12px">Stored in Supabase. Desktop app fetches it for provider API checks.</p>
    </div>
    <div class="row" style="align-items:flex-start">
      <div style="flex:2">
        <label>Host</label>
        <input name="host" value="<?= e((string) ($row['host'] ?? '')) ?>" required>
      </div>
      <div style="flex:1">
        <label>HTTP port</label>
        <input name="http_port" type="number" min="1" max="65535" value="<?= (int) ($row['http_port'] ?? 8080) ?>" required>
      </div>
      <div style="flex:1">
        <label>SOCKS5 port</label>
        <input name="socks_port" type="number" min="1" max="65535" value="<?= (int) ($row['socks_port'] ?? 1080) ?>" required>
      </div>
    </div>
    <div style="margin-top:14px">
      <button class="btn copper" type="submit">Save <?= e((string) ($row['label'] ?? $id)) ?></button>
    </div>
  </form>
<?php endforeach; ?>
<?php if (!$providers && $error === ''): ?>
  <p class="empty">No provider rows yet. Apply supabase/sa-proxy-providers.sql.</p>
<?php endif; ?>
</div>
<?php layout_end(); ?>