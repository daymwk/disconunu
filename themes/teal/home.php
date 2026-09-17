<?php
/** Home: paginated list of published posts.
 * @var list<\DN\Model\Post> $posts
 * @var int $page
 * @var int $pages
 * @var string $baseUrl
 */
use DN\Security;
use DN\View;
?>
<section class="post-list">
<?php if (!$posts): ?>
  <div class="post-card">
    <h2>No posts yet</h2>
    <p class="post-excerpt">The counter reads zero. <a href="<?= Security::e($baseUrl) ?>/admin/login">Sign in</a> and write the first one.</p>
  </div>
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

<?= View::pagination($page, $pages, Security::e($baseUrl) . '/?page={page}') ?>
