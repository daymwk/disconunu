<?php


require dirname(__FILE__) . '/../dbUtil/select.php';
require dirname(__FILE__) . '/../dbUtil/insert.php';

class User
{
  private $userID;		
  private $username;
  private $email;

  function __construct($UserID){

  	$data = selectUser($UserID);
  	$this->userID = $UserID;
  	$this->username = $data['username'];
  	$this->email= $data['email'];

  }  
  
  public function getUserID()
  {
    return $this->userID;
  }

  public function getUsername()
  {
    return $this->username;
  }

  public function getEmail()
  {
    return $this->email;
  }

}


class Article
{
  private $idArticle;		
  private $title;
  private $content;
  private $dateArticle;
  private $author;
  private $links;
    
  function __construct($idArticle){

  	$data = selectArticle($idArticle);
  	$this->idArticle = $idArticle;
  	$this->title = $data['title'];
  	$this->content= $data['content'];
  	$this->dateArticle = $data['dateArticle'];
  	
  	$author = new User($data['userID']);
  	$this->author = $author->getUsername();
  	$this->links = $data['links'];

  }  
  
  public function getIdArticle()
  {
    return $this->idArticle;
  }

  public function getTitle()
  {
    return $this->title;
  }

  public function getContent()
  {
    return $this->content;
  }

  public function getArticleDate()
  {
    return $this->dateArticle;
  }

  public function getAuthor()
  {
    return $this->author;
  }

  public function getLinks()
  {
    return $this->links;
  }
}

class MenuItem
{
  private $id;
  private $idMenu;  
  private $title;
  private $idArticle;

  function __construct($data){

    $this->id = $data['id'];
    $this->idMenu = $data['idMenu'];
    $this->title = $data['title'];
    $this->idArticle = $data['idArticle'];

  }  
  
  public function getID()
  {
    return $this->id;
  }

  public function getMenuID()
  {
    return $this->idMenu;
  }

  public function getTitle()
  {
    return $this->title;
  }

   public function getArticleID()
  {
    return $this->idArticle;
  }

    public function getArticle()
  {
    return selectArticle($this->idArticle);
  }

}


class Menu
{
  private $id;    
  private $title;
  private $items = array();

  function __construct($title){

    $data = selectMenu($title);
    $this->idMenu = $data['id'];
    $this->title = $title;
    $items = selectMenuItems($this->idMenu);
    for($i=0;$i<count($items);$i++){
          $this->items[$i] = new MenuItem($items[$i]);
    }



  }  
  
  public function getID()
  {
    return $this->idMenu;
  }

  public function getTitle()
  {
    return $this->title;
  }

  public function getItems()
  {
    return $this->items;
  }

}




?>