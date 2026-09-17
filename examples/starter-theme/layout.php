<?php
/** Starter theme: escaped chrome around the CMS-rendered content. */
use DN\Security;
use DN\Model\Settings;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::e($title ?? $appName) ?> — <?= Security::e($appName) ?></title>
<link rel="stylesheet" href="<?= Security::e($fallbackThemeUrl) ?>/css/style.css">
<link rel="stylesheet" href="<?= Security::e($themeUrl) ?>/css/style.css">
<?php if ($rssEnabled): ?><link rel="alternate" type="application/rss+xml" title="<?= Security::e($appName) ?>" href="<?= Security::e($baseUrl) ?>/feed"><?php endif; ?>
</head>
<body class="starter-theme">
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="site-header">
  <div class="wrap header-grid">
    <a class="brand" href="<?= Security::e($baseUrl) ?>/">
      <?php if ($logoUrl): ?><img class="brand-logo" src="<?= Security::e($logoUrl) ?>" alt="" width="40" height="40"><?php endif; ?>
      <span class="brand-text"><strong><?= Security::e($appName) ?></strong><small><?= Security::e($subtitle) ?></small></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
      <?php foreach (($cats ?? []) as $cat): ?>
        <a href="<?= Security::e($baseUrl) ?>/category/<?= rawurlencode($cat->slug) ?>"><?= Security::e($cat->title) ?></a>
      <?php endforeach; ?>
      <a href="<?= Security::e($baseUrl) ?>/search">Search</a>
      <a href="<?= Security::e($baseUrl) ?>/admin"><?= $user ? 'Dashboard' : 'Sign in' ?></a>
    </nav>
  </div>
</header>
<main class="wrap" id="main-content">
  <?php foreach ($flashes as $kind => $messages): foreach ($messages as $message): ?>
    <div class="flash flash-<?= Security::e($kind) ?>" role="status"><?= Security::e($message) ?></div>
  <?php endforeach; endforeach; ?>
  <?= $content ?>
</main>
<footer class="site-footer"><div class="wrap">
  <span><?= Security::e(Settings::footerText()) ?></span>
  <?php if ($rssEnabled): ?><a href="<?= Security::e($baseUrl) ?>/feed">RSS</a><?php endif; ?>
</div></footer>
</body>
</html>
