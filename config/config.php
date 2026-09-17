<?php
/**
 * Disco nunu — configuration.
 * Source of truth: environment variables override the defaults below.
 * SQLite is the default engine; mysql/postgres are optional.
 */

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

return [
    'app' => [
        'name'        => env('DN_APP_NAME', 'Disco nunu'),
        'subtitle'    => env('DN_APP_SUBTITLE', 'Simple CMS for blogs'),
        'url'         => env('DN_APP_URL', ''),            // e.g. https://blog.example.com — empty = auto-detect
        'debug'       => env('DN_DEBUG', '0') === '1',
        'timezone'    => env('DN_TIMEZONE', 'UTC'),
        'theme'       => env('DN_THEME', 'teal'),
    ],

    'db' => [
        // sqlite | mysql | pgsql
        'driver'   => env('DN_DB_DRIVER', 'sqlite'),
        'database' => env('DN_DB_DATABASE', __DIR__ . '/../var/disconunu.sqlite'),
        'host'     => env('DN_DB_HOST', 'localhost'),
        'port'     => env('DN_DB_PORT', ''),
        'user'     => env('DN_DB_USER', 'root'),
        'password' => env('DN_DB_PASSWORD', ''),
    ],

    'security' => [
        'session_name'        => 'dn_session',
        'secure_cookies'      => env('DN_SECURE_COOKIES', '0') === '1', // set to 1 behind HTTPS
        'login_max_attempts'  => 5,
        'login_lockout'       => 900,   // seconds
        'content_allow_html'  => true,  // post HTML passes through the sanitizer allowlist
    ],

    'blog' => [
        'posts_per_page' => 5,
    ],
];
