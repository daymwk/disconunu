# Disco nunu

Petit CMS pour blogs et sites simples — né en 2017 (PHP/Bootstrap 3/MySQL), ressuscité en 2026 (PHP 8.5, SQLite par défaut, thèmes).

## Lancer (dev)

```bash
php bin/disconunu migrate                 # crée var/disconunu.sqlite + schéma
php bin/disconunu user alice alice@x.y --admin   # premier admin
php -S 127.0.0.1:8080 -t public public/router.php
```

Va sur http://127.0.0.1:8080/admin — le premier lancement propose de créer le compte admin. C'est tout.

## Lancer (prod)

- Docroot = **`public/` uniquement** (le code est hors racine web). Le `.htaccess` fourni route tout vers `index.php` (Apache ; nginx : `try_files $uri /index.php?$query_string;`).
- Config par variables d'environnement (`DN_DB_DRIVER=sqlite|mysql|pgsql`, `DN_DB_*`, `DN_APP_URL`, `DN_THEME`, `DN_DEBUG=0` par défaut, `DN_SECURE_COOKIES=1` derrière HTTPS).
- `php bin/disconunu migrate` à chaque déploiement.

## Architecture

```
public/index.php      front controller unique (+ assets de thème)
public/router.php     routeur php -S (dev seulement)
src/                  App, Router, Database (sqlite/mysql/pgsql), Migrator,
                      Auth, Security, Http, View + Model/{Post,User,Menu}
themes/teal/          thème de base (le vert-bleu #29d9c2 de 2017, CSS moderne)
tests/                tests/run.php (unit, sans dépendance) + tests/smoke_http.sh (E2E HTTP)
bin/disconunu         CLI : migrate | user | password
legacy-2017/          l'ancienne version 2017, archivée
var/                  base SQLite (gitignoré)
```

- **Portable** : un seul schéma, traduit par `Database::type()` (sqlite AUTOINCREMENT / mysql AUTO_INCREMENT / pgsql BIGSERIAL). FTS5 activé sur sqlite uniquement, recherche LIKE ailleurs.
- **Minimaliste** : zéro dépendance, zéro framework JS, un seul CSS par thème.
- **Thèmes** : `DN_THEME=nom` → `themes/nom/` (layout.php + templates + css/). Le thème doit fournir layout/home/post/search/error/admin/{login,dashboard,post-edit}.

## Sécurité

Voir [SECURITY.md](SECURITY.md). Résumé : requêtes 100 % préparées, échappement sortant systématique, allowlist HTML à l'écriture (scripts/`on*`/`javascript:`/`data:` retirés), CSRF sur tout POST, sessions durcies (HttpOnly, SameSite=Lax, régénération, empreinte d'agent), argon2id avec rehash transparent, anti-brute-force, CSP + headers, docroot isolé, pas de `X-Powered-By`.

## Lancer les tests

```bash
php tests/run.php                                # 16 tests unitaires (SQLite temp)
bash tests/smoke_http.sh                         # 18 tests E2E (démarre son propre serveur)
# si pdo_sqlite n'est pas activé dans le php.ini système :
PHPFLAGS='-d extension=pdo_sqlite -d extension=sqlite3' php tests/run.php
```

## Différences avec 2017 (pour la mémoire)

- `config.php` → `config/config.php` (env vars, plus de mot de passe en dur).
- `dbConnect.php`/`select.php`/`insert.php` → `src/Database.php` + modèles.
- Bootstrap 3 + font-awesome → CSS maison (thème `teal`), aucune ressource externe.
- `get_article.php?idArticle=N` → URLs propres `/post/<slug>` (le `.htaccess` 2017 était une ébauche de ça).
- Table `ARTICLE` → `dn_post` (slug, statut brouillon/publié, extraits), `USER` → `dn_user` (argon2id, rôles).
