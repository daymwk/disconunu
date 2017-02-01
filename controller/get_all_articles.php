<?php

//require 'classes.php';

function get_all_articles(){

	$tab = selectAllArticles();
	$tabObjects = array();
	for($i=0;$i<count($tab);$i++){

		$tabObjects[$i] = new Article($tab[$i]['id']);

	}

	return $tabObjects;
}


?>


