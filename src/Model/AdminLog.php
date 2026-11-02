<?php
declare(strict_types=1);

namespace DN\Model;

use DN\Auth;
use DN\Database;

/**
 * Audit trail of administrative actions. Callers pass identifiers and short
 * labels only: never passwords, CSRF tokens or post bodies.
 * ponytail: no retention, rows grow forever; add a purge (e.g. older than N days) if the table gets large.
 */
final class AdminLog
{
    public const ACTIONS = [
        'login', 'login_failed', 'logout', 'setup',
        'post_create', 'post_update', 'post_delete',
        'category_save', 'category_delete',
        'user_create', 'user_delete', 'user_role',
        'settings', 'logo_upload', 'logo_remove',
        'theme_install', 'theme_activate', 'theme_delete',
    ];

    public int $id;
    public string $createdAt;
    public ?int $userId;
    public string $username;
    public string $ip;
    public string $action;
    public string $target;
    public string $detail;

    /** @param array<string,mixed> $row */
    private function __construct(array $row)
    {
        $this->id        = (int)$row['id'];
        $this->createdAt = (string)$row['created_at'];
        $this->userId    = $row['user_id'] !== null ? (int)$row['user_id'] : null;
        $this->username  = (string)$row['username'];
        $this->ip        = (string)$row['ip'];
        $this->action    = (string)$row['action'];
        $this->target    = (string)$row['target'];
        $this->detail    = (string)$row['detail'];
    }

    /** $username overrides the actor's name (e.g. the login attempted on a failure). */
    public static function record(?User $actor, string $action, string $target = '', string $detail = '', ?string $username = null): void
    {
        Database::get()->pdo()->prepare(
            'INSERT INTO dn_admin_log (created_at, user_id, username, ip, action, target, detail) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            Database::now(), $actor?->id, mb_substr($username ?? $actor?->username ?? '', 0, 64),
            mb_substr(Auth::clientIp(), 0, 45), $action, mb_substr($target, 0, 255), mb_substr($detail, 0, 500),
        ]);
    }

    public static function adminPage(array $input = []): array
    {
        $q = \DN\Pagination::text($input, 'q');
        $action = \DN\Pagination::text($input, 'action');
        if (!in_array($action, self::ACTIONS, true)) $action = '';
        $where = ['1=1']; $bind = [];
        if ($q !== '') {
            $where[] = "(username LIKE ? ESCAPE '!' OR action LIKE ? ESCAPE '!' OR target LIKE ? ESCAPE '!')";
            $bind = array_fill(0, 3, \DN\Pagination::like($q));
        }
        if ($action !== '') { $where[] = 'action = ?'; $bind[] = $action; }
        return \DN\Pagination::query('SELECT *', 'FROM dn_admin_log WHERE ' . implode(' AND ', $where),
            'id DESC', $bind, $input, compact('q', 'action'), '/admin/logs', 'logs', static fn(array $r): self => new self($r));
    }
}
