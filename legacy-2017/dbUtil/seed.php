<?php
// Schema + données de démo pour le port SQLite local (inféré depuis dbUtil/select.php + insert.php)
$dbh = new PDO('sqlite:' . __DIR__ . '/disconunu.sqlite');
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dbh->exec('CREATE TABLE IF NOT EXISTS USER (
  UserID INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT, password TEXT, email TEXT)');

$dbh->exec('CREATE TABLE IF NOT EXISTS ARTICLE (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT, content TEXT, dateArticle TEXT, userID INTEGER, links TEXT)');

$dbh->exec('CREATE TABLE IF NOT EXISTS MENU (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT)');

$dbh->exec('CREATE TABLE IF NOT EXISTS MENUITEM (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  idMenu INTEGER, title TEXT, idArticle INTEGER)');

if ($dbh->query('SELECT COUNT(*) FROM ARTICLE')->fetchColumn() == 0) {
  $u = $dbh->prepare('INSERT INTO USER (username, password, email) VALUES (?,?,?)');
  $u->execute(['daym', hash('sha256', '$KMGg4QdfF' . 'demo'), 'daym@example.com']);

  $a = $dbh->prepare('INSERT INTO ARTICLE (title, content, dateArticle, userID, links) VALUES (?,?,datetime(),?,?)');
  $a->execute(['Bienvenue sur Disco nunu',
    '<p>Ce CMS date de 2017, sorti de GitHub et ressuscité en SQLite en 2026. Le reste du code n\'a pas bougé : Bootstrap 3, font-awesome, et du PHP procedural comme on l\'aimait à l\'époque.</p><cut-article><p>Article complet : la pagination fonctionne, le menu vient de la table MENU, les articles de ARTICLE. Tout est dans cette petite base.</p>', 1, '']);
  $a->execute(['Raviolis chinois grillés à la poêle',
    '<p>La recette signature. Prendre les raviolis, les griller. C\'est tout, c\'est bon.</p><cut-article><p>Détail : feu moyen-vif, une cuillère d\'huile de sésame, déguster.</p>', 1, '']);
  $a->execute(['Linguines à la ratatouille',
    '<p>Pendant ce temps, l\'eau bout.</p><cut-article><p>Ratatouille du marché, linguines al dente, basilic.</p>', 1, '']);
  $a->execute(['Farfalles au pesto et à la banane flambée',
    '<p>Le plat que personne sur GitHub n\'a commandé en 2017, et pourtant il est là depuis le premier commit.</p><cut-article><p>Pesto maison, banane, rhum, courage.</p>', 1, '']);

  $dbh->exec("INSERT INTO MENU (title) VALUES ('main')");
  $m = $dbh->prepare('INSERT INTO MENUITEM (idMenu, title, idArticle) VALUES (?,?,?)');
  $m->execute([1, 'Accueil', 1]);
  $m->execute([1, 'Recettes', 2]);
  $m->execute([1, 'À propos', 4]);
}
echo "OK: " . $dbh->query('SELECT COUNT(*) FROM ARTICLE')->fetchColumn() . " articles, " .
     $dbh->query('SELECT COUNT(*) FROM MENUITEM')->fetchColumn() . " items de menu\n";
