<?php
require_once dirname(__FILE__) . '/../config.php';
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
<link rel="stylesheet" href="<?php echo Config::getSiteURL(); ?>/css/style.css">
<link rel="stylesheet" href="<?php echo Config::getSiteURL(); ?>/css/font-awesome.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
</head>
<div class="custom-container">
<div id="header">
<div style="float:left;">
	<a href="<?php echo Config::getSiteURL();?>"><img src="<?php echo Config::getSiteURL();?>/images/logo.jpg" id="logo" alt="logo"/></a>
</div>

     <div class="titles">
          <h1><?php echo Config::$sitename; ?></h1>
     <h3><?php echo Config::$subTitle;?></h3>
</div>
</div>

