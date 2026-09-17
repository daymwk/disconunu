<?php
declare(strict_types=1);

namespace DN;

use DN\Model\Category;

/**
 * Sequential, idempotent migrations (tracked in `dn_migrations`).
 * Statements are written to work on sqlite, mysql and pgsql via Database::type().
 */
final class Migrator
{
    /** @return list<string> versions applied during this run */
    public static function run(): array
    {
        $db  = Database::get();
        $pdo = $db->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS dn_migrations (
            version INTEGER NOT NULL,
            applied_at TEXT NOT NULL
        )');

        $q = $pdo->query('SELECT version FROM dn_migrations ORDER BY version');
        $done = array_map('intval', array_column($q->fetchAll(), 'version'));

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
                throw new \RuntimeException("Migration v{$version} failed: {$e->getMessage()}", 0, $e);
            }
        }

        self::ensureSearchIndex();
        self::afterMigrate();
        return $applied;
    }

    /**
     * Idempotent setup that must hold on every install (runs after the DDL, on
     * every `migrate`, and is written in portable PHP-on-SQL rather than
     * driver-specific upsert syntax).
     */
    public static function afterMigrate(): void
    {
        // 1. The default category, so the public menu is never empty and every
        //    post has a home. `Category::create` handles slug uniqueness.
        $general = Category::bySlug('general') ?? Category::create('General', 'general', true);

        // 2. Posts with no category yet land in "general" (spec: default category).
        $pdo = Database::get()->pdo();
        $rows = $pdo->query(
            'SELECT p.id FROM dn_post p
             WHERE NOT EXISTS (SELECT 1 FROM dn_post_category pc WHERE pc.post_id = p.id)'
        )->fetchAll();
        foreach ($rows as $r) {
            $pdo->prepare('INSERT INTO dn_post_category (post_id, category_id) VALUES (?, ?)')
                ->execute([(int)$r['id'], $general->id]);
        }

        // 3. Role vocabulary migration: v1 shipped admin/author, v1.1 ships
        //    superadmin/admin/writer. The oldest 'admin' account owns the site.
        $pdo->exec("UPDATE dn_user SET role = 'superadmin' WHERE role = 'admin'");
        $pdo->exec("UPDATE dn_user SET role = 'writer' WHERE role NOT IN ('superadmin','admin','writer')");

        // 4. Legacy 2017 menu tables were replaced by categories.
        $pdo->exec('DROP TABLE IF EXISTS dn_menu_item');
        $pdo->exec('DROP TABLE IF EXISTS dn_menu');
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
                    role VARCHAR(16) NOT NULL DEFAULT 'writer',
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
                "CREATE TABLE dn_setting (
                    k VARCHAR(64) PRIMARY KEY,
                    v TEXT NOT NULL
                )",
                "CREATE TABLE dn_category (
                    id {$pk},
                    slug VARCHAR(64) NOT NULL UNIQUE,
                    title VARCHAR(64) NOT NULL,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    visible INTEGER NOT NULL DEFAULT 1
                )",
                "CREATE TABLE dn_post_category (
                    post_id INTEGER NOT NULL,
                    category_id INTEGER NOT NULL,
                    PRIMARY KEY (post_id, category_id)
                )",
            ],
            // FTS5 = sqlite only; mysql/pgsql get their own mechanisms (FULLTEXT /
            // pg_trgm) — until then Post::search() falls back to LIKE (portable,
            // good enough for a small blog).
            ...($db->driver() === 'sqlite' ? self::sqliteFts() : []),
        ];
    }

    /**
     * (Re)create the SQLite full-text table and its triggers if they are missing.
     * Idempotent — repairs an index dropped or lost by a restore. No-op elsewhere.
     */
    public static function ensureSearchIndex(): bool
    {
        $db = Database::get();
        if ($db->driver() !== 'sqlite') {
            return false;
        }
        foreach (self::sqliteFts()[2] as $sql) {
            $db->pdo()->exec($sql);
        }
        return true;
    }

    /** @return array<int, list<string>> */
    public static function sqliteFts(): array
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
