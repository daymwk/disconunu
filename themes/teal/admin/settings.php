<?php
/** Site settings and trusted theme administration. */
use DN\Model\Settings;
use DN\Security;
use DN\View;
?>
<header class="page-heading"><div><p class="eyebrow">Site management</p><h1>Settings</h1><p class="page-description">Your identity, publishing defaults and public theme in one place.</p></div></header>
<nav class="section-links" aria-label="Settings sections"><a href="#identity">Identity</a><a href="#publishing">Publishing</a><a href="#logo">Logo</a><a href="#themes">Themes</a></nav>
<div class="stack">
<form class="settings-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/settings">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <section class="panel stack" id="identity"><div><h2>Site identity</h2><p class="muted">Leave text fields empty to use the site defaults.</p></div>
    <div class="form-grid">
      <label>Site title<input type="text" name="site_title" value="<?= Security::e(Settings::get('site_title')) ?>" maxlength="120" placeholder="<?= Security::e(\DN\App::config()['app']['name']) ?>"></label>
      <label>Tagline<input type="text" name="site_tagline" value="<?= Security::e(Settings::get('site_tagline')) ?>" maxlength="160" placeholder="<?= Security::e(\DN\App::config()['app']['subtitle']) ?>"></label>
    </div>
    <label>Footer text<input type="text" name="footer_text" value="<?= Security::e(Settings::get('footer_text')) ?>" maxlength="200"></label>
  </section>
  <section class="panel stack" id="publishing"><h2>Publishing defaults</h2>
    <div class="form-grid">
      <label>Posts per page<input type="number" name="posts_per_page" min="1" max="50" value="<?= (int)Settings::postsPerPage() ?>" required><small>Controls the public blog, not admin list sizes.</small></label>
      <label>Public theme<select name="theme"><?php foreach ($themes as $t): ?><option value="<?= Security::e($t) ?>" <?= $t === $current ? 'selected' : '' ?>><?= Security::e($t) ?></option><?php endforeach; ?></select><small>Administration always uses the bundled workspace.</small></label>
    </div>
    <input type="hidden" name="rss_enabled" value="0">
    <label class="cat-check"><input type="checkbox" name="rss_enabled" value="1" <?= Settings::rssEnabled() ? 'checked' : '' ?>>Enable the RSS feed</label>
    <div class="admin-actions"><button class="btn" type="submit">Save settings</button></div>
  </section>
</form>
<section class="panel" id="logo"><h2>Site logo</h2><p class="muted">PNG, JPEG, GIF or WebP, up to 512 KiB. SVG is not accepted. Uploading replaces the custom logo; removing it restores the theme logo or bundled fallback.</p>
  <div class="logo-previews">
    <?php if ($logoUrl): ?><figure><img src="<?= Security::e($logoUrl) ?>" alt="Current site logo" width="84" height="84"><figcaption>Current logo</figcaption></figure><?php endif; ?>
    <figure><img src="<?= Security::e($fallbackThemeUrl) ?>/images/logo.svg" alt="Bundled fallback logo" width="84" height="84"><figcaption>Bundled fallback</figcaption></figure>
  </div>
  <form class="stack" method="post" action="<?= Security::e($baseUrl) ?>/admin/logo" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <label>Choose a logo<input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"></label>
    <div class="admin-actions"><button class="btn" type="submit">Upload logo</button><button class="btn btn-danger" type="submit" name="remove" value="1" data-confirm="Remove the custom logo and restore the theme or bundled logo?">Remove custom logo</button></div>
  </form>
</section>
<section class="panel stack" id="themes"><div class="panel-heading"><div><h2>Public themes</h2><p>Install a ZIP first, then activate it when you are ready.</p></div><a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/theme/starter">Download starter ZIP</a></div>
  <p class="notice notice-warning">Install themes only from trusted sources. Themes contain executable PHP code with access to your site. A ZIP must contain <code>layout.php</code>; missing templates use the bundled fallback.</p>
  <table class="admin-table"><thead><tr><th scope="col">Theme</th><th scope="col">State</th><th scope="col">Actions</th></tr></thead><tbody>
    <?php foreach ($themes as $t): ?><tr>
      <td data-label="Theme"><code><?= Security::e($t) ?></code></td>
      <td data-label="State"><span class="badge"><?= $t === $current ? 'Active' : ($t === View::FALLBACK_THEME ? 'Bundled fallback' : 'Installed') ?></span></td>
      <td data-label="Actions"><?php if ($t !== $current && $t !== View::FALLBACK_THEME): ?>
        <form method="post" action="<?= Security::e($baseUrl) ?>/admin/theme/<?= rawurlencode($t) ?>/delete" data-confirm="<?= Security::e('Delete theme “' . $t . '” from disk?') ?>"><input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form>
      <?php else: ?><span class="muted">Protected: active or bundled theme</span><?php endif; ?></td>
    </tr><?php endforeach; ?>
  </tbody></table>
  <form class="stack" method="post" action="<?= Security::e($baseUrl) ?>/admin/theme" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <label>Theme ZIP<input type="file" name="themezip" accept=".zip" required></label>
    <label class="cat-check"><input type="checkbox" name="activate" value="1">Activate immediately after installation</label>
    <p class="field-help">Unchecked: install only, keeping the current public theme.</p>
    <div class="admin-actions"><button class="btn" type="submit">Install theme</button></div>
  </form>
</section>
</div>
