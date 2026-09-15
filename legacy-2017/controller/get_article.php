<?php

require_once dirname(__FILE__) . '/../config.php';
include(dirname(__FILE__) . '/../view/header.php');
 ?>


<body>
	

 <?php
function showArticle($idArticle){

$article = new Article($idArticle);

if($article->getTitle() != ''){
//opening tags
echo '<html>

<div class="col-md-12">
                     
                    <div class="col-md-9">
<div class="row">
<div class="haut-article">
</div>';

//show article title
echo '<h2>' . $article->getTitle() . '</h2>';

//print content
$toShow = preg_split('/<cut-article>/', $article->getContent());
for($i=0;$i<count($toShow);$i++){
	echo $toShow[$i];
}

//print article footer
echo '<div class="bas-article" style="
    margin-top: 10px;
    padding: 0;
    background-color: black;
width: 100%;
    height: 50px;">
<p style="color:white; float:right;margin:10px">By ' . $article->getAuthor() . ' | ' .  $article->getArticleDate() . '</p>';

//closing tags
echo '</div></div></div>
<div class="col-md-3">
<div class="row">

</div>
</div>

</div></html>';
}
else{
	echo '<div style="height:500px;"><h3>Error: couldn\'t find requested article!</h3></div>';
}



}


if(isset($_GET['idArticle'])){

	$idArticle = $_GET['idArticle'];
	showArticle($idArticle);

}
else{
	echo "You should choose an article to read.";
}


include(dirname(__FILE__) . '/../view/footer.php');

?>



</body>
</html>