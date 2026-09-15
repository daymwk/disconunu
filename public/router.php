<?php
/**
 * Routeur de développement pour `php -S 127.0.0.1:8080 -t public public/router.php`.
 * En production, Apache/nginx route tout vers public/index.php (voir .htaccess).
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// fichiers statiques réels sous public/ (robots.txt, favicons…)
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
