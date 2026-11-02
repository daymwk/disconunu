<?php
declare(strict_types=1);

/**
 * HTTP, cookie and CLI regressions against disposable copies — stdlib only.
 *   php tests/http.php
 *   BASE=http://host:port php tests/http.php   # public smoke checks only against a running site
 * Every group copies the application and uses a temporary SQLite database;
 * no live data, uploads or installed themes are touched.
 */

const ROOT = __DIR__ . '/..';

// Child PHP processes need the extensions the CLI may ship disabled.
exec(escapeshellarg(PHP_BINARY) . ' -m', $modules);
$modules = array_map('strtolower', array_map('trim', $modules));
$PHP = [PHP_BINARY];
foreach (['pdo_sqlite', 'sqlite3', 'zip'] as $ext) {
    if (!in_array($ext, $modules, true)) array_push($PHP, '-d', 'extension=' . $ext);
}
if (!extension_loaded('pdo_sqlite') || !extension_loaded('zip')) {
    $proc = proc_open([...$PHP, __FILE__], [STDIN, STDOUT, STDERR], $pipes);
    exit(proc_close($proc));
}

$pass = 0; $fail = 0; $failures = [];
$t = static function (string $name, callable $fn) use (&$pass, &$fail, &$failures): void {
    try {
        $fn();
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (\Throwable $e) {
        $fail++;
        $failures[] = $name . "\n      " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "  \033[31m✗\033[0m {$name}\n      \033[31m{$e->getMessage()}\033[0m\n";
    }
};

function assert_true($cond, string $msg = 'expected true'): void
{
    if ($cond !== true) throw new \Exception($msg);
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception(sprintf('%s expected=%s got=%s', $msg ?: 'value', var_export($expected, true), var_export($actual, true)));
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function copy_tree(string $from, string $to): void
{
    mkdir($to, 0775, true);
    foreach (scandir($from) as $entry) {
        if (in_array($entry, ['.', '..', '.git', 'var', '__pycache__'], true)) continue;
        $src = $from . '/' . $entry;
        if (is_link($src)) continue;
        is_dir($src) ? copy_tree($src, $to . '/' . $entry) : copy($src, $to . '/' . $entry);
    }
}

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
function run(array $cmd, string $cwd, array $env): array
{
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($proc), $out, $err];
}

function rows(string $db, string $sql, array $args = []): array
{
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_NUM);
}

function temp_dir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    return $dir;
}

/** A disposable application copy, database and `php -S` server. */
final class Site
{
    public string $tmp;
    public string $root;
    public string $db;
    public string $base;
    public array $env;
    public array $php;
    private $server;

    public function __construct(array $php, private string $router, array $env = [])
    {
        $this->tmp = temp_dir('dn-http-');
        $this->root = $this->tmp . '/app';
        copy_tree(ROOT, $this->root);
        $this->db = $this->tmp . '/test.sqlite';
        $this->env = $env + ['DN_DB_DRIVER' => 'sqlite', 'DN_DB_DATABASE' => $this->db] + getenv();
        $this->php = [...$php, '-d', 'session.save_path=' . $this->tmp];
        register_shutdown_function([$this, 'close']);
    }

    public function php(array $args): array
    {
        return run([...$this->php, ...$args], $this->root, $this->env);
    }

    public function start(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $this->base = 'http://127.0.0.1:' . $port;
        $log = $this->tmp . '/server.log';
        $this->server = proc_open([...$this->php, '-d', 'display_errors=1', '-S', '127.0.0.1:' . $port, '-t', 'public', $this->router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $this->root, $this->env);
        for ($i = 0; $i < 200; $i++) {
            if ($c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) { fclose($c); return; }
            if (!proc_get_status($this->server)['running']) throw new \RuntimeException('PHP server exited: ' . file_get_contents($log));
            usleep(30000);
        }
        throw new \RuntimeException('PHP server never became ready');
    }

    public function rows(string $sql, array $args = []): array
    {
        return rows($this->db, $sql, $args);
    }

    public function close(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        if (is_dir($this->tmp)) rrmdir($this->tmp);
    }
}

/** One browser-like actor: its own cookie jar, no redirect following. */
final class Client
{
    private array $cookies = [];
    public ?string $csrf = null;

    public function __construct(private string $base) {}

