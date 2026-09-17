<?php
declare(strict_types=1);

// Isolated filesystem, cached settings, no database connection or live session.
use DN\App;
use DN\Http;
use DN\Model\Settings;
use DN\ThemeManager;
use DN\View;

$root = sys_get_temp_dir() . '/dn-theme-services-' . bin2hex(random_bytes(8));
function removeTree(string $path): void {
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') removeTree($path . '/' . $entry);
    }
    rmdir($path);
}
function copyTree(string $source, string $target): void {
    if (is_link($source)) return;
    if (!is_dir($source)) { copy($source, $target); return; }
    mkdir($target, 0700, true);
    foreach (scandir($source) as $entry) {
        if ($entry !== '.' && $entry !== '..') copyTree($source . '/' . $entry, $target . '/' . $entry);
    }
}
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    removeTree($root);
});
foreach (['src', 'config', 'themes/teal'] as $path) copyTree(dirname(__DIR__) . '/' . $path, $root . '/' . $path);
copy(dirname(__DIR__) . '/autoload.php', $root . '/autoload.php');
mkdir($root . '/var/uploads', 0700, true);
mkdir($root . '/sessions', 0700);
session_save_path($root . '/sessions');
putenv('DN_APP_URL=http://example.test');
putenv('DN_DB_DATABASE=' . $root . '/never-open.sqlite');
require $root . '/autoload.php';
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, Settings::DEFAULTS + []);
App::startSession();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function setTheme(string $theme): void {
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, array_replace(Settings::DEFAULTS, ['theme' => $theme]));
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function capture(callable $callback): string {
    ob_start();
    try { $callback(); return (string)ob_get_contents(); }
    finally { ob_end_clean(); }
}
$tests = [];
$tests['admin templates and layout cannot be overridden by public themes'] = static function () use ($root): void {
    mkdir($root . '/themes/custom/admin', 0700, true);
    file_put_contents($root . '/themes/custom/layout.php', '<?php echo "CUSTOM_LAYOUT"; echo $content;');
    file_put_contents($root . '/themes/custom/admin/login.php', 'CUSTOM_ADMIN');
    setTheme('custom');
    $_SERVER['REQUEST_URI'] = '/admin/login';
    $html = capture(static fn() => View::render('admin/login', ['noUsers' => false, 'error' => null]));
    check(!str_contains($html, 'CUSTOM_'), 'active public theme hijacked admin template/layout');
    check(str_contains($html, 'admin-page'), 'bundled administration shell missing');
};

$tests['admin partials use bundled files and preserve flash messages'] = static function () use ($root): void {
    file_put_contents($root . '/themes/teal/admin/probe.php', 'BUNDLED_PARTIAL');
    file_put_contents($root . '/themes/custom/admin/probe.php', 'CUSTOM_PARTIAL');
    Http::flash('success', 'saved once');
    $html = capture(static fn() => View::partial('admin/probe'));
    check($html === 'BUNDLED_PARTIAL', 'active theme hijacked admin partial');
    check(Http::takeFlash() === ['success' => ['saved once']], 'partial consumed flashes');
};

$tests['public theme without a logo uses the unchanged bundled image'] = static function () use ($root): void {
    setTheme('custom');
    check(View::logoUrl() === 'http://example.test/themes/teal/images/logo.jpg', 'missing bundled logo fallback');
    check(hash_file('sha256', $root . '/themes/teal/images/logo.jpg') === hash_file('sha256', dirname(__DIR__) . '/themes/teal/images/logo.jpg'), 'bundled logo bytes changed');
};

