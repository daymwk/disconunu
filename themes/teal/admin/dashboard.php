<?php
/** Admin dashboard: post list + nav hub.
 * @var list<\DN\Model\Post> $posts
 * @var string $baseUrl
 * @var string $csrf
 * @var \DN\Model\User $user
 */
use DN\Acl;
use DN\Security;
use DN\View;
?>
<h1>Admin</h1>

<nav class="admin-nav">
  <a class="btn" href="<?= Security::e($baseUrl) ?>/admin/post/new">+ New post</a>
  <?php if (Acl::canManageCategories($user)): ?>
    <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/categories">Categories</a>
  <?php endif; ?>
  <?php if (Acl::canAdminister($user)): ?>
    <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/users">Users</a>
    <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/settings">Settings</a>
  <?php endif; ?>
  <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/">View site</a>
  <form method="post" action="<?= Security::e($baseUrl) ?>/admin/logout" style="margin-left:auto">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <button class="btn btn-ghost" type="submit">Sign out (<?= Security::e($user->username) ?> · <?= Security::e($user->role) ?>)</button>
  </form>
</nav>

<table class="admin-table">
  <thead><tr><th>Title</th><th>Status</th><th>Author</th><th>Published</th><th></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($posts as $p):
      $canEdit = Acl::canEditPost($user, $p->userId);
      $canDelete = Acl::canDeletePost($user, $p->authorRole, $p->userId); ?>
    <tr>
      <td><a href="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/edit"><?= Security::e($p->title) ?></a></td>
      <td><span class="badge badge-<?= Security::e($p->status) ?>"><?= Security::e($p->status) ?></span></td>
      <td><?= Security::e($p->authorName ?? '?') ?></td>
      <td><?= $p->publishedAt ? Security::e(date('d M Y H:i', strtotime($p->publishedAt))) : '—' ?></td>
      <td><?php if ($p->status === 'published'): ?><a href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>">view</a><?php endif; ?></td>
      <td class="admin-row-actions">
        <?php if ($canDelete): ?>
        <form method="post" action="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/delete"
              onsubmit="return confirm('Delete “<?= Security::e($p->title) ?>”?')">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <button class="btn btn-danger" type="submit">delete</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$posts): ?>
    <tr><td colspan="6">No posts yet. <a href="<?= Security::e($baseUrl) ?>/admin/post/new">Create the first one.</a></td></tr>
  <?php endif; ?>
  </tbody>
</table>
