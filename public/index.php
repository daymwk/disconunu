<?php
declare(strict_types=1);

/**
 * Disco nunu — single front controller.
 * Everything routes through here (public site + admin).
 */

require __DIR__ . '/../autoload.php';

use DN\Acl;
use DN\App;
use DN\Auth;
use DN\Http;
use DN\Model\Category;
use DN\Model\Post;
use DN\Model\Settings;
use DN\Model\User;
use DN\Router;
use DN\Security;
use DN\ThemeManager;
use DN\View;

App::boot();
Http::securityHeaders();

$router = new Router();

// ---------- Theme assets: /themes/<name>[/css|/js|/fonts|/images]/file ----------
// themes/ lives outside the docroot; this route serves it with an extension allowlist.
$path0 = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/themes/([a-zA-Z0-9_-]+)/(?:(css|js|fonts|images)/)?([A-Za-z0-9._-]+\.(?:css|js|png|jpe?g|gif|svg|webp|woff2?|ttf|ico))$#', $path0, $am)) {
    $root  = realpath(__DIR__ . '/../themes');
    $theme = $root . '/' . $am[1];
    $sub   = $am[2] ?? '';
    $file  = false;
    foreach (array_values(array_unique(array_filter([$sub !== '' ? $theme . '/' . $sub . '/' . $am[3] : null, $theme . '/css/' . $am[3], $theme . '/js/' . $am[3], $theme . '/' . $am[3]]))) as $candidat) {
        $real = realpath($candidat);
        if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $file = $real;
            break;
        }
    }
    if ($file === false) {
        Http::abort(404);
    }
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'css'   => 'text/css',
        'js'    => 'text/javascript',
        'svg'   => 'image/svg+xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'ico'   => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime . (str_starts_with($mime, 'text/') || str_contains($mime, 'javascript') ? '; charset=utf-8' : ''));
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

// Admin-uploaded site logo (var/uploads is outside the docroot too).
if (preg_match('#^/uploads/([A-Za-z0-9._-]+\.(?:png|jpe?g|gif|svg|webp|ico))$#', $path0, $am)) {
    $root = realpath(App::rootDir() . '/var/uploads');
    $file = $root !== false ? realpath($root . '/' . $am[1]) : false;
    if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        Http::abort(404);
    }
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        default => 'image/' . (pathinfo($file, PATHINFO_EXTENSION) === 'jpeg' ? 'jpeg' : pathinfo($file, PATHINFO_EXTENSION)),
    };
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

// ---------- Public ----------
$router->get('/', function (): void {
    $perPage = Settings::postsPerPage();
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $total   = Post::countPublished();
    $page    = min($page, max(1, (int)ceil($total / $perPage)));
    $posts   = Post::publishedPage($page, $perPage);

    View::render('home', [
        'posts' => $posts,
        'page'  => $page,
        'pages' => max(1, (int)ceil($total / $perPage)),
        'cats'  => Category::visible(),
        'title' => Settings::siteTitle(),
    ]);
});

$router->get('/category/{slug}', function (array $args): void {
    $cat = Category::bySlug((string)$args['slug']);
    if ($cat === null || !$cat->visible) {
        Http::abort(404, 'Category not found.');
    }
    $perPage = Settings::postsPerPage();
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $total   = Post::countPublishedIn($cat->id);
    $page    = min($page, max(1, (int)ceil($total / $perPage)));
    $posts   = Post::publishedPageIn($cat->id, $page, $perPage);

    View::render('category', [
        'cat'   => $cat,
        'posts' => $posts,
        'page'  => $page,
        'pages' => max(1, (int)ceil($total / $perPage)),
        'cats'  => Category::visible(),
        'title' => $cat->title,
    ]);
});

$router->get('/post/{slug}', function (array $args): void {
    $post = Post::findBySlug((string)$args['slug']);
    if ($post === null) {
        Http::abort(404, 'This post does not exist (or not yet).');
    }
    View::render('post', [
        'post'  => $post,
        'cats'  => Category::visible(),
        'title' => $post->title,
    ]);
});

