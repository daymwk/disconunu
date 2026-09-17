# Disco nunu

A small, dependency-free CMS for blogs and light websites.
PHP 8.2+ · SQLite by default (MySQL / PostgreSQL optional) · no Composer, no build step.

Revived in 2026 from a 2017 student project: same intent (tiny, portable, hackable),
modern engine, hardened for a real deployment.

---

## Features

**Content**
- Posts with slugs, drafts, publication dates, excerpts
- Categories: many-to-many, each one is a menu entry, each one can be hidden
  (every post falls back to the default `general` category)
- Full-text search (SQLite FTS5, `LIKE` fallback on MySQL/PostgreSQL)
- Paginated listings with windowed pagination (`1 … 4 5 6 … 98`)
- RSS feed, switchable on/off from the admin

**People**
- Three roles with a real ACL:

  | Role | Can |
  |---|---|
  | `superadmin` | everything: settings, themes, user roles, all posts |
  | `admin` | write + publish + edit/delete posts, manage categories |
  | `writer` | write drafts; edit/delete **their own** posts; cannot publish |

- An `admin` cannot delete a `superadmin`'s post; nobody can demote the last superadmin.
  The first account created on a fresh install becomes `superadmin`.

**Site**
- Admin-editable identity: site title, tagline, footer text, logo upload, posts per page, theme
- Themes: a folder under `themes/`, installable from a `.zip` upload, selectable at runtime.
  A theme may override as few or as many templates as it wants — anything missing falls
  back to the bundled `teal` theme, so a partial theme can never white-screen the site.
- Dark mode, sticky footer, mobile-friendly, ~7 KB of CSS, no JS framework

**Security** (all covered by the test suite — see `SECURITY.md`)
- Prepared statements everywhere, CSRF on every state-changing request
- Output escaping in templates + HTML sanitizing on write (allowlist)
- Argon2id password hashing (bcrypt fallback), transparent rehash, brute-force throttling
- Hardened sessions (HttpOnly, SameSite=Lax, regeneration on login, UA fingerprint)
- Strict CSP and security headers, `X-Powered-By` hidden
- Path-traversal-proof asset/upload routes; zip-slip-proof theme installs

---

## Quick start

```bash
git clone <your-repo> disconunu && cd disconunu

php bin/disconunu migrate          # schema + default category
php bin/disconunu user daym you@example.com --role=superadmin
php bin/seed-demo.php 200          # optional: 200 sample posts

php -S 127.0.0.1:8090 -t public public/router.php
# → http://127.0.0.1:8090/  (sign in at /admin/login)
```

Requirements: PHP 8.2+ with `pdo_sqlite` (or `pdo_mysql` / `pdo_pgsql`) and `zip`
for theme uploads. Nothing else.

> If your PHP lacks the SQLite driver as a compiled module, load it on the command
> line: `php -d extension=pdo_sqlite -d extension=sqlite3 …` (the test suite does
> this automatically).

## Configuration

Everything is environment-driven with sensible defaults; see `config/config.php`.

| Variable | Default | Purpose |
|---|---|---|
| `DN_DB_DRIVER` | `sqlite` | `sqlite`, `mysql` or `pgsql` |
| `DN_DB_DATABASE` | `var/disconunu.sqlite` | SQLite file path, or database name |
| `DN_DB_HOST` / `DN_DB_PORT` | `127.0.0.1` / 3306·5432 | MySQL / PostgreSQL |
| `DN_DB_USER` / `DN_DB_PASSWORD` | — | MySQL / PostgreSQL credentials |
| `DN_APP_URL` | auto-detected | Canonical base URL (set it behind a proxy) |
| `DN_APP_NAME` / `DN_APP_SUBTITLE` | — | Fallback identity (admin settings win) |
| `DN_THEME` | `teal` | Fallback theme (admin settings win) |
| `DN_DEBUG` | `0` | Show errors in the response — keep off in production |
| `DN_SECURE_COOKIES` | `0` | Set to `1` behind HTTPS |
| `DN_SESSION_NAME` | `dnsid` | Session cookie name |

