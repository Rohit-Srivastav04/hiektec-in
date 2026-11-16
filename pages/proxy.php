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
  <p class="muted">Enable a provider, set gateway user/pass, and save its API key here. Values are stored in Supabase — you can view and change them anytime. SA Browser fetches them for Launch + balance checks.</p>
</div>
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

<div style="display:grid;gap:14px;margin-top:18px;max-width:820px">
<?php foreach ($providers as $row): ?>
  <?php
    $id = (string) ($row['id'] ?? '');
    $savedPassword = (string) ($row['password'] ?? '');
    $savedApiKey = (string) ($row['api_key'] ?? '');
    $hasPassword = trim($savedPassword) !== '';
    $hasApiKey = trim($savedApiKey) !== '';
    $enabled = !empty($row['enabled']);
  ?>
  <form class="card" method="post" action="index.php?p=proxy">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="proxy_provider">
    <input type="hidden" name="next" value="proxy">
    <input type="hidden" name="provider_id" value="<?= e($id) ?>">
    <div class="spread">
      <div>
        <h3 style="margin:0"><?= e((string) ($row['label'] ?? $id)) ?><?php if ($id === 'anyip'): ?> <span class="pill ok">primary</span><?php endif; ?></h3>
        <p class="muted" style="margin:6px 0 0"><?= e((string) ($row['notes'] ?? '')) ?></p>
        <?php if ($id === 'anyip'): ?>
          <p class="muted" style="margin:8px 0 0;font-size:12px;line-height:1.45">
            <strong>Gateway auth ≠ API key.</strong>
            Host must be <code>portal.anyip.io</code> / <code>portal-as.anyip.io</code>, ports <code>1080</code>/<code>1080</code>,
            username <code>user_XXXX</code>, and the <em>proxy password</em> from AnyIP → Proxies (not the REST API key).
          </p>
        <?php endif; ?>
      </div>
      <label class="row" style="gap:6px;font-size:13px;font-weight:600">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Enabled
      </label>
    </div>
    <div class="row" style="margin-top:12px;align-items:flex-start">
      <div style="flex:1">
        <label>Username</label>
        <input name="username" value="<?= e((string) ($row['username'] ?? '')) ?>" placeholder="user_XXXX" required>
      </div>
      <div style="flex:1">
        <label>Password / gateway secret <?= $hasPassword ? '<span class="pill ok">saved</span>' : '<span class="pill warn">missing — Socket closed without this</span>' ?></label>
        <input name="password" type="text" value="" placeholder="<?= $hasPassword ? '•••• saved — leave blank to keep' : 'paste gateway password from AnyIP Proxies' ?>" autocomplete="off" spellcheck="false">
        <?php if ($hasPassword): ?>
          <label class="row" style="gap:6px;margin-top:6px;font-size:12px">
            <input type="checkbox" name="clear_password" value="1">
            Clear saved gateway password
          </label>
        <?php endif; ?>
      </div>
    </div>
    <div style="margin-top:4px">
      <label>API key (REST only) <?= $hasApiKey ? '<span class="pill ok">saved · leave blank to keep</span>' : '<span class="pill warn">optional — not used for SOCKS/HTTP</span>' ?></label>
      <input name="api_key" type="text" value="" placeholder="<?= $hasApiKey ? '•••• saved — leave blank to keep, or paste new key' : ($id === 'anyip' ? 'AnyIP dashboard API key (optional)' : 'Provider API key optional') ?>" autocomplete="off" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace">
      <?php if ($hasApiKey): ?>
        <label class="row" style="gap:6px;margin-top:6px;font-size:12px">
          <input type="checkbox" name="clear_api_key" value="1">
          Clear saved API key
        </label>
      <?php endif; ?>
      <p class="muted" style="margin:6px 0 0;font-size:12px">API key is <strong>not</strong> the proxy password. Desktop Launch Center needs gateway password or you get Socket closed.</p>
    </div>
    <div class="row" style="align-items:flex-start">
      <div style="flex:2">
        <label>Host</label>
        <input name="host" value="<?= e((string) ($row['host'] ?? ($id === 'anyip' ? 'portal-as.anyip.io' : ''))) ?>" required>
      </div>
      <div style="flex:1">
        <label>HTTP port</label>
        <input name="http_port" type="number" min="1" max="65535" value="<?= (int) ($row['http_port'] ?? ($id === 'anyip' ? 1080 : 8080)) ?>" required>
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