$router->get('/search', function (): void {
    $q     = trim((string)($_GET['q'] ?? ''));
    $posts = $q !== '' ? Post::search($q) : [];
    View::render('search', ['q' => $q, 'posts' => $posts, 'title' => 'Search', 'cats' => Category::visible()]);
});

$router->get('/feed', function (): void {
    if (!Settings::rssEnabled()) {
        Http::abort(404, 'RSS is disabled.');
    }
    $posts = Post::publishedPage(1, 20);
    $base  = App::baseUrl();
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0"><channel>';
    echo '<title>' . htmlspecialchars(Settings::siteTitle(), ENT_XML1) . '</title>';
    echo '<link>' . htmlspecialchars($base, ENT_XML1) . '</link>';
    echo '<description>' . htmlspecialchars(Settings::siteTagline(), ENT_XML1) . '</description>';
    foreach ($posts as $p) {
        $link = htmlspecialchars($base . '/post/' . rawurlencode($p->slug), ENT_XML1);
        $date = $p->publishedAt ? gmdate('D, d M Y H:i:s', strtotime($p->publishedAt . ' UTC')) . ' GMT' : '';
        echo '<item><title>' . htmlspecialchars($p->title, ENT_XML1) . '</title>'
            . '<link>' . $link . '</link><guid isPermaLink="true">' . $link . '</guid>'
            . '<pubDate>' . $date . '</pubDate>'
            . '<description>' . htmlspecialchars(Security::excerpt($p->content), ENT_XML1) . '</description></item>';
    }
    echo '</channel></rss>';
});

// ---------- Admin: auth ----------
$router->any('/admin/login', function (): void {
    $error   = '';
    $noUsers = User::countAll() === 0;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
            $error = 'Session expired, try again.';
        } elseif ($noUsers) {
            // First run: the very first account becomes superadmin (simple self-hosting).
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $email    = trim((string)($_POST['email'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username) || strlen($password) < 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Login: 3-64 chars (no spaces); password: 10+ chars; valid email required.';
            } elseif (User::emailExists($email)) {
                $error = 'Email already in use.';
            } else {
                try {
                    User::createFirst($username, $email, $password);
                    Auth::attempt($username, $password);
                    Http::flash('ok', 'Admin created. Welcome to Disco nunu.');
                    Http::redirect('/admin');
                } catch (\RuntimeException $e) {
                    $noUsers = User::countAll() === 0;
                    if ($noUsers) throw $e;
                    $error = 'Initial setup has already been completed. Please sign in.';
                }
            }
        } elseif (Auth::attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
            Http::redirect('/admin');
        } else {
            $error = 'Invalid credentials.';
        }
    }
    View::render('admin/login', ['title' => 'Sign in', 'error' => $error, 'noUsers' => $noUsers]);
});

$router->post('/admin/logout', function (): void {
    if (Security::checkCsrf($_POST['csrf'] ?? null)) {
        Auth::logout();
    }
    Http::redirect('/');
});

// ---------- Admin: dashboard ----------
$router->get('/admin', function (): void {
    Auth::requireAuth();
    $u = Auth::user();
    $data = Post::adminPage($_GET, Acl::canPublish($u) ? null : (int)$u->id);
    View::render('admin/dashboard', $data + [
        'categories' => Category::all(),
        'authors' => Acl::canPublish($u) ? User::all() : [$u],
        'title' => 'Admin',
    ]);
});

function requirePathId(string $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!preg_match('/^[1-9][0-9]*$/D', $value) || $id === false) {
        Http::abort(400, 'Invalid ID.');
    }
    return $id;
}

