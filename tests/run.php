<?php
declare(strict_types=1);

/**
 * Disco nunu test suite — standalone runner, zero dependency (no PHPUnit needed).
 *   php tests/run.php
 * Every run starts from a fresh SQLite database (temp file, deleted at start).
 *
 * NOTE: 404/405/403 paths go through Http::abort() which exits the process —
 * they are covered end-to-end by tests/smoke_http.sh rather than here.
 */

// Theme installation tests must never write into the working application.
$testRoot = sys_get_temp_dir() . '/dn_app_' . getmypid();
$copy = static function (string $from, string $to) use (&$copy): void {
    mkdir($to, 0775, true);
    foreach (scandir($from) as $entry) {
        if (in_array($entry, ['.', '..', '.git', 'var'], true)) continue;
        $src = $from . '/' . $entry;
        if (is_link($src)) continue;
        is_dir($src) ? $copy($src, $to . '/' . $entry) : copy($src, $to . '/' . $entry);
    }
};
$copy(dirname(__DIR__), $testRoot);
register_shutdown_function(static function () use ($testRoot): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($testRoot);
    @unlink(getenv('DN_DB_DATABASE'));
});
require $testRoot . '/autoload.php';

putenv('DN_DB_DRIVER=sqlite');
putenv('DN_DB_DATABASE=' . sys_get_temp_dir() . '/dn_tests_' . getmypid() . '.sqlite');
putenv('DN_APP_URL=http://example.test');

use DN\Acl;
use DN\App;
use DN\Database;
use DN\Migrator;
use DN\Model\Category;
use DN\Model\Post;
use DN\Model\Settings;
use DN\Model\User;
use DN\Router;
use DN\Security;
use DN\ThemeManager;

App::boot();

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

echo "\n=== Disco nunu — test suite ===\n";

// fresh database
@unlink(getenv('DN_DB_DATABASE'));
Database::resetConnection();
Migrator::run();

echo "\n-- Migrations & schema --\n";

($t)('migrations are idempotent (second run applies nothing)', function () {
    assert_eq([], Migrator::run(), 'second run');
});

($t)('schema present, legacy menu tables gone', function () {
    foreach (['dn_user', 'dn_post', 'dn_category', 'dn_post_category', 'dn_setting', 'dn_migrations'] as $tbl) {
        Database::get()->pdo()->query("SELECT COUNT(*) FROM {$tbl}");
    }
    $legacy = Database::get()->pdo()
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'dn_menu%'")
        ->fetchAll();
    assert_eq([], $legacy, 'menu tables dropped');
});

($t)('default category "general" exists after migrate', function () {
    $g = Category::bySlug('general');
    assert_true($g !== null, 'general category created');
    assert_eq(true, $g->visible, 'general is visible');
});

echo "\n-- Users & auth --\n";

($t)('user creation + strong password hash', function () {
    $u = User::create('alice', 'alice@example.test', 'a-strong-password-42');
    assert_true($u->id > 0, 'auto id');
    assert_true(str_starts_with($u->passwordHash, '$argon2') || str_starts_with($u->passwordHash, '$2y$'), 'strong hash');
    assert_true($u->verifyPassword('a-strong-password-42'), 'verify ok');
    assert_eq(false, $u->verifyPassword('wrong'), 'wrong password rejected');
});

($t)('duplicate email rejected (UNIQUE constraint)', function () {
    try {
        User::create('bob', 'alice@example.test', 'another-password-here');
        assert_true(false, 'should have thrown');
    } catch (\PDOException $e) {
        assert_true(true);
    }
});

($t)('roles: valid set only, role can be changed', function () {
    $u = User::create('carol', 'carol@example.test', 'yet-another-pass-1', Acl::WRITER);
    assert_eq(Acl::WRITER, $u->role, 'default writer');
    $u->setRole(Acl::ADMIN);
    assert_eq(Acl::ADMIN, $u->role, 'role updated');
    try {
        $u->setRole('root');
        assert_true(false, 'invalid role should throw');
    } catch (\InvalidArgumentException $e) {
        assert_true(true);
    }
});

($t)('new model users default to writer', function () {
    assert_eq(Acl::WRITER, User::create('default_writer', 'default@test.x', 'password-writer')->role);
});

 echo "\n-- Posts, slugs, pagination --\n";

($t)('slug is ASCII, unique, suffixed on collision', function () {
    $p1 = Post::create(1, 'Ratatouille Recipe à la Provençale', '<p>x</p>', 'x', 'published');
    $p2 = Post::create(1, 'Ratatouille Recipe à la Provençale', '<p>y</p>', 'y', 'published');
    assert_eq('ratatouille-recipe-a-la-provencale', $p1->slug);
    assert_eq('ratatouille-recipe-a-la-provencale-2', $p2->slug);
});

