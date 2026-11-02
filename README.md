# disconunu

A lightweight, self-hosted CMS for blogs and small websites. PHP, SQLite, server-rendered pages — no Composer dependencies, JavaScript framework or build step.

## Features

- **Posts:** drafts, publishing, unique slugs, HTML content with an allowlist sanitizer, excerpts and authenticated previews.
- **Menus and categories:** attach a post to multiple categories; show or hide each category in navigation. Uncategorized posts use **General**.
- **Administration:** responsive navigation, searchable and paginated post/category/account lists, filters, clear publishing permissions, editor shortcuts and an audit log of admin actions.
- **Site identity:** editable title, tagline and footer; a disco-ball logo is bundled, with optional custom logo upload and reset.
- **Publishing:** configurable public page size, responsive windowed pagination, search and an optional RSS feed.
- **Themes:** upload a ZIP, install without activating, switch themes separately and download a starter template. Administration stays on the bundled interface.
- **Small footprint:** SQLite by default; optional MySQL/PostgreSQL adapters. No third-party runtime packages.

## Requirements

- PHP **8.2 or newer** with PDO, a PDO database driver, `mbstring`, `dom`, `fileinfo` and sessions.
- `pdo_sqlite` for the default database; `pdo_mysql` or `pdo_pgsql` for an external database.
- `zip` for installing and downloading theme archives. Installing or activating a theme also requires `proc_open` and a working PHP CLI for syntax checks.
- A web server configured with **`public/` as its document root**.

SQLite is the primary tested database. External database adapters should be validated against the target server before deployment.

## Quick start

```sh
git clone https://github.com/daymwk/disconunu.git
cd disconunu
php bin/disconunu migrate
php -d opcache.enable_cli=0 -S 127.0.0.1:8090 -t public public/router.php
```

Open **http://127.0.0.1:8090/admin/login** and create the first account. It becomes the superadmin. Once an account exists, this page only permits sign-in; there is no public registration.

Alternatively, create the initial account from the terminal:

```sh
php bin/disconunu user owner owner@example.com --role=superadmin
```

The CLI prompts for a password. Never commit passwords or a populated database. For optional sample content, run `php bin/seed-demo.php 20` after creating an account.

If the SQLite driver is installed but not enabled, enable it in your PHP configuration or add `-d extension=pdo_sqlite` to PHP commands. The test scripts detect this local configuration automatically where supported.

The built-in PHP server is for local development only. Disabling CLI OPcache ensures template edits are visible immediately.

## Roles and permissions

| Capability | Writer | Admin | Superadmin |
|---|:---:|:---:|:---:|
| Create drafts | Yes | Yes | Yes |
| Edit/delete own drafts | Yes | Yes | Yes |
| Edit/delete another writer's posts | No | Yes | Yes |
| Publish or modify published posts | No | Yes | Yes |
| Delete a superadmin's posts | No | No | Yes |
| Manage categories | No | Yes | Yes |
| Manage accounts and roles | No | No | Yes |
| Change site settings, logo and themes | No | No | Yes |

A writer's article becomes read-only to that writer once published. An admin can edit and publish content; **only a superadmin can assign roles**, including additional superadmins. Self-deletion and self-demotion are blocked to prevent accidental lockout.

Authorization is checked on the server for every operation, including direct POST requests. Hiding a button is not an authorization boundary.

## Using the CMS

### Posts and navigation

Create a draft from **Posts → New post**. Enter a title and HTML content, then save or publish according to your role. Use **Save and continue** to keep editing. Preview opens separately, without publishing a draft. Published **View** links open a new tab.

Create menus under **Categories**, then assign categories in the post editor. A post may belong to several categories. Hiding a category removes its menu and public category page; it does **not** unpublish posts assigned to it. Change the post itself to Draft to hide its content from the public site.

Use the post list's search, status, author, category and sort controls to narrow results. Filters survive pagination. Account and category lists have their own relevant filters and page controls.

### Site settings

Under **Settings**, configure the site title, tagline, footer, posts per page and RSS. When RSS is disabled, the feed endpoint is unavailable and its links disappear.

Upload a PNG, JPEG, GIF or WebP logo (maximum 512 KiB), or reset to the default. SVG uploads are deliberately not supported. Short pages keep the footer at the bottom of the viewport.

### Themes

1. Download the starter ZIP under **Settings → Themes**.
2. Customize its PHP layout and CSS; see [`examples/starter-theme/README.md`](examples/starter-theme/README.md).
3. Upload the ZIP. Leave **Activate after installation** unchecked to install without changing the live site.
4. Select an installed theme and save settings to activate it later.
5. Switch away before uninstalling a theme. The default public theme is `maison`; the bundled `teal` fallback cannot be removed.

