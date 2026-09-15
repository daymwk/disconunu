<?php
declare(strict_types=1);

/**
 * Disco nunu — front controller unique.
 * Tout passe par ici (plus de "require depuis n'importe quel dossier" comme en 2017).
 */

require __DIR__ . '/../autoload.php';

use DN\App;
use DN\Auth;
use DN\Http;
use DN\Router;
use DN\Security;
use DN\Model\Post;
use DN\Model\Menu;
use DN\Model\User;
use DN\View;

App::boot();
Http::securityHeaders();

$router = new Router();

// ---------- Assets de thème : /themes/<nom>[/css|/js|/fonts]/fichier ----------
// Le dossier themes/ est hors docroot ; cette route le sert, extensions autorisées uniquement.
$path0 = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/themes/([a-zA-Z0-9_-]+)/(?:(css|js|fonts|images)/)?([A-Za-z0-9._-]+\.(?:css|js|png|jpe?g|gif|svg|woff2?|ttf|ico))$#', $path0, $am)) {
    $root  = realpath(__DIR__ . '/../themes');
    $theme = $root . '/' . $am[1];
    $file  = false;
    // le fichier peut vivre à la racine du thème ou dans un sous-dossier d'assets
    $sub = $am[2] ?? '';
    $candidates = array_values(array_unique(array_filter([
        $sub !== '' ? $theme . '/' . $sub . '/' . $am[3] : null,
        $theme . '/css/' . $am[3],
        $theme . '/js/' . $am[3],
        $theme . '/' . $am[3],
    ])));
    $file = false;
    foreach ($candidates as $candidat) {
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
        'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime . '; charset=' . ($mime === 'text/css' || $mime === 'text/javascript' ? 'utf-8' : 'binary'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

// ---------- Public ----------
$router->get('/', function (): void {
    $perPage = (int)App::config()['blog']['posts_per_page'];
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $total   = Post::countPublished();
    $posts   = Post::publishedPage($page, $perPage);
    $menu    = Menu::bySlug('main');

    View::render('home', [
        'posts'   => $posts,
        'page'    => $page,
        'pages'   => max(1, (int)ceil($total / $perPage)),
        'menu'    => $menu,
        'title'   => App::config()['app']['name'],
    ]);
});

$router->get('/post/{slug}', function (array $args): void {
    $post = Post::findBySlug((string)$args['slug']);
    if ($post === null) {
        Http::abort(404, 'Cet article n’existe pas (ou pas encore).');
    }
    View::render('post', ['post' => $post, 'title' => $post->title, 'menu' => Menu::bySlug('main')]);
});

$router->get('/search', function (): void {
    $q     = trim((string)($_GET['q'] ?? ''));
    $posts = $q !== '' ? Post::search($q) : [];
    View::render('search', ['q' => $q, 'posts' => $posts, 'title' => 'Recherche', 'menu' => Menu::bySlug('main')]);
});

$router->get('/feed', function (): void {
    $posts = Post::publishedPage(1, 20);
    $base  = App::baseUrl();
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0"><channel>';
    echo '<title>' . htmlspecialchars((string)App::config()['app']['name'], ENT_XML1) . '</title>';
    echo '<link>' . htmlspecialchars($base, ENT_XML1) . '</link>';
    echo '<description>' . htmlspecialchars((string)App::config()['app']['subtitle'], ENT_XML1) . '</description>';
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

// ---------- Admin ----------
$router->any('/admin/login', function (array $args): void {
    $error = '';
    $noUsers = \DN\Model\User::countAll() === 0;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
            $error = 'Session expirée, réessaie.';
        } elseif ($noUsers) {
            // Premier lancement : le tout premier compte créé est admin (auto-hébergement simple).
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $email    = trim((string)($_POST['email'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username) || strlen($password) < 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Login 3-64 caractères (sans espaces), mot de passe ≥ 10 caractères, email valide.';
            } elseif (User::emailExists($email)) {
                $error = 'Email déjà utilisé.';
            } else {
                User::create($username, $email, $password, 'admin');
                Auth::attempt($username, $password);
                Http::flash('ok', 'Admin créé. Bienvenue dans Disco nunu 2026.');
                Http::redirect('/admin');
            }
        } elseif (Auth::attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
            Http::redirect('/admin');
        } else {
            $error = 'Identifiants incorrects.';
        }
    }
    View::render('admin/login', ['title' => 'Connexion', 'error' => $error, 'noUsers' => $noUsers]);
});

$router->post('/admin/logout', function (): void {
    if (Security::checkCsrf($_POST['csrf'] ?? null)) {
        Auth::logout();
    }
    Http::redirect('/');
});

$router->get('/admin', function (): void {
    Auth::requireAdmin();
    View::render('admin/dashboard', ['posts' => Post::all(50), 'title' => 'Admin']);
});

$router->get('/admin/post/new', function (): void {
    Auth::requireAdmin();
    View::render('admin/post-edit', ['post' => null, 'title' => 'Nouvel article']);
});

$router->get('/admin/post/{id}/edit', function (array $args): void {
    Auth::requireAdmin();
    $post = Post::findById((int)$args['id']);
    if ($post === null) {
        Http::abort(404);
    }
    View::render('admin/post-edit', ['post' => $post, 'title' => 'Éditer — ' . $post->title]);
});

$router->post('/admin/post/save', function (): void {
    Auth::requireAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Jeton CSRF invalide.');
    }

    $id      = (int)($_POST['id'] ?? 0);
    $title   = trim((string)($_POST['title'] ?? ''));
    $content = Security::sanitizeHtml((string)($_POST['content'] ?? ''));
    $excerpt = Security::excerpt($content, 280);
    $status  = in_array($_POST['status'] ?? '', ['draft', 'published'], true) ? (string)$_POST['status'] : 'draft';

    if ($title === '' || $content === '') {
        Http::flash('error', 'Titre et contenu sont obligatoires.');
        Http::redirect($id ? "/admin/post/{$id}/edit" : '/admin/post/new');
    }

    $user = Auth::user();
    if ($id > 0) {
        $post = Post::findById($id);
        if ($post === null) {
            Http::abort(404);
        }
        $post->update($title, $content, $excerpt, $status);
        Http::flash('ok', 'Article mis à jour.');
    } else {
        $post = Post::create((int)$user->id, $title, $content, $excerpt, $status);
        Http::flash('ok', "Article créé : /post/{$post->slug}");
    }
    Http::redirect('/admin');
});

$router->post('/admin/post/{id}/delete', function (array $args): void {
    Auth::requireAdmin();
    if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
        Http::abort(400, 'Jeton CSRF invalide.');
    }
    $post = Post::findById((int)$args['id']);
    if ($post !== null) {
        $post->delete();
        Http::flash('ok', 'Article supprimé.');
    }
    Http::redirect('/admin');
});

$router->get('/admin/install-user', function (): void {
    // Première ouverture : si aucun utilisateur, on présente le formulaire (géré par la vue login).
    Auth::requireAuth();
    Http::redirect('/admin');
});

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);
