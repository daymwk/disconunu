<?php


function getArticlePage($limit1,$limit2){

	$tab = selectArticleRange($limit1,$limit2);
	$tabObjects = array();
	for($i=0;$i<count($tab);$i++){

		$tabObjects[$i] = new Article($tab[$i]['id']);

	}

	return $tabObjects;
}


function numberOfArticles(){
	return count(selectAllArticles());
}


?>


