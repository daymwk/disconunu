<?php

class Config{

	public static $sitename = 'Pasta blog';
	public static $hash = ''; 
	public static $folder = 'disconunu';
	public static $servername = "localhost";
	public static $dbusername = "root";
	public static $dbpassword = "";
	public static $dbname = "disconunu";


	static function getSiteURL(){

		return "http://".$_SERVER['SERVER_NAME'] . '/' . self::$folder;

	}
}


$config = new Config();

?>