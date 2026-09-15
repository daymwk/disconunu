<?php


function printAllArticles($articlePerPage){
require_once 'view/article.php';
require_once  dirname(__FILE__) . '/../controller/getArticlePage.php';

$total = numberOfArticles();
$numberOfPages = ceil($total/$articlePerPage);

if(isset($_GET['page']))
$page = $_GET['page'];
else
$page = 1;

$limit1 = ($page - 1) * $articlePerPage;

$articles = getArticlePage($limit1,$articlePerPage);



foreach ($articles as $article) {

  		printArticle($article->getIdArticle());

}

echo '<ul class="pagination">';
for ($j = 1 ; $j <= $numberOfPages ; $j++)
{


		if($j == $page)
   echo '<li class="active"><a href="?page=' . $j . '">' . $j . '</a></li>';
	else
	 echo '<li><a href="?page=' . $j . '">' . $j . '</a></li>';





}
echo '</ul>';

}

?>