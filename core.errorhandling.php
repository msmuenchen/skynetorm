<?
//Custom exception supporting sprintf-syntax
class BMS_Exception extends Exception {
  public function __construct() {
    $args=func_get_args();
    parent::__construct(call_user_func_array("sprintf",$args));
  }
}

set_error_handler(function($level,$message,$file,$line){
  throw new BMS_Exception("PHP error %s (%d) in %s:%d",$message,$level,$file,$line);
  return true;
});
