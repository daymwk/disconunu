
<?php

function salt() {
	require_once dirname(__FILE__) . '/../config.php';
	return Config::$salt;
}


function insertNewUser($username,$password,$email){
	require 'dbConnect.php';
	$password = hash('sha256',salt() . $password);
	$stmt = $dbh->prepare("INSERT INTO USER (username, password, email)  VALUES (:username, :password, :email)");
	$stmt->bindParam(':username', $username);
	$stmt->bindParam(':password', $password);
	$stmt->bindParam(':email', $email);

	return $stmt->execute();

}



function insertNewArticle($title,$content,$userID,$links){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("INSERT INTO ARTICLE (title, content, dateArticle , userID, links)  VALUES (:title, :content, NOW() , :userID, :links)");
	$stmt->bindParam(':title', $title);
	$stmt->bindParam(':content', $content);
	$stmt->bindParam(':userID', $userID);
	$stmt->bindParam(':links', $links);

	return $stmt->execute();

}


?>