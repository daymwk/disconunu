# Sécurité — Disco nunu

## Ce qui est en place (et testé)

| Contrôle | Implémentation | Test |
|---|---|---|
| SQL | 100 % requêtes préparées, `PDO::ATTR_EMULATE_PREPARES=false` | tests unitaires + smoke |
| XSS sortant | `Security::e()` partout dans les vues | smoke (`&lt;script&gt;` rendu) |
| XSS stocké | allowlist de balises à l'écriture (`Security::sanitizeHtml`) : `on*`, `javascript:`, `data:` retirés | unit + smoke |
| CSRF | jeton par session, vérifié sur tout POST, rotation à la connexion | smoke (`POST csrf=fake → 400`) |
| Sessions | HttpOnly, SameSite=Lax, Secure si HTTPS, `session_regenerate_id` à la connexion, empreinte User-Agent (jette la session si UA change) | smoke (header) |
| Mots de passe | argon2id (fallback bcrypt), rehash transparent si l'algo change ; sel en dur 2017 supprimé | unit |
| Brute-force login | 5 tentatives / IP / 15 min (compteur session) | — |
| Chemins | docroot = `public/` seul ; route d'assets thème avec allowlist d'extensions + contrôle `realpath` sous `themes/` | smoke (traversées 404) |
| En-têtes | CSP (`default-src 'self'`), `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Powered-By` retiré | smoke |
| ERREUR 500 | message générique au navigateur, trace dans error_log (sauf `DN_DEBUG=1`) | code |
| URLs | slugs ASCII (translittération + allowlist regex) — plus d'injection via `?idArticle=` | unit |

## Pour un déploiement prod

1. **HTTPS obligatoire** + `DN_SECURE_COOKIES=1`.
2. Derrière un reverse proxy : `X-Powered-By` déjà masqué ; désactiver les logs de requêtes contenant les cookies.
3. `DN_DEBUG=0` (défaut). Ne jamais mettre `var/` ou `config/` sous le docroot.
4. `php bin/disconunu user … --admin` : mot de passe ≥ 10 caractères (contrôle minimal — ajouter zxcvbn si tu veux durcir).
5 `dn_migrations` : versionner le schéma ; sauvegarder `var/disconunu.sqlite` (ou dump PG/MySQL) avant chaque `migrate`.

## Limites assumées (scope "petit CMS")

- Anti-brute-force en session (pas en base) : un attaquant avec un nouveau cookie repart de zéro. Suffisant pour un blog ; à migrer vers une table si exposition publique sérieuse.
- Allowlist HTML maison (pas HTML Purifier) : stricte mais plus faible qu'un parseur complet. Le contenu est écrit par des utilisateurs **connectés** (admin/auteurs), pas du public — le threat model est celui d'un blog à petits auteurs.
- Pas de 2FA, pas de rate-limit global (à faire côté serveur web).
- `style-src 'unsafe-inline'` dans la CSP (les vues posent des styles inline minimes) — à remplacer par des nonces si durcissement.

## Signaler un bug

Ouvre une issue. Les tests qui couvrent ces surfaces : `tests/run.php` (16) + `tests/smoke_http.sh` (18).
