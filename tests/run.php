<?php
declare(strict_types=1);

/**
 * Tests de Disco nunu — runner autonome, zéro dépendance (pas de PHPUnit requis).
 *   php tests/run.php
 * Chaque test repart d'une base SQLite en mémoire (ou fichier temp) fraîche.
 */

require __DIR__ . '/../autoload.php';

putenv('DN_DB_DRIVER=sqlite');
putenv('DN_DB_DATABASE=' . sys_get_temp_dir() . '/dn_tests_' . getmypid() . '.sqlite');
putenv('DN_APP_URL=http://example.test');

use DN\App;
use DN\Auth;
use DN\Database;
use DN\Migrator;
use DN\Model\Menu;
use DN\Model\Post;
use DN\Model\User;
use DN\Security;

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

function assert_true($cond, string $msg = 'condition attendue vraie'): void
{
    if ($cond !== true) {
        throw new \Exception($msg);
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception(sprintf(
            '%s attendu=%s obtenu=%s',
            $msg ?: 'valeur',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

echo "\n=== Disco nunu — tests ===\n";

// base fraîche
@unlink(getenv('DN_DB_DATABASE'));
Database::resetConnection();
Migrator::run();

echo "\n-- Migrations & schéma --\n";

($t)('migrations idempotentes (2e run = 0 appliquée)', function () {
    $applied = Migrator::run();
    assert_eq([], $applied, 'deuxième run');
});

($t)('schéma complet présent', function () {
    foreach (['dn_user', 'dn_post', 'dn_menu', 'dn_menu_item', 'dn_migrations'] as $tbl) {
        Database::get()->pdo()->query("SELECT COUNT(*) FROM {$tbl}");
    }
    assert_true(true);
});

echo "\n-- Utilisateurs & auth --\n";

($t)('création user + hash argon2id/bcrypt', function () {
    $u = User::create('alice', 'alice@example.test', 'motdepasse-solide-42');
    assert_true($u->id > 0, 'id auto');
    assert_true(str_starts_with($u->passwordHash, '$argon2') || str_starts_with($u->passwordHash, '$2y$'), 'hash fort');
    assert_true($u->verifyPassword('motdepasse-solide-42'), 'verify ok');
    assert_eq(false, $u->verifyPassword('mauvais'), 'mauvais mdp rejeté');
});

($t)('email en double rejeté (contrainte UNIQUE)', function () {
    try {
        User::create('bob', 'alice@example.test', 'un-autre-motdepasse');
        assert_true(false, 'aurait dû lever');
    } catch (\PDOException $e) {
        assert_true(true);
    }
});

echo "\n-- Posts, slugs, pagination --\n";

($t)('slug ASCII unique (accents translittérés, doublons numérotés)', function () {
    $p1 = Post::create(1, 'Recette de Ratatouille à la Ratatouille', '<p>x</p>', 'x', 'published');
    $p2 = Post::create(1, 'Recette de Ratatouille à la Ratatouille', '<p>y</p>', 'y', 'published');
    assert_eq('recette-de-ratatouille-a-la-ratatouille', $p1->slug);
    assert_eq('recette-de-ratatouille-a-la-ratatouille-2', $p2->slug);
});

($t)('brouillon invisible côté public, visible côté admin', function () {
    $d = Post::create(1, 'Brouillon secret', '<p>psst</p>', 'psst', 'draft');
    assert_eq(null, Post::findBySlug($d->slug, true), 'masqué public');
    assert_true(Post::findBySlug($d->slug, false) !== null, 'visible admin');
});

($t)('pagination : 12 posts, page 2/3 de 5', function () {
    for ($i = 1; $i <= 12; $i++) {
        Post::create(1, "Article pagination {$i}", "<p>corps {$i}</p>", "extrait {$i}", 'published');
    }
    $total = Post::countPublished();
    assert_true($total >= 12, "total>=12 (got {$total})");
    $page2 = Post::publishedPage(2, 5);
    assert_true(count($page2) >= 1, 'page 2 non vide');
});

($t)('recherche FTS trouve par mot du corps', function () {
    Post::create(1, 'Notes de cuisine', '<p>la <b>cardamome</b> change tout</p>', 'extrait', 'published');
    $r = Post::search('cardamome');
    assert_true(count($r) >= 1, 'au moins 1 résultat');
    assert_true(str_contains($r[0]->title, 'cardamome') || str_contains($r[0]->content, 'cardamome'), 'bon post');
});

($t)('update + publication horodatée', function () {
    $p = Post::create(1, 'À publier plus tard', '<p>brouillon</p>', '', 'draft');
    assert_eq(null, $p->publishedAt, 'brouillon sans date de pub');
    $p->update('À publier plus tard', '<p>final</p>', 'final', 'published');
    assert_true($p->publishedAt !== null, 'publié horodaté');
});

($t)('suppression propre', function () {
    $p = Post::create(1, 'À supprimer', '<p>bye</p>', '', 'published');
    $id = $p->id;
    $p->delete();
    assert_eq(null, Post::findById($id), 'disparu');
});

echo "\n-- Sécurité --\n";

($t)('sanitizeHtml strip scripts/on*/javascript:', function () {
    $dirty = '<p onclick="evil()">ok</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)>';
    $clean = Security::sanitizeHtml($dirty);
    assert_true(!str_contains($clean, '<script'), 'script retiré');
    assert_true(!str_contains($clean, 'onclick'), 'onclick retiré');
    assert_true(!str_contains($clean, 'javascript:'), 'javascript: retiré');
    assert_true(!str_contains($clean, 'onerror'), 'onerror retiré');
    assert_true(str_contains($clean, '<p>ok</p>'), 'contenu légitime conservé');
});

($t)('sanitizeHtml casse les data: dans href/src', function () {
    $clean = Security::sanitizeHtml('<a href="data:text/html,<script>evil()</script>">x</a>');
    assert_true(!str_contains($clean, 'data:text/html'), 'data: retiré');
});

($t)('excerpt = texte brut tronqué', function () {
    $ex = Security::excerpt('<p>Bonjour   le <b>monde</b></p>' . str_repeat('mot ', 200), 50);
    assert_true(!str_contains($ex, '<'), 'pas de HTML');
    assert_true(mb_strlen($ex) <= 50, 'tronqué à 50');
    assert_true(str_ends_with($ex, '…'), 'pointillés de troncature');
});

($t)('slugify anti-injection', function () {
    assert_eq('hello-world', Post::slugify('Hello, World!'));
    assert_eq('ca-fe-creme', Post::slugify('ça-fé & crème'), 'accents translittérés, séparateurs collapsés');
    assert_eq('', Post::slugify('***'), 'caractères spéciaux -> vide');
});

echo "\n-- Menus --\n";

($t)('menu + items ordonnés', function () {
    $m = Menu::create('main', 'Menu principal');
    $m->addItem('Accueil', '/', 0);
    $m->addItem('À propos', 'page/a-propos', 1);
    $items = $m->items();
    assert_eq(2, count($items));
    assert_eq('Accueil', $items[0]['title']);
    $m->removeItem((int)$items[1]['id']);
    assert_eq(1, count($m->items()));
});

echo "\n-- Router --\n";

($t)('routeur : params, 404, 405', function () {
    $router = new \DN\Router();
    $hit = null;
    $router->get('/post/{slug}', function (array $args) use (&$hit) { $hit = $args['slug']; });
    ob_start();
    $router->dispatch('GET', '/post/bonjour-le-monde');
    ob_end_clean();
    assert_eq('bonjour-le-monde', $hit, 'param extrait');

    // NOTE : 404/405 passent par Http::abort() qui fait exit() — couverts par le smoke HTTP E2E
    // (tests/smoke_http.sh) plutôt que ce process.
});

echo "\n=== {$pass} réussis";
if ($fail > 0) {
    echo ", {$fail} ÉCHECS :\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\033[0m\n";
    exit(1);
}
echo " / " . ($pass + $fail) . "\033[0m\n";
exit(0);


