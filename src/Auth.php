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

        // anti-brute-force : compteur en session (le strict maximal sans table ; suffisant pour le scope)
        $sec  = App::config()['security'];
        $key  = 'login_attempts_' . self::clientIp();
        $now  = time();
        $atts = &$_SESSION[$key];
        if (is_array($atts) && $atts['count'] >= (int)$sec['login_max_attempts'] && ($now - $atts['last']) < (int)$sec['login_lockout']) {
            return false; // locked out
        }

        $user = User::findByUsername($username);
        if ($user === null || !$user->verifyPassword($password)) {
            $atts = ['count' => (is_array($atts) ? $atts['count'] : 0) + 1, 'last' => $now];
            return false;
        }

        $user->rehashIfNeeded($password);
        unset($_SESSION[$key]);

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

    private static function clientIp(): string
    {
        // REMOTE_ADDR only: no X-Forwarded-For (spoofable; behind a proxy, trust the front layer instead)
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
