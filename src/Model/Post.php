<?php
declare(strict_types=1);

namespace DN\Model;

use DN\Database;

final class Post
{
    public int $id;
    public int $userId;
    public string $title;
    public string $slug;
    public string $excerpt;
    public string $content;
    public string $status;
    public ?string $publishedAt;
    public ?string $updatedAt;
    public ?string $authorName = null;
    public ?string $authorRole = null;

    /** @param array<string,mixed> $row */
    public function __construct(array $row)
    {
        $this->id          = (int)$row['id'];
        $this->userId      = (int)$row['user_id'];
        $this->title       = (string)$row['title'];
        $this->slug        = (string)$row['slug'];
        $this->excerpt     = (string)$row['excerpt'];
        $this->content     = (string)$row['content'];
        $this->status      = (string)$row['status'];
        $this->publishedAt = $row['published_at'] !== null ? (string)$row['published_at'] : null;
        $this->updatedAt   = $row['updated_at'] !== null ? (string)$row['updated_at'] : null;
        $this->authorName  = isset($row['username']) ? (string)$row['username'] : null;
        $this->authorRole  = isset($row['role']) ? (string)$row['role'] : null;
    }

    private const SELECT_JOINED = 'SELECT p.*, u.username, u.role FROM dn_post p JOIN dn_user u ON u.id = p.user_id';

    public static function findBySlug(string $slug, bool $publishedOnly = true): ?self
    {
        $sql  = self::SELECT_JOINED . ' WHERE p.slug = ?';
        $sql .= $publishedOnly ? " AND p.status = 'published' AND p.published_at <= ?" : '';
        $st = Database::get()->pdo()->prepare($sql);
        $st->execute($publishedOnly ? [$slug, Database::now()] : [$slug]);
        $row = $st->fetch();
        return $row === false ? null : new self($row);
    }

