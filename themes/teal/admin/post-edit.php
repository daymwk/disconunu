<?php
/** @var \DN\Model\Post|null $post */
use DN\Acl;
use DN\Security;
$isEdit = $post !== null && $post->id > 0;
$canPublish = Acl::canPublish($user);
$myCats = $selectedCatIds ?? $myCats ?? [];
if ($isEdit && !Acl::canEditPost($user, $post->userId, $post->authorRole ?? '', $post->status)):
?>
<div class="notice notice-warning">This post cannot be edited by your account. Writers can edit only their own drafts.</div>
<a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin">Return to posts</a>
<?php return; endif; ?>
<header class="page-heading">
  <div><p class="eyebrow">Writing workspace</p><h1><?= $isEdit ? 'Edit post' : 'New post' ?></h1>
  <p class="page-description">Write in simple HTML. Save your changes before previewing.</p></div>
  <?php if ($isEdit): ?><a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/post/<?= (int)$post->id ?>/preview" target="_blank" rel="noopener noreferrer">Preview</a><?php endif; ?>
</header>
<form class="post-form editor-form" data-editor method="post" action="<?= Security::e($baseUrl) ?>/admin/post/save">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$post->id ?>"><?php endif; ?>
  <div class="editor-grid">
    <section class="panel stack" aria-label="Post content">
      <label>Title<input class="editor-title" type="text" name="title" value="<?= Security::e($post->title ?? '') ?>" required maxlength="255" autofocus></label>
      <div>
        <label>Content (HTML)<textarea class="editor-content" name="content" required aria-describedby="html-help"><?= Security::e($post->content ?? '<p></p>') ?></textarea></label>
        <div class="editor-toolbar"><output id="word-count" aria-live="polite">Word count loads with JavaScript</output><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>S</kbd> to save</span></div>
      </div>
      <details class="html-help" id="html-help"><summary>HTML writing guide</summary>
        <p>Use <code>&lt;p&gt;</code> for paragraphs, <code>&lt;h2&gt;</code> for headings, <code>&lt;strong&gt;</code> for emphasis and <code>&lt;ul&gt;&lt;li&gt;</code> for lists.</p>
        <p>Links: <code>&lt;a href="https://example.com"&gt;Link text&lt;/a&gt;</code>. Images: <code>&lt;img src="https://example.com/photo.jpg" alt="Description"&gt;</code>. Unsupported tags and unsafe attributes are removed on save.</p>
      </details>
    </section>
    <aside class="panel stack" aria-label="Publishing options">
      <h2>Publishing</h2>
      <label>Status<select name="status" <?= $canPublish ? '' : 'disabled' ?>>
        <option value="draft" <?= (($post->status ?? 'draft') === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= (($post->status ?? 'draft') === 'published') ? 'selected' : '' ?>>Published</option>
      </select></label>
      <?php if (!$canPublish): ?><input type="hidden" name="status" value="draft"><p class="notice">Writers can edit only their own drafts. An admin must publish your post.</p><?php endif; ?>
      <?php if (Acl::canManageCategories($user)): ?>
      <fieldset class="cat-picker"><legend>Categories</legend>
        <?php foreach ($cats as $c): ?><label class="cat-check"><input type="checkbox" name="categories[]" value="<?= (int)$c->id ?>" <?= in_array($c->id, $myCats, true) ? 'checked' : '' ?>><?= Security::e($c->title) ?><?= $c->visible ? '' : ' (hidden)' ?></label><?php endforeach; ?>
        <?php if (!$cats): ?><p class="field-help">No categories yet.</p><?php endif; ?>
      </fieldset>
      <?php endif; ?>
      <div class="editor-save">
        <button class="btn" type="submit" name="save_action" value="continue">Save and continue</button>
        <button class="btn btn-ghost" type="submit" name="save_action" value="list">Save and return to posts</button>
        <a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin">Cancel</a>
      </div>
    </aside>
  </div>
</form>
