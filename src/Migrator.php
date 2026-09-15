<?php
declare(strict_types=1);

namespace DN;

/**
 * Migrations séquentielles idempotentes (table dn_migrations).
 * SQL = compatible sqlite/mysql/pgsql via Database::type().
 */
final class Migrator
{
    /** @return list<string> migrations appliquées à cette exécution */
    public static function run(): array
    {
        $db  = Database::get();
        $pdo = $db->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS dn_migrations (
            version INTEGER NOT NULL,
            applied_at TEXT NOT NULL
        )');

        $q = $pdo->query('SELECT version FROM dn_migrations ORDER BY version');
        $done = array_column($q->fetchAll(), 'version');
        $done = array_map('intval', $done);

        $applied = [];
        foreach (self::migrations($db) as $version => $stmts) {
            if (in_array($version, $done, true)) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                foreach ($stmts as $sql) {
                    $pdo->exec($sql);
                }
                $ins = $pdo->prepare('INSERT INTO dn_migrations (version, applied_at) VALUES (?, ?)');
                $ins->execute([$version, Database::now()]);
                $pdo->commit();
                $applied[] = "v{$version}";
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("Migration v{$version} échouée : {$e->getMessage()}", 0, $e);
            }
        }
        return $applied;
    }

    /** @return array<int, list<string>> */
    private static function migrations(Database $db): array
    {
        $pk = $db->type('pk');
        $ts = $db->type('ts');

        return [
            1 => [
                "CREATE TABLE dn_user (
                    id {$pk},
                    username VARCHAR(64) NOT NULL UNIQUE,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    role VARCHAR(16) NOT NULL DEFAULT 'author',
                    created_at {$ts} NOT NULL
                )",
                "CREATE TABLE dn_post (
                    id {$pk},
                    user_id INTEGER NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    excerpt VARCHAR(500) NOT NULL DEFAULT '',
                    content TEXT NOT NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'published',
                    published_at {$ts} NULL,
                    updated_at {$ts} NULL
                )",
                "CREATE INDEX dn_post_status_pub ON dn_post (status, published_at)",
                "CREATE TABLE dn_menu (
                    id {$pk},
                    slug VARCHAR(64) NOT NULL UNIQUE,
                    title VARCHAR(64) NOT NULL
                )",
                "CREATE TABLE dn_menu_item (
                    id {$pk},
                    menu_id INTEGER NOT NULL,
                    title VARCHAR(64) NOT NULL,
                    target VARCHAR(255) NOT NULL,
                    sort_order INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE dn_setting (
                    k VARCHAR(64) PRIMARY KEY,
                    v TEXT NOT NULL
                )",
            ],
            // FTS5 = sqlite only ; mysql/pgsql ont leurs propres mécanismes (FULLTEXT/pg_trgm)
            // — gérés en fallback LIKE dans Post::search() (portable, suffisant pour un petit blog).
            ...($db->driver() === 'sqlite' ? self::sqliteFts() : []),
        ];
    }

    /** @return array<int, list<string>> */
    private static function sqliteFts(): array
    {
        return [2 => [
                'CREATE VIRTUAL TABLE IF NOT EXISTS dn_post_fts USING fts5(title, excerpt, content, content=dn_post, content_rowid=id)',
                "CREATE TRIGGER IF NOT EXISTS dn_post_ai AFTER INSERT ON dn_post BEGIN
                    INSERT INTO dn_post_fts (rowid, title, excerpt, content)
                    VALUES (new.id, new.title, new.excerpt, new.content);
                END",
                "CREATE TRIGGER IF NOT EXISTS dn_post_au AFTER UPDATE ON dn_post BEGIN
                    INSERT INTO dn_post_fts (dn_post_fts, rowid, title, excerpt, content)
                    VALUES ('delete', old.id, old.title, old.excerpt, old.content);
                    INSERT INTO dn_post_fts (rowid, title, excerpt, content)
                    VALUES (new.id, new.title, new.excerpt, new.content);
                END",
                "CREATE TRIGGER IF NOT EXISTS dn_post_ad AFTER DELETE ON dn_post BEGIN
                    INSERT INTO dn_post_fts (dn_post_fts, rowid) VALUES ('delete', old.id);
                END",
            ],
        ];
    }
}
