<?php
//require  dirname(__FILE__) . '/../config.php';
/* 
Prints an article 
*/
function printArticle($idArticle){

$config = Config::getConfig();
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
if($config->mod_rewrite == false)
$address = $config->getSiteURL() . '/controller/get_article.php?idArticle='. $idArticle;
else
$address = $config->getSiteURL() . '/article/'. $idArticle;

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