// Retain invalid submissions only in the authenticated session, never in the database.
function renderPostEditor(?Post $post): void
{
    $id = $post?->id ?? 0;
    $selectedCatIds = $id ? array_map(static fn(Category $c): int => $c->id, Category::forPost($id)) : [];
    $form = $_SESSION['post_form'][$id] ?? null;
    unset($_SESSION['post_form'][$id]);
    if (is_array($form)) {
        $post ??= new Post(['id' => 0, 'user_id' => Auth::user()->id, 'title' => '', 'slug' => '',
            'excerpt' => '', 'content' => '', 'status' => 'draft', 'published_at' => null, 'updated_at' => null]);
        $post->title = $form['title'];
        $post->content = $form['content'];
        $post->status = $form['status'];
        $selectedCatIds = $form['categories'];
    }
    View::render('admin/post-edit', [
        'post' => $post, 'cats' => Category::all(), 'selectedCatIds' => $selectedCatIds,
        'myCats' => $selectedCatIds, 'title' => $id ? 'Edit — ' . $post->title : 'New post',
    ]);
}

// ---------- Admin: posts ----------
$router->get('/admin/post/new', function (): void {
    Auth::requireAuth();
    renderPostEditor(null);
});

$router->get('/admin/post/{id}/edit', function (array $args): void {
    Auth::requireAuth();
    $post = Post::findById(requirePathId($args['id']));
    if ($post === null) {
        Http::abort(404);
    }
    if (!Acl::canEditPost(Auth::user(), $post->userId, $post->authorRole ?? '', $post->status)) {
        Http::abort(403, 'You cannot edit this post.');
    }
    renderPostEditor($post);
});

$router->get('/admin/post/{id}/preview', function (array $args): void {
    Auth::requireAuth();
    $post = Post::findById(requirePathId($args['id']));
    if ($post === null) {
        Http::abort(404);
    }
    $user = Auth::user();
    if (!Acl::canPublish($user) && $post->userId !== $user->id) {
        Http::abort(403, 'You cannot preview this post.');
    }
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    View::render('post', ['post' => $post, 'cats' => Category::visible(), 'title' => 'Preview — ' . $post->title, 'isPreview' => true]);
});

$router->post('/admin/post/save', function (): void {
    Auth::requireAuth();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }

    $u       = Auth::user();
    $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false) {
        Http::abort(400, 'Invalid post ID.');
    }
    $post = $id > 0 ? Post::findById($id) : null;
    if ($id > 0 && $post === null) {
        Http::abort(404);
    }
    if ($post !== null && !Acl::canEditPost($u, $post->userId, $post->authorRole ?? '', $post->status)) {
        Http::abort(403, 'You cannot edit this post.');
    }
    $title   = trim((string)($_POST['title'] ?? ''));
    $content = Security::sanitizeHtml((string)($_POST['content'] ?? ''));
    $excerpt = Security::excerpt($content, 280);
    $status  = in_array($_POST['status'] ?? '', ['draft', 'published'], true) ? (string)$_POST['status'] : 'draft';
    // Only admin/superadmin may publish; writers are forced to draft (saved as "pending" intent).
    if ($status === 'published' && !Acl::canPublish($u)) {
        $status = 'draft';
        Http::flash('info', 'Saved as draft: writers cannot publish directly.');
    }

    if ($title === '' || $content === '') {
        $_SESSION['post_form'][$id] = [
            'title' => (string)($_POST['title'] ?? ''),
            'content' => (string)($_POST['content'] ?? ''),
            'status' => $status,
            'categories' => array_map('intval', (array)($_POST['categories'] ?? [])),
        ];
        Http::flash('error', 'Title and content are required.');
        Http::redirect($id ? "/admin/post/{$id}/edit" : '/admin/post/new');
    }

    unset($_SESSION['post_form'][$id]);
    if ($post !== null) {
        $post->update($title, $content, $excerpt, $status);
        Http::flash('ok', 'Post updated.');
    } else {
        $post = Post::create((int)$u->id, $title, $content, $excerpt, $status);
        Http::flash('ok', 'Post created: /post/' . $post->slug);
    }

    // Categories: writers cannot manage the taxonomy, and any post left without
    // a category falls back to "general" (the default category).
    $catIds = array_map('intval', (array)($_POST['categories'] ?? []));
    if (!Acl::canManageCategories($u)) {
        $catIds = array_map(static fn(Category $c): int => $c->id, Category::forPost($post->id));
    }
    if ($catIds === []) {
        $general = Category::bySlug('general');
        if ($general !== null) {
            $catIds = [$general->id];
        }
    }
    Category::setPostCategories($post->id, $catIds);
    Http::redirect(($_POST['save_action'] ?? '') === 'continue' ? "/admin/post/{$post->id}/edit" : '/admin');
});

