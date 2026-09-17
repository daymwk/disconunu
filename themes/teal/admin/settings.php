<?php
/** Site settings (superadmin): identity, footer, RSS, posts/page, theme, logo.
 * @var list<string> $themes
 * @var string $current
 * @var string $baseUrl
 * @var string $csrf
 */
use DN\Model\Settings;
use DN\Security;
?>
<h1>Settings</h1>

<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/settings">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

  <label>Site title
    <input type="text" name="site_title" value="<?= Security::e(Settings::get('site_title')) ?>" maxlength="120" placeholder="<?= Security::e(\DN\App::config()['app']['name']) ?>">
  </label>

  <label>Tagline
    <input type="text" name="site_tagline" value="<?= Security::e(Settings::get('site_tagline')) ?>" maxlength="160" placeholder="<?= Security::e(\DN\App::config()['app']['subtitle']) ?>">
  </label>

  <label>Footer text
    <input type="text" name="footer_text" value="<?= Security::e(Settings::get('footer_text')) ?>" maxlength="200">
  </label>

  <label>Posts per page
    <input type="number" name="posts_per_page" min="1" max="50" value="<?= (int)Settings::postsPerPage() ?>">
  </label>

  <label class="cat-check">
    <input type="checkbox" name="rss_enabled" <?= Settings::rssEnabled() ? 'checked' : '' ?>> RSS feed enabled
  </label>

  <label>Theme
    <select name="theme">
      <?php foreach ($themes as $t): ?>
        <option value="<?= Security::e($t) ?>" <?= $t === $current ? 'selected' : '' ?>><?= Security::e($t) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <button class="btn" type="submit">Save settings</button>
</form>

<h2>Logo</h2>
<p class="post-excerpt">PNG / JPEG / GIF / WebP / SVG, max 512 Ko. Leave empty to use the theme’s own logo.</p>
<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/logo" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Upload logo
    <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
  </label>
  <div class="admin-actions">
    <button class="btn" type="submit">Upload</button>
    <button class="btn btn-danger" type="submit" name="remove" value="1"
            onclick="return confirm('Remove custom logo?')">Remove custom logo</button>
  </div>
</form>

<h2>Theme packs</h2>
<p class="post-excerpt">A theme is a folder under <code>themes/</code> containing <code>layout.php</code>. Drop one there manually, or install a <code>.zip</code> theme pack (superadmin):</p>
<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/theme" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Theme .zip (must contain layout.php at its root)
    <input type="file" name="themezip" accept=".zip">
  </label>
  <div class="admin-actions">
    <button class="btn" type="submit">Install theme</button>
  </div>
  <p class="post-excerpt">Installed themes: <?= Security::e(implode(', ', $themes)) ?></p>
</form>
