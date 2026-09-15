#!/usr/bin/env bash
# Smoke E2E HTTP — autonome : démarre son propre serveur sur une base SQLite fraîche
# dans un temp dir, seed un admin via bin/disconunu, teste, puis tout arrête.
#   bash tests/smoke_http.sh
# (ou BASE=http://hote:port bash tests/smoke_http.sh pour cibler une instance existante —
#  dans ce mode, le test CSRF-400 est sauté faute de session admin garantie.)
set -u
cd "$(dirname "$0")/.."
ROOT=$(pwd)
PHP=${PHP:-php}
PHPFLAGS=${PHPFLAGS:-}   # ex : '-d extension_dir=/tmp/php-mods/mods -d extension=pdo_sqlite -d extension=sqlite3' si pdo_sqlite n'est pas activé

PW="smoke-admin-pass-9x"
PASS=0; FAIL=0
ok(){ echo "  \033[32m✓\033[0m $1"; PASS=$((PASS+1)); }
ko(){ echo "  \033[31m✗\033[0m $1"; FAIL=$((FAIL+1)); }
check(){ [ "$2" = "$3" ] && ok "$1" || ko "$1 (attendu $3, obtenu $2)"; }

MODE="live"
if [ -z "${BASE:-}" ]; then
  MODE="auto"
  DB=$(mktemp -d)/smoke.sqlite
  PORT=8391
  BASE="http://127.0.0.1:$PORT"
  DN_DB_DATABASE="$DB" $PHP $PHPFLAGS bin/disconunu migrate >/dev/null
  DN_DB_DATABASE="$DB" DN_USER_PASSWORD="$PW" $PHP $PHPFLAGS bin/disconunu user admin_smoke smoke@example.test --admin >/dev/null || { echo "seed admin FAILED"; exit 1; }
  DN_DB_DATABASE="$DB" $PHP $PHPFLAGS -S 127.0.0.1:$PORT -t public public/router.php >/dev/null 2>&1 &
  SRV=$!
  trap 'kill $SRV 2>/dev/null; rm -f "$CJ"' EXIT
  sleep 1
fi
CJ=$(mktemp)

echo "=== Smoke HTTP — $BASE ($MODE) ==="

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
check "GET / -> 200" "$code" "200"

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/themes/teal/css/style.css")
check "CSS du thème -> 200" "$code" "200"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/themes/teal/style.css")
ct=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/themes/teal/style.css")
check "CSS URL raccourcie -> 200 + text/css" "$ct" "text/css; charset=utf-8"

code=$(curl -s --path-as-is -o /dev/null -w '%{http_code}' "$BASE/../config/config.php")
[ "$code" != "200" ] && ok "traversal ../ bloqué ($code)" || ko "traversal ../ SERVÉ"
code=$(curl -s --path-as-is -o /dev/null -w '%{http_code}' "$BASE/themes/../../config/config.php")
[ "$code" != "200" ] && ok "traversal via route thèmes bloqué ($code)" || ko "traversal via thèmes SERVÉ"

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/post/nexiste-pas")
check "article inconnu -> 404" "$code" "404"

code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" --data "title=x&content=y")
check "admin sans session -> 303 login" "$code" "303"

hdr=$(curl -sI "$BASE/" | tr -d '\r')
echo "$hdr" | grep -qi '^Content-Security-Policy:' && ok "CSP présent" || ko "CSP absent"
echo "$hdr" | grep -qi 'X-Powered-By' && ko "X-Powered-By exposé" || ok "X-Powered-By masqué"
echo "$hdr" | grep -qi 'HttpOnly' && ok "cookie HttpOnly" || ko "cookie pas HttpOnly"

CSRF=$(curl -s -c "$CJ" "$BASE/admin/login" | grep -oE 'name="csrf" value="[a-f0-9]{64}' | grep -oE '[a-f0-9]{64}')
[ -n "$CSRF" ] && ok "jeton CSRF émis" || ko "pas de jeton CSRF"

if [ "$MODE" = "auto" ]; then
  code=$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/login" \
    --data-urlencode "csrf=$CSRF" -d "username=admin_smoke&password=$PW")
  check "login admin -> 303 /admin" "$code" "303"

  CSRF=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/post/new" | grep -oE 'name="csrf" value="[a-f0-9]{64}' | grep -oE '[a-f0-9]{64}')
  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" -d "csrf=fake&title=x")
  check "POST CSRF invalide (session admin) -> 400" "$code" "400"

  code=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/post/save" \
    --data-urlencode "csrf=$CSRF" --data-urlencode 'title=Article <script>alert(1)</script> smoke' \
    --data-urlencode 'content=<p onclick="x()">corps</p><a href="javascript:alert(1)">x</a>' \
    --data-urlencode 'status=published')
  check "création article (CSRF ok) -> 303" "$code" "303"

  body=$(curl -s "$BASE/")
  echo "$body" | grep -q 'Article &lt;script&gt;alert(1)&lt;/script&gt;' && ok "XSS échappé à l’affichage" || ko "XSS non échappé"
  echo "$body" | grep -qi 'onclick\|javascript:alert' && ko "XSS présent dans le rendu" || ok "aucun onclick/javascript: rendu"

  body=$(curl -s "$BASE/feed")
  echo "$body" | grep -q '<rss version="2.0">' && ok "RSS well-formed" || ko "RSS cassée"

  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/")
  check "POST / -> 405" "$code" "405"
fi

echo "=== $PASS réussis, $FAIL échoués ==="
[ "$FAIL" -eq 0 ]
