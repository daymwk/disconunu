<?php
/** The bundled public layout and theme-independent administration shell. */
use DN\Security;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim(parse_url($baseUrl, PHP_URL_PATH) ?: '', '/');
$adminPath = substr($requestPath, strlen($basePath));
$isAdmin = $isAdmin ?? ($adminPath === '/admin' || str_starts_with($adminPath, '/admin/'));
$adminAssets = ($fallbackThemeUrl ?? $baseUrl . '/themes/teal');
$active = match (true) {
    str_starts_with($adminPath, '/admin/post/') => 'post',
    str_starts_with($adminPath, '/admin/categories') => 'categories',
    str_starts_with($adminPath, '/admin/users') => 'users',
    str_starts_with($adminPath, '/admin/logs') => 'logs',
    str_starts_with($adminPath, '/admin/settings'), str_starts_with($adminPath, '/admin/theme'), str_starts_with($adminPath, '/admin/logo') => 'settings',
    default => 'dashboard',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="generator" content="disconunu <?= \DN\App::VERSION ?>">
<title><?= Security::e($title ?? ($isAdmin ? 'Administration' : $appName)) ?> — <?= Security::e($appName) ?></title>
<link rel="stylesheet" href="<?= Security::e($isAdmin ? $adminAssets : $themeUrl) ?>/css/style.css">
<?php if ($isAdmin): ?>
<link rel="stylesheet" href="<?= Security::e($adminAssets) ?>/css/admin.css">
<script src="<?= Security::e($adminAssets) ?>/js/admin.js" defer></script>
<?php endif; ?>
<?php if ($rssEnabled): ?><link rel="alternate" type="application/rss+xml" title="<?= Security::e($appName) ?> — feed" href="<?= Security::e($baseUrl) ?>/feed"><?php endif; ?>
</head>
<body<?= $isAdmin ? ' class="admin-page"' : '' ?>>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if ($isAdmin): ?>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <a class="admin-brand" href="<?= Security::e($baseUrl) ?>/admin">
      <img src="<?= Security::e($adminAssets) ?>/images/logo.svg" alt="" width="44" height="44">
      <span><strong>disconunu</strong><small>Publishing workspace</small></span>
    </a>
    <?php if ($user): ?>
      <?php require __DIR__ . '/admin/nav.php'; ?>
    <?php else: ?>
      <p class="sidebar-note">A little space for big ideas.</p>
      <a class="sidebar-site" href="<?= Security::e($baseUrl) ?>/" target="_blank" rel="noopener">View site <span aria-hidden="true">↗</span></a>
    <?php endif; ?>
  </aside>
  <div class="admin-workspace">
    <header class="admin-topbar">
      <span class="workspace-name"><?= Security::e($appName) ?><small>Content management</small></span>
      <?php if ($user): ?><span class="account-chip"><span class="account-avatar" aria-hidden="true"><?= Security::e(strtoupper(substr($user->username, 0, 1))) ?></span><span><?= Security::e($user->username) ?><small><?= Security::e(ucfirst($user->role)) ?></small></span></span><?php endif; ?>
    </header>
    <main class="admin-main" id="main-content">
<?php else: ?>
<header class="site-header">
  <div class="wrap header-grid">
    <a class="brand" href="<?= Security::e($baseUrl) ?>/">
      <?php if ($logoUrl): ?><img class="brand-logo" src="<?= Security::e($logoUrl) ?>" alt="" width="40" height="40"><?php else: ?><span class="brand-mark">◍</span><?php endif; ?>
      <span class="brand-text"><strong><?= Security::e($appName) ?></strong><small><?= Security::e($subtitle) ?></small></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
      <?php foreach (($cats ?? []) as $cat): ?>
        <a href="<?= Security::e($baseUrl) ?>/category/<?= rawurlencode($cat->slug) ?>"><?= Security::e($cat->title) ?></a>
      <?php endforeach; ?>
      <a href="<?= Security::e($baseUrl) ?>/search">Search</a>
      <?php if ($user): ?><a class="btn-admin" href="<?= Security::e($baseUrl) ?>/admin"><?= Security::e($user->username) ?></a>
      <?php else: ?><a class="btn-admin" href="<?= Security::e($baseUrl) ?>/admin/login">Sign in</a><?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap" id="main-content">
<?php endif; ?>
<?php foreach ($flashes as $kind => $msgs): ?>
  <?php foreach ($msgs as $m): ?>
    <div class="flash flash-<?= Security::e($kind) ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>"><?= Security::e($m) ?></div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?= $content ?>
</main>
<?php if ($isAdmin): ?>
    <footer class="admin-footer"><span>disconunu <span aria-hidden="true">·</span> Made for publishing.</span><a href="<?= Security::e($baseUrl) ?>/" target="_blank" rel="noopener">View site ↗</a></footer>
  </div>
</div>
<?php else: ?>
<footer class="site-footer">
  <div class="wrap"><span><?= Security::e(\DN\Model\Settings::footerText()) ?></span><?php if ($rssEnabled): ?><span><a href="<?= Security::e($baseUrl) ?>/feed">RSS</a></span><?php endif; ?></div>
</footer>
<?php endif; ?>
</body>
</html>