$tests['uploaded GIF overrides the theme logo'] = static function () use ($root): void {
    file_put_contents($root . '/var/uploads/logo.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
    try { check(View::logoUrl() === 'http://example.test/uploads/logo.gif', 'GIF upload was ignored'); }
    finally { unlink($root . '/var/uploads/logo.gif'); }
};

$tests['PHP syntax validation uses the CLI binary under FPM'] = static function (): void {
    $method = new ReflectionMethod(ThemeManager::class, 'phpCliBinary');
    $binary = $method->invoke(null, 'fpm-fcgi');
    check($binary === PHP_BINDIR . '/php', 'FPM must not launch PHP_BINARY as its syntax checker');
};

$tests['pagination clamps out-of-range pages and exposes the current page'] = static function (): void {
    foreach ([-99 => 1, 99 => 10, 5 => 5] as $page => $expected) {
        $html = View::pagination($page, 10, '/?q="x"&page={page}');
        check(str_contains($html, 'aria-current="page">' . $expected . '</span>'), 'current page missing or not clamped');
        check(substr_count($html, 'aria-current="page"') === 1, 'multiple current pages');
        check(str_contains($html, '&quot;x&quot;&amp;page='), 'pagination URLs not escaped');
    }
    check(View::pagination(5, 1, '/{page}') === '' && View::pagination(5, 0, '/{page}') === '', 'empty pagination should be omitted');
};

$tests['pagination is bounded even at the largest integer page count'] = static function (): void {
    if (function_exists('pcntl_alarm')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): never { throw new RuntimeException('pagination scanned unbounded page count'); });
        pcntl_alarm(2);
    }
    try {
        foreach ([1, 50, PHP_INT_MAX] as $page) {
            $html = View::pagination($page, PHP_INT_MAX, '/?page={page}');
            check(substr_count($html, '<a ') <= 6, 'too many pagination links');
            check(str_contains($html, 'aria-current="page">' . $page . '</span>'), 'large current page missing');
            check(strlen($html) < 1500, 'unbounded output');
        }
    } finally { if (function_exists('pcntl_alarm')) pcntl_alarm(0); }
};

function themeZip(string $path, array $entries, ?string $symlink = null): void {
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'could not create fixture ZIP');
    foreach ($entries as $entry => $contents) $zip->addFromString($entry, $contents);
    if ($symlink !== null) $zip->setExternalAttributesName($symlink, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    check($zip->close(), 'could not finish fixture ZIP');
}
function rejectArchive(string $path): void {
    $themes = App::rootDir() . '/themes';
    $before = scandir($themes);
    $rejected = false;
    try { ThemeManager::installFromZip($path); }
    catch (RuntimeException) { $rejected = true; }
    check($rejected, 'unsafe ZIP was accepted: ' . basename($path));
    check(scandir($themes) === $before, 'rejected archive left a theme directory');
    check(glob(App::rootDir() . '/var/.theme-install-*') === [], 'rejected archive leaked staging directory');
}
$tests['invalid ZIP entries are rejected atomically rather than silently skipped'] = static function () use ($root): void {
    $layout = ['package/layout.php' => '<?php echo $content;'];
    $cases = [
        'traversal' => $layout + ['package/../escape.php' => 'x'],
        'backslash' => $layout + ['package/..\\escape.php' => 'x'],
        'absolute' => $layout + ['/escape.php' => 'x'],
        'drive' => $layout + ['C:/escape.php' => 'x'],
        'dot-segment' => $layout + ['package/./extra.php' => 'x'],
        'ambiguous' => $layout + ['other/layout.php' => 'x'],
        'ambiguous-flat' => $layout + ['layout.php' => 'x'],
        'outside-root' => $layout + ['elsewhere/a.css' => 'x'],
        'symlink' => $layout + ['package/link.css' => '../teal/layout.php'],
        'file-size' => $layout + ['package/big.css' => str_repeat('a', 2 * 1024 * 1024 + 1)],
        'ignored-file-size' => $layout + ['package/big.txt' => str_repeat('a', 2 * 1024 * 1024 + 1)],
        'aggregate-size' => $layout,
        'entry-count' => $layout,
        'invalid-php' => $layout + ['package/post.php' => '<?php broken syntax !'],
        'missing-layout' => ['package/a.css' => 'body{}'],
        'path-conflict' => $layout + ['package/a.css' => 'x', 'package/a.css/b.css' => 'y'],
    ];
    for ($i = 0; $i < 11; $i++) $cases['aggregate-size']['package/a' . $i . '.css'] = str_repeat('a', 2 * 1024 * 1024);
    for ($i = 0; $i < 1001; $i++) $cases['entry-count']['package/a' . $i . '.css'] = 'a';
    $errors = [];
    foreach ($cases as $name => $entries) {
        $path = $root . '/' . $name . '.zip';
        themeZip($path, $entries, $name === 'symlink' ? 'package/link.css' : null);
        try { rejectArchive($path); }
        catch (Throwable $e) { $errors[] = $name . ': ' . $e->getMessage(); }
        // Isolated fixtures only; clean old implementation's failed rollback for the next case.
        foreach (glob($root . '/themes/package*') ?: [] as $dir) removeTree($dir);
    }
    check($errors === [], implode('; ', $errors));
};

