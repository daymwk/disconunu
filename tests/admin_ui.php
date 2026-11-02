<?php
declare(strict_types=1);

/**
 * Admin UI contracts and starter-theme packaging — rendered markup checks.
 *   php tests/admin_ui.php
 * Every render runs real templates and models in a child PHP process against
 * a throwaway SQLite database. No live data, uploads or credentials are used.
 */

$root = dirname(__DIR__);
$theme = $root . '/themes/teal';
// Child PHP processes need the extensions the CLI may ship disabled.
exec(escapeshellarg(PHP_BINARY) . ' -m', $modules);
$modules = array_map('strtolower', array_map('trim', $modules));
$php = [PHP_BINARY];
foreach (['pdo_sqlite', 'sqlite3', 'zip'] as $ext) {
    if (!in_array($ext, $modules, true)) array_push($php, '-d', 'extension=' . $ext);
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
    if ($cond !== true) {
        throw new \Exception($msg);
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception(sprintf(
            '%s expected=%s got=%s',
            $msg ?: 'value',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_in(string $needle, string $haystack, string $msg = ''): void
{
    assert_true(str_contains($haystack, $needle), ($msg ?: 'missing') . ': ' . $needle);
}

function rm_tree(string $dir): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}

/** Run PHP code in a child process; returns [exit code, stdout, stderr]. */
function run_php(array $cmd, array $env, string $cwd): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env + getenv());
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

const RENDER = <<<'PHP'
require 'autoload.php';
DN\App::boot();
DN\Migrator::run();
DN\App::startSession();
$actor = DN\Model\User::create('ui-tester', 'tester@example.test', bin2hex(random_bytes(24)), getenv('UI_ROLE'));
$_SESSION['user_id'] = $actor->id;
$draft = DN\Model\Post::create($actor->id, 'Draft title', '<p>Two words</p>', 'Two words', 'draft');
$published = DN\Model\Post::create($actor->id, 'Published title', '<p>Live words</p>', 'Live words', 'published');
$posts = DN\Model\Post::all();
$template = getenv('UI_TEMPLATE');
$uri = match($template) {
    'dashboard' => '/admin', 'post-edit' => '/admin/post/' . $draft->id . '/edit',
    default => '/admin/' . $template,
};
$_SERVER['REQUEST_URI'] = $uri;
$data = [
    'posts' => $posts, 'post' => DN\Model\Post::findById($draft->id),
    'cats' => DN\Model\Category::all(), 'categories' => DN\Model\Category::all(),
    'authors' => [$actor], 'users' => [$actor], 'superadminCount' => 1,
    'myCats' => [], 'selectedCatIds' => [], 'status' => '', 'current' => 'teal',
    'page' => 2, 'pages' => 8, 'total' => 142, 'perPage' => 20,
    'filters' => ['q' => 'tea & cake', 'status' => '', 'category' => '', 'author' => '', 'sort' => 'newest', 'role' => '', 'visibility' => '', 'per_page' => 20],
    'paginationUrl' => $uri . '?q=tea%20%26%20cake&page={page}',
    'error' => '', 'noUsers' => false,
];
if (getenv('UI_SCENARIO') === 'repopulate') {
    $category = DN\Model\Category::create('Tea & "cake"');
    $data['cats'] = DN\Model\Category::all();
    $data['selectedCatIds'] = [$category->id];
    $data['post']->title = 'Submitted "title"';
    $data['post']->content = '<p>Submitted & safe</p>';
}
if (getenv('UI_SCENARIO') === 'published') $data['post'] = $published;
if (getenv('UI_SCENARIO') === 'foreign') {
    $other = DN\Model\User::create('other', 'other@example.test', bin2hex(random_bytes(24)), 'writer');
    $data['post'] = DN\Model\Post::create($other->id, 'Other draft', '<p>Private</p>', 'Private', 'draft');
}
if (getenv('UI_SCENARIO') === 'new') $data['post'] = null;
if (getenv('UI_SCENARIO') === 'new-validation') {
    $data['post']->id = 0;
    $data['post']->status = 'published';
}
if (getenv('UI_SCENARIO') === 'empty') {
    $data['cats'] = []; $data['users'] = [];
    $data['total'] = 0; $data['page'] = 1; $data['pages'] = 1;
}
DN\View::render('admin/' . $template, $data);
session_destroy();
PHP;

/** Render an admin template as the given role, in an isolated child process. */
$render = static function (string $template, string $role = 'superadmin', string $scenario = 'default') use ($php, $root): string {
    $tmp = sys_get_temp_dir() . '/dn-admin-ui-' . bin2hex(random_bytes(6));
    mkdir($tmp);
    try {
        [$code, $out, $err] = run_php(
            array_merge($php, ['-d', 'session.save_path=' . $tmp, '-r', RENDER]),
            ['DN_DB_DRIVER' => 'sqlite', 'DN_DB_DATABASE' => $tmp . '/test.sqlite', 'DN_APP_URL' => 'http://example.test',
             'DN_APP_DEBUG' => '1', 'UI_TEMPLATE' => $template, 'UI_ROLE' => $role, 'UI_SCENARIO' => $scenario],
            $root
        );
    } finally {
        rm_tree($tmp);
    }
    if ($code !== 0 || $err !== '' || str_contains($out, 'Server error 500')) {
        throw new \Exception("render {$template} failed: " . $err . $out);
    }
    return $out;
};

/** Every start tag as [name, attributes]; a present-but-empty attribute maps to ''. */
function tags(string $html): array
{
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $tags = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        $attrs = [];
        foreach ($el->attributes as $a) $attrs[$a->name] = $a->value;
        $tags[] = [$el->tagName, $attrs];
    }
    return $tags;
}

function attrs(array $tags, string $tag): array
{
    return array_values(array_map(static fn($x) => $x[1], array_filter($tags, static fn($x) => $x[0] === $tag)));
}

function field_names(array $tags): array
{
    $names = [];
    foreach ($tags as [$tag, $a]) {
        if (in_array($tag, ['input', 'select'], true) && isset($a['name'])) $names[] = $a['name'];
    }
    return $names;
}

function any(array $items, callable $fn): bool
{
    foreach ($items as $i) if ($fn($i)) return true;
    return false;
}

function has_fields(array $tags, array $expected): void
{
    $missing = array_diff($expected, field_names($tags));
    assert_eq([], array_values($missing), 'missing fields');
}

$source = static fn(string $path): string => file_get_contents($theme . '/' . $path);
$href = static fn(array $a): string => $a['href'] ?? '';
$action = static fn(array $a): string => $a['action'] ?? '';

echo "\n=== Disco nunu — admin UI ===\n\n";

($t)('post listing: filters, pagination, writer view-only', function () use ($render, $href, $action) {
    $html = $render('dashboard', 'writer');
    $tags = tags($html);
    has_fields($tags, ['q', 'status', 'category', 'author', 'sort', 'per_page']);
    assert_in('value="tea &amp; cake"', $html);
    assert_in('142', $html);
    $links = attrs($tags, 'a');
    assert_true(any($links, fn($a) => str_contains($href($a), 'page=3') && str_contains($href($a), 'tea%20%26%20cake')), 'page 3 link keeps filters');
    assert_true(!any($links, fn($a) => str_contains($href($a), '/admin/post/2/edit')), 'no edit link for published post');
    assert_true(!any(attrs($tags, 'form'), fn($a) => str_contains($action($a), '/admin/post/2/delete')), 'no delete form for published post');
    assert_true(any($links, fn($a) => str_contains($href($a), '/admin/post/1/edit')), 'draft edit link');
    assert_true(any($links, fn($a) => str_contains($href($a), '/admin/post/1/preview')), 'draft preview link');
    foreach ($links as $a) {
        if (str_contains($href($a), '/post/published-title') || str_contains($href($a), '/preview')) {
            assert_eq('_blank', $a['target'] ?? null, 'new-tab target');
            assert_true(str_contains($a['rel'] ?? '', 'noopener'), 'noopener');
        }
    }
    assert_eq(0, preg_match('/\son(?:submit|click)=/', $html), 'inline handlers');
});

($t)('editor: safe save, preview, writing help', function () use ($render, $source, $href) {
    $html = $render('post-edit');
    $tags = tags($html);
    $values = array_map(fn($a) => $a['value'] ?? null, array_filter(attrs($tags, 'button'), fn($a) => ($a['name'] ?? '') === 'save_action'));
    sort($values);
    assert_eq(['continue', 'list'], $values, 'save actions');
    assert_true(any(attrs($tags, 'form'), fn($a) => array_key_exists('data-editor', $a)), 'data-editor form');
    assert_true(any(attrs($tags, 'a'), fn($a) => str_ends_with($href($a), '/admin/post/1/preview') && ($a['target'] ?? '') === '_blank'), 'preview link');
    assert_in('id="word-count"', $html);
    assert_in('id="html-help"', $html);
    assert_in('name="categories[]"', $html);
    $writer = $render('post-edit', 'writer');
    assert_true(!str_contains($writer, 'name="categories[]"'), 'writer sees no categories');
    assert_in('name="status" value="draft"', $writer);
    $js = $source('js/admin.js');
    foreach (['beforeunload', 'requestSubmit', 'metaKey', 'ctrlKey', 'data-confirm', 'defaultPrevented'] as $hook) {
        assert_in($hook, $js, 'admin.js hook');
    }
    assert_eq(0, preg_match('/\son(?:submit|click)=/', $html), 'inline handlers');
    assert_eq(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>\s*\S/', $html), 'inline script');
});

($t)('editor repopulates submitted categories', function () use ($render) {
    $html = $render('post-edit', 'superadmin', 'repopulate');
    $selected = array_filter(attrs(tags($html), 'input'), fn($a) => ($a['name'] ?? '') === 'categories[]' && array_key_exists('checked', $a));
    assert_eq(['2'], array_values(array_map(fn($a) => $a['value'], $selected)), 'checked categories');
    assert_in('value="Submitted &quot;title&quot;"', $html);
    assert_in('&lt;p&gt;Submitted &amp; safe&lt;/p&gt;', $html);
});

($t)('writer cannot edit published or foreign posts', function () use ($render) {
    foreach (['published', 'foreign'] as $scenario) {
        $html = $render('post-edit', 'writer', $scenario);
        assert_true(!any(attrs(tags($html), 'form'), fn($a) => array_key_exists('data-editor', $a)), "{$scenario}: no editor form");
        assert_in('This post cannot be edited', $html, $scenario);
    }
});

($t)('settings install trusted themes without implicit activation', function () use ($render, $href) {
    $html = $render('settings');
    $tags = tags($html);
    $inputs = [];
    foreach (attrs($tags, 'input') as $a) $inputs[$a['name'] ?? ''] = $a;
    assert_true(isset($inputs['activate']), 'activate input');
    assert_eq('checkbox', $inputs['activate']['type'] ?? null);
    assert_eq('1', $inputs['activate']['value'] ?? null);
    assert_true(!array_key_exists('checked', $inputs['activate']), 'activate not pre-checked');
    assert_eq('image/png,image/jpeg,image/gif,image/webp', $inputs['logo']['accept'] ?? null);
    assert_eq([], array_values(array_diff(['site_title', 'site_tagline', 'footer_text', 'rss_enabled', 'posts_per_page'], array_keys($inputs))), 'missing inputs');
    assert_true(any(attrs($tags, 'a'), fn($a) => str_ends_with($href($a), '/admin/theme/starter')), 'starter link');
    assert_in('Download starter ZIP</a>', $html);
    assert_in('trusted', strtolower($html));
    assert_in('PHP', $html);
    assert_eq(0, preg_match('/\son\w+=/', $html), 'inline handlers');
    assert_true(any(attrs($tags, 'button'), fn($a) => array_key_exists('data-confirm', $a)), 'data-confirm button');
    assert_true(any(attrs($tags, 'section'), fn($a) => ($a['id'] ?? '') === 'identity'), 'identity section');
    assert_true(any(attrs($tags, 'section'), fn($a) => ($a['id'] ?? '') === 'themes'), 'themes section');
});

$listChecks = static function (string $html, array $tags, array $fields) use ($href): void {
    assert_true(any(attrs($tags, 'form'), fn($a) => ($a['method'] ?? '') === 'get' && ($a['class'] ?? '') === 'filter-bar'), 'GET filter-bar form');
    has_fields($tags, $fields);
    assert_in('value="tea &amp; cake"', $html);
    assert_in('142 results', $html);
    assert_in('Page 2 of 8', $html);
    assert_true(any(attrs($tags, 'a'), fn($a) => str_contains($href($a), 'page=3') && str_contains($href($a), 'tea%20%26%20cake')), 'page 3 link keeps filters');
    foreach (attrs($tags, 'td') as $a) assert_true(array_key_exists('data-label', $a), 'td without data-label');
    assert_eq(0, preg_match('/\son\w+=/', $html), 'inline handlers');
};

($t)('categories: filters, paginated responsive management', function () use ($render, $listChecks, $action) {
    $html = $render('categories');
    $tags = tags($html);
    $listChecks($html, $tags, ['q', 'visibility', 'per_page']);
    assert_true(!any(attrs($tags, 'form'), fn($a) => str_ends_with($action($a), '/categories/1/delete')), 'General is deletable');
    assert_in('General is the fallback category', $html);
});

($t)('users: filters, paginated responsive management', function () use ($render, $listChecks, $action) {
    $html = $render('users');
    $tags = tags($html);
    $listChecks($html, $tags, ['q', 'role', 'per_page']);
    assert_true(!any(attrs($tags, 'form'), fn($a) => preg_match('#/users/1/(role|delete)$#', $action($a)) === 1), 'self role/delete form');
    assert_in('own drafts', $html);
    assert_in('class="role-guide"', $html);
    assert_true(any(attrs($tags, 'form'), fn($a) => str_ends_with($action($a), '/users/create')), 'create form');
});

($t)('empty lists offer filter recovery', function () use ($render) {
    foreach (['categories', 'users'] as $template) {
        $html = $render($template, 'superadmin', 'empty');
        assert_in('class="empty-state"', $html, $template);
        assert_in('No ' . $template . ' found', $html, $template);
        assert_in('0 results', $html, $template);
        assert_in('Reset', $html, $template);
        assert_true(!str_contains($html, 'class="pagination"'), "{$template}: pagination shown");
    }
});

($t)('new-post validation keeps status, no fake preview or id', function () use ($render, $href) {
    $html = $render('post-edit', 'superadmin', 'new-validation');
    $tags = tags($html);
    assert_in('<h1>New post</h1>', $html);
    assert_true(!any(attrs($tags, 'input'), fn($a) => ($a['name'] ?? '') === 'id'), 'id input');
    assert_true(!any(attrs($tags, 'a'), fn($a) => str_contains($href($a), '/preview')), 'preview link');
    assert_true(any(attrs($tags, 'option'), fn($a) => ($a['value'] ?? '') === 'published' && array_key_exists('selected', $a)), 'published selected');
    assert_true(!any(attrs($tags, 'option'), fn($a) => ($a['value'] ?? '') === 'draft' && array_key_exists('selected', $a)), 'draft selected');
});

($t)('admin shell is separate and accessible', function () use ($source) {
    $layout = $source('layout.php');
    foreach (['class="admin-shell"', 'id="main-content"', 'class="skip-link"', '/css/admin.css', '/js/admin.js'] as $s) assert_in($s, $layout, 'layout');
    $nav = $source('admin/nav.php');
    assert_in('aria-label="Administration"', $nav, 'nav');
    assert_in('aria-current="page"', $nav, 'nav');
    assert_eq(1, preg_match('/target="_blank" rel="noopener(?: noreferrer)?"/', $nav), 'nav new-tab link');
    $css = $source('css/admin.css');
    foreach (['min-width: 0', '@media', 'var(--font-ui)'] as $s) assert_in($s, $css, 'admin.css');
    assert_eq(0, preg_match('/\s(?:style|on\w+)=/', $nav), 'nav inline style/handler');
});

echo "\n-- Starter theme --\n";

($t)('starter theme can be packaged and rendered', function () use ($root, $php) {
    $src = $root . '/examples/starter-theme';
    assert_true(is_file($src . '/layout.php'), 'Starter layout is missing');
    assert_true(is_file($src . '/css/style.css'), 'starter css');
    assert_true(is_file($src . '/README.md'), 'starter README');
    $tmp = sys_get_temp_dir() . '/dn-starter-' . bin2hex(random_bytes(6));
    mkdir($tmp);
    try {
        $archive = $tmp . '/starter-theme.zip';
        $zip = new ZipArchive();
        assert_true($zip->open($archive, ZipArchive::CREATE) === true, 'zip open');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if ($f->isFile()) $zip->addFile($f->getPathname(), 'starter-theme/' . substr($f->getPathname(), strlen($src) + 1));
        }
        assert_true($zip->close(), 'zip close');
        $zip = new ZipArchive();
        assert_true($zip->open($archive, ZipArchive::CHECKCONS) === true, 'zip integrity');
        assert_true($zip->locateName('starter-theme/layout.php') !== false, 'layout in zip');
        $zip->close();

        $env = ['DN_DB_DATABASE' => $tmp . '/site.sqlite'];
        [$code, , $err] = run_php(array_merge($php, ['bin/disconunu', 'migrate']), $env, $root);
        assert_eq(0, $code, 'migrate: ' . $err);
        $script = <<<'PHP'
        require 'autoload.php'; DN\App::boot();
        $appName='<Example>'; $subtitle='Small site'; $baseUrl='http://example.test';
        $title='Example'; $themeUrl='/themes/starter-theme'; $fallbackThemeUrl='/themes/teal';
        $logoUrl='/themes/teal/images/logo.jpg'; $rssEnabled=true; $user=null;
        $cats=[]; $flashes=[]; $content='<article>Test content</article>';
        require 'examples/starter-theme/layout.php';
        PHP;
        [$code, $out, $err] = run_php(array_merge($php, ['-r', $script]), $env, $root);
        assert_eq(0, $code, 'render exit');
        assert_eq('', $err, 'render stderr');
        foreach (['&lt;Example&gt;', '<article>Test content</article>', 'lang="en"', '/feed', 'site-footer'] as $s) assert_in($s, $out, 'starter render');
    } finally {
        rm_tree($tmp);
    }
});

echo "\n=== {$pass} passed";
if ($fail > 0) {
    echo ", {$fail} FAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\033[0m\n";
    exit(1);
}
echo " / " . ($pass + $fail) . "\033[0m\n";
exit(0);
