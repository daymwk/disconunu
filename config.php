<?php

class Config{

	public static $sitename = 'Disco nunu';
	public static $subTitle = 'Simple CMS for blogs';
	public static $salt = '$KMGg4QdfF'; 
	public static $folder = 'disconunu';
	public static $servername = "localhost";
	public static $dbusername = "root";
	public static $dbpassword = "";
	public static $dbname = "disconunu";


	static function getSiteURL(){

		return "http://".$_SERVER['SERVER_NAME'] . '/' . self::$folder;

	}
}



?>