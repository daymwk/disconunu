<?php
declare(strict_types=1);
// Seed de démo : 200 articles dans la base du serveur de dev (var/disconunu.sqlite).
require __DIR__ . '/../autoload.php';

// base par défaut de la config

use DN\Database;
use DN\Migrator;
use DN\Model\Post;
use DN\Security;

Migrator::run();

$pdo = Database::get()->pdo();
$existing = (int)$pdo->query('SELECT COUNT(*) FROM dn_post')->fetchColumn();
$authorId = (int)($pdo->query("SELECT id FROM dn_user WHERE username='daym'")->fetchColumn() ?: 1);

$topics = ['raviolis', 'linguines', 'farfalles', 'risotto', 'ramen', 'tacos', 'galette', 'quiche', 'pho', 'gnocchis', 'pad thaï', 'cassoulet', 'sushi', 'poutine', 'bouillabaisse'];
$adjs   = ['grillés', 'à la ratatouille', 'au pesto', 'flambés', 'croustillants', 'fondants', 'à la vanille', 'façon grand-mère', 'express', 'de minuit', 'de la mer', 'd’automne', 'épicés', 'au fromage', 'de saison'];

$needed = 200 - $existing;
if ($needed <= 0) {
    echo "déjà $existing articles, rien à faire\n";
    exit(0);
}

for ($i = 1; $i <= $needed; $i++) {
    $title = 'Recette ' . $topics[$i % count($topics)] . ' ' . $adjs[intdiv($i, count($topics)) % count($adjs)] . ' n°' . $i;
    $body  = "<p>Article de démo n°{$i}. " . str_repeat('Pain de mie, beurre allégé, cornichons au vinaigre balsamique, moules de bouchot, nouilles de riz. ', 6) . "</p>"
          . "<h2>Préparation</h2><p>Mélanger, goutter, rectifier. Le mot-clé de la semaine : <b>" . $topics[$i % count($topics)] . "</b>.</p>"
          . "<blockquote>Si c’est bon, c’est que quelqu’un a goûté avant.</blockquote>";
    Post::create($authorId, $title, $body, Security::excerpt($body), 'published');
}

$n = (int)$pdo->query('SELECT COUNT(*) FROM dn_post')->fetchColumn();
echo "créés : {$needed}, total : {$n}\n";
