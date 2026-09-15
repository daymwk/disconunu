<?php
/** Autoloader PSR-4 minimal — no composer required. DN\ => src/ */
spl_autoload_register(function (string $class): void {
    $prefix = 'DN\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
