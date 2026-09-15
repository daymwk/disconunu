<?php
/**
 * Layout du thème "teal" — héritier de la DA 2017 (vert-bleu #29d9c2/#00a2a5),
 * reconstruite en CSS moderne : variables, dark mode auto, pas de framework.
 *
 * @var string $content  rendu par le template
 * @var string $appName
 * @var string $subtitle
 * @var string $baseUrl
 * @var string $themeUrl
 * @var \DN\Model\User|null $user
 * @var array<string,list<string>> $flashes
 * @var \DN\Model\Menu|null $menu (si fourni par la route)
 */
use DN\Security;
use DN\Http;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="generator" content="Disco nunu <?= \DN\App::VERSION ?>">
<title><?= Security::e($title ?? $appName) ?> — <?= Security::e($appName) ?></title>
<link rel="stylesheet" href="<?= Security::e($themeUrl) ?>/style.css">
<link rel="alternate" type="application/rss+xml" title="<?= Security::e($appName) ?> — flux" href="<?= Security::e($baseUrl) ?>/feed">
</head>
<body>
<header class="site-header">
  <div class="wrap header-grid">
    <a class="brand" href="<?= Security::e($baseUrl) ?>/">
      <span class="brand-mark">◍</span>
      <span class="brand-text">
        <strong><?= Security::e($appName) ?></strong>
        <small><?= Security::e($subtitle) ?></small>
      </span>
    </a>
    <nav class="site-nav">
      <?php if (!empty($menu)): ?>
        <?php foreach ($menu->items() as $item):
            $href = str_starts_with($item['target'], 'http')
                ? $item['target']
                : $baseUrl . '/' . ltrim($item['target'], '/'); ?>
          <a href="<?= Security::e($href) ?>"><?= Security::e($item['title']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <a href="<?= Security::e($baseUrl) ?>/search">Recherche</a>
      <?php if ($user): ?>
        <a class="btn-admin" href="<?= Security::e($baseUrl) ?>/admin"><?= Security::e($user->username) ?></a>
      <?php else: ?>
        <a class="btn-admin" href="<?= Security::e($baseUrl) ?>/admin/login">Connexion</a>
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
    <span><?= Security::e($appName) ?> — un CMS minimaliste, relancé en 2026.</span>
    <span><a href="<?= Security::e($baseUrl) ?>/feed">RSS</a></span>
  </div>
</footer>
</body>
</html>