($t)('drafts are hidden publicly, visible to admins', function () {
    $d = Post::create(1, 'Secret draft', '<p>psst</p>', 'psst', 'draft');
    assert_eq(null, Post::findBySlug($d->slug, true), 'hidden from public');
    assert_true(Post::findBySlug($d->slug, false) !== null, 'visible to admin');
});

($t)('pagination: 12 posts, page 2 of 5-per-page', function () {
    for ($i = 1; $i <= 12; $i++) {
        Post::create(1, "Pagination post {$i}", "<p>body {$i}</p>", "excerpt {$i}", 'published');
    }
    $total = Post::countPublished();
    assert_true($total >= 12, "total>=12 (got {$total})");
    $page2 = Post::publishedPage(2, 5);
    assert_true(count($page2) >= 1, 'page 2 not empty');
    assert_true(count(Post::publishedPage(1, 5)) === 5, 'page 1 holds exactly 5');
});

($t)('full-text search finds a word from the body', function () {
    Post::create(1, 'Kitchen notes', '<p><b>cardamom</b> changes everything</p>', 'excerpt', 'published');
    $r = Post::search('cardamom');
    assert_true(count($r) >= 1, 'at least 1 result');
    assert_true(str_contains($r[0]->content, 'cardamom'), 'right post');
});

($t)('search input is sanitized (no FTS syntax crash)', function () {
    $r = Post::search('cardamom" OR "');
    assert_true(is_array($r), 'returns an array, does not throw');
});

($t)('search survives a broken/missing full-text index', function () {
    Post::create(1, 'Resilience', '<p>the <b>saffron</b> note</p>', 'x', 'published');
    Database::get()->pdo()->exec('DROP TABLE IF EXISTS dn_post_fts');
    $r = Post::search('saffron');
    assert_true(is_array($r), 'returns an array instead of throwing');
    assert_true(count($r) >= 1, 'LIKE fallback still finds the post');

    // ensureSearchIndex() repairs the index, so writes and search work again
    assert_true(Migrator::ensureSearchIndex(), 'index recreated');
    Post::create(1, 'After repair', '<p>turmeric</p>', 'x', 'published');
    assert_true(count(Post::search('turmeric')) >= 1, 'search works again after repair');
    assert_true(Post::rebuildIndex(), 'rebuild runs on an existing index');
});

($t)('future publication is hidden from permalink and search', function () {
    $p = Post::create(1, 'futuresecret', '<p>futuresecret</p>', 'futuresecret', 'published');
    Database::get()->pdo()->prepare('UPDATE dn_post SET published_at = ? WHERE id = ?')->execute(['2999-01-01 00:00:00', $p->id]);
    assert_eq(null, Post::findBySlug($p->slug));
    assert_eq([], Post::search('futuresecret'));
    Database::get()->pdo()->exec('DROP TABLE dn_post_fts');
    assert_eq([], Post::search('futuresecret'), 'LIKE fallback also hides future posts');
});

($t)('update stamps the publication date', function () {
    $p = Post::create(1, 'Publish me later', '<p>draft</p>', '', 'draft');
    assert_eq(null, $p->publishedAt, 'draft has no publish date');
    $p->update('Publish me later', '<p>final</p>', 'final', 'published');
    assert_true($p->publishedAt !== null, 'published and stamped');
});

($t)('delete removes the post', function () {
    $p = Post::create(1, 'Delete me', '<p>bye</p>', '', 'published');
    $id = $p->id;
    $p->delete();
    assert_eq(null, Post::findById($id), 'gone');
});

echo "\n-- Security --\n";

($t)('sanitizeHtml strips scripts/on*/javascript:', function () {
    $dirty = '<p onclick="evil()">ok</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)>';
    $clean = Security::sanitizeHtml($dirty);
    assert_true(!str_contains($clean, '<script'), 'script removed');
    assert_true(!str_contains($clean, 'onclick'), 'onclick removed');
    assert_true(!str_contains($clean, 'javascript:'), 'javascript: removed');
    assert_true(!str_contains($clean, 'onerror'), 'onerror removed');
    assert_true(str_contains($clean, '<p>ok</p>'), 'legit markup kept');
});

($t)('sanitizeHtml breaks data: URLs in href/src', function () {
    $clean = Security::sanitizeHtml('<a href="data:text/html,<script>evil()</script>">x</a>');
    assert_true(!str_contains($clean, 'data:text/html'), 'data: removed');
});

