<?php
declare(strict_types=1);

namespace DN\Model;

use DN\Database;
use PDO;

final class User
{
    public int $id;
    public string $username;
    public string $email;
    public string $passwordHash;
    public string $role;
    public string $createdAt;

    /** @param array<string,mixed> $row */
    private function __construct(array $row)
    {
        $this->id           = (int)$row['id'];
        $this->username     = (string)$row['username'];
        $this->email        = (string)$row['email'];
        $this->passwordHash = (string)$row['password_hash'];
        $this->role         = (string)$row['role'];
        $this->createdAt    = (string)$row['created_at'];
    }

    /** @return array<string,mixed>|null */
    private static function row(string $sql, array $bind): ?array
    {
        $st = Database::get()->pdo()->prepare($sql);
        $st->execute($bind);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function findByUsername(string $username): ?self
    {
        $row = self::row('SELECT * FROM dn_user WHERE username = ?', [$username]);
        return $row ? new self($row) : null;
    }

    public static function findById(int $id): ?self
    {
        $row = self::row('SELECT * FROM dn_user WHERE id = ?', [$id]);
        return $row ? new self($row) : null;
    }

    public static function emailExists(string $email): bool
    {
        $st = Database::get()->pdo()->prepare('SELECT 1 FROM dn_user WHERE email = ?');
        $st->execute([$email]);
        return (bool)$st->fetchColumn();
    }

    public static function countAll(): int
    {
        return (int)Database::get()->pdo()->query('SELECT COUNT(*) FROM dn_user')->fetchColumn();
    }

    /** @return list<self> */
    public static function all(): array
    {
        $rows = Database::get()->pdo()->query('SELECT * FROM dn_user ORDER BY id')->fetchAll();
        return array_map(static fn(array $r): self => new self($r), $rows);
    }

    /** @return int number of accounts holding a given role */
    public static function countByRole(string $role): int
    {
        $st = Database::get()->pdo()->prepare('SELECT COUNT(*) FROM dn_user WHERE role = ?');
        $st->execute([$role]);
        return (int)$st->fetchColumn();
    }

    /**
     * Delete the account. Callers must go through Acl::canDeleteUser first —
     * the model deliberately does not know who is asking.
     */
    public function delete(): void
    {
        Database::get()->pdo()->prepare('DELETE FROM dn_user WHERE id = ?')->execute([$this->id]);
    }

    public function setRole(string $role): void
    {
        if (!\DN\Acl::isValidRole($role)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }
        $st = Database::get()->pdo()->prepare('UPDATE dn_user SET role = ? WHERE id = ?');
        $st->execute([$role, $this->id]);
        $this->role = $role;
    }

    /** Modern hashing: argon2id when available, bcrypt (cost 12) otherwise. */
    public static function hashPassword(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }

    public function verifyPassword(string $plain): bool
    {
        return password_verify($plain, $this->passwordHash);
    }

    /** Transparently rehash on login when the algorithm or cost changed. */
    public function rehashIfNeeded(string $plain): void
    {
        if (password_needs_rehash($this->passwordHash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $st = Database::get()->pdo()->prepare('UPDATE dn_user SET password_hash = ? WHERE id = ?');
            $st->execute([self::hashPassword($plain), $this->id]);
            $this->passwordHash = self::hashPassword($plain);
        }
    }

    public static function create(string $username, string $email, string $plain, string $role = 'author'): self
    {
        $pdo = Database::get()->pdo();
        $st  = $pdo->prepare('INSERT INTO dn_user (username, email, password_hash, role, created_at) VALUES (?,?,?,?,?)');
        $st->execute([$username, $email, self::hashPassword($plain), $role, Database::now()]);
        $u = self::findById((int)$pdo->lastInsertId());
        assert($u !== null);
        return $u;
    }

    public function isAdmin(): bool
    {
        return \DN\Acl::canPublish($this);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === \DN\Acl::SUPERADMIN;
    }
}
