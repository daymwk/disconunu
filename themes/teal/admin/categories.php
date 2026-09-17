<?php
/** Category admin: list + inline create/rename/toggle/delete.
 * @var list<\DN\Model\Category> $cats
 * @var string $baseUrl
 * @var string $csrf
 */
use DN\Security;
?>
<h1>Categories</h1>

<table class="admin-table">
  <thead><tr><th>Title</th><th>Slug</th><th>Visible</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cats as $c): ?>
    <tr>
      <td>
        <form method="post" action="<?= Security::e($baseUrl) ?>/admin/categories/save" class="inline-form">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$c->id ?>">
          <input type="text" name="title" value="<?= Security::e($c->title) ?>" maxlength="64" required>
          <label class="cat-check"><input type="checkbox" name="visible" <?= $c->visible ? 'checked' : '' ?>> visible</label>
          <button class="btn" type="submit">save</button>
        </form>
      </td>
      <td><code>/category/<?= Security::e($c->slug) ?></code></td>
      <td><?= $c->visible ? 'yes' : 'no' ?></td>
      <td class="admin-row-actions">
        <form method="post" action="<?= Security::e($baseUrl) ?>/admin/categories/<?= (int)$c->id ?>/delete"
              onsubmit="return confirm('Delete category “<?= Security::e($c->title) ?>”? Posts will stay, uncategorized.')">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <button class="btn btn-danger" type="submit">delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Add category</h2>
<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/categories/save">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Title
    <input type="text" name="title" maxlength="64" required placeholder="e.g. Recipes">
  </label>
  <label>Slug (optional — auto-generated from the title if empty)
    <input type="text" name="slug" maxlength="64" placeholder="recipes">
  </label>
  <label class="cat-check"><input type="checkbox" name="visible" checked> visible in the menu</label>
  <button class="btn" type="submit">Add category</button>
</form>