($t)('excerpt is plain text, truncated', function () {
    $ex = Security::excerpt('<p>Hello   the <b>world</b></p>' . str_repeat('word ', 200), 50);
    assert_true(!str_contains($ex, '<'), 'no HTML');
    assert_true(mb_strlen($ex) <= 50, 'truncated to 50');
    assert_true(str_ends_with($ex, '…'), 'ellipsis marker');
});

($t)('slugify is injection-proof', function () {
    assert_eq('hello-world', Post::slugify('Hello, World!'));
    assert_eq('ca-fe-creme', Post::slugify('ça-fé & crème'), 'accents transliterated, separators collapsed');
    assert_eq('', Post::slugify('***'), 'symbols only -> empty');
    assert_eq('etc-passwd', Post::slugify('../../etc/passwd'), 'path traversal neutralized');
});

echo "\n-- Categories --\n";

($t)('create, visibility toggle, attach, detach on delete', function () {
    $c1 = Category::create('Recipes');
    $c2 = Category::create('Notes', 'notes', false);
    assert_eq('recipes', $c1->slug);
    assert_eq(true, $c1->visible);
    assert_eq(false, $c2->visible);

    $p = Post::create(1, 'Test dish', '<p>x</p>', 'x', 'published');
    Category::setPostCategories($p->id, [$c1->id, $c2->id, $c2->id]);
    assert_eq(2, count(Category::forPost($p->id)), '2 categories (deduplicated)');
    // "general" (created by the migration) is visible too: 2 visible = general + recipes
    assert_eq(2, count(Category::visible()), 'general + recipes visible');

    assert_eq(1, Post::countPublishedIn($c1->id), 'countPublishedIn sees the post');
    assert_true(count(Post::publishedPageIn($c1->id, 1, 5)) >= 1, 'publishedPageIn lists the post');

    $c1->update('Cooking', false);
    assert_eq(1, count(Category::visible()), 'hiding a category removes it from the menu');

    $c1->delete();
    assert_eq(null, Category::byId($c1->id), 'deleted');
    assert_eq(1, count(Category::forPost($p->id)), 'c1 detached, c2 kept');
});

($t)('posts without a category fall back to "general"', function () {
    $g = Category::bySlug('general');
    $p = Post::create(1, 'Uncategorized post', '<p>z</p>', 'z', 'published');
    Migrator::afterMigrate(); // what `migrate` runs
    $cats = Category::forPost($p->id);
    assert_eq(1, count($cats), 'exactly one category');
    assert_eq($g->id, $cats[0]->id, 'it is general');
});

($t)('deleting the final assigned category immediately reattaches General', function () {
    $c = Category::create('Last category');
    $p = Post::create(1, 'Category survivor', '<p>x</p>', '', 'draft');
    Category::setPostCategories($p->id, [$c->id]);
    $c->delete();
    assert_eq([Category::bySlug('general')->id], array_map(static fn($c) => $c->id, Category::forPost($p->id)));
});

($t)('General cannot be deleted and keeps its reserved slug on rename', function () {
    $g = Category::bySlug('general');
    $g->update('Everything', true);
    assert_eq('general', Category::byId($g->id)->slug);
    $g->delete();
    assert_true(Category::bySlug('general') !== null);
});

($t)('new posts and empty or invalid assignments immediately fall back to General', function () {
    $p = Post::create(1, 'Immediate General', '<p>x</p>', '', 'draft');
    $general = Category::bySlug('general')->id;
    assert_eq([$general], array_map(static fn($c) => $c->id, Category::forPost($p->id)));
    Category::setPostCategories($p->id, [999999, 0]);
    assert_eq([$general], array_map(static fn($c) => $c->id, Category::forPost($p->id)));
    Category::setPostCategories($p->id, []);
    assert_eq([$general], array_map(static fn($c) => $c->id, Category::forPost($p->id)));
});

echo "\n-- Roles & ACL --\n";

