#!/usr/bin/env bash
# End-to-end HTTP smoke test — self-contained: boots its own PHP server on a
# throwaway SQLite database, seeds accounts, exercises public + admin flows,
# then tears everything down.
#
#   bash tests/smoke_http.sh
#   PHPFLAGS='-d extension=pdo_sqlite -d extension=sqlite3' bash tests/smoke_http.sh
#   BASE=http://host:port bash tests/smoke_http.sh   # target a running instance
#       (in that mode the seeded-account tests are skipped: no guaranteed admin session)
set -u
cd "$(dirname "$0")/.."
ROOT=$(pwd)
PHP=${PHP:-php}

# If the sqlite PDO driver isn't compiled in, load the shared modules.
if [ -z "${PHPFLAGS:-}" ] && ! "$PHP" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
  PHPFLAGS='-d extension=pdo_sqlite -d extension=sqlite3'
fi
PHPFLAGS=${PHPFLAGS:-}

ADMIN_PW='smoke-admin-pass-9x'
WRITER_PW='smoke-writer-pass-9x'
PASS=0; FAIL=0
ok(){ echo "  \033[32m✓\033[0m $1"; PASS=$((PASS+1)); }
ko(){ echo "  \033[31m✗\033[0m $1"; FAIL=$((FAIL+1)); }
check(){ [ "$2" = "$3" ] && ok "$1" || ko "$1 (expected $3, got $2)"; }
# Admin pages hold several forms (one CSRF token each): always take the FIRST one.
csrf(){ curl -s -b "$1" -c "$1" "$BASE$2" | grep -oE 'name="csrf" value="[a-f0-9]{64}' | grep -oE '[a-f0-9]{64}' | head -1; }
# Name of the theme the settings screen reports as active.
active_theme(){ curl -s -b "$1" "$BASE/admin/settings" | grep -oE '<option value="[A-Za-z0-9_-]+" selected>' | head -1 | sed -e 's/.*value="//' -e 's/".*//'; }

MODE="live"
DB=""
if [ -z "${BASE:-}" ]; then
  MODE="auto"
  TMPD=$(mktemp -d)
  DB="$TMPD/smoke.sqlite"
  PORT=8391
  BASE="http://127.0.0.1:$PORT"
  UPLOADED_LOGO=""

  cleanup() {
    kill "$SRV" 2>/dev/null
    rm -f "$CJ" "$CJ2" "$TMPD/logo.png" "$TMPD/theme.zip"
    # the app writes uploads/themes into the repo: undo that
    rm -f "$ROOT/var/uploads/logo."* 2>/dev/null
    if [ -n "${INSTALLED_THEME:-}" ]; then
      rm -rf "$ROOT/themes/$INSTALLED_THEME" 2>/dev/null
    fi
    rm -rf "$TMPD"
  }

  DN_DB_DATABASE="$DB" $PHP $PHPFLAGS bin/disconunu migrate >/dev/null
  DN_DB_DATABASE="$DB" DN_USER_PASSWORD="$ADMIN_PW" $PHP $PHPFLAGS bin/disconunu user admin_smoke smoke@example.test --role=superadmin >/dev/null \
    || { echo "seeding the admin failed"; exit 1; }
  DN_DB_DATABASE="$DB" DN_USER_PASSWORD="$WRITER_PW" $PHP $PHPFLAGS bin/disconunu user writer_smoke writer@example.test --role=writer >/dev/null \
    || { echo "seeding the writer failed"; exit 1; }
  DN_DB_DATABASE="$DB" $PHP $PHPFLAGS -d display_errors=1 -S 127.0.0.1:$PORT -t public public/router.php >"$TMPD/server.log" 2>&1 &
  SRV=$!
  CJ=$(mktemp); CJ2=$(mktemp)
  trap cleanup EXIT
  sleep 1
else
  CJ=$(mktemp); CJ2=$(mktemp)
fi

echo "=== HTTP smoke — $BASE ($MODE) ==="

# ---------- public ----------
check "GET / -> 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" "200"
check "theme CSS -> 200 + text/css" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/themes/teal/css/style.css")" "text/css; charset=utf-8"
check "short CSS URL -> 200 + text/css" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/themes/teal/style.css")" "text/css; charset=utf-8"

