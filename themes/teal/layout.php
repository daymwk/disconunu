<?php
/** Theme "teal" layout.
 * @var string $content  rendered by the template
 * @var string $appName
 * @var string $subtitle
 * @var string $baseUrl
 * @var string $themeUrl
 * @var string|null $logoUrl
 * @var bool $rssEnabled
 * @var \DN\Model\User|null $user
 * @var array<string,list<string>> $flashes
 * @var list<\DN\Model\Category> $cats (when provided by the route)
 */
use DN\Security;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="generator" content="Disco nunu <?= \DN\App::VERSION ?>">
<title><?= Security::e($title ?? $appName) ?> — <?= Security::e($appName) ?></title>
<link rel="stylesheet" href="<?= Security::e($themeUrl) ?>/css/style.css">
<?php if ($rssEnabled): ?><link rel="alternate" type="application/rss+xml" title="<?= Security::e($appName) ?> — feed" href="<?= Security::e($baseUrl) ?>/feed"><?php endif; ?>
</head>
<body>
<header class="site-header">
  <div class="wrap header-grid">
    <a class="brand" href="<?= Security::e($baseUrl) ?>/">
      <?php if ($logoUrl): ?><img class="brand-logo" src="<?= Security::e($logoUrl) ?>" alt="" width="40" height="40"><?php else: ?><span class="brand-mark">◍</span><?php endif; ?>
      <span class="brand-text">
        <strong><?= Security::e($appName) ?></strong>
        <small><?= Security::e($subtitle) ?></small>
      </span>
    </a>
    <nav class="site-nav">
      <?php foreach (($cats ?? []) as $cat): ?>
        <a href="<?= Security::e($baseUrl) ?>/category/<?= rawurlencode($cat->slug) ?>"><?= Security::e($cat->title) ?></a>
      <?php endforeach; ?>
      <a href="<?= Security::e($baseUrl) ?>/search">Search</a>
      <?php if ($user): ?>
        <a class="btn-admin" href="<?= Security::e($baseUrl) ?>/admin"><?= Security::e($user->username) ?></a>
      <?php else: ?>
        <a class="btn-admin" href="<?= Security::e($baseUrl) ?>/admin/login">Sign in</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="wrap">
<?php foreach ($flashes as $kind => $msgs): ?>
  <?php foreach ($msgs as $m): ?>
    <div class="flash flash-<?= Security::e($kind) ?>"><?= Security::e($m) ?></div>
  <?php endforeach; ?>
<?php endforeach; ?>

<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <span><?= Security::e(\DN\Model\Settings::footerText()) ?></span>
    <?php if ($rssEnabled): ?><span><a href="<?= Security::e($baseUrl) ?>/feed">RSS</a></span><?php endif; ?>
  </div>
</footer>
</body>
</html>