    /** @param array<string, string|int|list<string>>|null $fields null = GET */
    public function request(string $path, ?array $fields = null, ?array $upload = null, string $method = ''): array
    {
        $headers = [];
        $content = null;
        if ($fields !== null) {
            if ($this->csrf !== null && !array_key_exists('csrf', $fields)) $fields['csrf'] = $this->csrf;
            if ($upload) {
                $b = 'dn-http-regression-boundary';
                $content = '';
                foreach ($fields as $k => $v) {
                    foreach ((array) $v as $one) $content .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$one}\r\n";
                }
                [$field, $filename, $mime, $data] = $upload;
                $content .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$filename}\"\r\nContent-Type: {$mime}\r\n\r\n{$data}\r\n--{$b}--\r\n";
                $headers[] = 'Content-Type: multipart/form-data; boundary=' . $b;
            } else {
                $pairs = [];
                foreach ($fields as $k => $v) {
                    foreach ((array) $v as $one) $pairs[] = urlencode((string) $k) . '=' . urlencode((string) $one);
                }
                $content = implode('&', $pairs);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        $http = ['method' => $method ?: ($fields === null ? 'GET' : 'POST'), 'header' => $headers,
                 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
        if ($content !== null) $http['content'] = $content;
        $fp = fopen($this->base . $path, 'r', false, stream_context_create(['http' => $http]));
        if ($fp === false) throw new \RuntimeException('request failed: ' . $path);
        $raw = stream_get_meta_data($fp)['wrapper_data'];
        $body = stream_get_contents($fp);
        fclose($fp);
        $status = (int) explode(' ', $raw[0])[1];
        $hdr = [];
        foreach (array_slice($raw, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $name = strtolower($name);
            $hdr[$name] = isset($hdr[$name]) ? $hdr[$name] . "\n" . $value : $value;
            if ($name === 'set-cookie') {
                [$pair] = explode(';', $value, 2);
                [$k, $v] = explode('=', $pair, 2) + [1 => ''];
                if ($v === '' || $v === 'deleted') unset($this->cookies[$k]); else $this->cookies[$k] = $v;
            }
        }
        return [$status, $hdr, $body];
    }

    public function status(string $path, ?array $fields = null, ?array $upload = null, string $method = ''): int
    {
        return $this->request($path, $fields, $upload, $method)[0];
    }

    public function body(string $path): string
    {
        return $this->request($path)[2];
    }

    /** Admin pages hold several forms (one CSRF token each): always take the first one. */
    public function token(string $path): string
    {
        preg_match('/name="csrf" value="([a-f0-9]{64})/', $this->body($path), $m);
        return $m[1] ?? '';
    }
}

function zip_bytes(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'dn-zip-');
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $data) $z->addFromString($name, $data);
    $z->close();
    $bytes = file_get_contents($path);
    unlink($path);
    return $bytes;
}

echo "\n=== Disco nunu — HTTP & CLI regressions ===\n";

// ---------------------------------------------------------------- smoke
echo "\n-- HTTP smoke --\n";

$live = (string) getenv('BASE') !== '';
$ADMIN_PW = 'smoke-admin-pass-9x';
$WRITER_PW = 'smoke-writer-pass-9x';
if ($live) {
    $base = rtrim((string) getenv('BASE'), '/');
} else {
    $smoke = new Site($PHP, 'public/router.php');
    $smoke->php(['bin/disconunu', 'migrate']);
    $env = $smoke->env;
    $smoke->env = ['DN_USER_PASSWORD' => $ADMIN_PW] + $env;
    $a = $smoke->php(['bin/disconunu', 'user', 'admin_smoke', 'smoke@example.test', '--role=superadmin']);
    $smoke->env = ['DN_USER_PASSWORD' => $WRITER_PW] + $env;
    $w = $smoke->php(['bin/disconunu', 'user', 'writer_smoke', 'writer@example.test', '--role=writer']);
    $smoke->env = $env;
    if ($a[0] !== 0 || $w[0] !== 0) { echo "seeding accounts failed\n{$a[2]}{$w[2]}"; exit(1); }
    $smoke->start();
    $base = $smoke->base;
}
$anon = static fn() => new Client($base);
$admin = new Client($base);
$writer = new Client($base);

($t)('public pages, assets and status codes', function () use ($anon) {
    assert_eq(200, $anon()->status('/'), 'GET /');
    assert_eq('text/css; charset=utf-8', $anon()->request('/themes/teal/css/style.css')[1]['content-type'] ?? '', 'theme CSS');
    assert_eq('text/css; charset=utf-8', $anon()->request('/themes/teal/style.css')[1]['content-type'] ?? '', 'short CSS URL');
    assert_true($anon()->status('/../config/config.php') !== 200, 'path traversal ../ served');
    assert_true($anon()->status('/themes/../../config/config.php') !== 200, 'traversal through themes served');
    assert_eq(404, $anon()->status('/post/nope-does-not-exist'), 'unknown post');
    assert_eq(404, $anon()->status('/category/nope'), 'unknown category');
    assert_eq(200, $anon()->status('/category/general'), 'default category');
    assert_eq(303, $anon()->status('/admin/post/save', ['title' => 'x', 'content' => 'y']), 'POST save without session');
    assert_eq(405, $anon()->status('/', [], null, 'POST'), 'POST /');
});

($t)('security headers and session cookie', function () use ($anon) {
    $h = $anon()->request('/', null, null, 'HEAD')[1];
    assert_true(isset($h['content-security-policy']), 'CSP header missing');
    assert_true(!isset($h['x-powered-by']), 'X-Powered-By leaked');
    assert_true(stripos(implode("\n", $h), 'HttpOnly') !== false, 'cookie not HttpOnly');
});

($t)('CSRF token issued', function () use ($admin) {
    assert_true($admin->token('/admin/login') !== '', 'no CSRF token');
});

if (!$live) {
    ($t)('admin login and screens', function () use ($admin, $ADMIN_PW) {
        assert_eq(303, $admin->status('/admin/login', ['csrf' => $admin->token('/admin/login'), 'username' => 'admin_smoke', 'password' => $ADMIN_PW]), 'admin login');
        foreach (['/admin', '/admin/categories', '/admin/settings', '/admin/users', '/admin/post/new'] as $p) {
            assert_eq(200, $admin->status($p), "GET {$p}");
        }
    });

    ($t)('bad CSRF rejected, XSS escaped on render', function () use ($admin, $anon) {
        $csrf = $admin->token('/admin/post/new');
        assert_eq(400, $admin->status('/admin/post/save', ['csrf' => 'fake', 'title' => 'x']), 'bad CSRF');
        assert_eq(303, $admin->status('/admin/post/save', ['csrf' => $csrf, 'title' => 'Smoke <script>alert(1)</script> post',
            'content' => '<p onclick="x()">body</p><a href="javascript:alert(1)">x</a>', 'status' => 'published']), 'create post');
        $body = $anon()->body('/');
        assert_true(str_contains($body, 'Smoke &lt;script&gt;alert(1)&lt;/script&gt; post'), 'XSS not escaped');
        assert_true(!preg_match('/onclick|javascript:alert/i', $body), 'XSS present in rendered HTML');
    });

    ($t)('category creation and public listing', function () use ($admin, $anon) {
        assert_eq(303, $admin->status('/admin/categories/save', ['csrf' => $admin->token('/admin/categories'), 'title' => 'Smoke category', 'visible' => '1']), 'create category');
        assert_eq(200, $anon()->status('/category/smoke-category'), 'new category page');
    });

    ($t)('posts per page and windowed pagination', function () use ($admin, $anon) {
        $admin->request('/admin/settings', ['csrf' => $admin->token('/admin/settings'), 'site_title' => 'Smoke blog', 'posts_per_page' => '1', 'rss_enabled' => '1', 'theme' => 'teal']);
        for ($i = 1; $i <= 5; $i++) {
            $admin->request('/admin/post/save', ['csrf' => $admin->token('/admin/post/new'), 'title' => "Windowed post {$i}", 'content' => '<p>body</p>', 'status' => 'published']);
        }
        $body = $anon()->body('/');
        assert_true(str_contains($body, 'class="pagination"'), 'no pagination block');
        assert_true(str_contains($body, 'pagination-gap'), 'pagination not windowed');
        assert_true((bool) preg_match('/href="[^"]*page=2"/', $body), 'no page-2 link');
        assert_true(str_contains($body, 'Smoke blog'), 'site title not applied');
    });

    ($t)('RSS toggle', function () use ($admin, $anon) {
        assert_eq(200, $anon()->status('/feed'), 'RSS enabled');
        $admin->request('/admin/settings', ['csrf' => $admin->token('/admin/settings'), 'site_title' => 'Smoke blog', 'posts_per_page' => '1', 'theme' => 'teal', 'rss_enabled' => '0']);
        assert_eq(404, $anon()->status('/feed'), 'RSS disabled');
        $admin->request('/admin/settings', ['csrf' => $admin->token('/admin/settings'), 'site_title' => 'Smoke blog', 'posts_per_page' => '5', 'rss_enabled' => '1', 'theme' => 'teal']);
        assert_eq(200, $anon()->status('/feed'), 'RSS re-enabled');
        assert_true(str_contains($anon()->body('/feed'), '<rss version="2.0">'), 'RSS malformed');
    });

    ($t)('logo upload', function () use ($admin, $anon) {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
        assert_eq(303, $admin->status('/admin/logo', ['csrf' => $admin->token('/admin/settings')], ['logo', 'logo.png', 'image/png', $png]), 'logo upload');
        assert_eq('image/png', $anon()->request('/uploads/logo.png')[1]['content-type'] ?? '', 'uploaded logo served');
        assert_true(str_contains($anon()->body('/'), '/uploads/logo.png'), 'logo not used in the layout');
    });

    ($t)('theme pack install, protection and uninstall', function () use ($admin, $anon) {
        $zip = zip_bytes(['smoketheme/layout.php' => '<!DOCTYPE html><html><body><?= $content ?><footer>SMOKETHEME</footer></body></html>']);
        assert_eq(303, $admin->status('/admin/theme', ['csrf' => $admin->token('/admin/settings'), 'activate' => '1'], ['themezip', 'theme.zip', 'application/zip', $zip]), 'theme zip install');
        preg_match('/<option value="([A-Za-z0-9_-]+)" selected>/', $admin->body('/admin/settings'), $m);
        $installed = $m[1] ?? '';
        assert_true(str_contains($anon()->body('/'), 'SMOKETHEME'), 'theme not applied');
        $admin->request('/admin/theme/teal/delete', ['csrf' => $admin->token('/admin/settings')]);
        assert_true(str_contains($admin->body('/admin/settings'), '<code>teal</code>'), 'bundled theme deleted');
        $admin->request('/admin/settings', ['csrf' => $admin->token('/admin/settings'), 'theme' => 'teal']);
        assert_true(!str_contains($anon()->body('/'), 'SMOKETHEME'), 'theme switch back failed');
        assert_eq(303, $admin->status("/admin/theme/{$installed}/delete", ['csrf' => $admin->token('/admin/settings')]), 'uninstall theme');
        assert_true(!str_contains($admin->body('/admin/settings'), "<code>{$installed}</code>"), 'theme still listed after delete');
    });

    ($t)('shared admin navigation, footer text, dashboard status filter', function () use ($admin, $anon) {
        foreach (['/admin', '/admin/categories', '/admin/users', '/admin/settings', '/admin/post/new'] as $p) {
            assert_true(str_contains($admin->body($p), 'admin-nav'), "admin nav missing on {$p}");
        }
        $admin->request('/admin/settings', ['csrf' => $admin->token('/admin/settings'), 'site_title' => 'Smoke blog', 'footer_text' => 'Smoke footer 42', 'posts_per_page' => '5', 'rss_enabled' => '1', 'theme' => 'teal']);
        assert_true(str_contains($anon()->body('/'), 'Smoke footer 42'), 'footer text not applied');
        $drafts = $admin->body('/admin?status=draft');
        assert_true(str_contains($drafts, '<option value="draft" selected>') && !str_contains($drafts, 'class="badge badge-published"'), 'dashboard filter broken');
    });

    ($t)('user management: create, role, delete, no self-delete', function () use ($admin) {
        assert_eq(303, $admin->status('/admin/users/create', ['csrf' => $admin->token('/admin/users'), 'username' => 'smoke_extra',
            'email' => 'smoke_extra@example.test', 'password' => 'smoke-extra-pass-1', 'role' => 'writer']), 'create user');
        $page = $admin->body('/admin/users');
        assert_true(str_contains($page, 'smoke_extra'), 'new user missing');
        assert_true(str_contains($page, 'smoke_extra</strong>') && str_contains($page, 'badge-role">writer'), 'role wrong');
        preg_match_all('#/admin/users/([0-9]+)/delete#', $page, $m);
        $csrf = $admin->token('/admin/users');
        assert_eq(303, $admin->status('/admin/users/' . end($m[1]) . '/delete', ['csrf' => $csrf]), 'delete user');
        $page = $admin->body('/admin/users');
        assert_true(!str_contains($page, 'smoke_extra'), 'user still listed after delete');
        assert_eq(1, preg_match_all('#/admin/users/[0-9]*/delete#', $page), 'delete buttons (none for the acting superadmin)');
    });

    ($t)('writer ACL: no settings/categories, posts forced to draft', function () use ($writer, $anon, $WRITER_PW) {
        assert_eq(303, $writer->status('/admin/login', ['csrf' => $writer->token('/admin/login'), 'username' => 'writer_smoke', 'password' => $WRITER_PW]), 'writer login');
        assert_eq(200, $writer->status('/admin'), 'writer dashboard');
        assert_eq(403, $writer->status('/admin/settings'), 'writer settings');
        assert_eq(403, $writer->status('/admin/categories'), 'writer categories');
        assert_eq(303, $writer->status('/admin/post/save', ['csrf' => $writer->token('/admin/post/new'), 'title' => 'Writer tried to publish',
            'content' => '<p>nope</p>', 'status' => 'published']), 'writer submit');
        $dash = $writer->body('/admin');
        assert_true(str_contains($dash, 'Writer tried to publish'), 'writer post missing');
        assert_true(str_contains($dash, 'badge-draft'), 'writer post was published (ACL breach)');
        assert_eq(0, substr_count($anon()->body('/'), 'Writer tried to publish'), 'writer post on the public site');
    });

    if ($fail && isset($smoke) && preg_match_all('/^.*(Fatal|Uncaught|PHP (Warning|Notice)).*$/mi', (string) @file_get_contents($smoke->tmp . '/server.log'), $m)) {
        echo "--- server log (errors) ---\n" . implode("\n", array_slice($m[0], 0, 12)) . "\n";
    }
    $smoke->close();
}

// ---------------------------------------------------------------- accounts (CLI)
echo "\n-- Accounts (CLI) --\n";

$accounts = static function (callable $fn) use ($PHP): callable {
    return static function () use ($fn, $PHP) {
        $tmp = temp_dir('dn-accounts-');
        try {
            $db = $tmp . '/test.sqlite';
            $env = ['DN_DB_DRIVER' => 'sqlite', 'DN_DB_DATABASE' => $db, 'DN_USER_PASSWORD' => bin2hex(random_bytes(18))] + getenv();
            $cli = static fn(string ...$args) => run([...$PHP, ...$args], ROOT, $env);
            $cli('bin/disconunu', 'migrate');
            $fn($cli, fn(string $sql) => rows($db, $sql));
        } finally {
            rrmdir($tmp);
        }
    };
};

($t)('first CLI account is superadmin, second is writer', $accounts(function ($cli, $q) {
    assert_eq(0, $cli('bin/disconunu', 'user', 'first_user', 'first@example.test')[0], 'first user');
    assert_eq([['superadmin']], $q('select role from dn_user'));
    assert_eq(0, $cli('bin/disconunu', 'user', 'second_user', 'second@example.test')[0], 'second user');
    assert_eq([['superadmin'], ['writer']], $q('select role from dn_user order by id'));
}));

($t)('last superadmin cannot be demoted via CLI', $accounts(function ($cli, $q) {
    $cli('bin/disconunu', 'user', 'first_user', 'first@example.test', '--role=superadmin');
    assert_true($cli('bin/disconunu', 'role', 'first_user', 'writer')[0] !== 0, 'demotion accepted');
    assert_eq([['superadmin']], $q('select role from dn_user'));
}));

($t)('CLI username validation matches the web', $accounts(function ($cli, $q) {
    assert_true($cli('bin/disconunu', 'user', 'bad user', 'bad@example.test')[0] !== 0, 'bad username accepted');
    assert_eq([], $q('select id from dn_user'));
}));

($t)('seeder on an empty site does not claim the first account', $accounts(function ($cli, $q) {
    assert_true($cli('bin/seed-demo.php', '3')[0] !== 0, 'seeder ran without an account');
    assert_eq([], $q('select id from dn_user'));
}));

($t)('bootstrap is single use', $accounts(function ($cli, $q) {
    $script = "require 'autoload.php'; DN\\Model\\User::createFirst('owner','owner@example.test',getenv('DN_USER_PASSWORD')); try {DN\\Model\\User::createFirst('intruder','intruder@example.test',getenv('DN_USER_PASSWORD')); exit(2);} catch(RuntimeException \$e) {echo 'blocked';}";
    [$code, $out, $err] = $cli('-r', $script);
    assert_eq(0, $code, $err);
    assert_eq('blocked', $out);
    assert_eq([['owner', 'superadmin']], $q('select username,role from dn_user'));
}));

// ---------------------------------------------------------------- backend (one fresh site per test)
echo "\n-- Backend HTTP --\n";

$backend = static function (callable $fn) use ($PHP): callable {
    return static function () use ($fn, $PHP) {
        $site = new Site($PHP, 'public/index.php', ['DN_DEBUG' => '1', 'DN_THEME' => 'teal', 'DN_SECURE_COOKIES' => '0', 'DN_APP_URL' => '']);
        try {
            $seed = <<<'PHP'
            require 'autoload.php'; DN\App::boot(); DN\Migrator::run();
            foreach (['superadmin'=>'superadmin','admin'=>'admin','writer'=>'writer','writer2'=>'writer'] as $name=>$role) {
                $u=DN\Model\User::create($name, $name.'@example.test','Test-pass-12345',$role);
                DN\Model\Post::create($u->id,$name.' draft','<p>Private '.$name.'</p>','','draft');
                DN\Model\Post::create($u->id,$name.' published','<p>Public '.$name.'</p>','','published');
            }
            DN\Model\Category::create('Hidden category','hidden-category',false);
            DN\Model\Category::create('Visible category','visible-category',true);
            PHP;
            [$code, , $err] = $site->php(['-r', $seed]);
            assert_eq(0, $code, 'seed: ' . $err);
            $site->start();
            $c = ['anon' => new Client($site->base)];
            foreach (['superadmin', 'admin', 'writer', 'writer2'] as $name) {
                $client = new Client($site->base);
                [$status, , $body] = $client->request('/admin/login');
                assert_eq(200, $status, 'login page');
                assert_true((bool) preg_match('/name="csrf" value="([^"]+)"/', $body, $m), 'login CSRF');
                $client->csrf = $m[1];
                assert_eq(303, $client->status('/admin/login', ['username' => $name, 'password' => 'Test-pass-12345']), "login {$name}");
                $c[$name] = $client;
            }
            $fn($site, $c);
        } finally {
            $site->close();
        }
    };
};

($t)('ACL mutations', $backend(function (Site $s, array $c) {
    foreach (['admin', 'writer', 'writer2'] as $actor) {
        $uid = $s->rows('SELECT id FROM dn_user WHERE username=?', [$actor])[0][0];
        assert_eq(403, $c[$actor]->status("/admin/users/{$uid}/role", ['role' => 'superadmin']), "escalation {$actor}");
        assert_eq($actor === 'admin' ? 'admin' : 'writer', $s->rows('SELECT role FROM dn_user WHERE id=?', [$uid])[0][0], "role of {$actor}");
    }
    foreach ([6, 7, 8] as $id) {
        foreach (['edit', 'save', 'delete'] as $action) {
            $before = $s->rows('SELECT * FROM dn_post WHERE id=?', [$id]);
            $status = $action === 'save'
                ? $c['writer']->status('/admin/post/save', ['id' => $id, 'title' => 'Intrusion', 'content' => '<p>Intrusion</p>', 'status' => 'draft'])
                : $c['writer']->status("/admin/post/{$id}/{$action}", $action === 'delete' ? [] : null);
            assert_eq(403, $status, "post {$id} {$action}");
            assert_eq($before, $s->rows('SELECT * FROM dn_post WHERE id=?', [$id]), "post {$id} {$action} unchanged");
        }
    }
    assert_eq(200, $c['writer']->status('/admin/post/5/edit'));
    assert_eq(303, $c['writer']->status('/admin/post/save', ['id' => 5, 'title' => 'My draft', 'content' => '<p>Saved</p>', 'status' => 'published']));
    assert_eq(['My draft', 'draft'], $s->rows('SELECT title,status FROM dn_post WHERE id=5')[0]);
    assert_eq(303, $c['writer']->status('/admin/post/5/delete', []));
    assert_eq([], $s->rows('SELECT id FROM dn_post WHERE id=5'));
}));

($t)('category permissions and invalid ids', $backend(function (Site $s, array $c) {
    $general = $s->rows("SELECT id FROM dn_category WHERE slug='general'")[0][0];
    assert_eq(403, $c['writer']->status('/admin/categories/save', ['title' => 'Blocked']), 'writer save');
    assert_eq(403, $c['writer']->status("/admin/categories/{$general}/delete", []), 'writer delete');
    foreach ([
        ['/admin/categories/save', ['id' => '9999', 'title' => 'Missing'], 404],
        ['/admin/categories/save', ['id' => 'garbage', 'title' => 'Invalid'], 400],
        ['/admin/categories/9999/delete', [], 404],
        ['/admin/categories/1oops/delete', [], 400],
        ["/admin/categories/{$general}/delete", [], 403],
    ] as [$path, $fields, $expected]) {
        assert_eq($expected, $c['admin']->status($path, $fields), $path . ' ' . json_encode($fields));
    }
    assert_true((bool) $s->rows("SELECT id FROM dn_category WHERE slug='general'"), 'general deleted');
    assert_eq([], $s->rows("SELECT id FROM dn_category WHERE title IN ('Blocked','Invalid','Missing')"));
}));

($t)('paginated lists and public clamping', $backend(function (Site $s, array $c) {
    // More than the old 500-row cap; query matches the oldest item.
    $pdo = new PDO('sqlite:' . $s->db);
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO dn_post(user_id,title,slug,excerpt,content,status,published_at,updated_at) VALUES(3,?,?, '', '<p>bulk</p>', 'draft',NULL,'2026-01-01')");
    for ($i = 0; $i < 505; $i++) $st->execute([sprintf('Bulk %03d', $i), "bulk-{$i}"]);
    $pdo->commit();
    $pdo = null;
    foreach ([
        ['admin', 'q=writer%20draft&per_page=1&sort=oldest', ['writer draft']],
        ['admin', 'q=Bulk&per_page=2&page=2&sort=oldest', ['Bulk 002', 'Bulk 003']],
        ['admin', 'q=Bulk&per_page=2&page=999999&sort=oldest', ['Bulk 504']],
        ['writer', 'author=4&per_page=2', []],
        ['writer2', 'q=Bulk&per_page=2', []],
    ] as [$actor, $query, $expected]) {
        [$status, , $body] = $c[$actor]->request('/admin?' . $query);
        assert_eq(200, $status, $query);
        preg_match_all('#<a class="post-title"[^>]*>(.*?)</a>#s', $body, $m);
        assert_eq($expected, array_map(fn($x) => trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5)), $m[1]), "{$actor} {$query}");
    }
    foreach ([
        ['/admin/categories?q=category&visibility=hidden&per_page=1', 'Hidden category', 'Visible category'],
        ['/admin/users?q=writer&role=writer&per_page=1&page=2', 'writer2@example.test', 'writer@example.test'],
    ] as [$path, $present, $absent]) {
        [$status, , $body] = $c['superadmin']->request($path);
        assert_eq(200, $status, $path);
        assert_true(str_contains($body, $present) && !str_contains($body, $absent), $path);
    }
    foreach (['/?page=999999', '/category/general?page=999999'] as $path) {
        [$status, , $body] = $c['anon']->request($path);
        assert_eq(200, $status, $path);
        assert_true(str_contains($body, 'writer published'), $path);
    }
}));

