<?php
declare(strict_types=1);

/**
 * Demo seeder: fills the database with 200 sample posts.
 *
 * Without --reset, only creates the posts missing from the requested total.
 * Use a disposable development database: this script runs migrations, and
 * --reset deletes ALL posts, not just demo content. Existing posts are not
 * translated. Set DN_DB_DRIVER=sqlite and DN_DB_DATABASE to a temporary path.
 * Posts go through the Post model (slug generation + FTS triggers) and are
 * attached to the "general" category, like any post left uncategorized.
 *
 * Usage:
 *   php bin/seed-demo.php            # top up to 200 posts
 *   php bin/seed-demo.php --reset    # delete every post first, then seed 200
 *   php bin/seed-demo.php 500        # target a different total
 */

require __DIR__ . '/../autoload.php';

use DN\Acl;
use DN\Database;
use DN\Migrator;
use DN\Model\Category;
use DN\Model\Post;
use DN\Model\User;
use DN\Security;

$args   = array_slice($argv, 1);
$reset  = in_array('--reset', $args, true);
$target = 200;
foreach ($args as $a) {
    if (ctype_digit($a)) {
        $target = max(1, (int)$a);
    }
}

Migrator::run();
$pdo = Database::get()->pdo();

if ($reset) {
    $pdo->exec('DELETE FROM dn_post_category');
    $pdo->exec('DELETE FROM dn_post');
    echo "reset: all posts deleted\n";
}

$existing = (int)$pdo->query('SELECT COUNT(*) FROM dn_post')->fetchColumn();
$authorId = (int)($pdo->query('SELECT MIN(id) FROM dn_user')->fetchColumn() ?: 0);
if ($authorId === 0) {
    fwrite(STDERR, "Create the first account before seeding demo posts.\n");
    exit(1);
}

$needed = $target - $existing;
if ($needed <= 0) {
    echo "already {$existing} posts, nothing to do\n";
    exit(0);
}

$general = Category::bySlug('general') ?? Category::create('General', 'general', true);

$dishes = ['ravioli', 'linguine', 'farfalline', 'risotto', 'ramen', 'tacos', 'buckwheat pancake', 'savory tart', 'pho', 'gnocchi',
           'pad thai', 'bean stew', 'sushi', 'cheese-curd fries', 'fish stew'];
$styles = ['grilled', 'with vegetable stew', 'with pesto', 'flame-cooked', 'crispy', 'melting', 'with vanilla',
           'grandma-style', 'in a hurry', 'at midnight', 'from the sea', 'autumn', 'spicy', 'with cheese', 'in season'];

for ($i = 1; $i <= $needed; $i++) {
    $dish  = $dishes[$i % count($dishes)];
    $style = $styles[intdiv($i, count($dishes)) % count($styles)];
    $title = ucfirst($dish) . ' ' . $style . ' #' . $i;
    $body  = '<p>Sample post #' . $i . '. '
           . str_repeat('Breadcrumbs, softened butter, balsamic pickles, mussels, rice noodles. ', 5) . '</p>'
           . '<h2>Method</h2><p>Mix, taste, adjust. Keyword of the week: <b>' . $dish . '</b>.</p>'
           . '<blockquote>If it tastes good, someone tasted it before you.</blockquote>';
    $post = Post::create($authorId, $title, $body, Security::excerpt($body), 'published');
    Category::setPostCategories($post->id, [$general->id]);
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM dn_post')->fetchColumn();
echo "created: {$needed}, total: {$total} (category: {$general->slug})\n";
