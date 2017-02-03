<?php

class Config{

	private static $config = null;
	public $sitename = 'Disco nunu';
	public $subTitle = 'Simple CMS for blogs';
	public $salt = '$KMGg4QdfF'; 
	public $folder = 'disconunu';
	public $servername = "localhost";
	public $dbusername = "root";
	public $dbpassword = "";
	public $dbname = "disconunu";
	public $mod_rewrite = true;


	private function __contrust(){
		
	}


	public static function getConfig(){

		if(is_null(self::$config))
			self::$config = new Config();

	return self::$config;

	}


	public function getSiteURL(){

		return "http://".$_SERVER['SERVER_NAME'] . '/' . $this->folder;

	}
}

$config = Config::getConfig();
?>