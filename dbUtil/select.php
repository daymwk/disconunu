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



function selectArticleRange($limit1,$limit2){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("SELECT * FROM ARTICLE ORDER BY id DESC LIMIT " . $limit1 . " , " . $limit2);
	if($stmt->execute()){
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function selectMenu($menuName){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("SELECT * FROM MENU WHERE title = :menuName");
	$stmt->bindParam(':menuName', $menuName);

	if($stmt->execute()){
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}
	else{
		return false;
	}

}


function selectMenuItems($idMenu){
	require 'dbConnect.php';
	$stmt = $dbh->prepare("SELECT * FROM MENUITEM WHERE idMenu = :idMenu");
	$stmt->bindParam(':idMenu', $idMenu);

	if($stmt->execute()){
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	else{
		return false;
	}

}


?>