$router->post('/admin/post/{id}/delete', function (array $args): void {
    Auth::requireAuth();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $post   = Post::findById(requirePathId($args['id']));
    if ($post === null) {
        Http::abort(404);
    }
    $author = User::findById($post->userId);
    if (!Acl::canDeletePost(Auth::user(), $author?->role, $post->userId, $post->status)) {
        Http::abort(403, 'You cannot delete this post.');
    }
    $post->delete();
    Http::flash('ok', 'Post deleted.');
    Http::redirect('/admin');
});

// ---------- Admin: categories ----------
$router->get('/admin/categories', function (): void {
    Auth::requireAuth();
    if (!Acl::canManageCategories(Auth::user())) {
        Http::abort(403, 'Admins and superadmins only.');
    }
    View::render('admin/categories', Category::adminPage($_GET) + ['title' => 'Categories']);
});

$router->post('/admin/categories/save', function (): void {
    Auth::requireAuth();
    if (!Acl::canManageCategories(Auth::user())) {
        Http::abort(403, 'Admins and superadmins only.');
    }
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false) {
        Http::abort(400, 'Invalid category ID.');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        Http::flash('error', 'Category title is required.');
        Http::redirect('/admin/categories');
    }
    $visible = isset($_POST['visible']);
    if ($id > 0) {
        $cat = Category::byId($id);
        if ($cat === null) {
            Http::abort(404, 'Category not found.');
        }
        $cat->update($title, $visible);
    } else {
        Category::create($title, trim((string)($_POST['slug'] ?? '')) ?: null, $visible);
    }
    Http::flash('ok', 'Category saved.');
    Http::redirect('/admin/categories');
});

$router->post('/admin/categories/{id}/delete', function (array $args): void {
    Auth::requireAuth();
    if (!Acl::canManageCategories(Auth::user())) {
        Http::abort(403, 'Admins and superadmins only.');
    }
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $id = filter_var($args['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        Http::abort(400, 'Invalid category ID.');
    }
    $cat = Category::byId($id);
    if ($cat === null) {
        Http::abort(404, 'Category not found.');
    }
    if ($cat->slug === 'general') {
        Http::abort(403, 'The general category cannot be deleted.');
    }
    $cat->delete();
    Http::flash('ok', 'Category deleted; uncategorized posts moved to General.');
    Http::redirect('/admin/categories');
});

// ---------- Admin: settings (superadmin) ----------
$router->any('/admin/settings', function (): void {
    Auth::requireSuperAdmin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
            Http::abort(400, 'Invalid CSRF token.');
        }
        if (array_key_exists('theme', $_POST)
            && (!is_string($_POST['theme']) || !ThemeManager::exists($_POST['theme']))) {
            Http::abort(400, 'Invalid theme.');
        }
        foreach (['site_title', 'site_tagline', 'footer_text'] as $key) {
            if (array_key_exists($key, $_POST)) {
                Settings::set($key, trim((string)$_POST[$key]));
            }
        }
        if (array_key_exists('posts_per_page', $_POST)) {
            Settings::set('posts_per_page', (string)max(1, min(50, (int)$_POST['posts_per_page'])));
        }
        if (array_key_exists('rss_enabled', $_POST)) {
            Settings::set('rss_enabled', $_POST['rss_enabled'] === '1' ? '1' : '0');
        }
        if (array_key_exists('theme', $_POST)) {
            Settings::set('theme', $_POST['theme']);
        }
        Http::flash('ok', 'Settings saved.');
        Http::redirect('/admin/settings');
    }
    View::render('admin/settings', [
        'title'  => 'Settings',
        'themes' => ThemeManager::available(),
        'current' => Settings::theme(),
    ]);
});

