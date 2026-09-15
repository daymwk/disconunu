<?php
/** Dashboard admin : liste des articles + actions.
 * @var list<\DN\Model\Post> $posts
 * @var string $baseUrl
 * @var string $csrf
 */
use DN\Security;
?>
<h1>Administration</h1>

<div class="admin-actions">
  <a class="btn" href="<?= Security::e($baseUrl) ?>/admin/post/new">+ Nouvel article</a>
  <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/">Voir le site</a>
  <form method="post" action="<?= Security::e($baseUrl) ?>/admin/logout" style="margin-left:auto">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <button class="btn btn-ghost" type="submit">Déconnexion (<?= Security::e($user->username ?? '') ?>)</button>
  </form>
</div>

<table class="admin-table">
  <thead><tr><th>Titre</th><th>Statut</th><th>Auteur</th><th>Publié</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($posts as $p): ?>
    <tr>
      <td><a href="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/edit"><?= Security::e($p->title) ?></a></td>
      <td><span class="badge badge-<?= Security::e($p->status) ?>"><?= Security::e($p->status) ?></span></td>
      <td><?= Security::e($p->authorName ?? '?') ?></td>
      <td><?= $p->publishedAt ? Security::e(date('d/m/Y H:i', strtotime($p->publishedAt))) : '—' ?></td>
      <td>
        <?php if ($p->status === 'published'): ?>
          <a href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($p->slug) ?>">voir</a>
        <?php endif; ?>
      </td>
      <td>
        <form method="post" action="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$p->id ?>/delete"
              onsubmit="return confirm('Supprimer « <?= Security::e($p->title) ?> » ?')">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <button class="btn btn-danger" type="submit">supprimer</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$posts): ?>
    <tr><td colspan="6">Aucun article. <a href="<?= Security::e($baseUrl) ?>/admin/post/new">Crée le premier.</a></td></tr>
  <?php endif; ?>
  </tbody>
</table>