($t)('preview access and private headers', $backend(function (Site $s, array $c) {
    foreach ([['anon', 5, 303], ['writer', 5, 200], ['writer', 6, 200], ['writer', 7, 403], ['writer2', 5, 403], ['admin', 5, 200], ['superadmin', 7, 200], ['admin', 9999, 404]] as [$actor, $post, $expected]) {
        [$status, $h, $body] = $c[$actor]->request("/admin/post/{$post}/preview");
        assert_eq($expected, $status, "{$actor} preview {$post}");
        if ($expected === 200) {
            assert_true(str_contains($h['cache-control'] ?? '', 'no-store'), 'no-store');
            assert_true(str_contains($h['x-robots-tag'] ?? '', 'noindex'), 'noindex');
            assert_true(str_contains($body, 'class="site-header"') && !str_contains($body, 'class="admin-shell"'), 'public layout');
        }
    }
    assert_eq(404, $c['anon']->status('/post/writer-draft'));
}));

($t)('editor save-continue and validation retention', $backend(function (Site $s, array $c) {
    [$status, $h] = $c['admin']->request('/admin/post/save', ['title' => 'Continue post', 'content' => '<p>Saved body</p>', 'status' => 'draft', 'save_action' => 'continue']);
    $id = $s->rows("SELECT id FROM dn_post WHERE title='Continue post'")[0][0];
    assert_eq(303, $status);
    assert_eq($s->base . "/admin/post/{$id}/edit", $h['location'] ?? '');
    foreach ([0, 3] as $postId) {
        $before = $s->rows('SELECT * FROM dn_post WHERE id=?', [$postId]);
        [$status, $h] = $c['admin']->request('/admin/post/save', ['id' => $postId, 'title' => '', 'content' => '<p>Keep my text &amp; markup</p>', 'status' => 'published', 'categories[]' => ['2', '3']]);
        assert_eq(303, $status);
        $path = $postId ? "/admin/post/{$postId}/edit" : '/admin/post/new';
        assert_eq($s->base . $path, $h['location'] ?? '');
        [$status, , $text] = $c['admin']->request($path);
        assert_eq(200, $status, $path);
        assert_true(str_contains($text, '&lt;p&gt;Keep my text &amp;amp; markup&lt;/p&gt;'), 'content kept');
        assert_true((bool) preg_match('/name="title"[^>]*value=""/', $text), 'empty title');
        assert_true((bool) preg_match('/value="published"\s+selected/', $text), 'status kept');
        foreach ([2, 3] as $cat) assert_true((bool) preg_match('/name="categories\[\]" value="' . $cat . '"\s+checked/', $text), "category {$cat} kept");
        assert_eq($before, $s->rows('SELECT * FROM dn_post WHERE id=?', [$postId]), 'no write');
    }
    $c['admin']->request('/admin/post/save', ['title' => 'Retain this title', 'content' => '', 'status' => 'draft']);
    assert_true(str_contains($c['admin']->body('/admin/post/new'), 'Retain this title'), 'missing-title retention');
    // Authorization must run before even invalid input can affect editor state.
    assert_eq(403, $c['writer']->status('/admin/post/save', ['id' => 6, 'title' => '', 'content' => '']));
}));

