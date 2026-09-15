<?php
/** Éditeur d'article (création/édition). Minimaliste : titre + contenu HTML, préversion via /preview.
 * @var \DN\Model\Post|null $post
 * @var string $baseUrl
 * @var string $csrf
 */
use DN\Security;
$isEdit = $post !== null;
?>
<h1><?= $isEdit ? 'Éditer l’article' : 'Nouvel article' ?></h1>

<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/post/save">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$post->id ?>"><?php endif; ?>

  <label>Titre
    <input type="text" name="title" value="<?= Security::e($post->title ?? '') ?>" required maxlength="255" autofocus>
  </label>

  <label>Statut
    <select name="status">
      <option value="draft"     <?= (!$isEdit || $post->status === 'draft') ? 'selected' : '' ?>>Brouillon</option>
      <option value="published" <?= ($isEdit && $post->status === 'published') ? 'selected' : '' ?>>Publié</option>
    </select>
  </label>

  <label>Contenu (HTML simple — les balises hors allowlist sont retirées à l’enregistrement)
    <textarea name="content" required><?= Security::e($post->content ?? "<p></p>") ?></textarea>
  </label>

  <div class="admin-actions">
    <button class="btn" type="submit"><?= $isEdit ? 'Enregistrer' : 'Créer' ?></button>
    <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin">Annuler</a>
    <?php if ($isEdit && $post->status === 'published'): ?>
      <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($post->slug) ?>">Préversion</a>
    <?php endif; ?>
  </div>
</form>
