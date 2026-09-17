<?php
declare(strict_types=1);

namespace DN {
    function time(): int
    {
        return $GLOBALS['authTestNow'] ?? \time();
    }
}

namespace DN\Model {
    function password_verify(string $password, string $hash): bool
    {
        $valid = \password_verify($password, $hash);
        $afterVerify = $GLOBALS['authTestAfterVerify'] ?? null;
        unset($GLOBALS['authTestAfterVerify']);
        if ($afterVerify !== null) {
            $afterVerify();
        }
        return $valid;
    }
}

namespace {
/** Run: php -d extension=pdo_sqlite -d extension=sqlite3 tests/security_regression.php */
require __DIR__ . '/../autoload.php';

use DN\App;
use DN\Auth;
use DN\Database;
use DN\Migrator;
use DN\Model\User;
use DN\Security;

// Buffer output so real PHP sessions can be exercised after assertions.
ob_start();
$failures = 0;
$passed = 0;
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function test(string $name, callable $run): void
{
    global $failures, $passed;
    try {
        $run();
        ++$passed;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        ++$failures;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}
function parseFragment(string $html): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return $doc;
}

test('rich HTML cannot carry tracking or browser-controlled attributes', function (): void {
    $dirty = '<p id="admin" name="user_id" style="position:fixed" data-secret="x">Hello</p>'
        . '<a href="https://example.test/" ping="https://tracker.test/" target="_blank">link</a>'
        . '<img src="/photo.png" srcset="https://tracker.test/pixel 2x" is="custom-image">';
    $doc = parseFragment(Security::sanitizeHtml($dirty));
    foreach ($doc->getElementsByTagName('*') as $node) {
        foreach (['id', 'name', 'style', 'data-secret', 'ping', 'target', 'srcset', 'is'] as $attribute) {
            check(!$node->hasAttribute($attribute), "unexpected {$attribute} on {$node->tagName}");
        }
    }
    check($doc->getElementsByTagName('a')[0]->getAttribute('href') === 'https://example.test/', 'safe link lost');
    check($doc->getElementsByTagName('img')[0]->getAttribute('src') === '/photo.png', 'safe image lost');
});

test('parser removes active subtrees without corrupting safe text and attributes', function (): void {
    $dirty = '<p>Café &amp; tea</p><script><script>secretPayload()</script></script>'
        . '<style>secretStyle</style><svg><a>secretSvg</a></svg>'
        . '<a href="https://example.test/?a=1&amp;b=2" title="a > b &amp; c">safe</a>';
    $clean = Security::sanitizeHtml($dirty);
    $doc = parseFragment($clean);
    check(!str_contains($doc->textContent, 'secret'), 'active subtree contents survived');
    check(str_contains($doc->textContent, 'Café & tea'), 'safe Unicode or entities corrupted');
    $link = $doc->getElementsByTagName('a')[0];
    check($link->getAttribute('href') === 'https://example.test/?a=1&b=2', 'URL entities corrupted');
    check($link->getAttribute('title') === 'a > b & c', 'quoted attribute corrupted');
});

$tempDir = sys_get_temp_dir() . '/dn_security_' . bin2hex(random_bytes(8));
mkdir($tempDir, 0700);
putenv('DN_DB_DRIVER=sqlite');
putenv('DN_DB_DATABASE=' . $tempDir . '/test.sqlite');
ini_set('session.save_path', $tempDir);
register_shutdown_function(static function () use ($tempDir): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    Database::resetConnection();
    foreach (glob($tempDir . '/*') as $file) {
        unlink($file);
    }
    rmdir($tempDir);
});
Migrator::run();
$loginUser = User::create('security_test', 'security@example.test', 'test-password-strong', 'writer');

function freshSession(string $ip): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $_SESSION = [];
    session_id('');
    $_SERVER['REMOTE_ADDR'] = $ip;
    App::startSession();
}