Site identity, footer, logo, RSS, posts-per-page and the active theme live in the
database and are edited from **Admin → Settings** (superadmin).

## Production

Point the document root at `public/` and give the app a writable `var/`:

```apache
<VirtualHost *:443>
    ServerName blog.example.com
    DocumentRoot /srv/disconunu/public

    <Directory /srv/disconunu/public>
        AllowOverride All
        Require all granted
    </Directory>

    # nothing outside public/ is reachable
    <Directory /srv/disconunu>
        Require all denied
    </Directory>
</VirtualHost>
```

`public/.htaccess` routes every non-file request to `index.php`. An nginx
equivalent is:

```nginx
root /srv/disconunu/public;
location / { try_files $uri /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php-fpm.sock; }
# deny access to secrets/config if the docroot is ever mis-set
location ~ ^/(config|src|var|bin|tests)/ { deny all; }
```

Then: `chmod -R u+rwX var/`, set `DN_DEBUG=0`, `DN_SECURE_COOKIES=1`,
`DN_APP_URL=https://blog.example.com`, run `php bin/disconunu migrate`, and back up
the SQLite file (a single file — the whole site is in it).

## Architecture

```
public/            document root
  index.php        single front controller (all routes)
  router.php       dev server router
  .htaccess        Apache rewrite
config/config.php  env-driven configuration
src/
  App.php          bootstrap, config, hardened sessions
  Router.php       {param} patterns, 404/405
  Http.php         security headers, redirects, flash, abort
  Security.php     CSRF, escaping, HTML sanitizing
  Auth.php         login/logout, throttling, session hardening
  Acl.php          roles and post/category/user permissions
  Database.php     PDO for sqlite | mysql | pgsql
  Migrator.php     migrations + idempotent setup (default category, legacy cleanup)
  View.php         template rendering with per-template theme fallback
  ThemeManager.php theme discovery + zip install (zip-slip proof)
  Model/           User, Post, Category, Settings
themes/teal/       bundled theme (layout, templates, admin pages, CSS)
var/               SQLite database + uploads (writable, git-ignored)
tests/             run.php (unit) + smoke_http.sh (end-to-end)
```

## Themes

A theme is a folder in `themes/` containing at least `layout.php`. Templates are
looked up in the active theme first, then in `teal`:

```
layout.php  home.php  post.php  category.php  search.php  error.php  pagination.php
admin/{login,dashboard,post-edit,categories,settings,users}.php
css/style.css  images/logo.jpg
```

`layout.php` receives `$content` plus `$appName`, `$subtitle`, `$baseUrl`, `$csrf`,
`$flashes`, `$user`, `$themeUrl`, `$fallbackThemeUrl`, `$logoUrl`, `$rssEnabled`
and `$cats` (visible categories). Upload a `.zip` of the theme folder from
**Admin → Settings → Theme packs**: entries outside the theme root are ignored,
archives without `layout.php` are refused, and oversized files are skipped.

## Maintenance

```bash
php bin/disconunu migrate                 # apply migrations
php bin/disconunu user <login> <email> [--role=writer|admin|superadmin]
php bin/disconunu password <login>        # or: DN_USER_PASSWORD=… for scripting
php bin/disconunu role <login> <role>
php bin/seed-demo.php [--reset] [count]   # sample content
```

`migrate` also performs idempotent housekeeping: it creates the `general` category,
attaches any uncategorized post to it, normalizes the role vocabulary and drops the
2017 menu tables.

## Tests

```bash
php tests/run.php          # 26 unit/behaviour tests (no dependency)
bash tests/smoke_http.sh   # 48 end-to-end HTTP checks on a throwaway database
```

The smoke suite boots its own PHP server and its own SQLite database, exercises the
public site and the whole admin (login, ACL, posts, categories, settings, RSS toggle,
logo upload, theme install, pagination) and cleans up after itself. Both suites are
green on PHP 8.5.

## License

MIT — see `LICENSE`.
