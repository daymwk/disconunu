<?php
/** Résultats de recherche.
 * @var string $q
 * @var list<\DN\Model\Post> $posts
 * @var string $baseUrl
 */
use DN\Security;
?>
<h1>Recherche</h1>
<form method="get" action="<?= Security::e($baseUrl) ?>/search" style="margin-bottom:1.5rem">
  <input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="mots-clés…" autofocus style="min-width:min(24rem,80vw)">
  <button class="btn" type="submit">Chercher</button>
</form>

<?php if ($q !== ''): ?>
  <p class="post-meta"><?= count($posts) ?> résultat<?= count($posts) === 1 ? '' : 's' ?> pour « <?= Security::e($q) ?> »</p>
<?php endif; ?>

<section class="post-list">
<?php foreach ($posts as $p): ?>
  <article class="post-card">
    <h2><a href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>"><?= Security::e($p->title) ?></a></h2>
    <p class="post-meta"><span>par <?= Security::e($p->authorName ?? '?') ?></span></p>
    <div class="post-excerpt"><?= Security::e($p->excerpt) ?></div>
  </article>
<?php endforeach; ?>
</section>
