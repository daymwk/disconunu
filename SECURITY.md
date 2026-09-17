# Security

Disco nunu is a small self-hosted CMS, not a multi-tenant hosting platform.
Visitors are untrusted. Writer and admin accounts are constrained by server-side
authorization. Superadmins and installed PHP themes are trusted with the site.

## Security boundaries

- **Writers** can create drafts and edit/delete only their own drafts. Publishing
  an article makes it read-only to its writer. Ownership and status are checked
  again when saving or deleting, not just when displaying the editor.
- **Admins** manage content and categories. They cannot manage accounts, assign
  roles, change site configuration, install themes or delete superadmin posts.
- **Superadmins** administer the site. Self-demotion and self-deletion are
  rejected to prevent lockout. Create the first account before making a new
  installation accessible to the public.
- **Themes are executable PHP**, not safe data. An uploaded theme can run code
  with the PHP service's filesystem and database privileges. ZIP validation is
  not a sandbox. Only install source code you trust.

## Controls

| Surface | Control |
|---|---|
| Database access | Prepared values; filter/sort SQL fragments are allowlisted internal constants |
| Post HTML | DOM-based tag/attribute allowlist; active subtrees and unsafe URL schemes removed |
| Templates | Text and attribute values escaped on output |
| State-changing requests | Session authentication, role/ownership checks and CSRF tokens |
| Passwords | PHP password hashing (Argon2id when available, bcrypt fallback) and transparent rehash |
| Sessions | HttpOnly, SameSite=Lax, Secure on HTTPS, session ID regeneration on login |
| Login attempts | Database-backed IP budget; replacing the browser cookie does not reset failures |
| Draft/future content | Public queries exclude drafts and future publication dates; authenticated preview is non-cacheable and non-indexable |
| Logo uploads | Superadmin only; PNG/JPEG/GIF/WebP, validated image data and size limit; no SVG uploads |
| Theme archives | Superadmin only; root/layout validation, path confinement and extraction limits |
| Assets | Restricted extensions and filesystem confinement; application data stays outside the document root |
| Browser headers | Content Security Policy, frame restrictions, nosniff and referrer policy |
| Exceptions | Generic public error response unless explicitly running with debug enabled |

See the executable tests under `tests/`, particularly `backend_http.py`,
`accounts.py`, `security_regression.php`, `theme_services.php` and
`smoke_http.sh`. Tests are regression evidence, not a security certification.

## Deployment checklist

1. Configure **`public/` as the document root**. Never expose `var/`, `src/`,
   configuration files or CLI tools through the web server.
2. Use HTTPS and set `DN_SECURE_COOKIES=1`, `DN_DEBUG=0` and an explicit
   `DN_APP_URL` matching the canonical host.
3. Run PHP as a dedicated unprivileged service account. Keep application source
   read-only; grant write access to `var/` and only grant theme-directory writes
   when theme installation/removal is required.
4. Install migrations and claim the initial account before opening the site to
   visitors. There are no bundled default credentials.
5. Give people separate accounts and the least privilege needed. Use long,
   unique passwords and revoke accounts that no longer need access.
6. Add reverse-proxy rate limits. The built-in IP throttle uses `REMOTE_ADDR`,
   not arbitrary forwarded headers. Configure trusted proxies at the web-server
   layer to avoid treating every visitor as one address.
7. Back up the database, uploaded logos and custom themes. Use SQLite's backup
   facility for an active database; copying only the main file can miss WAL data.
8. Keep PHP, the web server and theme source up to date. Test restored backups
   and upgrades in an isolated environment.

## Limits

- No two-factor authentication, SSO, password recovery email or account approval
  workflow is provided. Account recovery is an operator task using the CLI.
- IP-based throttling is not protection against distributed credential attacks;
  shared networks may also share the attempt budget. Use edge rate limiting.
- The HTML sanitizer is deliberately small and conservative. Do not extend its
  allowlist without browser-level security tests. It is not a full rich-text
  editing engine.
- Content owners must trust superadmins and installed themes. Keeping the admin
  layout independent of public themes prevents accidental UI breakage; it does
  not confine the capabilities of PHP theme code.
- SQLite is the primary tested backend. MySQL/PostgreSQL deployment behavior and
  cross-version PHP compatibility need validation on the target platform.

## Reporting vulnerabilities

Do not include credentials, private content or a working attack against a live
site in a public issue. Use the repository's private vulnerability reporting
feature if enabled. Otherwise open a minimal issue requesting a private contact
channel, without publishing exploit details. Include affected revisions and a
minimal reproduction using a disposable database when a private channel is
available.
