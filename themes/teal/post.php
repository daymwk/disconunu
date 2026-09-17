<?php
/** Single post. Content was sanitized on write (Security::sanitizeHtml).
 * @var \DN\Model\Post $post
 * @var string $baseUrl
 */
use DN\Model\Category;
use DN\Security;
$cats = Category::forPost($post->id);
?>
<article class="post">
  <h1><?= Security::e($post->title) ?></h1>
  <p class="post-meta">
    <span>by <?= Security::e($post->authorName ?? '?') ?></span>
    <?php if ($post->publishedAt): ?>
      <time datetime="<?= Security::e($post->publishedAt) ?>Z"><?= Security::e(date('d M Y', strtotime($post->publishedAt))) ?></time>
    <?php endif; ?>
    <?php foreach ($cats as $c): ?>
      <a class="badge badge-cat" href="<?= Security::e($baseUrl) ?>/category/<?= rawurlencode($c->slug) ?>"><?= Security::e($c->title) ?></a>
    <?php endforeach; ?>
  </p>
  <div class="post-body"><?= $post->content /* sanitized on write */ ?></div>
  <p><a class="more-link" href="<?= Security::e($baseUrl) ?>/">← All posts</a></p>
</article>
