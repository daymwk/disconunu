# Starter theme

A minimal, warm editorial theme for Disco nunu. It inherits the bundled public
page templates and CSS, overrides the layout and a few colors, and retains the
original logo unless a custom site logo has been uploaded.

## Install

Download the starter ZIP from **Admin → Settings → Themes**, or zip this folder
with `starter-theme/layout.php` at the archive root. Upload it from the same
screen. Installation does not switch the live site unless **Activate after
installation** is selected. Choose the installed theme and save settings to
activate it later.

## Customize

Rename the folder before packaging a separate theme. Edit `css/style.css` for
colors or `layout.php` for structure. Copy `home.php`, `post.php`, `category.php`
or `search.php` from `themes/teal/` to override a public page. Missing public
templates use the bundled fallback. Administration always uses the bundled UI.

Layout variables include `$content` (already rendered HTML), `$title`, `$appName`,
`$subtitle`, `$baseUrl`, `$themeUrl`, `$fallbackThemeUrl`, `$logoUrl`, `$rssEnabled`,
`$user`, `$cats` and `$flashes`. Escape textual values with `DN\Security::e()`;
only the rendered `$content` should be emitted as HTML. Use external CSS and JS
because the content security policy blocks inline scripts. Keep all assets local.

**Themes execute PHP on the server. Only install themes whose source you trust.**
Archive validation prevents path traversal, not malicious PHP execution.