($t)('theme install is explicit, starter downloads', $backend(function (Site $s, array $c) {
    $archive = fn(string $name) => ['themezip', $name . '.zip', 'application/zip', zip_bytes([$name . '/layout.php' => '<!doctype html><title>Custom</title><main><?= $content ?></main>'])];
    foreach ([['installed-only', [], 'teal'], ['activated', ['activate' => '1'], 'activated'], ['not-activated', ['activate' => 'yes'], 'activated']] as [$name, $fields, $expected]) {
        assert_eq(303, $c['superadmin']->status('/admin/theme', $fields, $archive($name)), $name);
        assert_true(is_file("{$s->root}/themes/{$name}/layout.php"), "{$name} installed");
        $rows = $s->rows("SELECT v FROM dn_setting WHERE k='theme'");
        assert_eq($expected, $rows ? $rows[0][0] : 'teal', "{$name} active theme");
    }
    foreach (['admin', 'writer', 'writer2'] as $actor) {
        assert_eq(403, $c[$actor]->status('/admin/theme', [], $archive('denied')), "{$actor} install");
        assert_eq(403, $c[$actor]->status('/admin/theme/starter'), "{$actor} starter");
    }
    [$status, $h, $body] = $c['superadmin']->request('/admin/theme/starter');
    assert_eq(200, $status);
    assert_true(str_contains($h['content-type'] ?? '', 'application/zip'), 'zip content type');
    $path = $s->tmp . '/starter.zip';
    file_put_contents($path, $body);
    $zip = new ZipArchive();
    assert_eq(true, $zip->open($path, ZipArchive::CHECKCONS), 'zip integrity');
    $src = $s->root . '/examples/starter-theme';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        assert_eq(file_get_contents($f->getPathname()), $zip->getFromName('starter-theme/' . $rel), $rel);
    }
    $zip->close();
    assert_true(str_contains($c['superadmin']->body('/admin/settings'), 'class="admin-shell"'), 'admin shell');
}));

