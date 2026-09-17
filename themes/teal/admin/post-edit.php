<?php
/** Post editor (create/edit). Categories checkbox when the user may manage them.
 * @var \DN\Model\Post|null $post
 * @var list<\DN\Model\Category> $cats
 * @var list<int> $myCats
 * @var string $baseUrl
 * @var string $csrf
 * @var \DN\Model\User $user
 */
use DN\Acl;
use DN\Security;
use DN\View;
$isEdit = $post !== null;
$canPublish = Acl::canPublish($user);
$myCats = $myCats ?? [];
?>
<h1><?= $isEdit ? 'Edit post' : 'New post' ?></h1>

<?php View::partial('admin/nav', ['active' => 'post']); ?>

<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/post/save">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$post->id ?>"><?php endif; ?>

  <label>Title
    <input type="text" name="title" value="<?= Security::e($post->title ?? '') ?>" required maxlength="255" autofocus>
  </label>

  <label>Status
    <select name="status" <?= $canPublish ? '' : 'disabled' ?>>
      <option value="draft" <?= (!$isEdit || $post->status === 'draft') ? 'selected' : '' ?>>Draft</option>
      <option value="published" <?= ($isEdit && $post->status === 'published') ? 'selected' : '' ?>>Published</option>
    </select>
    <?php if (!$canPublish): ?><small>Writers save as draft; an admin must publish.</small>
      <input type="hidden" name="status" value="draft"><?php endif; ?>
  </label>

  <?php if ($cats): ?>
  <fieldset class="cat-picker">
    <legend>Categories</legend>
    <?php foreach ($cats as $c): ?>
      <label class="cat-check">
        <input type="checkbox" name="categories[]" value="<?= (int)$c->id ?>"
               <?= in_array($c->id, $myCats, true) ? 'checked' : '' ?>>
        <?= Security::e($c->title) ?><?= $c->visible ? '' : ' (hidden)' ?>
      </label>
    <?php endforeach; ?>
  </fieldset>
  <?php endif; ?>

  <label>Content (simple HTML — tags outside the allowlist are stripped on save)
    <textarea name="content" required><?= Security::e($post->content ?? '<p></p>') ?></textarea>
  </label>

  <div class="admin-actions">
    <button class="btn" type="submit"><?= $isEdit ? 'Save' : 'Create' ?></button>
    <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin">Cancel</a>
    <?php if ($isEdit && $post->status === 'published'): ?>
      <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/post/<?= rawurlencode($post->slug) ?>">Preview</a>
    <?php endif; ?>
  </div>
</form>
