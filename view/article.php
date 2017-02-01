<?php
require  dirname(__FILE__) . '/../controller/classes.php';

/* 
Prints an article 
*/
function printArticle($idArticle){

$article = new Article($idArticle);

//opening tags
echo '<html>
<div class="row">
<div class="haut-article">
</div>';

//show article title
echo '<h2>' . $article->getTitle() . '</h2>';

//print content
$toShow = preg_split('/<cut-article>/', $article->getContent());
echo $toShow[0];

//print 'read more' button
$address = "http://".$_SERVER['SERVER_NAME'].$_SERVER["REQUEST_URI"]. 'controller/get_article.php?idArticle='. $idArticle;
echo '<a href="'. $address. '"><button type="button" class="btn btn-secondary">Read more</button></a>';

//print article footer
echo '<div class="bas-article" style="
    margin-top: 10px;
    padding: 0;
    background-color: black;
width: 100%;
    height: 50px;">
<p style="color:white; float:right;margin:10px">By ' . $article->getAuthor() . ' | ' .  $article->getArticleDate() . '</p>';

//closing tags
echo '</div></div></html>';

}



?>
