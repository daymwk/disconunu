<?php
include('view/header.php');
require_once 'view/allArticles.php';

?>


	

         <div class="col-md-12">
                     
                    <div class="col-md-8">
<?php


printAllArticles(2);


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