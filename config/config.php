<?php
/**
 * Disco nunu — configuration.
 * Source de vérité : les variables d'environnement prennent le pas sur les défauts.
 * SQLite est le moteur par défaut ; mysql/postgres optionnels.
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
        'url'         => env('DN_APP_URL', ''),            // ex: https://blog.example.com — vide = auto-détect
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
        'secure_cookies'      => env('DN_SECURE_COOKIES', '0') === '1', // mettre à 1 derrière HTTPS
        'login_max_attempts'  => 5,
        'login_lockout'       => 900,   // secondes
        'content_allow_html'  => true,  // les posts des auteurs passent par l'allowlist de sanitizeHtml()
    ],

    'blog' => [
        'posts_per_page' => 5,
    ],
];
