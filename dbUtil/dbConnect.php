<?php
require_once dirname(__FILE__) . '/../config.php';



try {
    $dbh = new PDO("mysql:host=$config->servername;dbname=$config->dbname", $config->dbusername, $config->dbpassword);
    // set the PDO error mode to exception
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
catch(PDOException $e)
    {
    echo "Connection to database failed: " . $e->getMessage();
    }
?>