($t)('settings reject an invalid theme without any writes', $backend(function (Site $s, array $c) {
    $initial = ['site_title' => 'Kept title', 'site_tagline' => 'Kept tagline', 'footer_text' => 'Kept footer', 'posts_per_page' => '7', 'rss_enabled' => '1', 'theme' => 'teal'];
    assert_eq(303, $c['superadmin']->status('/admin/settings', $initial));
    mkdir($s->root . '/themes/invalid-review');
    file_put_contents($s->root . '/themes/invalid-review/layout.php', '<?php invalid syntax !');
    $before = $s->rows('SELECT k,v FROM dn_setting ORDER BY k');
    foreach (['invalid-review', 'missing-theme', '../teal', ''] as $theme) {
        $fields = ['site_title' => 'Changed', 'site_tagline' => 'Changed', 'footer_text' => 'Changed', 'posts_per_page' => '2', 'rss_enabled' => '0', 'theme' => $theme] + $initial;
        $status = $c['superadmin']->status('/admin/settings', $fields);
        assert_eq([400, $before], [$status, $s->rows('SELECT k,v FROM dn_setting ORDER BY k')], "theme '{$theme}'");
        assert_eq(200, $c['anon']->status('/'), 'public still up');
        assert_eq(200, $c['superadmin']->status('/admin/settings'), 'settings still up');
    }
}));

