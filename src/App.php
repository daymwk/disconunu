<?php
declare(strict_types=1);

namespace DN;

/**
 * Application bootstrap: config, error handling, hardened sessions.
 */
final class App
{
    public const VERSION = '1.1.0';

    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$config === null) {
            /** @var array<string,mixed> $cfg */
            $cfg = require __DIR__ . '/../config/config.php';
            self::$config = $cfg;
            date_default_timezone_set((string)$cfg['app']['timezone']);
        }
        return self::$config;
    }

    public static function boot(): void
    {
        $cfg = self::config();
        $debug = (bool)$cfg['app']['debug'];

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_exception_handler(static function (\Throwable $e) use ($debug): void {
            error_log((string)$e);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $debug
                ? "Server error 500\n" . $e . "\n"
                : "Internal server error. (500)";
        });
    }

    /** Absolute path of the project root. */
    public static function rootDir(): string
    {
        return dirname(__DIR__);
    }

    /** Absolute base URL (config app.url, otherwise auto-detected). */
    public static function baseUrl(): string
    {
        $cfg = self::config();
        $url = (string)$cfg['app']['url'];
        if ($url !== '') {
            return rtrim($url, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $host  = htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? 'localhost'), ENT_QUOTES, 'UTF-8');
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /** Start a hardened session (HttpOnly, SameSite, agent fingerprint, rotation on login). */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = self::config()['security'];
        $secure = (bool)$cfg['secure_cookies']
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_name((string)$cfg['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Agent fingerprint: if UA/accept-language change mid-session, drop it.
        $fp = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if (!empty($_SESSION['fp']) && !hash_equals($_SESSION['fp'], $fp)) {
            self::destroySession();
            self::startSession();
        }
        $_SESSION['fp'] = $fp;
    }

    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            foreach ([true, false] as $secure) {
                setcookie(session_name(), '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
            $_SESSION = [];
        }
    }
}
