<?php
/** User management (superadmin): list, create, assign roles, delete.
 * @var list<\DN\Model\User> $users
 * @var int $superadminCount
 * @var string $baseUrl
 * @var string $csrf
 * @var \DN\Model\User $user
 */
use DN\Acl;
use DN\Security;
use DN\View;
?>
<h1>Users</h1>

<?php View::partial('admin/nav', ['active' => 'users']); ?>

<p class="post-excerpt">
  <strong>superadmin</strong> — everything, incl. settings, themes and user roles ·
  <strong>admin</strong> — write, publish, manage categories and posts ·
  <strong>writer</strong> — write drafts (edits and deletes own posts only).
</p>

<table class="admin-table">
  <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Since</th><th>Role change</th><th></th></tr></thead>
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
      <td class="admin-row-actions">
        <?php if (Acl::canDeleteUser($user, $u, $superadminCount)): ?>
          <form method="post" action="<?= Security::e($baseUrl) ?>/admin/users/<?= (int)$u->id ?>/delete"
                onsubmit="return confirm('Delete account “<?= Security::e($u->username) ?>”?')">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
            <button class="btn btn-danger" type="submit">delete</button>
          </form>
        <?php else: ?>
          <span class="post-meta" title="You cannot delete yourself, and the last superadmin cannot be removed.">—</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Add a user</h2>
<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/users/create">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Username
    <input type="text" name="username" required maxlength="64" pattern="[A-Za-z0-9_.\-]{3,64}"
           title="3-64 characters: letters, digits, dot, underscore, dash">
  </label>
  <label>Email
    <input type="email" name="email" required maxlength="255">
  </label>
  <label>Password (10 characters minimum)
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
  </label>
  <label>Role
    <select name="role">
      <option value="<?= Acl::WRITER ?>" selected><?= Acl::WRITER ?> — drafts only</option>
      <option value="<?= Acl::ADMIN ?>"><?= Acl::ADMIN ?> — write and publish</option>
      <option value="<?= Acl::SUPERADMIN ?>"><?= Acl::SUPERADMIN ?> — full control</option>
    </select>
  </label>
  <button class="btn" type="submit">Create user</button>
</form>
