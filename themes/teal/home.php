<?php
/** Accueil : liste paginée des articles publiés.
 * @var list<\DN\Model\Post> $posts
 * @var int $page
 * @var int $pages
 * @var string $baseUrl
 * @var string $csrf
 */
use DN\Security;
?>
<section class="post-list">
<?php if (!$posts): ?>
  <div class="post-card">
    <h2>Aucun article pour l’instant</h2>
    <p class="post-excerpt">Le compteur est à zéro. <a href="<?= Security::e($baseUrl) ?>/admin/login">Connecte-toi</a> et écris le premier.</p>
  </div>
<?php endif; ?>

<?php foreach ($posts as $p): ?>
  <article class="post-card">
    <h2><a href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>"><?= Security::e($p->title) ?></a></h2>
    <p class="post-meta">
      <span>par <?= Security::e($p->authorName ?? '?') ?></span>
      <?php if ($p->publishedAt): ?><time datetime="<?= Security::e($p->publishedAt) ?>Z"><?= Security::e(date('d/m/Y', strtotime($p->publishedAt))) ?></time><?php endif; ?>
    </p>
    <div class="post-excerpt"><?= Security::e($p->excerpt) ?></div>
    <a class="more-link" href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>">Lire la suite →</a>
  </article>
<?php endforeach; ?>
</section>

<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="Pagination">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?>
      <li class="current"><?= $i ?></li>
    <?php else: ?>
      <li><a href="<?= Security::e($baseUrl) ?>/?page=<?= $i ?>"><?= $i ?></a></li>
    <?php endif; ?>
  <?php endfor; ?>
</nav>
<?php endif; ?>
