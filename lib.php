<?
//central include file

//Config - include only if the project doesn't have a config array
if(!isset($config) || !is_array($config))
  require("config.php");

//DB - include only if the classes haven't been included yet
if(!class_exists("DB",false)) {
  require("DB.php");
  require("DB_Query.php");
}

require("core.errorhandling.php");
require("core.DBObj.php");

/**
 * This function generates a password salt as a string of x (default = 15) characters
 * in the a-zA-Z0-9!@#$%&*? range.
 * @param $max integer The number of characters in the string
 * @return string
 * @author AfroSoft <info@afrosoft.tk>
 */
function generateSalt($max = 15) {
  $characterList = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  $i = 0;
  $salt = "";
  while ($i < $max) {
    $salt .= $characterList{mt_rand(0, (strlen($characterList) - 1))};
    $i++;
  }
  return $salt;
}

//escape shorthand
function esc($s) {
  $flags=ENT_QUOTES;
  if(defined("ENT_HTML5"))
    $flags|=ENT_HTML5;
  return htmlspecialchars($s,$flags,"UTF-8");
}
