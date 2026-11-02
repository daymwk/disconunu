<?php
declare(strict_types=1);

namespace DN;

use DN\Model\User;

/** Authentication: argon2id/bcrypt, per-attempt brute-force limiting, session regeneration. */
final class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        App::startSession();

        $reservationExpires = self::reserveLoginAttempt();
        if ($reservationExpires === null) {
            return false;
        }

        $user = User::findByUsername($username);
        if ($user === null || !$user->verifyPassword($password)) {
            return false;
        }

        $user->rehashIfNeeded($password);
        // A slow success must not release a failure from a replacement window.
        Database::get()->pdo()->prepare('UPDATE dn_login_throttle SET attempts = attempts - 1 WHERE ip_key = ? AND expires_at = ? AND attempts > 0')
            ->execute([hash('sha256', self::clientIp()), $reservationExpires]);

        // regenerate to defeat session fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;
        return true;
    }

    public static function logout(): void
    {
        App::destroySession();
    }

    public static function user(): ?User
    {
        App::startSession();
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $cached = User::findById($id);
        return $cached;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            Http::flash('info', 'Sign in to access the admin area.');
            Http::redirect('/admin/login');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireAuth();
        $u = self::user();
        if ($u === null || !$u->isAdmin()) {
            Http::abort(403, 'Admins only.');
        }
    }

    public static function requireSuperAdmin(): void
    {
        self::requireAuth();
        if (!Acl::canAdminister(self::user())) {
            Http::abort(403, 'Superadmins only.');
        }
    }

    /** Reserve before verifying: parallel requests must share the same finite budget. */
    private static function reserveLoginAttempt(): ?int
    {
        $db = Database::get();
        $pdo = $db->pdo();
        $sec = App::config()['security'];
        $now = time();
        $key = hash('sha256', self::clientIp());
        $expires = $now + max(1, (int)$sec['login_lockout']);
        // Reserve every in-flight attempt; successful authentication releases only
        // its own reservation and cannot erase previous failed guesses.
        $pdo->exec('CREATE TABLE IF NOT EXISTS dn_login_throttle (
            ip_key VARCHAR(64) PRIMARY KEY,
            attempts INTEGER NOT NULL,
            expires_at BIGINT NOT NULL
        )');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM dn_login_throttle WHERE expires_at <= ?')->execute([$now]);
            $insert = 'INSERT INTO dn_login_throttle (ip_key, attempts, expires_at) VALUES (?, 0, ?)';
            $insert .= $db->driver() === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE ip_key = ip_key'
                : ' ON CONFLICT (ip_key) DO NOTHING';
            $pdo->prepare($insert)->execute([$key, $expires]);
            // One conditional UPDATE is the admission decision, not a racy read/increment.
            $st = $pdo->prepare('UPDATE dn_login_throttle SET attempts = attempts + 1
                WHERE ip_key = ? AND attempts < ? AND expires_at > ?');
            $st->execute([$key, max(1, (int)$sec['login_max_attempts']), $now]);
            $reservationExpires = null;
            if ($st->rowCount() === 1) {
                // Keep the write lock until we read the actual window we reserved.
                $st = $pdo->prepare('SELECT expires_at FROM dn_login_throttle WHERE ip_key = ?');
                $st->execute([$key]);
                $reservationExpires = (int)$st->fetchColumn();
            }
            $pdo->commit();
            return $reservationExpires;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function clientIp(): string
    {
        // REMOTE_ADDR only: no X-Forwarded-For (spoofable; behind a proxy, trust the front layer instead)
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