test('IP throttle survives new cookies, connections and usernames', function (): void {
    $max = App::config()['security']['login_max_attempts'];
    for ($i = 0; $i < $max; ++$i) {
        freshSession('192.0.2.10');
        Database::resetConnection();
        check(!Auth::attempt('missing_' . $i, 'wrong'), 'bad password accepted');
    }
    freshSession('192.0.2.10');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
    Database::resetConnection();
    check(!Auth::attempt('security_test', 'test-password-strong'), 'new cookie bypassed IP lockout');
    freshSession('192.0.2.11');
    check(Auth::attempt('security_test', 'test-password-strong'), 'unrelated IP was locked');
});

test('successful logins do not spend or reset the failed-attempt budget', function (): void {
    for ($i = 0; $i < 8; ++$i) {
        freshSession('192.0.2.12');
        check(Auth::attempt('security_test', 'test-password-strong'), 'valid login spent the failure budget');
    }
    for ($i = 0; $i < 4; ++$i) {
        freshSession('192.0.2.12');
        check(!Auth::attempt('missing', 'wrong'), 'bad password accepted');
        freshSession('192.0.2.12');
        check(Auth::attempt('security_test', 'test-password-strong'), 'valid login blocked before fifth failure');
    }
    check(!Auth::attempt('missing', 'wrong'), 'bad password accepted');
    freshSession('192.0.2.12');
    check(!Auth::attempt('security_test', 'test-password-strong'), 'success cleared the existing failure budget');
});

test('older successful login cannot release a replacement window failure', function (): void {
    freshSession('192.0.2.13');
    $pdo = Database::get()->pdo();
    $key = hash('sha256', $_SERVER['REMOTE_ADDR']);
    $GLOBALS['authTestNow'] = time();
    $GLOBALS['authTestAfterVerify'] = static function () use ($pdo, $key): void {
        $st = $pdo->prepare('SELECT expires_at FROM dn_login_throttle WHERE ip_key = ?');
        $st->execute([$key]);
        // Advance exactly to expiry while the successful login is in flight.
        $GLOBALS['authTestNow'] = (int)$st->fetchColumn();
        check(!Auth::attempt('missing', 'wrong'), 'replacement window accepted bad credentials');
    };
    try {
        check(Auth::attempt('security_test', 'test-password-strong'), 'older valid login failed');
        check(isset($_SESSION['user_id']), 'successful login did not establish a session');
        $st = $pdo->prepare('SELECT attempts FROM dn_login_throttle WHERE ip_key = ?');
        $st->execute([$key]);
        check((int)$st->fetchColumn() === 1, 'older success erased the replacement window failure');
    } finally {
        unset($GLOBALS['authTestNow'], $GLOBALS['authTestAfterVerify']);
    }
});

test('reservation returns the stored window expiry and releases only its own attempt', function (): void {
    freshSession('192.0.2.14');
    $pdo = Database::get()->pdo();
    $key = hash('sha256', $_SERVER['REMOTE_ADDR']);
    $GLOBALS['authTestNow'] = time();
    $expires = $GLOBALS['authTestNow'] + 60;
    $pdo->prepare('INSERT INTO dn_login_throttle (ip_key, attempts, expires_at) VALUES (?, 1, ?)')
        ->execute([$key, $expires]);
    try {
        $reserve = new ReflectionMethod(Auth::class, 'reserveLoginAttempt');
        check($reserve->invoke(null) === $expires, 'reservation did not return the actual stored expiry');
        check(Auth::attempt('security_test', 'test-password-strong'), 'existing-window valid login failed');
        $st = $pdo->prepare('SELECT attempts FROM dn_login_throttle WHERE ip_key = ?');
        $st->execute([$key]);
        check((int)$st->fetchColumn() === 2, 'success did not release exactly its own reservation');
        $pdo->prepare('UPDATE dn_login_throttle SET attempts = ? WHERE ip_key = ?')
            ->execute([App::config()['security']['login_max_attempts'], $key]);
        check($reserve->invoke(null) === null, 'full window did not return null');
    } finally {
        unset($GLOBALS['authTestNow']);
    }
});

printf("Security regression: %d passed, %d failed\n", $passed, $failures);
ob_end_flush();
exit($failures === 0 ? 0 : 1);
}
