<?php
declare(strict_types=1);

namespace DN;

use DN\Model\User;

/** Authentification : bcrypt/argon2, anti-brute-force par tentative, régénération de session. */
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
            return false; // verrouillé
        }

        $user = User::findByUsername($username);
        if ($user === null || !$user->verifyPassword($password)) {
            $atts = ['count' => (is_array($atts) ? $atts['count'] : 0) + 1, 'last' => $now];
            return false;
        }

        $user->rehashIfNeeded($password);
        unset($_SESSION[$key]);

        // régénération anti-fixation
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
            Http::flash('info', 'Connecte-toi pour accéder à l’administration.');
            Http::redirect('/admin/login');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireAuth();
        $u = self::user();
        if ($u === null || !$u->isAdmin()) {
            Http::abort(403, 'Réservé aux admins.');
        }
    }

    private static function clientIp(): string
    {
        // REMOTE_ADDR only : pas de X-Forwarded-For (spoofable ; derrière un proxy, faire confiance en amont)
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
