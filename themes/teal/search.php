<?php
/** Search results.
 * @var string $q
 * @var list<\DN\Model\Post> $posts
 * @var string $baseUrl
 */
use DN\Security;
?>
<h1>Search</h1>

<form class="search-form" method="get" action="<?= Security::e($baseUrl) ?>/search">
  <input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="keywords…" autofocus style="min-width:min(24rem,80vw)">
  <button class="btn" type="submit">Search</button>
</form>

<?php if ($q !== ''): ?>
  <p class="post-meta"><?= count($posts) ?> result<?= count($posts) === 1 ? '' : 's' ?> for “<?= Security::e($q) ?>”</p>
<?php endif; ?>

<section class="post-list">
<?php if ($q !== '' && !$posts): ?>
  <div class="post-card"><p class="post-excerpt">Nothing matched. Try fewer or broader words.</p></div>
<?php endif; ?>

<?php foreach ($posts as $p): ?>
  <article class="post-card">
    <h2><a href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>"><?= Security::e($p->title) ?></a></h2>
    <div class="post-excerpt"><?= Security::e($p->excerpt) ?></div>
    <a class="more-link" href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>">Read more →</a>
  </article>
<?php endforeach; ?>
</section>
