<?php
include('view/header.php');
require_once 'view/allArticles.php';
?>


<body>
	
<nav class="navbar navbar-default">
  <div class="container-fluid">
    <!-- Brand and toggle get grouped for better mobile display -->
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1" aria-expanded="false">
        <span class="sr-only">Toggle navigation</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <a class="navbar-brand" href="#"><i class="fa fa-home fa-5" aria-hidden="true"></i></a>
    </div>

    <!-- Collect the nav links, forms, and other content for toggling -->
    <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
      <ul class="nav navbar-nav">
        <li class="active"><a href="#">Link<span class="sr-only">(current)</span></a></li>
        <li><a href="#">Link</a></li>
        <li class="dropdown">
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
        </li>
      </ul>

    </div><!-- /.navbar-collapse -->
  </div><!-- /.container-fluid -->
</nav>
         <div class="col-md-12">
                     
                    <div class="col-md-8">
<?php


printAllArticles(3);


?>

                    </div>
                    <div class="col-md-4">
                         
                         <div class="row">
                                                            <div  class="haut-article">
</div>
                              <div class="box-droite">
                              <h4>Dernières recettes</h4>
                              <a><i class="fa fa-caret-right" aria-hidden="true"></i> Raviolis chinois grillés à la poêle</a><br />

                              <a><i class="fa fa-caret-right" aria-hidden="true"></i> Linguines à la ratatouille</a><br />
                              <a><i class="fa fa-caret-right" aria-hidden="true"></i> Farfalles au pesto et à la banane flambée</a><br />
                              </div>
                              <div class="separator-box-droite">
</div>
                              <div class="box-droite">
                               <h4>Recettes populaires</h4>
                               <a><i class="fa fa-caret-right" aria-hidden="true"></i> Raviolis chinois grillés à la poêle</a><br />

                              <a><i class="fa fa-caret-right" aria-hidden="true"></i> Linguines à la ratatouille</a><br />
                              <a><i class="fa fa-caret-right" aria-hidden="true"></i> Farfalles au pesto et à la banane flambée</a><br />
                         </div>
                         </div>
                    </div>
               
          </div>
<?php include('view/footer.php');?>
</div>



</body>




</html>