($t)('partial settings preserve absent fields, RSS is explicit', $backend(function (Site $s, array $c) {
    $initial = ['site_title' => 'Identity', 'site_tagline' => 'Tagline', 'footer_text' => 'Footer', 'posts_per_page' => '7', 'rss_enabled' => '1', 'theme' => 'teal'];
    assert_eq(303, $c['superadmin']->status('/admin/settings', $initial));
    mkdir($s->root . '/themes/alternate-review');
    file_put_contents($s->root . '/themes/alternate-review/layout.php', '<main><?= $content ?></main>');
    $expected = $initial;
    foreach ([['theme' => 'alternate-review'], ['site_title' => 'New title'], ['rss_enabled' => '0'], [], ['rss_enabled' => '1'], ['rss_enabled' => 'yes'], ['rss_enabled' => '']] as $fields) {
        $expected = $fields + $expected;
        if (array_key_exists('rss_enabled', $fields)) $expected['rss_enabled'] = $fields['rss_enabled'] === '1' ? '1' : '0';
        assert_eq(303, $c['superadmin']->status('/admin/settings', $fields), json_encode($fields));
        $actual = array_column($s->rows('SELECT k,v FROM dn_setting'), 1, 0);
        ksort($actual); ksort($expected);
        assert_eq($expected, $actual, json_encode($fields));
        assert_eq($expected['rss_enabled'] === '1' ? 200 : 404, $c['anon']->status('/feed'), 'feed ' . json_encode($fields));
    }
}));

