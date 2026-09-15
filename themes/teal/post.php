<?php
/** Article complet. Le contenu a été sanitizé à l'écriture (Security::sanitizeHtml).
 * @var \DN\Model\Post $post
 * @var string $baseUrl
 */
use DN\Security;
?>
<article class="post-full">
  <h1><?= Security::e($post->title) ?></h1>
  <p class="post-meta">
    <span>par <?= Security::e($post->authorName ?? '?') ?></span>
    <?php if ($post->publishedAt): ?>
      <time datetime="<?= Security::e($post->publishedAt) ?>Z"><?= Security::e(date('d/m/Y', strtotime($post->publishedAt))) ?></time>
    <?php endif; ?>
    <?php if ($post->updatedAt): ?><span>maj <?= Security::e(date('d/m/Y', strtotime($post->updatedAt))) ?></span><?php endif; ?>
  </p>
  <div class="post-body"><?= $post->content /* sanitizé à l'écriture */ ?></div>
  <p><a class="more-link" href="<?= Security::e($baseUrl) ?>/">← Tous les articles</a></p>
</article>
