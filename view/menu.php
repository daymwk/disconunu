<?php
require  dirname(__FILE__) . '/../controller/classes.php';


$main = new Menu('main');

//opening menu tags
echo '<nav class="navbar navbar-default">
  <div class="container-fluid">
    <!-- Brand and toggle get grouped for better mobile display -->
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1" aria-expanded="false">
        <span class="sr-only">Toggle navigation</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <a class="navbar-brand" href="' . Config::getSiteURL() . '"><i class="fa fa-home fa-5" aria-hidden="true"></i></a>
    </div><!-- Collect the nav links, forms, and other content for toggling -->
    <div class="collapse navbar-collapse">
      <ul class="nav navbar-nav">';

//creating menu links
foreach($main->getItems() as $menuItem){


if (Config::$mod_rewrite){

  if(isset($_GET['idArticle']) &&  $_GET['idArticle'] == $menuItem->getArticleID())
  echo '<li class="active"><a href="' . Config::getSiteURL() . '/article/' . $menuItem->getArticleID() . '">' . $menuItem->getTitle() . '</a></li>';
  else
  echo '<li><a href="' . Config::getSiteURL() . '/article/' . $menuItem->getArticleID() . '">' . $menuItem->getTitle() . '</a></li>';

}  
else{


  if(isset($_GET['idArticle']) &&  $_GET['idArticle'] == $menuItem->getArticleID())
  echo '<li class="active"><a href="' . Config::getSiteURL() . '/controller/get_article.php?idArticle=' . $menuItem->getArticleID() . '">' . $menuItem->getTitle() . '</a></li>';
  else
  echo '<li><a href="' . Config::getSiteURL() . '/controller/get_article.php?idArticle=' . $menuItem->getArticleID() . '">' . $menuItem->getTitle() . '</a></li>';


}


}


//closing tags
echo '</ul>
    </div><!-- /.navbar-collapse -->
  </div><!-- /.container-fluid -->
</nav>';
?>








       <!-- <li class="dropdown">
          <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">Dropdown <span class="caret"></span></a>
          <ul class="dropdown-menu">
            <li><a href="#">Action</a></li>
            <li><a href="#">Another action</a></li>
            <li><a href="#">Something else here</a></li>
            <li role="separator" class="divider"></li>
            <li><a href="#">Separated link</a></li>
            <li role="separator" class="divider"></li>
            <li><a href="#">One more separated link</a></li>
          </ul>
        </li> -->