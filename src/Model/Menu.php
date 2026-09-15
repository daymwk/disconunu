<?php
declare(strict_types=1);

namespace DN\Model;

use DN\Database;

final class Menu
{
    public int $id;
    public string $slug;
    public string $title;

    /** @param array<string,mixed> $row */
    private function __construct(array $row)
    {
        $this->id    = (int)$row['id'];
        $this->slug  = (string)$row['slug'];
        $this->title = (string)$row['title'];
    }

    public static function bySlug(string $slug): ?self
    {
        $st = Database::get()->pdo()->prepare('SELECT * FROM dn_menu WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row === false ? null : new self($row);
    }

    /** @return list<array{id:int,title:string,target:string,sort_order:int}> */
    public function items(): array
    {
        $st = Database::get()->pdo()->prepare(
            'SELECT id, title, target, sort_order FROM dn_menu_item WHERE menu_id = ? ORDER BY sort_order, id');
        $st->execute([$this->id]);
        return $st->fetchAll();
    }

    /** @return list<self> */
    public static function all(): array
    {
        $rows = Database::get()->pdo()->query('SELECT * FROM dn_menu ORDER BY id')->fetchAll();
        return array_map(static fn(array $r): self => new self($r), $rows);
    }

    public static function create(string $slug, string $title): self
    {
        $pdo = Database::get()->pdo();
        $st  = $pdo->prepare('INSERT INTO dn_menu (slug, title) VALUES (?, ?)');
        $st->execute([$slug, $title]);
        $m = self::bySlug($slug);
        assert($m !== null);
        return $m;
    }

    public function addItem(string $title, string $target, int $sort = 0): void
    {
        $st = Database::get()->pdo()->prepare(
            'INSERT INTO dn_menu_item (menu_id, title, target, sort_order) VALUES (?,?,?,?)');
        $st->execute([$this->id, $title, $target, $sort]);
    }

    public function removeItem(int $itemId): void
    {
        $st = Database::get()->pdo()->prepare('DELETE FROM dn_menu_item WHERE id = ? AND menu_id = ?');
        $st->execute([$itemId, $this->id]);
    }
}
