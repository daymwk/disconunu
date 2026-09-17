<?php
/** User management (superadmin): list + role assignment.
 * @var list<\DN\Model\User> $users
 * @var string $baseUrl
 * @var string $csrf
 * @var \DN\Model\User $user
 */
use DN\Acl;
use DN\Security;
?>
<h1>Users</h1>

<p class="post-excerpt">
  <strong>superadmin</strong> — everything, incl. settings, themes and user roles ·
  <strong>admin</strong> — write, publish, manage categories and posts ·
  <strong>writer</strong> — write drafts (edits/deletes own posts only).
</p>

<table class="admin-table">
  <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Since</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= Security::e($u->username) ?></strong><?= $u->id === $user->id ? ' <em>(you)</em>' : '' ?></td>
      <td><?= Security::e($u->email) ?></td>
      <td><span class="badge badge-role"><?= Security::e($u->role) ?></span></td>
      <td><?= Security::e(date('d M Y', strtotime($u->createdAt))) ?></td>
      <td>
        <?php if ($u->id === $user->id): ?>
          <span class="post-meta">—</span>
        <?php else: ?>
        <form method="post" action="<?= Security::e($baseUrl) ?>/admin/users/<?= (int)$u->id ?>/role" class="inline-form">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <select name="role">
            <?php foreach ([Acl::WRITER, Acl::ADMIN, Acl::SUPERADMIN] as $r): ?>
              <option value="<?= $r ?>" <?= $u->role === $r ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-ghost" type="submit">set role</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