code=$(curl -s --path-as-is -o /dev/null -w '%{http_code}' "$BASE/../config/config.php")
[ "$code" != "200" ] && ok "path traversal ../ blocked ($code)" || ko "path traversal ../ SERVED"
code=$(curl -s --path-as-is -o /dev/null -w '%{http_code}' "$BASE/themes/../../config/config.php")
[ "$code" != "200" ] && ok "traversal through the theme route blocked ($code)" || ko "traversal through themes SERVED"

check "unknown post -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/post/nope-does-not-exist")" "404"
check "unknown category -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/category/nope")" "404"
check "default category /category/general -> 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/category/general")" "200"
check "POST /admin/post/save without session -> 303" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" --data 'title=x&content=y')" "303"
check "POST / -> 405" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/")" "405"

hdr=$(curl -sI "$BASE/" | tr -d '\r')
echo "$hdr" | grep -qi '^Content-Security-Policy:' && ok "CSP header present" || ko "CSP header missing"
echo "$hdr" | grep -qi 'X-Powered-By' && ko "X-Powered-By leaked" || ok "X-Powered-By hidden"
echo "$hdr" | grep -qi 'HttpOnly' && ok "session cookie is HttpOnly" || ko "cookie not HttpOnly"

# ---------- admin ----------
CSRF=$(csrf "$CJ" /admin/login)
[ -n "$CSRF" ] && ok "CSRF token issued" || ko "no CSRF token"

