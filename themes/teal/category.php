<?php
/** Category listing: posts in one category, paginated.
 * @var \DN\Model\Category $cat
 * @var list<\DN\Model\Post> $posts
 * @var int $page
 * @var int $pages
 * @var string $baseUrl
 */
use DN\Security;
use DN\View;
?>
<h1><?= Security::e($cat->title) ?></h1>

<section class="post-list">
<?php if (!$posts): ?>
  <div class="post-card"><p class="post-excerpt">No published posts in this category yet.</p></div>
<?php endif; ?>

<?php foreach ($posts as $p): ?>
  <article class="post-card">
    <h2><a href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>"><?= Security::e($p->title) ?></a></h2>
    <p class="post-meta">
      <span>by <?= Security::e($p->authorName ?? '?') ?></span>
      <?php if ($p->publishedAt): ?><time datetime="<?= Security::e($p->publishedAt) ?>Z"><?= Security::e(date('d M Y', strtotime($p->publishedAt))) ?></time><?php endif; ?>
    </p>
    <div class="post-excerpt"><?= Security::e($p->excerpt) ?></div>
    <a class="more-link" href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>">Read more →</a>
  </article>
<?php endforeach; ?>
</section>

<?= View::pagination($page, $pages, Security::e($baseUrl) . '/category/' . rawurlencode($cat->slug) . '?page={page}') ?>