// ---------- Admin: logo upload (superadmin) ----------
$router->any('/admin/logo', function (): void {
    Auth::requireSuperAdmin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
            Http::abort(400, 'Invalid CSRF token.');
        }
        if (isset($_POST['remove']) && $_POST['remove'] === '1') {
            foreach (glob(App::rootDir() . '/var/uploads/logo.*') ?: [] as $f) {
                @unlink($f);
            }
            Http::flash('ok', 'Logo removed (theme default restored).');
            Http::redirect('/admin/settings');
        }
        $f = $_FILES['logo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Http::flash('error', 'No file uploaded.');
            Http::redirect('/admin/settings');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $image = @getimagesize($f['tmp_name']);
        $size = filesize($f['tmp_name']);
        if (!isset($allowed[$mime]) || $size === false || $size > 512 * 1024
            || $image === false || ($image['mime'] ?? '') !== $mime || $image[0] < 1 || $image[1] < 1) {
            Http::flash('error', 'Invalid logo: PNG/JPEG/GIF/WebP only, max 512 KiB.');
            Http::redirect('/admin/settings');
        }
        $dir = App::rootDir() . '/var/uploads';
        $staged = false;
        $target = $dir . '/logo.' . $allowed[$mime];
        if ((is_dir($dir) || @mkdir($dir, 0775, true)) && ($staged = @tempnam($dir, '.logo-')) !== false
            && @move_uploaded_file($f['tmp_name'], $staged) && @chmod($staged, 0644) && @rename($staged, $target)) {
            // Only remove the old extension after the replacement has succeeded.
            foreach (glob($dir . '/logo.*') ?: [] as $old) {
                if ($old !== $target && is_file($old)) @unlink($old);
            }
        } else {
            if ($staged !== false && is_file($staged)) @unlink($staged);
            Http::flash('error', 'Could not save logo. The previous logo was kept.');
            Http::redirect('/admin/settings');
        }
        Http::flash('ok', 'Logo updated.');
        Http::redirect('/admin/settings');
    }
    View::render('admin/settings', ['title' => 'Settings', 'themes' => ThemeManager::available(), 'current' => Settings::theme()]);
});

