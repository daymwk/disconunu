<?php
/** Paginated user administration; authorization remains enforced by routes and ACL. */
use DN\Acl;
use DN\Security;
use DN\View;
?>
<header class="page-heading"><div><p class="eyebrow">Access management</p><h1>Users <span class="count-pill"><?= (int)$total ?></span></h1><p class="page-description">Give each person the access they need to contribute.</p></div><a class="btn" href="#add-user">Add user</a></header>
<div class="role-guide">
  <section class="panel"><h2>Writer</h2><p>Creates drafts. Edits and deletes only their own drafts; an admin publishes them.</p></section>
  <section class="panel"><h2>Admin</h2><p>Writes and publishes posts, manages categories and edits posts by other authors.</p></section>
  <section class="panel"><h2>Superadmin</h2><p>Full access, including settings, trusted themes, user accounts and roles.</p></section>
</div>
<form class="filter-bar" method="get" action="<?= Security::e($baseUrl) ?>/admin/users">
  <label class="search-field">Search users<input type="search" name="q" value="<?= Security::e($filters['q'] ?? '') ?>" placeholder="Username or email"></label>
  <label>Role<select name="role"><?php foreach (['' => 'All roles', Acl::WRITER => 'Writer', Acl::ADMIN => 'Admin', Acl::SUPERADMIN => 'Superadmin'] as $value => $label): ?><option value="<?= $value ?>" <?= ($filters['role'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label>Per page<input type="number" name="per_page" min="1" max="100" value="<?= (int)$perPage ?>"></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/users">Reset</a></div>
</form>
<div class="section-grid">
  <div>
    <section class="panel table-panel" aria-label="Users"><div class="panel-heading"><h2>Manage access</h2><p>You cannot remove yourself or the last superadmin.</p></div>
      <table class="admin-table"><thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Actions</th></tr></thead><tbody>
      <?php foreach ($users as $u): ?><tr>
        <td data-label="User"><strong><?= Security::e($u->username) ?></strong><?= $u->id === $user->id ? ' (you)' : '' ?><span class="row-subtitle"><?= Security::e($u->email) ?></span><span class="row-subtitle">Joined <?= Security::e(date('d M Y', strtotime($u->createdAt))) ?></span></td>
        <td data-label="Role"><span class="badge badge-role"><?= Security::e($u->role) ?></span>
          <?php if ($u->id !== $user->id): ?>
          <form method="post" action="<?= Security::e($baseUrl) ?>/admin/users/<?= (int)$u->id ?>/role" class="stack" data-confirm="<?= Security::e('Change the role for “' . $u->username . '”? This changes their access to the site.') ?>">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
            <label>New role<select name="role"><?php foreach ([Acl::WRITER, Acl::ADMIN, Acl::SUPERADMIN] as $r): ?><option value="<?= $r ?>" <?= $u->role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-ghost btn-small" type="submit">Set role</button>
          </form>
          <?php else: ?><span class="row-subtitle">Your role is protected.</span><?php endif; ?>
        </td>
        <td data-label="Actions"><?php if (Acl::canDeleteUser($user, $u, $superadminCount)): ?>
          <form method="post" action="<?= Security::e($baseUrl) ?>/admin/users/<?= (int)$u->id ?>/delete" data-confirm="<?= Security::e('Delete account “' . $u->username . '”? This cannot be undone.') ?>"><input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form>
        <?php else: ?><span class="muted">Protected account</span><?php endif; ?></td>
      </tr><?php endforeach; ?>
      </tbody></table>
      <?php if (!$users): ?><div class="empty-state"><strong>No users found</strong>Try a different search or reset the filters.</div><?php endif; ?>
    </section>
    <?php View::partial('admin/pagination', compact('page', 'pages', 'total', 'perPage', 'filters', 'paginationUrl')); ?>
  </div>
  <section class="panel stack" id="add-user"><div><h2>Add a user</h2><p class="muted">Start with Writer unless broader access is needed.</p></div>
    <form class="stack" method="post" action="<?= Security::e($baseUrl) ?>/admin/users/create">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <label>Username<input type="text" name="username" required maxlength="64" pattern="[A-Za-z0-9_.\-]{3,64}" autocomplete="off" title="3–64 characters: letters, digits, dot, underscore or dash"></label>
      <label>Email<input type="email" name="email" required maxlength="255" autocomplete="off"></label>
      <label>Password<input type="password" name="password" required minlength="10" autocomplete="new-password"><small>At least 10 characters. Share it securely with the new user.</small></label>
      <label>Role<select name="role"><option value="<?= Acl::WRITER ?>" selected>Writer — drafts only</option><option value="<?= Acl::ADMIN ?>">Admin — write and publish</option><option value="<?= Acl::SUPERADMIN ?>">Superadmin — full control</option></select></label>
      <button class="btn" type="submit">Create user</button>
    </form>
  </section>
</div>
