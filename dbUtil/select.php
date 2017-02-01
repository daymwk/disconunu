<?php



function selectArticle($idArticle){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("SELECT * FROM ARTICLE WHERE id = :idArticle");
	$stmt->bindParam(':idArticle', $idArticle);

	if($stmt->execute()){
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}
	else{
		return false;
	}

}


function selectAllArticles(){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("SELECT * FROM ARTICLE");
	$stmt->bindParam(':idArticle', $idArticle);

	if($stmt->execute()){
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	else{
		return false;
	}

}



function selectUser($idUser){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("SELECT username,email FROM USER WHERE UserID = :idUser");
	$stmt->bindParam(':idUser', $idUser);

	if($stmt->execute()){
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}
	else{
		return false;
	}

}



?>