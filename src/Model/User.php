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

    /** Hash moderne : argon2id si dispo, sinon bcrypt (coût 12). */
    public static function hashPassword(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }

    public function verifyPassword(string $plain): bool
    {
        return password_verify($plain, $this->passwordHash);
    }

    /** Recalcule le hash à chaud si l'algo/coût a changé (rehash transparent). */
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
        return $this->role === 'admin';
    }
}
