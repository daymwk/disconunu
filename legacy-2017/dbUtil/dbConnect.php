<?php
require_once dirname(__FILE__) . '/../config.php';

// SQLite local (remplace MySQL — port local 2026, ne pas committer en l'état sur le repo d'origine)
try {
    $dbh = new PDO('sqlite:' . dirname(__FILE__) . '/disconunu.sqlite');
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // MySQL's NOW() n'existe pas en SQLite -> fonction équivalente
    $dbh->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
}
catch (PDOException $e) {
    echo "Connection to database failed: " . $e->getMessage();
}
?>
