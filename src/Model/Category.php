<?php
declare(strict_types=1);

namespace DN\Model;

use DN\Database;

/** Category = a navigable menu entry. Posts attach to 0..n categories. */
final class Category
{
    public int $id;
    public string $slug;
    public string $title;
    public int $sortOrder;
    public bool $visible;

    /** @param array<string,mixed> $row */
    private function __construct(array $row)
    {
        $this->id        = (int)$row['id'];
        $this->slug      = (string)$row['slug'];
        $this->title     = (string)$row['title'];
        $this->sortOrder = (int)$row['sort_order'];
        $this->visible   = (bool)$row['visible'];
    }

    public static function bySlug(string $slug): ?self
    {
        $st = Database::get()->pdo()->prepare('SELECT * FROM dn_category WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row === false ? null : new self($row);
    }

    public static function byId(int $id): ?self
    {
        $st = Database::get()->pdo()->prepare('SELECT * FROM dn_category WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : new self($row);
    }

    /** @return list<self> all, admin order */
    public static function all(): array
    {
        $rows = Database::get()->pdo()
            ->query('SELECT * FROM dn_category ORDER BY sort_order, id')->fetchAll();
        return array_map(static fn(array $r): self => new self($r), $rows);
    }

    /** @return list<self> visible only, for the public nav */
    public static function visible(): array
    {
        $rows = Database::get()->pdo()
            ->query('SELECT * FROM dn_category WHERE visible = 1 ORDER BY sort_order, id')->fetchAll();
        return array_map(static fn(array $r): self => new self($r), $rows);
    }

    public static function create(string $title, ?string $slug = null, bool $visible = true): self
    {
        $slug = Post::slugify($slug !== null && $slug !== '' ? $slug : $title);
        if ($slug === '') {
            $slug = 'category';
        }
        // unique slug
        $pdo = Database::get()->pdo();
        $candidate = $slug;
        for ($i = 2; ; $i++) {
            $st = $pdo->prepare('SELECT id FROM dn_category WHERE slug = ?');
            $st->execute([$candidate]);
            if ($st->fetch() === false) {
                break;
            }
            $candidate = $slug . '-' . $i;
        }
        $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM dn_category')->fetchColumn();
        $st = $pdo->prepare('INSERT INTO dn_category (slug, title, sort_order, visible) VALUES (?,?,?,?)');
        $st->execute([$candidate, $title, $max + 1, $visible ? 1 : 0]);
        $c = self::bySlug($candidate);
        assert($c !== null);
        return $c;
    }

    public function update(string $title, bool $visible): void
    {
        $st = Database::get()->pdo()->prepare('UPDATE dn_category SET title = ?, visible = ? WHERE id = ?');
        $st->execute([$title, $visible ? 1 : 0, $this->id]);
        $this->title = $title;
        $this->visible = $visible;
    }

    public function delete(): void
    {
        $pdo = Database::get()->pdo();
        $pdo->prepare('DELETE FROM dn_post_category WHERE category_id = ?')->execute([$this->id]);
        $pdo->prepare('DELETE FROM dn_category WHERE id = ?')->execute([$this->id]);
    }

    /** @param list<int> $ids replace all category assignments of a post */
    public static function setPostCategories(int $postId, array $ids): void
    {
        $pdo = Database::get()->pdo();
        $pdo->prepare('DELETE FROM dn_post_category WHERE post_id = ?')->execute([$postId]);
        $ins = $pdo->prepare('INSERT INTO dn_post_category (post_id, category_id) VALUES (?,?)');
        foreach (array_unique(array_map('intval', $ids)) as $cid) {
            if ($cid > 0) {
                $ins->execute([$postId, $cid]);
            }
        }
    }

    /** @return list<self> */
    public static function forPost(int $postId): array
    {
        $st = Database::get()->pdo()->prepare(
            'SELECT c.* FROM dn_category c JOIN dn_post_category pc ON pc.category_id = c.id
             WHERE pc.post_id = ? ORDER BY c.sort_order, c.id');
        $st->execute([$postId]);
        return array_map(static fn(array $r): self => new self($r), $st->fetchAll());
    }
}
