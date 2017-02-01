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
?>