Three public themes ship with disconunu: `maison` (light, default), `disco` (dark, mirror-ball rainbow) and `teal` (the original look and bundled fallback).

The ZIP must contain `layout.php`, either at its root or inside one enclosing theme folder. Missing public templates use the bundled fallback. Administration uses the bundled templates regardless of the selected public theme.

**Themes execute PHP with the application's permissions. Only install trusted source code.** Archive validation prevents unsafe paths and oversized extraction; it does not sandbox PHP. Keep the `themes/` directory writable only when installing/removing themes if your deployment supports that workflow.

### Audit log

Superadmins open **Logs** (`/admin/logs`) to see who did what and when: sign-ins (including failed attempts with the login tried), sign-outs, first-account setup, post, category, user, settings, logo and theme changes. Each entry stores the time (UTC), account, client IP, action and target. Passwords, CSRF tokens and post bodies are never recorded. Entries are kept until removed from the `dn_admin_log` table; disconunu does not purge them.

## Configuration

Environment variables override defaults in [`config/config.php`](config/config.php). Site settings saved in the database override their corresponding environment defaults.

| Variable | Default | Purpose |
|---|---|---|
| `DN_DB_DRIVER` | `sqlite` | `sqlite`, `mysql` or `pgsql` |
| `DN_DB_DATABASE` | `var/disconunu.sqlite` | SQLite path or external database name |
| `DN_DB_HOST` | `localhost` | External database hostname |
| `DN_DB_PORT` | Driver default | External database port |
| `DN_DB_USER` / `DN_DB_PASSWORD` | See configuration | External database credentials |
| `DN_APP_URL` | Request-derived | Canonical URL; set explicitly in production |
| `DN_APP_NAME` | `disconunu` | Initial site title |
| `DN_APP_SUBTITLE` | `Simple CMS for blogs` | Initial tagline |
| `DN_THEME` | `maison` | Initial theme |
| `DN_TIMEZONE` | `UTC` | Application timezone |
| `DN_DEBUG` | `0` | Error details; leave disabled in production |
| `DN_SECURE_COOKIES` | `0` | Set to `1` on HTTPS deployments |

Pass environment variables through your PHP-FPM pool or service configuration. This project does not automatically load a `.env` file.

## Deployment

1. Serve **only `public/`**, never the repository root.
2. Enable HTTPS, set `DN_SECURE_COOKIES=1`, `DN_DEBUG=0` and `DN_APP_URL=https://blog.example.com`.
3. Give the PHP service write access to `var/` (SQLite and uploads). ZIP installation also requires write access to `themes/`.
4. Run migrations before accepting requests and create the first account **before exposing the site publicly**.
5. Apply reverse-proxy rate limits and maintain backups. Read [`SECURITY.md`](SECURITY.md).

Apache deployments can use the supplied `public/.htaccess` with `mod_rewrite` and `AllowOverride FileInfo` enabled for that directory. A minimal nginx PHP-FPM location configuration is:

```nginx
root /srv/disconunu/public;
index index.php;

location / {
    try_files $uri /index.php?$query_string;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}

# The front controller is the only PHP file that should be executed publicly.
location ~ \.php$ {
    return 404;
}
```

Adapt the PHP-FPM socket to the host. TLS, virtual-host configuration and proxy rate limits are deployment-specific; the snippet above is not a complete web-server configuration.

## Maintenance and backups

```sh
php bin/disconunu migrate
php bin/disconunu user contributor contributor@example.com --role=writer
php bin/disconunu password contributor
php bin/disconunu role contributor admin
```

The password prompt is preferred for interactive use. `DN_USER_PASSWORD` supports automation; supply it from a secret store rather than shell history or source control.

Back up the **database, `var/uploads/`, and custom themes**. For a running SQLite site, use SQLite's backup API or the `sqlite3` `.backup` command instead of blindly copying a database that may have active WAL files. External databases require their native backup tools. Test restoration before upgrades.

To upgrade, back up first, deploy the new source without replacing `var/` or custom themes, run `php bin/disconunu migrate`, and test sign-in and publishing before reopening traffic.

## Development and tests

```sh
php tests/run_all.php
```

The suites use temporary databases. HTTP suites isolate uploads and installed themes from the working site. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for development guidance and additional checks. Test success is not a guarantee that a deployment is secure; configuration and trusted theme code remain the operator's responsibility.

## Project layout

```text
public/                 Web entry point and development router
config/                 Environment-backed configuration
src/                    Routing, authentication, ACLs, models and rendering
themes/teal/            Bundled public theme and administration UI
examples/starter-theme/ Installable theme template
bin/                    Migration, account and sample-content commands
tests/                  Regression and end-to-end checks
var/                    Local database and uploads (not versioned)
```

## License

[MIT](LICENSE).
