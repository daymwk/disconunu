<?php
/** Paginated category management; filters and totals are supplied by SQL pagination. */
use DN\Security;
use DN\View;
?>
<header class="page-heading"><div><p class="eyebrow">Content organization</p><h1>Categories <span class="count-pill"><?= (int)$total ?></span></h1><p class="page-description">Organize posts and choose which categories appear in the public menu.</p></div><a class="btn" href="#add-category">Add category</a></header>
<form class="filter-bar" method="get" action="<?= Security::e($baseUrl) ?>/admin/categories">
  <label class="search-field">Search categories<input type="search" name="q" value="<?= Security::e($filters['q'] ?? '') ?>" placeholder="Title or slug"></label>
  <label>Visibility<select name="visibility"><?php foreach (['' => 'All visibility', 'visible' => 'Visible', 'hidden' => 'Hidden'] as $value => $label): ?><option value="<?= $value ?>" <?= ($filters['visibility'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label>Per page<input type="number" name="per_page" min="1" max="100" value="<?= (int)$perPage ?>"></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/categories">Reset</a></div>
</form>
<div class="section-grid">
  <div>
    <section class="panel table-panel" aria-label="Categories">
      <div class="panel-heading"><h2>Manage categories</h2><p>General is the fallback category and cannot be deleted.</p></div>
      <table class="admin-table"><thead><tr><th scope="col">Category</th><th scope="col">Public path</th><th scope="col">Actions</th></tr></thead><tbody>
      <?php foreach ($cats as $c): ?><tr>
        <td data-label="Category">
          <form method="post" action="<?= Security::e($baseUrl) ?>/admin/categories/save" class="stack">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$c->id ?>">
            <label>Title<input type="text" name="title" value="<?= Security::e($c->title) ?>" maxlength="64" required></label>
            <label class="cat-check"><input type="checkbox" name="visible" value="1" <?= $c->visible ? 'checked' : '' ?>>Visible in the menu</label>
            <button class="btn btn-ghost btn-small" type="submit">Save category</button>
          </form>
        </td>
        <td data-label="Public path"><code>/category/<?= Security::e($c->slug) ?></code><span class="row-subtitle"><?= $c->visible ? 'Visible' : 'Hidden' ?></span></td>
        <td data-label="Actions"><?php if ($c->slug !== 'general'): ?>
          <form method="post" action="<?= Security::e($baseUrl) ?>/admin/categories/<?= (int)$c->id ?>/delete" data-confirm="<?= Security::e('Delete category “' . $c->title . '”? Posts are kept; those without another category move to General.') ?>">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button>
          </form>
        <?php else: ?><span class="muted">Protected fallback</span><?php endif; ?></td>
      </tr><?php endforeach; ?>
      </tbody></table>
      <?php if (!$cats): ?><div class="empty-state"><strong>No categories found</strong>Try a different search or reset the filters.</div><?php endif; ?>
    </section>
    <?php View::partial('admin/pagination', compact('page', 'pages', 'total', 'perPage', 'filters', 'paginationUrl')); ?>
  </div>
  <section class="panel stack" id="add-category"><div><h2>Add category</h2><p class="muted">New categories are available when editing posts.</p></div>
    <form class="stack" method="post" action="<?= Security::e($baseUrl) ?>/admin/categories/save">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <label>Title<input type="text" name="title" maxlength="64" required placeholder="e.g. Recipes"></label>
      <label>Slug (optional)<input type="text" name="slug" maxlength="64" placeholder="recipes"><small>Leave empty to generate it from the title.</small></label>
      <label class="cat-check"><input type="checkbox" name="visible" value="1" checked>Visible in the menu</label>
      <button class="btn" type="submit">Add category</button>
    </form>
  </section>
</div>
