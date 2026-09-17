<?php
/**
 * Shared admin navigation. Included by every admin screen so you can always
 * move between them (previously only the dashboard had it).
 *
 * @var string $baseUrl
 * @var string $csrf
 * @var \DN\Model\User $user
 * @var string|null $active  dashboard|post|categories|users|settings
 */
use DN\Acl;
use DN\Security;
$active = $active ?? 'dashboard';
$tab = static function (string $key, string $href, string $label, string $current): string {
    return '<a class="btn btn-ghost' . ($key === $current ? ' is-active' : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
        . htmlspecialchars($label, ENT_QUOTES) . '</a>';
};
$base = Security::e($baseUrl);
?>
<nav class="admin-nav">
  <?= $tab('dashboard', $baseUrl . '/admin', 'Posts', $active) ?>
  <?= $tab('post', $baseUrl . '/admin/post/new', '+ New post', $active) ?>
  <?php if (Acl::canManageCategories($user)): ?>
    <?= $tab('categories', $baseUrl . '/admin/categories', 'Categories', $active) ?>
  <?php endif; ?>
  <?php if (Acl::canAdminister($user)): ?>
    <?= $tab('users', $baseUrl . '/admin/users', 'Users', $active) ?>
    <?= $tab('settings', $baseUrl . '/admin/settings', 'Settings', $active) ?>
  <?php endif; ?>
  <a class="btn btn-ghost" href="<?= $base ?>/">View site</a>
  <form method="post" action="<?= $base ?>/admin/logout" style="margin-left:auto">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <button class="btn btn-ghost" type="submit">Sign out (<?= Security::e($user->username) ?> · <?= Security::e($user->role) ?>)</button>
  </form>
</nav>
