<?php
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "disconunu";

try {
    $dbh = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
    // set the PDO error mode to exception
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
catch(PDOException $e)
    {
    echo "Connection to database failed: " . $e->getMessage();
    }
?>