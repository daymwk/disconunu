# Security — Disco nunu

Threat model in one line: a small self-hosted CMS whose authors are trusted
(logged-in) people, exposed to the public internet. The interesting attacks are
therefore **stored XSS, CSRF, credential stuffing, path traversal and upload
abuse** — not hostile co-authors.

## Controls in place (and what tests them)

| Control | Implementation | Covered by |
|---|---|---|
| SQL injection | 100% prepared statements, `PDO::ATTR_EMULATE_PREPARES = false` | unit + smoke |
| Reflected XSS | `Security::e()` on every variable printed in a template | smoke (`<script>` rendered escaped) |
| Stored XSS | allowlist sanitizer on write (`Security::sanitizeHtml`): `on*`, `javascript:`, `data:` stripped | unit + smoke |
| CSRF | per-session token, verified on every state-changing request | smoke (`CSRF=fake` → 400) |
| Session fixation | `session_regenerate_id(true)` on login | code |
| Sessions | HttpOnly, SameSite=Lax, Secure when HTTPS, user-agent fingerprint (session dropped if it changes) | smoke (headers) |
| Password storage | Argon2id when available, bcrypt cost 12 otherwise, transparent rehash on login; the 2017 hard-coded salt is gone | unit |
| Login throttling | 5 failed attempts / IP / 15 min | code |
| Path traversal | docroot is `public/` only; theme-asset route restricted to an extension allowlist and confined by `realpath` under `themes/` | smoke (traversals → 404) |
| Theme zip upload | superadmin only, entry names with `..`/absolute paths skipped, per-file size cap, requires `layout.php` | unit (zip-slip attempt) |
| Logo upload | superadmin only, MIME sniffed with `finfo`, extension allowlist, 512 KB cap, stored in `var/uploads` (outside the docroot) | smoke (upload + serve) |
| Authorization | `Acl` — writers reach only their own drafts, admins cannot touch a superadmin's post, only superadmins manage settings/themes/users, no self-demotion of the last superadmin | unit + smoke (403 paths) |
| Headers | CSP `default-src 'self'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Powered-By` removed | smoke |
| Error disclosure | generic 500 body, details only in `error_log` (unless `DN_DEBUG=1`) | code |
| URL handling | ASCII slugs (transliteration + regex allowlist) — no injection through `?idArticle=` | unit |

## Production checklist

1. **HTTPS only**, with `DN_SECURE_COOKIES=1` and `DN_APP_URL` set.
2. `DN_DEBUG=0` (default). Never put `var/`, `config/`, `src/` or `bin/` under the docroot.
3. Keep the document root on `public/`; deny everything above it in the web server config.
4. Writable only where needed: `var/` (database + uploads). Everything else read-only.
5. Back up `var/disconunu.sqlite` (or the MySQL/PostgreSQL dump) before every `migrate`,
   and version `dn_migrations` alongside releases.
6. Rotate the first superadmin password after install (`bin/disconunu password <login>`),
   then add the other accounts with the least privilege that fits (`--role=writer` by default).

## Known limits (deliberate scope of a small CMS)

- Login throttling lives in the session, not the database: an attacker with a fresh
  cookie starts over. Fine for a personal blog — move it to a table before exposing
  a high-value site.
- The HTML allowlist is home-grown (no HTML Purifier): strict, but weaker than a full
  parser. Authors are authenticated, so this is not a public-input surface.
- No 2FA, no global rate limiting (do it at the web server / reverse proxy).
- `style-src 'unsafe-inline'` in the CSP, because the minimal theme inlines a few
  styles; switch to nonces if you need a stricter policy.
- Uploads accept SVG (an SVG can carry script if you open it directly). It is served
  as `image/svg+xml` from `var/uploads`; drop SVG from the allowlist in
  `public/index.php` if your authors are not all trusted.

## Reporting a bug

Open an issue. The suites that guard these surfaces are `tests/run.php` (26 tests)
and `tests/smoke_http.sh` (48 checks) — please include a failing case if you can.
