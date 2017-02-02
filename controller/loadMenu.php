<?php

require_once 'classes.php';
require_once dirname(__FILE__) . '/../config.php';
include(dirname(__FILE__) . '/../view/header.php');



function loadMenu($menuTitle){

	return new Menu($menuTitle);
}


 ?>