($t)('hidden category badges are not public', $backend(function (Site $s, array $c) {
    assert_eq(303, $c['admin']->status('/admin/post/save', ['id' => 4, 'title' => 'admin published', 'content' => '<p>Still public</p>', 'status' => 'published', 'categories[]' => ['2', '3']]));
    assert_eq(404, $c['anon']->status('/category/hidden-category'));
    [$status, , $body] = $c['anon']->request('/post/admin-published');
    assert_eq(200, $status);
    foreach (['Still public', '/category/visible-category', 'Visible category', '/category/general'] as $needle) assert_true(str_contains($body, $needle), "missing {$needle}");
    foreach (['/category/hidden-category', 'Hidden category'] as $needle) assert_true(!str_contains($body, $needle), "leaked {$needle}");
    assert_eq([[2], [3]], $s->rows('SELECT category_id FROM dn_post_category WHERE post_id=4 ORDER BY category_id'));
}));

($t)('theme upload activation revalidates the installed theme', $backend(function (Site $s, array $c) {
    // Wiring guard: the installer already rejects invalid PHP; activation must
    // independently validate the published directory before changing settings.
    $controller = file_get_contents($s->root . '/public/index.php');
    $activation = explode("if ((\$_POST['activate'] ?? '') === '1') {", $controller, 2)[1] ?? '';
    $beforeWrite = explode("Settings::set('theme', \$name)", $activation, 2)[0];
    assert_true($activation !== '' && str_contains($beforeWrite, 'ThemeManager::exists($name)'), 'activation does not revalidate');
    $before = $s->rows('SELECT k,v FROM dn_setting ORDER BY k');
    assert_eq(303, $c['superadmin']->status('/admin/theme', ['activate' => '1'], ['themezip', 'invalid.zip', 'application/zip', zip_bytes(['invalid-upload/layout.php' => '<?php invalid syntax !'])]));
    assert_eq($before, $s->rows('SELECT k,v FROM dn_setting ORDER BY k'));
    assert_eq(200, $c['anon']->status('/'));
    assert_true(str_contains($c['superadmin']->body('/admin/settings'), 'Theme install failed'), 'no failure flash');
}));

