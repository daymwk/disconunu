<?php
declare(strict_types=1);
namespace DN;

/** SQL pagination shared by administrative lists; SQL fragments are internal constants. */
final class Pagination
{
    public static function text(array $input, string $key, string $default = ''): string
    {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : $default;
    }

    public static function like(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }

    public static function query(string $select, string $from, string $order, array $bind, array $input, array $filters, string $path, string $key, callable $map): array
    {
        $pdo = Database::get()->pdo();
        $count = $pdo->prepare('SELECT COUNT(*) ' . $from);
        $count->execute($bind);
        $total = (int)$count->fetchColumn();
        $perPage = max(1, min(100, (int)self::text($input, 'per_page', '20')));
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, (int)self::text($input, 'page', '1')));
        $st = $pdo->prepare($select . ' ' . $from . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        foreach (array_values($bind) as $i => $value) {
            $st->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->bindValue(count($bind) + 1, $perPage, \PDO::PARAM_INT);
        $st->bindValue(count($bind) + 2, ($page - 1) * $perPage, \PDO::PARAM_INT);
        $st->execute();
        $filters['per_page'] = $perPage;
        $paginationUrl = App::baseUrl() . $path . '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986) . '&page={page}';
        return [$key => array_map($map, $st->fetchAll()), 'page'=>$page, 'pages'=>$pages, 'total'=>$total, 'perPage'=>$perPage, 'filters'=>$filters, 'paginationUrl'=>$paginationUrl];
    }
}
