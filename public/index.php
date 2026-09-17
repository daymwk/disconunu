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
                User::create($username, $email, $password, Acl::SUPERADMIN);
                Auth::attempt($username, $password);
                Http::flash('ok', 'Admin created. Welcome to Disco nunu.');
                Http::redirect('/admin');
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
    $posts = Acl::canPublish($u)
        ? Post::all(500)
        : Post::allByAuthor((int)$u->id, 500);

    // Optional ?status=draft|published filter, handy once the list grows.
    $status = in_array($_GET['status'] ?? '', ['draft', 'published'], true) ? (string)$_GET['status'] : '';
    if ($status !== '') {
        $posts = array_values(array_filter($posts, static fn(Post $x): bool => $x->status === $status));
    }

    View::render('admin/dashboard', ['posts' => $posts, 'status' => $status, 'title' => 'Admin']);
});

// ---------- Admin: posts ----------
$router->get('/admin/post/new', function (): void {
    Auth::requireAuth();
    View::render('admin/post-edit', [
        'post'   => null,
        'cats'   => Category::all(),
        'title'  => 'New post',
    ]);
});

$router->get('/admin/post/{id}/edit', function (array $args): void {
    Auth::requireAuth();
    $post = Post::findById((int)$args['id']);
    if ($post === null) {
        Http::abort(404);
    }
    if (!Acl::canEditPost(Auth::user(), $post->userId)) {
        Http::abort(403, 'You cannot edit this post.');
    }
    View::render('admin/post-edit', [
        'post'    => $post,
        'cats'    => Category::all(),
        'myCats'  => array_map(static fn(Category $c): int => $c->id, Category::forPost($post->id)),
        'title'   => 'Edit — ' . $post->title,
    ]);
});

$router->post('/admin/post/save', function (): void {
    Auth::requireAuth();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }

    $u       = Auth::user();
    $id      = (int)($_POST['id'] ?? 0);
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
        Http::flash('error', 'Title and content are required.');
        Http::redirect($id ? "/admin/post/{$id}/edit" : '/admin/post/new');
    }

    if ($id > 0) {
        $post = Post::findById($id);
        if ($post === null) {
            Http::abort(404);
        }
        if (!Acl::canEditPost($u, $post->userId)) {
            Http::abort(403, 'You cannot edit this post.');
        }
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
    Http::redirect('/admin');
});

$router->post('/admin/post/{id}/delete', function (array $args): void {
    Auth::requireAuth();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $post   = Post::findById((int)$args['id']);
    if ($post === null) {
        Http::abort(404);
    }
    $author = User::findById($post->userId);
    if (!Acl::canDeletePost(Auth::user(), $author?->role, $post->userId)) {
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
    View::render('admin/categories', ['cats' => Category::all(), 'title' => 'Categories']);
});

$router->post('/admin/categories/save', function (): void {
    Auth::requireAuth();
    if (!Acl::canManageCategories(Auth::user()) || !Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid request.');
    }
    $id    = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        Http::flash('error', 'Category title is required.');
        Http::redirect('/admin/categories');
    }
    $visible = isset($_POST['visible']);
    if ($id > 0) {
        $cat = Category::byId($id);
        if ($cat !== null) {
            $cat->update($title, $visible);
        }
    } else {
        Category::create($title, trim((string)($_POST['slug'] ?? '')) ?: null, $visible);
    }
    Http::flash('ok', 'Category saved.');
    Http::redirect('/admin/categories');
});

$router->post('/admin/categories/{id}/delete', function (array $args): void {
    Auth::requireAuth();
    if (!Acl::canManageCategories(Auth::user()) || !Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid request.');
    }
    $cat = Category::byId((int)$args['id']);
    if ($cat !== null) {
        $cat->delete();
        Http::flash('ok', 'Category deleted (posts keep existing, uncategorized).');
    }
    Http::redirect('/admin/categories');
});

// ---------- Admin: settings (superadmin) ----------
$router->any('/admin/settings', function (): void {
    Auth::requireSuperAdmin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
            Http::abort(400, 'Invalid CSRF token.');
        }
        Settings::set('site_title', trim((string)($_POST['site_title'] ?? '')));
        Settings::set('site_tagline', trim((string)($_POST['site_tagline'] ?? '')));
        Settings::set('footer_text', trim((string)($_POST['footer_text'] ?? '')));
        Settings::set('posts_per_page', (string)max(1, min(50, (int)($_POST['posts_per_page'] ?? 5))));
        Settings::set('rss_enabled', isset($_POST['rss_enabled']) ? '1' : '0');
        Settings::set('theme', (string)($_POST['theme'] ?? ''));
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
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        if (!isset($allowed[$mime]) || $f['size'] > 512 * 1024) {
            Http::flash('error', 'Invalid logo: PNG/JPEG/GIF/WebP/SVG only, max 512 Ko.');
            Http::redirect('/admin/settings');
        }
        $dir = App::rootDir() . '/var/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        move_uploaded_file($f['tmp_name'], $dir . '/logo.' . $allowed[$mime]);
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
        Http::flash('error', 'Archive too large (max 5 Mo).');
        Http::redirect('/admin/settings');
    }
    try {
        $name = ThemeManager::installFromZip((string)$f['tmp_name']);
        Settings::set('theme', $name);
        Http::flash('ok', "Theme “{$name}” installed and activated.");
    } catch (\RuntimeException $e) {
        Http::flash('error', 'Theme install failed: ' . $e->getMessage());
    }
    Http::redirect('/admin/settings');
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
    View::render('admin/users', [
        'users'            => User::all(),
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
    $target = User::findById((int)$args['id']);
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
    Auth::requireAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Invalid CSRF token.');
    }
    $me  = Auth::user();
    $tgt = User::findById((int)$args['id']);
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