($t)('ACL: writers reach only their own posts, admins cannot touch a superadmin post', function () {
    $sa = User::create('sa_test', 'sa@test.x', 'password-super-1', Acl::SUPERADMIN);
    $ad = User::create('ad_test', 'ad@test.x', 'password-admin-1', Acl::ADMIN);
    $wr = User::create('wr_test', 'wr@test.x', 'password-writer1', Acl::WRITER);
    $wrPost = Post::create($wr->id, 'W1', '<p>a</p>', 'a', 'draft');
    $saPost = Post::create($sa->id, 'S1', '<p>b</p>', 'b', 'published');

    assert_true(Acl::canPublish($sa), 'superadmin can publish');
    assert_true(Acl::canPublish($ad), 'admin can publish');
    assert_eq(false, Acl::canPublish($wr), 'writer cannot publish');
    assert_eq(false, Acl::canAdminister($ad), 'admin has no settings access');
    assert_true(Acl::canAdminister($sa), 'superadmin holds settings access');
    assert_true(Acl::canManageCategories($ad), 'admin manages categories');

    assert_true(Acl::canEditPost($wr, $wrPost->userId), 'writer edits own post');
    assert_eq(false, Acl::canEditPost($wr, $saPost->userId), 'writer cannot touch others');
    assert_true(Acl::canDeletePost($wr, 'writer', $wrPost->userId), 'writer deletes own post');
    assert_eq(false, Acl::canDeletePost($ad, 'superadmin', $saPost->userId), 'admin cannot delete a superadmin post');
    assert_true(Acl::canDeletePost($ad, 'writer', $wrPost->userId), 'admin deletes a writer post');
    assert_true(Acl::canDeletePost($sa, 'superadmin', $saPost->userId), 'superadmin can do anything');
});

($t)('user deletion rules: not yourself, not the last superadmin', function () {
    $sa  = User::create('sa_del', 'sa_del@test.x', 'password-super-9', Acl::SUPERADMIN);
    $ad  = User::create('ad_del', 'ad_del@test.x', 'password-admin-9', Acl::ADMIN);
    $wr  = User::create('wr_del', 'wr_del@test.x', 'password-writer-9', Acl::WRITER);

    $supers = User::countByRole(Acl::SUPERADMIN);
    assert_true($supers >= 1, 'superadmin counted');

    assert_eq(false, Acl::canDeleteUser($sa, $sa, $supers), 'cannot delete yourself');
    assert_true(Acl::canDeleteUser($sa, $wr, $supers), 'superadmin deletes a writer');
    assert_true(Acl::canDeleteUser($sa, $ad, $supers), 'superadmin deletes an admin');
    assert_eq(false, Acl::canDeleteUser($ad, $wr, $supers), 'admin cannot manage accounts at all');

    // a lone superadmin is protected, but is deletable once another one exists
    $lone = User::create('sa_lone', 'sa_lone@test.x', 'password-super-8', Acl::SUPERADMIN);
    $others = User::countByRole(Acl::SUPERADMIN) - 1;
    assert_eq(false, Acl::canDeleteUser($sa, $lone, 1), 'last superadmin protected');
    assert_true(Acl::canDeleteUser($sa, $lone, 2), 'deletable when another superadmin exists');
    assert_true($others >= 1, 'two superadmins in the fixture');

    $before = User::countByRole(Acl::WRITER);
    $id = $wr->id;
    $wr->delete();
    assert_eq(null, User::findById($id), 'account deleted');
    assert_eq($before - 1, User::countByRole(Acl::WRITER), 'role counter follows deletions');
});

($t)('writers cannot edit or delete even their own published posts', function () {
    $wr = User::findByUsername('wr_test');
    assert_eq(false, Acl::canEditPost($wr, $wr->id, Acl::WRITER, 'published'));
    assert_eq(false, Acl::canDeletePost($wr, Acl::WRITER, $wr->id, 'published'));
    assert_true(Acl::canEditPost($wr, $wr->id, Acl::WRITER, 'draft'));
    assert_true(Acl::canDeletePost($wr, Acl::WRITER, $wr->id, 'draft'));
});

echo "\n-- Settings --\n";

($t)('settings override config defaults, values are clamped', function () {
    Settings::set('site_title', 'My blog');
    assert_eq('My blog', Settings::siteTitle());
    Settings::set('site_title', '');
    assert_true(Settings::siteTitle() !== '', 'falls back to config');

    Settings::set('posts_per_page', '999');
    assert_eq(50, Settings::postsPerPage(), 'clamped to 50');
    Settings::set('posts_per_page', '5');
    assert_eq(5, Settings::postsPerPage());

    assert_eq(true, Settings::rssEnabled(), 'RSS on by default');
    Settings::set('rss_enabled', '0');
    assert_eq(false, Settings::rssEnabled(), 'RSS can be switched off');
    Settings::set('rss_enabled', '1');

    Settings::set('footer_text', 'Custom footer');
    assert_eq('Custom footer', Settings::footerText());
    Settings::set('footer_text', '');
});

echo "\n-- Themes --\n";