// ---------- Admin: theme install (superadmin) ----------
$router->post('/admin/theme', function (): void {
    Auth::requireSuperAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $f = $_FILES['themezip'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        Http::flash('error', 'No theme archive uploaded.');
        Http::redirect('/admin/settings');
    }
    if (($f['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || $f['size'] > 5 * 1024 * 1024) {
        Http::flash('error', 'Archive too large (max 5 MiB).');
        Http::redirect('/admin/settings');
    }
    try {
        $name = ThemeManager::installFromZip((string)$f['tmp_name']);
        if (($_POST['activate'] ?? '') === '1') {
            if (!ThemeManager::exists($name)) {
                Http::abort(400, 'Invalid theme.');
            }
            Settings::set('theme', $name);
            Http::flash('ok', "Theme “{$name}” installed and activated.");
        } else {
            Http::flash('ok', "Theme “{$name}” installed. Activate it in Settings when ready.");
        }
    } catch (\RuntimeException $e) {
        Http::flash('error', 'Theme install failed: ' . $e->getMessage());
    }
    Http::redirect('/admin/settings');
});

$router->get('/admin/theme/starter', function (): void {
    Auth::requireSuperAdmin();
    if (!class_exists(ZipArchive::class)) {
        Http::abort(503, 'ZIP support is unavailable.');
    }
    $source = App::rootDir() . '/examples/starter-theme';
    if (!is_dir($source)) {
        Http::abort(404, 'Starter theme is unavailable.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'dn-starter-');
    if ($tmp === false) {
        Http::abort(500, 'Could not create starter archive.');
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create starter archive.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $name = 'starter-theme/' . substr($file->getPathname(), strlen($source) + 1);
                if (!$zip->addFile($file->getPathname(), $name)) {
                    throw new RuntimeException('Could not add starter file.');
                }
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish starter archive.');
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="starter-theme.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
    } finally {
        unlink($tmp);
    }
});

// ---------- Admin: theme uninstall (superadmin) ----------
$router->post('/admin/theme/{name}/delete', function (array $args): void {
    Auth::requireSuperAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $name = (string)$args['name'];
    if ($name === View::FALLBACK_THEME) {
        Http::flash('error', 'The bundled theme cannot be removed: it is the fallback for every install.');
    } elseif ($name === Settings::theme()) {
        Http::flash('error', 'This theme is active — switch to another one first.');
    } elseif (!ThemeManager::exists($name)) {
        Http::flash('error', 'Unknown theme.');
    } elseif (ThemeManager::removeTheme($name)) {
        Http::flash('ok', "Theme “{$name}” deleted.");
    } else {
        Http::flash('error', "Could not delete theme “{$name}”.");
    }
    Http::redirect('/admin/settings');
});

// ---------- Admin: users (superadmin) ----------
$router->get('/admin/users', function (): void {
    Auth::requireSuperAdmin();
    View::render('admin/users', User::adminPage($_GET) + [
        'superadminCount'  => User::countByRole(Acl::SUPERADMIN),
        'title'            => 'Users',
    ]);
});

$router->post('/admin/users/create', function (): void {
    Auth::requireSuperAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = (string)($_POST['role'] ?? Acl::WRITER);

    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
        Http::flash('error', 'Username must be 3-64 characters: letters, digits, dot, underscore or dash.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Http::flash('error', 'Invalid email address.');
    } elseif (strlen($password) < 10) {
        Http::flash('error', 'Password must be at least 10 characters.');
    } elseif (!Acl::isValidRole($role)) {
        Http::flash('error', 'Invalid role.');
    } elseif (User::findByUsername($username) !== null || User::emailExists($email)) {
        Http::flash('error', 'That username or email is already taken.');
    } else {
        $created = User::create($username, $email, $password, $role);
        Http::flash('ok', "User {$created->username} created ({$role}).");
    }
    Http::redirect('/admin/users');
});

$router->post('/admin/users/{id}/delete', function (array $args): void {
    Auth::requireSuperAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $me     = Auth::user();
    $target = User::findById(requirePathId($args['id']));
    if ($target === null) {
        Http::abort(404);
    }
    if (!Acl::canDeleteUser($me, $target, User::countByRole(Acl::SUPERADMIN))) {
        Http::abort(403, 'You cannot delete this account (yourself, or the last superadmin).');
    }
    $target->delete();
    Http::flash('ok', 'Account deleted.');
    Http::redirect('/admin/users');
});

$router->post('/admin/users/{id}/role', function (array $args): void {
    Auth::requireSuperAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $me  = Auth::user();
    $tgt = User::findById(requirePathId($args['id']));
    $role = (string)($_POST['role'] ?? '');
    if ($tgt === null || !Acl::isValidRole($role)) {
        Http::abort(400, 'Invalid user or role.');
    }
    if ($me->id === $tgt->id && $role !== Acl::SUPERADMIN) {
        Http::flash('error', 'You cannot demote yourself (lockout protection).');
    } else {
        $tgt->setRole($role);
        Http::flash('ok', "Role of {$tgt->username} set to {$role}.");
    }
    Http::redirect('/admin/users');
});

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);