    public static function findById(int $id): ?self
    {
        $st = Database::get()->pdo()->prepare(self::SELECT_JOINED . ' WHERE p.id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : new self($row);
    }

    /** @return list<self> */
    public static function publishedPage(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $st = Database::get()->pdo()->prepare(
            self::SELECT_JOINED . " WHERE p.status = 'published' AND p.published_at <= ?"
            . ' ORDER BY p.published_at DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, Database::now());
        $st->bindValue(2, $perPage, \PDO::PARAM_INT);
        $st->bindValue(3, $offset, \PDO::PARAM_INT);
        $st->execute();
        return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
    }

    public static function countPublished(): int
    {
        $st = Database::get()->pdo()->prepare("SELECT COUNT(*) FROM dn_post WHERE status = 'published' AND published_at <= ?");
        $st->execute([Database::now()]);
        return (int)$st->fetchColumn();
    }

    /** @return list<self> */
    public static function all(int $limit = 500): array
    {
        $st = Database::get()->pdo()->prepare(self::SELECT_JOINED . ' ORDER BY p.id DESC LIMIT ?');
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->execute();
        return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
    }

    /** @return list<self> */
    public static function allByAuthor(int $userId, int $limit = 500): array
    {
        $st = Database::get()->pdo()->prepare(self::SELECT_JOINED . ' WHERE p.user_id = ? ORDER BY p.id DESC LIMIT ?');
        $st->bindValue(1, $userId, \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();
        return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
    }

    /** Filter first, then page in SQL. The actor restriction cannot be overridden by filters. */
    public static function adminPage(array $input = [], ?int $onlyAuthor = null): array
    {
        $q = \DN\Pagination::text($input, 'q');
        $status = \DN\Pagination::text($input, 'status');
        $status = in_array($status, ['draft', 'published'], true) ? $status : '';
        $category = max(0, (int)\DN\Pagination::text($input, 'category'));
        $author = max(0, (int)\DN\Pagination::text($input, 'author'));
        $sort = \DN\Pagination::text($input, 'sort', 'newest');
        $orders = ['newest'=>'p.id DESC', 'oldest'=>'p.id ASC', 'title'=>'p.title ASC, p.id ASC', 'updated'=>'p.updated_at DESC, p.id DESC'];
        if (!isset($orders[$sort])) $sort = 'newest';
        $where = ['1=1']; $bind = [];
        if ($onlyAuthor !== null) { $where[] = 'p.user_id = ?'; $bind[] = $onlyAuthor; }
        if ($author > 0) { $where[] = 'p.user_id = ?'; $bind[] = $author; }
        if ($status !== '') { $where[] = 'p.status = ?'; $bind[] = $status; }
        if ($category > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM dn_post_category pc WHERE pc.post_id = p.id AND pc.category_id = ?)';
            $bind[] = $category;
        }
        if ($q !== '') {
            $where[] = "(p.title LIKE ? ESCAPE '!' OR p.content LIKE ? ESCAPE '!' OR p.excerpt LIKE ? ESCAPE '!')";
            array_push($bind, ...array_fill(0, 3, \DN\Pagination::like($q)));
        }
        return \DN\Pagination::query('SELECT p.*, u.username, u.role',
            'FROM dn_post p JOIN dn_user u ON u.id = p.user_id WHERE ' . implode(' AND ', $where),
            $orders[$sort], $bind, $input, compact('q', 'status', 'category', 'author', 'sort'), '/admin', 'posts', static fn(array $r): self => new self($r));
    }

    /** Published-post count within a category. */
    public static function countPublishedIn(int $categoryId): int
    {
        $st = Database::get()->pdo()->prepare(
            "SELECT COUNT(*) FROM dn_post p JOIN dn_post_category pc ON pc.post_id = p.id
             WHERE pc.category_id = ? AND p.status = 'published' AND p.published_at <= ?");
        $st->execute([$categoryId, Database::now()]);
        return (int)$st->fetchColumn();
    }

    /** @return list<self> */
    public static function publishedPageIn(int $categoryId, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $st = Database::get()->pdo()->prepare(
            self::SELECT_JOINED . " JOIN dn_post_category pc ON pc.post_id = p.id"
            . " WHERE pc.category_id = ? AND p.status = 'published' AND p.published_at <= ?"
            . ' ORDER BY p.published_at DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, $categoryId, \PDO::PARAM_INT);
        $st->bindValue(2, Database::now());
        $st->bindValue(3, $perPage, \PDO::PARAM_INT);
        $st->bindValue(4, $offset, \PDO::PARAM_INT);
        $st->execute();
        return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
    }

    /**
     * Full-text search (FTS5 on sqlite, LIKE elsewhere), published posts only.
     * @return list<self>
     */
    public static function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $db = Database::get();

        if ($db->driver() === 'sqlite' && self::ftsAvailable()) {
            // permissive OR query over the terms, bounded by FTS syntax escaping
            $terms = preg_split('/\s+/', $q) ?: [];
            $match = implode(' OR ', array_map(
                static fn(string $t): string => '"' . str_replace('"', '""', $t) . '"',
                array_slice($terms, 0, 8)
            ));
            $sql = self::SELECT_JOINED
                . " JOIN dn_post_fts f ON f.rowid = p.id"
                . " WHERE dn_post_fts MATCH ? AND p.status = 'published' AND p.published_at <= ?"
                . ' ORDER BY rank LIMIT ?';
            try {
                $st = $db->pdo()->prepare($sql);
                $st->bindValue(1, $match);
                $st->bindValue(2, Database::now());
                $st->bindValue(3, $limit, \PDO::PARAM_INT);
                $st->execute();
                return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
            } catch (\Throwable $e) {
                // A damaged FTS index must never take a page down: log it, repair
                // it in the background of this request, and answer with LIKE.
                error_log('FTS search failed (' . $e->getMessage() . '), rebuilding the index');
                self::rebuildIndex();
            }
        }

        // fallback portable (mysql/pgsql, ou sqlite sans FTS5)
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $st = $db->pdo()->prepare(
            self::SELECT_JOINED . " WHERE p.status = 'published' AND p.published_at <= ? AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)"
            . ' ORDER BY p.published_at DESC LIMIT ?');
        $st->bindValue(1, Database::now());
        $st->bindValue(2, $like);
        $st->bindValue(3, $like);
        $st->bindValue(4, $like);
        $st->bindValue(5, $limit, \PDO::PARAM_INT);
        $st->execute();
        return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
    }

    /**
     * Rebuild the FTS index from the content table (sqlite only). Repairs an
     * index that got out of sync or damaged; a no-op elsewhere.
     */
    public static function rebuildIndex(): bool
    {
        $db = Database::get();
        if ($db->driver() !== 'sqlite') {
            return false;
        }
        // Recreate the table/triggers if they vanished, then re-index everything.
        \DN\Migrator::ensureSearchIndex();
        try {
            $db->pdo()->exec("INSERT INTO dn_post_fts (dn_post_fts) VALUES ('rebuild')");
            return true;
        } catch (\Throwable $e) {
            error_log('FTS rebuild failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function ftsAvailable(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                Database::get()->pdo()->query('SELECT 1 FROM dn_post_fts LIMIT 1');
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function create(int $userId, string $title, string $content, string $excerpt, string $status, ?string $slug = null): self
    {
        $pdo  = Database::get()->pdo();
        $slug = self::uniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now  = Database::now();
        $pub  = $status === 'published' ? $now : null;

        $st = $pdo->prepare('INSERT INTO dn_post (user_id, title, slug, excerpt, content, status, published_at, updated_at)
                             VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$userId, $title, $slug, $excerpt, $content, $status, $pub, $now]);
        $p = self::findById((int)$pdo->lastInsertId());
        assert($p !== null);
        Category::setPostCategories($p->id, []);
        return $p;
    }

    public function update(string $title, string $content, string $excerpt, string $status): void
    {
        if ($status === 'published' && $this->publishedAt === null) {
            $this->publishedAt = Database::now();
        }
        $st = Database::get()->pdo()->prepare(
            'UPDATE dn_post SET title = ?, excerpt = ?, content = ?, status = ?, published_at = ?, updated_at = ? WHERE id = ?');
        $st->execute([$title, $excerpt, $content, $status, $this->publishedAt, Database::now(), $this->id]);
        $this->title = $title;
        $this->content = $content;
        $this->excerpt = $excerpt;
        $this->status = $status;
    }

    public function delete(): void
    {
        $st = Database::get()->pdo()->prepare('DELETE FROM dn_post WHERE id = ?');
        $st->execute([$this->id]);
    }

    /** URL-safe slug, ASCII when transliterable, unique (-2, -3… suffix on collision). */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $slug = self::slugify($title);
        $slug = $slug !== '' ? $slug : 'article';
        $pdo  = Database::get()->pdo();
        $candidate = $slug;
        for ($i = 2; ; $i++) {
            $st = $pdo->prepare('SELECT id FROM dn_post WHERE slug = ?');
            $st->execute([$candidate]);
            $row = $st->fetch();
            if ($row === false || (int)$row['id'] === $ignoreId) {
                return $candidate;
            }
            $candidate = $slug . '-' . $i;
        }
    }

    public static function slugify(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        if (function_exists('transliterator_transliterate')) {
            $t = @\transliterator_transliterate('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $s);
            if (is_string($t) && $t !== '') {
                $s = $t;
            }
        }
        // table manuelle si intl absent
        $s = strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'œ' => 'oe', 'æ' => 'ae']);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }
}
