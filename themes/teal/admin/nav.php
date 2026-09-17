<?php
/** Navigation is rendered once by the bundled admin shell, on every screen. */
use DN\Acl;
use DN\Security;
$active = $active ?? 'dashboard';
$links = [
    ['dashboard', '/admin', 'Posts', '▤'],
    ['post', '/admin/post/new', 'New post', '+'],
];
if (Acl::canManageCategories($user)) {
    $links[] = ['categories', '/admin/categories', 'Categories', '▦'];
}
if (Acl::canAdminister($user)) {
    $links[] = ['users', '/admin/users', 'Users', '♙'];
    $links[] = ['settings', '/admin/settings', 'Settings', '⚙'];
}
?>
<p class="nav-heading">Workspace</p>
<nav class="admin-nav" aria-label="Administration">
  <?php foreach ($links as [$key, $path, $label, $icon]): ?>
    <a href="<?= Security::e($baseUrl . $path) ?>"<?= $active === $key ? ' class="is-active" aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= $icon ?></span><?= $label ?></a>
  <?php endforeach; ?>
</nav>
<div class="sidebar-bottom">
  <a class="sidebar-site" href="<?= Security::e($baseUrl) ?>/" target="_blank" rel="noopener">View site <span aria-hidden="true">↗</span></a>
  <form method="post" action="<?= Security::e($baseUrl) ?>/admin/logout">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <button class="signout" type="submit">Sign out</button>
  </form>
  <p class="sidebar-note">Your words. Your website.</p>
</div>