($t)('post and user path ids reject malformed values without mutations', $backend(function (Site $s, array $c) {
    $snapshot = fn() => [$s->rows('SELECT * FROM dn_post ORDER BY id'), $s->rows('SELECT * FROM dn_user ORDER BY id')];
    foreach (['abc', '1junk', '2junk', '0', '-1', '999999999999999999999999'] as $bad) {
        foreach ([['post', 'edit', null], ['post', 'preview', null], ['post', 'delete', []], ['users', 'delete', []], ['users', 'role', ['role' => 'writer']]] as [$resource, $action, $fields]) {
            $before = $snapshot();
            $status = $c['superadmin']->status("/admin/{$resource}/{$bad}/{$action}", $fields);
            assert_eq([400, $before], [$status, $snapshot()], "{$resource}/{$bad}/{$action}");
        }
    }
}));

($t)('logo is raster-validated and replaced atomically', $backend(function (Site $s, array $c) {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=');
    $upload = ['logo', 'untrusted.svg', 'image/svg+xml', $png];
    assert_eq(303, $c['superadmin']->status('/admin/logo', [], $upload));
    $target = $s->root . '/var/uploads/logo.png';
    assert_eq($png, (string) @file_get_contents($target), 'stored logo');
    assert_eq($png, $c['anon']->body('/uploads/logo.png'), 'served logo');
    foreach ([
        ['evil.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ['fake.png', 'image/png', "\x89PNG\r\n\x1a\nnot an image"],
        ['huge.png', 'image/png', $png . str_repeat("\0", 512 * 1024)],
    ] as [$filename, $mime, $data]) {
        assert_eq(303, $c['superadmin']->status('/admin/logo', [], ['logo', $filename, $mime, $data]), $filename);
        assert_true(is_file($target), "{$filename}: rejected upload removed the previous logo");
        assert_eq($png, file_get_contents($target), $filename);
        assert_true(!file_exists(dirname($target) . '/logo.svg'), "{$filename}: svg written");
        assert_true(str_contains($c['superadmin']->body('/admin/settings'), 'Invalid logo'), "{$filename}: no error flash");
    }
    foreach (['admin', 'writer', 'writer2'] as $actor) assert_eq(403, $c[$actor]->status('/admin/logo', [], $upload), $actor);
    // A failing atomic rename must not delete the previous logo.
    unlink($target);
    mkdir($target);
    file_put_contents(dirname($target) . '/logo.gif', 'previous logo sentinel');
    assert_eq(303, $c['superadmin']->status('/admin/logo', [], $upload));
    assert_eq('previous logo sentinel', file_get_contents(dirname($target) . '/logo.gif'));
    assert_true(str_contains($c['superadmin']->body('/admin/settings'), 'Could not save logo'), 'no save error flash');
}));

($t)('first web account uses the atomic bootstrap', $backend(function (Site $s, array $c) {
    // Wiring guard complements the concurrent model tests above.
    assert_true(str_contains(file_get_contents($s->root . '/public/index.php'), 'User::createFirst($username, $email, $password)'), 'web bootstrap not atomic');
    $pdo = new PDO('sqlite:' . $s->db);
    $pdo->exec('DELETE FROM dn_post_category; DELETE FROM dn_post; DELETE FROM dn_user;');
    $pdo = null;
    $anon = $c['anon'];
    preg_match('/name="csrf" value="([^"]+)"/', $anon->body('/admin/login'), $m);
    assert_eq(303, $anon->status('/admin/login', ['csrf' => $m[1] ?? '', 'username' => 'first-owner', 'email' => 'owner@example.test', 'password' => 'New-owner-pass-12345', 'role' => 'writer']));
    assert_eq([['first-owner', 'superadmin']], $s->rows('SELECT username,role FROM dn_user'));
    assert_eq(200, $anon->status('/admin/users'));
}));

echo "\n=== {$pass} passed";
if ($fail > 0) {
    echo ", {$fail} FAILED:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    echo "\033[0m\n";
    exit(1);
}
echo " / " . ($pass + $fail) . "\033[0m\n";
exit(0);