if [ "$MODE" = "auto" ]; then
  code=$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/login" \
    --data-urlencode "csrf=$CSRF" -d "username=admin_smoke&password=$ADMIN_PW")
  check "admin login -> 303" "$code" "303"

  for p in /admin /admin/categories /admin/settings /admin/users /admin/post/new; do
    check "GET $p -> 200" "$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' "$BASE$p")" "200"
  done

  CSRF=$(csrf "$CJ" /admin/post/new)
  check "bad CSRF on POST -> 400" "$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" -d 'csrf=fake&title=x')" "400"

  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" \
    --data-urlencode "csrf=$CSRF" --data-urlencode 'title=Smoke <script>alert(1)</script> post' \
    --data-urlencode 'content=<p onclick="x()">body</p><a href="javascript:alert(1)">x</a>' \
    --data-urlencode 'status=published')
  check "create post (valid CSRF) -> 303" "$code" "303"

  body=$(curl -s "$BASE/")
  echo "$body" | grep -q 'Smoke &lt;script&gt;alert(1)&lt;/script&gt; post' && ok "XSS escaped on render" || ko "XSS not escaped"
  echo "$body" | grep -qi 'onclick\|javascript:alert' && ko "XSS present in rendered HTML" || ok "no onclick/javascript: in render"

  # category creation + public listing
  CSRF=$(csrf "$CJ" /admin/categories)
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/categories/save" \
    --data-urlencode "csrf=$CSRF" --data-urlencode 'title=Smoke category' --data-urlencode 'visible=1')
  check "create category -> 303" "$code" "303"
  check "new category page -> 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/category/smoke-category")" "200"

  # posts per page + windowed pagination (needs more than 2 pages)
  CSRF=$(csrf "$CJ" /admin/settings)
  curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/settings" --data-urlencode "csrf=$CSRF" \
    -d 'site_title=Smoke blog' -d 'posts_per_page=1' -d 'rss_enabled=1' -d 'theme=teal'
  for i in 1 2 3 4 5; do
    CSRF2=$(csrf "$CJ" /admin/post/new)
    curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/post/save" --data-urlencode "csrf=$CSRF2" \
      --data-urlencode "title=Windowed post $i" --data-urlencode 'content=<p>body</p>' -d 'status=published'
  done
  body=$(curl -s "$BASE/")
  echo "$body" | grep -q 'class="pagination"' && ok "pagination rendered" || ko "no pagination block"
  echo "$body" | grep -q 'pagination-gap' && ok "pagination is windowed (… gap)" || ko "pagination not windowed"
  echo "$body" | grep -q 'href="[^"]*page=2"' && ok "pagination links to page 2" || ko "no page-2 link"
  [ "$(echo "$body" | grep -c 'Smoke blog')" -ge 1 ] && ok "settings applied (site title rendered)" || ko "site title not applied"

  # RSS toggle
  [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/feed")" = "200" ] && ok "RSS enabled -> 200" || ko "RSS not serving"
  CSRF=$(csrf "$CJ" /admin/settings)
  curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/settings" --data-urlencode "csrf=$CSRF" \
    -d 'site_title=Smoke blog' -d 'posts_per_page=1' -d 'theme=teal'   # rss_enabled omitted = off
  check "RSS disabled -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/feed")" "404"
  CSRF=$(csrf "$CJ" /admin/settings)
  curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/settings" --data-urlencode "csrf=$CSRF" \
    -d 'site_title=Smoke blog' -d 'posts_per_page=5' -d 'rss_enabled=1' -d 'theme=teal'
  check "RSS re-enabled -> 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/feed")" "200"
  body=$(curl -s "$BASE/feed")
  echo "$body" | grep -q '<rss version="2.0">' && ok "RSS is well-formed" || ko "RSS malformed"

  # logo upload
  printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==' | base64 -d > "$TMPD/logo.png"
  CSRF=$(csrf "$CJ" /admin/settings)
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/logo" -F "csrf=$CSRF" -F "logo=@$TMPD/logo.png;type=image/png")
  check "logo upload -> 303" "$code" "303"
  check "uploaded logo served -> 200 image/png" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/uploads/logo.png")" "image/png"
  curl -s "$BASE/" | grep -q '/uploads/logo.png' && ok "logo shown in the layout" || ko "logo not used in the layout"

  # theme pack install
  $PHP $PHPFLAGS -r '$z=new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $z->addFromString("smoketheme/layout.php", "<!DOCTYPE html><html><body><?= \$content ?><footer>SMOKETHEME</footer></body></html>");
    $z->close();' "$TMPD/theme.zip"
  CSRF=$(csrf "$CJ" /admin/settings)
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/theme" -F "csrf=$CSRF" -F "themezip=@$TMPD/theme.zip")
  check "theme zip install -> 303" "$code" "303"
  INSTALLED_THEME=$(active_theme "$CJ")
  if curl -s "$BASE/" | grep -q 'SMOKETHEME'; then
    ok "installed theme is active (as “$INSTALLED_THEME”)"
  else
    ko "theme not applied -> $(curl -s -b "$CJ" "$BASE/admin/settings" | grep -oE 'flash-error[^<]*<[^>]*>[^<]{0,120}' | head -1)"
  fi

  # the bundled fallback cannot be deleted
  CSRF=$(csrf "$CJ" /admin/settings)
  curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/theme/teal/delete" --data-urlencode "csrf=$CSRF"
  curl -s -b "$CJ" "$BASE/admin/settings" | grep -q '<code>teal</code>' && ok "bundled theme protected from deletion" || ko "bundled theme deleted"

  # back to the bundled theme, then delete the installed one for real
  CSRF=$(csrf "$CJ" /admin/settings)
  curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/settings" --data-urlencode "csrf=$CSRF" -d 'theme=teal'
  curl -s "$BASE/" | grep -q 'SMOKETHEME' && ko "theme switch back failed" || ok "theme switched back to teal"
  CSRF=$(csrf "$CJ" /admin/settings)
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/theme/$INSTALLED_THEME/delete" --data-urlencode "csrf=$CSRF")
  check "uninstall theme -> 303" "$code" "303"
  curl -s -b "$CJ" "$BASE/admin/settings" | grep -q "<code>$INSTALLED_THEME</code>" && ko "theme still listed after delete" || ok "theme removed from the list"
  INSTALLED_THEME=""   # nothing left for the cleanup trap

  # shared admin navigation on every screen
  for p in /admin /admin/categories /admin/users /admin/settings /admin/post/new; do
    curl -s -b "$CJ" "$BASE$p" | grep -q 'admin-nav' && ok "admin nav present on $p" || ko "admin nav missing on $p"
  done

  # footer text is editable
  CSRF=$(csrf "$CJ" /admin/settings)
  curl -s -b "$CJ" -o /dev/null -X POST "$BASE/admin/settings" --data-urlencode "csrf=$CSRF" \
    -d 'site_title=Smoke blog' -d 'footer_text=Smoke footer 42' -d 'posts_per_page=5' -d 'rss_enabled=1' -d 'theme=teal'
  curl -s "$BASE/" | grep -q 'Smoke footer 42' && ok "footer text applied" || ko "footer text not applied"

  # dashboard status filter
  curl -s -b "$CJ" "$BASE/admin?status=draft" | grep -q 'Filtered:' && ok "dashboard filters by status" || ko "dashboard filter broken"

  # user management: create then delete
  CSRF=$(csrf "$CJ" /admin/users)
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/users/create" \
    --data-urlencode "csrf=$CSRF" -d 'username=smoke_extra&email=smoke_extra@example.test&password=smoke-extra-pass-1&role=writer')
  check "create user from the admin -> 303" "$code" "303"
  users_page=$(curl -s -b "$CJ" "$BASE/admin/users")
  echo "$users_page" | grep -q 'smoke_extra' && ok "new user listed" || ko "new user missing"
  echo "$users_page" | grep -q 'smoke_extra</strong>' && echo "$users_page" | grep -qE 'badge-role">writer' && ok "new user has the writer role" || ko "role wrong"

  CSRF=$(csrf "$CJ" /admin/users)
  DUID=$(echo "$users_page" | grep -oE '/admin/users/[0-9]+/delete' | tail -1 | grep -oE '[0-9]+')
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/users/$DUID/delete" --data-urlencode "csrf=$CSRF")
  check "delete user -> 303" "$code" "303"
  curl -s -b "$CJ" "$BASE/admin/users" | grep -q 'smoke_extra' && ko "user still listed after delete" || ok "user deleted"
  # the superadmin cannot delete their own account (no delete form on their row)
  check "no self-delete button for the acting superadmin" "$(curl -s -b "$CJ" "$BASE/admin/users" | grep -c '/admin/users/[0-9]*/delete')" "1"

  # writer ACL
  CSRF=$(csrf "$CJ2" /admin/login)
  code=$(curl -s -b "$CJ2" -c "$CJ2" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/login" \
    --data-urlencode "csrf=$CSRF" -d "username=writer_smoke&password=$WRITER_PW")
  check "writer login -> 303" "$code" "303"
  check "writer can open the dashboard -> 200" "$(curl -s -b "$CJ2" -c "$CJ2" -o /dev/null -w '%{http_code}' "$BASE/admin")" "200"
  check "writer cannot open settings -> 403" "$(curl -s -b "$CJ2" -c "$CJ2" -o /dev/null -w '%{http_code}' "$BASE/admin/settings")" "403"
  check "writer cannot open categories -> 403" "$(curl -s -b "$CJ2" -c "$CJ2" -o /dev/null -w '%{http_code}' "$BASE/admin/categories")" "403"
  CSRF3=$(csrf "$CJ2" /admin/post/new)
  code=$(curl -s -b "$CJ2" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" \
    --data-urlencode "csrf=$CSRF3" --data-urlencode 'title=Writer tried to publish' \
    --data-urlencode 'content=<p>nope</p>' -d 'status=published')
  check "writer submit -> 303" "$code" "303"
  dash=$(curl -s -b "$CJ2" -c "$CJ2" "$BASE/admin")
  echo "$dash" | grep -q 'Writer tried to publish' && ok "writer post saved" || ko "writer post missing"
  echo "$dash" | grep -q 'badge-draft' && ok "writer post forced to draft" || ko "writer post was published (ACL breach)"
  check "writer post absent from the public site" "$(curl -s "$BASE/" | grep -c 'Writer tried to publish')" "0"
fi

echo "=== $PASS passed, $FAIL failed ==="
if [ "$FAIL" -gt 0 ] && [ -n "${TMPD:-}" ] && grep -qiE 'Fatal|Uncaught|PHP (Warning|Notice)' "$TMPD/server.log" 2>/dev/null; then
  echo "--- server log (errors) ---"
  grep -iE 'Fatal|Uncaught|PHP (Warning|Notice)' "$TMPD/server.log" | head -12
fi
[ "$FAIL" -eq 0 ]
