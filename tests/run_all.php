<?php
declare(strict_types=1);

/** Run every regression suite: php tests/run_all.php */
$flags = [];
foreach (['pdo_sqlite', 'sqlite3', 'zip'] as $ext) {
    if (!extension_loaded($ext)) $flags[] = '-d extension=' . $ext;
}
$php = escapeshellarg(PHP_BINARY) . ($flags ? ' ' . implode(' ', $flags) : '');
putenv('BASE');
putenv('DN_DB_DRIVER=sqlite');
chdir(dirname(__DIR__));
$suites = ['run.php', 'security_regression.php', 'theme_services.php', 'http.php', 'admin_ui.php'];
foreach ($suites as $suite) {
    echo "\n>>> php tests/{$suite}\n";
    passthru($php . ' tests/' . $suite, $code);
    if ($code !== 0) exit($code);
}
echo "\nAll " . count($suites) . " suites passed.\n";
