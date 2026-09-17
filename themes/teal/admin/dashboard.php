<?php
/** Paginated post workspace. All links and actions follow the post-level ACL. */
use DN\Acl;
use DN\Security;
use DN\View;
$filters = $filters ?? [];
$total = $total ?? count($posts);
$page = $page ?? 1;
$pages = $pages ?? 1;
$perPage = $perPage ?? 20;
?>
<div class="page-heading">
  <div><p class="eyebrow">Your publishing desk</p><h1>Posts <span class="count-pill"><?= (int)$total ?></span></h1><p class="page-description">Bring your ideas to life. Write, organize, and publish in one place.</p></div>
  <a class="btn" href="<?= Security::e($baseUrl) ?>/admin/post/new"><span aria-hidden="true">+</span> New post</a>
</div>
<?php if ($user->role === Acl::WRITER): ?><p class="notice">You can edit and delete your own drafts. An admin publishes them; published posts are view-only.</p><?php endif; ?>
<form class="filter-bar" method="get" action="<?= Security::e($baseUrl) ?>/admin" role="search" aria-label="Filter posts">
  <label class="search-field" for="post-search">Search posts<input id="post-search" type="search" name="q" value="<?= Security::e($filters['q'] ?? '') ?>" placeholder="Search titles and content"></label>
  <label for="post-status">Status<select id="post-status" name="status"><option value="">All statuses</option><?php foreach (['draft' => 'Draft', 'published' => 'Published'] as $value => $label): ?><option value="<?= $value ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label for="post-category">Category<select id="post-category" name="category"><option value="">All categories</option><?php foreach (($categories ?? []) as $c): ?><option value="<?= (int)$c->id ?>" <?= (string)($filters['category'] ?? '') === (string)$c->id ? 'selected' : '' ?>><?= Security::e($c->title) ?></option><?php endforeach; ?></select></label>
  <label for="post-author">Author<select id="post-author" name="author"><option value="">All authors</option><?php foreach (($authors ?? []) as $a): ?><option value="<?= (int)$a->id ?>" <?= (string)($filters['author'] ?? '') === (string)$a->id ? 'selected' : '' ?>><?= Security::e($a->username) ?></option><?php endforeach; ?></select></label>
  <label for="post-sort">Sort by<select id="post-sort" name="sort"><?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'Title A–Z', 'updated' => 'Recently updated'] as $value => $label): ?><option value="<?= $value ?>" <?= ($filters['sort'] ?? 'newest') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label for="per-page">Per page<input id="per-page" type="number" name="per_page" min="1" max="100" value="<?= (int)$perPage ?>"></label>
  <div class="filter-actions"><button class="btn btn-small" type="submit">Apply filters</button><a class="btn btn-ghost btn-small" href="<?= Security::e($baseUrl) ?>/admin">Reset</a></div>
</form>
<section class="panel table-panel" aria-label="Posts">
  <div class="panel-heading"><h2>All posts</h2><p><?= (int)$total ?> <?= $total === 1 ? 'result' : 'results' ?> · Page <?= (int)$page ?> of <?= (int)$pages ?></p></div>
  <?php if ($posts): ?>
  <table class="admin-table">
    <thead><tr><th scope="col">Post</th><th scope="col">Status</th><th scope="col">Author</th><th scope="col">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($posts as $p):
        $canEdit = Acl::canEditPost($user, $p->userId, $p->authorRole ?? '', $p->status);
        $canDelete = Acl::canDeletePost($user, $p->authorRole, $p->userId, $p->status);
        $viewUrl = $p->status === 'published' ? '/post/' . rawurlencode($p->slug) : '/admin/post/' . (int)$p->id . '/preview'; ?>
      <tr>
        <td data-label="Post">
          <?php if ($canEdit): ?><a class="post-title" href="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/edit"><?= Security::e($p->title) ?></a>
          <?php else: ?><a class="post-title" href="<?= Security::e($baseUrl . $viewUrl) ?>" target="_blank" rel="noopener"><?= Security::e($p->title) ?></a><?php endif; ?>
          <span class="row-subtitle"><?= $p->publishedAt ? 'Published ' . Security::e(date('M j, Y', strtotime($p->publishedAt))) : 'Not published yet' ?></span>
        </td>
        <td data-label="Status"><span class="badge badge-<?= Security::e($p->status) ?>"><?= Security::e(ucfirst($p->status)) ?></span></td>
        <td data-label="Author"><?= Security::e($p->authorName ?? 'Unknown author') ?><?= $p->userId === $user->id ? '<span class="row-subtitle">You</span>' : '' ?></td>
        <td data-label="Actions"><div class="row-actions">
          <?php if ($canEdit): ?><a class="btn btn-ghost btn-small" href="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/edit">Edit</a><?php endif; ?>
          <a class="btn btn-ghost btn-small" href="<?= Security::e($baseUrl . $viewUrl) ?>" target="_blank" rel="noopener"><?= $p->status === 'published' ? 'View' : 'Preview' ?> <span aria-hidden="true">↗</span></a>
          <?php if ($canDelete): ?><form method="post" action="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/delete" data-confirm="<?= Security::e('Delete “' . $p->title . '”? This cannot be undone.') ?>"><input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>"><button class="btn btn-danger btn-small" type="submit" aria-label="<?= Security::e('Delete ' . $p->title) ?>">Delete</button></form><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><div class="empty-state"><strong>No posts found</strong><p>Try different filters, or start with a fresh idea.</p><a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/post/new">Write a post</a></div><?php endif; ?>
</section>
<?php require __DIR__ . '/pagination.php'; ?>
