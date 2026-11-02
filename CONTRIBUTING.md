# Contributing to disconunu

disconunu is a small PHP CMS with server-rendered templates and an environment-driven configuration. Keep changes focused and include runnable checks for behavior changes.

## Requirements

- PHP 8.2 or newer with PDO and `pdo_sqlite` for local development, `mbstring`, `dom`, and `fileinfo`.
- SQLite with FTS5 support for full-text search; `zip` for theme installation and its tests.
- Git. The test suites are plain PHP; nothing else is needed.

There is no Composer dependency, JavaScript package installation, or asset build step. The project uses `autoload.php`; PHP templates, CSS, and JavaScript are edited directly.

Check your environment:

```bash
php -v
php -m
```

If the SQLite modules are installed but disabled, add `-d extension=pdo_sqlite -d extension=sqlite3` after `php` in the commands below, or enable them in your CLI PHP configuration. Do not load an extension twice. `tests/run_all.php` loads missing modules on its own.

## Local setup

Work in a separate development checkout, not the directory serving an existing site. Run these commands from the repository root in the same shell:

```bash
export DN_DB_DRIVER=sqlite
export DN_DEV_DIR="$(mktemp -d)"
export DN_DB_DATABASE="$DN_DEV_DIR/development.sqlite"
export DN_APP_URL=http://127.0.0.1:8090
export DN_DEBUG=1
export DN_SECURE_COOKIES=0

php bin/disconunu migrate
```

Create your own account using the interactive password prompt. Replace the quoted placeholders; do not reuse deployment credentials:

```bash
php bin/disconunu user '<login>' '<email>' --role=superadmin
```

Passwords must contain at least 10 bytes. For automation, the CLI also reads `DN_USER_PASSWORD`; supply it through your secret-handling mechanism rather than committing it or pasting it into shell history. The first account becomes `superadmin`; subsequent accounts default to `writer` if no role is specified. Permission rules live in `src/Acl.php` and are covered by the regression tests.

Optional demo content, **only with the disposable database above selected**:

```bash
php bin/seed-demo.php 200
```

The number is a target total, not an insertion count. The seeder creates only the missing posts and leaves existing article text unchanged. It uses an existing account as author and refuses to run until the first account has been created. It runs migrations before seeding. **Never use it to translate an existing site. `--reset` deletes every post and its category associations, including real content.**

Start the development server:

```bash
php -d opcache.enable_cli=0 -S 127.0.0.1:8090 -t public public/router.php
```

Visit the configured URL; sign in at `/admin/login`. The built-in server is for local development, not production. Stop it before removing the temporary database. Database isolation does not isolate uploads or installed themes: those still belong to the development checkout. Keep that checkout separate from a deployed site.

## Code layout and conventions

- `public/index.php`: request routing and controllers; `public/` is the document root.
- `src/`: application services, authorization, database access, and models.
- `src/Migrator.php`: versioned schema changes and database setup.
- `themes/teal/`: bundled templates and assets, including the bundled logo (`images/logo.svg`).
- `examples/starter-theme/`: starting point for a custom theme; see its README.
- `bin/disconunu`: maintenance CLI; `bin/seed-demo.php`: optional sample content.
- `tests/`: standalone PHP checks, including HTTP end-to-end runs.

Use English for code comments, interface text, sample content, and contributor documentation. Follow the surrounding PHP style, use prepared SQL statements, escape rendered values, sanitize user HTML, and enforce authorization and CSRF checks on the server. UI visibility is not a permission check. Keep theme fallbacks working and preserve accessibility, keyboard navigation, and mobile layouts.

Add migrations rather than requiring contributors to discard their database. Test both a new database and an upgrade on a disposable copy. Avoid unrelated refactors, and generated artifacts. Never include database files, uploads, credentials, or personal account details in a patch.

## Tests

Run from the repository root:

```bash
php tests/run_all.php
```

Each suite also runs alone (`php tests/http.php`, …). They use temporary application copies, databases and servers, never the working site. Rendered markup checks do not replace a real browser: review responsive layouts and keyboard behavior manually when changing the interface. Record actual test output, not a fixed expected test count.

Lint changed PHP files, including CLI scripts without a `.php` extension:

```bash
php -l bin/disconunu
php -l bin/seed-demo.php
```

For an isolated migration and seeder check in a fresh shell:

```bash
export DN_DB_DRIVER=sqlite
export DN_DB_DATABASE="$(mktemp -d)/seed-check.sqlite"
php bin/disconunu migrate
php bin/disconunu user seed_owner seed-owner@example.test
php bin/seed-demo.php 200
php bin/seed-demo.php 200
php -r '$n = (new PDO("sqlite:" . getenv("DN_DB_DATABASE")))->query("SELECT COUNT(*) FROM dn_post")->fetchColumn(); echo "posts: $n\n"; exit($n == 200 ? 0 : 1);'
```

The second seed should report nothing to do, and the query should report `posts: 200`. Use only disposable databases to test `--reset` or different target totals. Remove temporary fixtures when finished.

## Existing maintenance commands

Run `php bin/disconunu help` for the command list. Placeholders below must be replaced:

```text
php bin/disconunu migrate
php bin/disconunu user <login> <email> [--role=writer|admin|superadmin]
php bin/disconunu password <login>
php bin/disconunu role <login> <writer|admin|superadmin>
php bin/disconunu reindex
php bin/disconunu seed [--reset] [count]
```

`seed` is an alias for the demo script, with the same destructive reset option. `reindex` rebuilds the SQLite full-text index; other database drivers have no local FTS index to rebuild. User deletion is available through the authorized admin interface, not a CLI delete command. Always verify the selected database before running maintenance commands.

## Review and release checklist

1. Describe the change, user-visible effects, and reproduction or verification steps. Add regression coverage for fixes and update documentation for changed commands or behavior.
2. Run the relevant tests and PHP lint. Before release, run the full suite on PHP 8.2 and the PHP version intended for deployment; do not claim compatibility with versions or database drivers that were not exercised.
3. Test installation on a fresh database and migration on an isolated copy of an existing database. Verify content counts, ownership and role restrictions, categories, search, and settings. Never seed or reset a real site to validate a release.
4. Review the diff and release contents. Include application code, bundled assets and logo, documentation, and license. Exclude local databases, uploaded media, secrets, temporary themes, and test artifacts. Record the tested revision and any known limitations.
5. Before deployment, make a consistent database backup and back up uploaded media and custom themes separately. For SQLite, use its backup facility or stop writers before copying the database; an arbitrary copy of an active file may miss WAL data. Verify restoration in isolation.
6. Deploy with the web root set to `public/`, writable application data directories, HTTPS, `DN_DEBUG=0`, `DN_SECURE_COOKIES=1`, and the correct `DN_APP_URL`. Run migrations against the intended database under controlled deployment conditions. Follow the deployment guidance in the README.
7. Check public pages, login, role-restricted actions, search, and assets without adding demo content. Keep the previous code and consistent data backup available; schema changes may require restoring data as well as code for rollback.

Report security concerns according to [SECURITY.md](SECURITY.md). Do not publish credentials or private site data in issues, logs, screenshots, or test reports.