$tests['installation preserves collisions and never executes trusted PHP while validating'] = static function () use ($root): void {
    mkdir($root . '/themes/reserved');
    file_put_contents($root . '/themes/reserved/keep', 'untouched');
    $path = $root . '/valid.zip';
    $layout = '<?php file_put_contents(' . var_export($root . '/executed', true) . ', "bad"); echo $content;';
    themeZip($path, ['reserved/layout.php' => $layout, 'reserved/css/style.css' => 'body{}']);
    $name = ThemeManager::installFromZip($path);
    check($name === 'reserved-2', 'invalid existing directory not preserved');
    check(file_get_contents($root . '/themes/reserved/keep') === 'untouched', 'existing file changed');
    check(!file_exists($root . '/executed'), 'syntax validation executed PHP');
    check(file_get_contents($root . '/themes/' . $name . '/layout.php') === $layout, 'valid layout bytes changed');
    check(ThemeManager::installFromZip($path) === 'reserved-3', 'valid theme collision overwritten');
    themeZip($root . '/flat.zip', ['layout.php' => '<?php echo $content;', 'css/style.css' => 'body{}']);
    check(ThemeManager::installFromZip($root . '/flat.zip') === 'flat', 'flat ZIP not supported');
    check(glob($root . '/var/.theme-install-*') === [], 'staging directory leaked');
};
$tests['parse-invalid installed themes cannot be selected for activation'] = static function () use ($root): void {
    mkdir($root . '/themes/invalid');
    file_put_contents($root . '/themes/invalid/layout.php', '<?php invalid syntax !');
    check(!ThemeManager::exists('invalid'), 'parse-invalid theme advertised as activatable');
    check(ThemeManager::exists('flat'), 'valid theme not activatable');
};

$tests['public previews retain the public shell on admin URLs'] = static function () use ($root): void {
    setTheme('teal');
    file_put_contents($root . '/themes/teal/preview-probe.php', '<?php echo $isAdmin ? "WRONG_ADMIN_FLAG" : "PUBLIC_PREVIEW";');
    $_SERVER['REQUEST_URI'] = '/admin/post/1/preview';
    $html = capture(static fn() => View::render('preview-probe', ['isAdmin' => false]));
    check(str_contains($html, 'PUBLIC_PREVIEW'), 'View failed to provide public isAdmin flag');
    check(!str_contains($html, 'admin-page') && str_contains($html, 'site-header'), 'layout ignored public preview flag');
};
$tests['missing active theme logo resolves to the bundled URL rather than a broken URL'] = static function (): void {
    setTheme('does-not-exist');
    check(View::logoUrl() === 'http://example.test/themes/teal/images/logo.jpg', 'fallback image uses invalid active theme URL');
};

$tests['linked theme directories cannot expose or delete another theme'] = static function () use ($root): void {
    symlink($root . '/themes/flat', $root . '/themes/linked');
    check(!ThemeManager::exists('linked'), 'linked theme allowed activation');
    check(!in_array('linked', ThemeManager::available(), true), 'linked theme listed');
    check(!ThemeManager::removeTheme('linked'), 'linked theme removal followed a directory symlink');
    check(is_file($root . '/themes/flat/layout.php'), 'symlink target deleted');
};
$tests['duplicate ZIP names cannot choose the installed layout'] = static function () use ($root): void {
    $path = $root . '/duplicate.zip';
    themeZip($path, ['dupe/layout.php' => '<?php echo $content;', 'dupe/layout.phq' => 'duplicate']);
    file_put_contents($path, str_replace('dupe/layout.phq', 'dupe/layout.php', file_get_contents($path)));
    rejectArchive($path);
    check(glob($root . '/var/.theme-install-*') === [], 'rejected duplicate leaked staging directory');
};

$tests['numeric theme directories are valid archive roots'] = static function () use ($root): void {
    $path = $root . '/numeric.zip';
    themeZip($path, ['123/' => '', '123/layout.php' => '<?php echo $content;']);
    check(ThemeManager::installFromZip($path) === '123', 'numeric theme root rejected');
};

// New regression cases are added above the runner.
$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
check(!file_exists($root . '/never-open.sqlite'), 'tests unexpectedly opened a database');
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed ? 1 : 0);