($t)('ThemeManager rejects a zip without layout.php', function () {
    $zipPath = sys_get_temp_dir() . '/dn_badtheme.zip';
    $z = new \ZipArchive();
    $z->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $z->addFromString('style.css', 'body{}');
    $z->close();
    try {
        ThemeManager::installFromZip($zipPath);
        assert_true(false, 'should have refused');
    } catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'layout.php'), 'explicit error message');
    }
    @unlink($zipPath);
});

($t)('ThemeManager installs a real theme, blocks zip-slip, removes cleanly', function () {
    $root = App::rootDir() . '/themes';
    $zipPath = sys_get_temp_dir() . '/dn_goodtheme.zip';
    $z = new \ZipArchive();
    $z->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $z->addFromString('mini/layout.php', '<?= $content ?>');
    $z->addFromString('mini/css/style.css', 'body{}');
    $z->addFromString('../../evil.php', '<?php system($_GET["c"]);'); // zip-slip attempt
    $z->close();

    $rejected = false;
    try {
        ThemeManager::installFromZip($zipPath);
    } catch (\RuntimeException $error) {
        $rejected = true;
    }
    assert_true($rejected, 'unsafe archive rejected in full');
    $z->open($zipPath);
    $z->deleteName('../../evil.php');
    $z->close();
    $name = ThemeManager::installFromZip($zipPath);
    assert_true(ThemeManager::exists($name), "theme {$name} installed");
    assert_true(!file_exists($root . '/../evil.php') && !file_exists($root . '/evil.php'), 'zip-slip blocked');
    @unlink($zipPath);

    assert_true(ThemeManager::removeTheme($name), 'removeTheme works');
    assert_true(!ThemeManager::exists($name), 'theme gone');
});

($t)('the bundled teal theme is installable/visible', function () {
    assert_true(ThemeManager::exists('teal'), 'teal listed');
});

($t)('admin post pages filter before slicing beyond 500 posts', function () {
    $wr = User::findByUsername('wr_test');
    $cat = Category::create('Paging category');
    for ($i = 0; $i < 505; $i++) {
        $p = Post::create($wr->id, sprintf('Needle %03d', $i), '<p>body</p>', '', 'draft');
        Category::setPostCategories($p->id, [$cat->id]);
    }
    Post::create(1, 'Needle foreign', '<p>body</p>', '', 'draft');
    $r = Post::adminPage(['q'=>'Needle', 'status'=>'draft', 'category'=>$cat->id, 'sort'=>'oldest', 'page'=>101, 'per_page'=>5], $wr->id);
    assert_eq(505, $r['total']);
    assert_eq(101, $r['pages']);
    assert_eq(101, $r['page']);
    assert_eq(5, count($r['posts']));
    assert_eq('Needle 500', $r['posts'][0]->title);
    assert_true(str_contains($r['paginationUrl'], 'q=Needle'));
    assert_true(str_contains($r['paginationUrl'], 'page={page}'));
    assert_eq(0, Post::adminPage(['q'=>"' OR 1=1 --"])['total']);
    assert_eq(0, Post::adminPage(['author'=>1], $wr->id)['total']);
    assert_eq(100, Post::adminPage(['per_page'=>900, 'page'=>-3])['perPage']);
});

($t)('category pages combine query and visibility and clamp pages', function () {
    for ($i = 0; $i < 23; $i++) Category::create('Paged category ' . $i, null, false);
    $r = Category::adminPage(['q'=>'Paged category', 'visibility'=>'hidden', 'per_page'=>5, 'page'=>999]);
    assert_eq(23, $r['total']); assert_eq(5, $r['page']); assert_eq(3, count($r['cats']));
    assert_eq(0, Category::adminPage(['q'=>'Paged category', 'visibility'=>'visible'])['total']);
    assert_eq(0, Category::adminPage(['q'=>'%'])['total'], 'wildcards are literal');
});

($t)('user pages filter role and email search with real offsets', function () {
    $r = User::adminPage(['q'=>'@', 'role'=>'superadmin', 'per_page'=>1, 'page'=>2]);
    assert_eq(User::countByRole('superadmin'), $r['total']);
    assert_eq(1, count($r['users'])); assert_eq(2, $r['page']);
    assert_eq('superadmin', $r['users'][0]->role);
    assert_eq(0, User::adminPage(['q'=>"%' OR 1=1 --"])['total']);
});

echo "\n-- Router --\n";

($t)('router: params, method matching', function () {
    $router = new Router();
    $hit = null;
    $router->get('/post/{slug}', function (array $args) use (&$hit) { $hit = $args['slug']; });
    ob_start();
    $router->dispatch('GET', '/post/hello-world');
    ob_end_clean();
    assert_eq('hello-world', $hit, 'param extracted');
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
