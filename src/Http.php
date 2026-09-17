<?php
declare(strict_types=1);

namespace DN;

/** Security headers, responses, redirects, 404/403 handling. */
final class Http
{
    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: ' . implode('; ', [
            "default-src 'self'",
            "img-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",   // the minimal theme inlines styles; tighten to a nonce-based CSP if needed
            "script-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
        ]));
        header('X-XSS-Protection: 0'); // deprecated: switched off explicitly (CSP does the work)
    }

    /** @param array<string,string> $params */
    public static function query(string $path, array $params = []): string
    {
        $base = App::baseUrl();
        return $params
            ? $base . $path . '?' . http_build_query($params)
            : $base . $path;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . self::query($path), true, 303);
        exit;
    }

    /** Flash messages via session. */
    public static function flash(string $kind, string $msg): void
    {
        App::startSession();
        $_SESSION['flash'][$kind][] = $msg;
    }

    /** @return array<string, list<string>> */
    public static function takeFlash(): array
    {
        App::startSession();
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    public static function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        View::render('error', ['code' => $code, 'message' => $message]);
        exit;
    }
}
