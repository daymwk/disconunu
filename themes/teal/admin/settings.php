<?php
/** Site settings (superadmin): identity, footer, RSS, posts/page, logo, themes.
 * @var list<string> $themes
 * @var string $current
 * @var string $baseUrl
 * @var string $csrf
 * @var string $themeUrl
 */
use DN\Model\Settings;
use DN\Security;
use DN\View;
?>
<h1>Settings</h1>

<?php View::partial('admin/nav', ['active' => 'settings']); ?>

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
<p class="post-excerpt">PNG / JPEG / GIF / WebP / SVG, max 512 KB. Leave it alone to keep the current logo
  (the bundled theme ships the original one).</p>
<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/logo" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Upload logo
    <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
  </label>
  <div class="admin-actions">
    <button class="btn" type="submit">Upload</button>
    <button class="btn btn-danger" type="submit" name="remove" value="1"
            onclick="return confirm('Remove the custom logo and fall back to the theme logo?')">Remove custom logo</button>
  </div>
</form>

<h2>Themes</h2>
<p class="post-excerpt">A theme is a folder under <code>themes/</code> containing <code>layout.php</code>
  (templates it does not override come from the bundled <code>teal</code> theme).</p>

<table class="admin-table">
  <thead><tr><th>Theme</th><th>State</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($themes as $t): ?>
    <tr>
      <td><code><?= Security::e($t) ?></code></td>
      <td>
        <?php if ($t === $current): ?>active
        <?php elseif ($t === View::FALLBACK_THEME): ?>bundled fallback
        <?php else: ?>installed<?php endif; ?>
      </td>
      <td class="admin-row-actions">
        <?php if ($t !== $current && $t !== View::FALLBACK_THEME): ?>
          <form method="post" action="<?= Security::e($baseUrl) ?>/admin/theme/<?= rawurlencode($t) ?>/delete"
                onsubmit="return confirm('Delete theme “<?= Security::e($t) ?>” from the disk?')">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
            <button class="btn btn-danger" type="submit">delete</button>
          </form>
        <?php else: ?>
          <span class="post-meta" title="The active theme and the bundled fallback cannot be removed.">—</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/theme" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Install a theme pack (.zip, must contain <code>layout.php</code>)
    <input type="file" name="themezip" accept=".zip">
  </label>
  <div class="admin-actions">
    <button class="btn" type="submit">Install theme</button>
  </div>
</form>
