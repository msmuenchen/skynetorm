<?

//thrown by getById() when there is no corresponding object found
//catch this exception if you expect this may happen
class DBObj_NotFoundException extends BMS_Exception {
}

//thrown by commit() when the MySQL statement changes 0 or more than 1 rows
class DBObj_NoChangeException extends BMS_Exception {
}

//thrown by validate() when some constraint fails
class DBObj_ValidateFailException extends BMS_Exception {
  public $fields=array();
  public function __construct($fields) {
    $this->fields=$fields;
    parent::__construct("Feldvalidation fehlgeschlagen");
  }
}

abstract class DBObj {
  protected static $__table="";
  public static $list_elements=array(); //these elements are the columns of the action=list view
  public static $detail_elements=array(); //these elements are the rows in the action=view view
  public static $one2many=array(); //one-to-x relationships (this is in the "dominant" object)
  public static $links=array(); //x-to-x relationships
  public static $detail_views=array();
  public static $edit_elements=array();
  public static $mod="INVALID";
  public static $sub="INVALID";
  protected $__invalidFields=array(); //used by validate(), each entry is one of the elements(!) keys
  
  protected function loadFrom($id,$recurse=true) {
    $q=new DB_Query("select * from ".static::$__table." where id=?",$id);
    if($q->numRows!=1)
      throw new DBObj_NotFoundException("Konnte Objekt %d nicht finden",$id);
    $r=$q->fetch();

    if(isset($r["creator"]) && $recurse) {
      try {
        $u=User::getById($r["creator"],false);
        $r["creator"]=array("id"=>$u->id,"name"=>$u->name);
      } catch(DBObj_NotFoundException $e) {
        $r["creator"]=array("id"=>0,"name"=>"Unbekannt");
      }
    }
    if(isset($r["last_editor"]) && $recurse) {
      try {
        $u=User::getById($r["last_editor"],false);
        $r["last_editor"]=array("id"=>$u->id,"name"=>$u->name);
      } catch(DBObj_NotFoundException $e) {
        $r["last_editor"]=array("id"=>0,"name"=>"Unbekannt");
      }
    }
    if(isset($r["create_time"]))
      $r["create_time"]=date("d.m.Y H:i:s",$r["create_time"]);
    if(isset($r["modify_time"]))
      $r["modify_time"]=date("d.m.Y H:i:s",$r["modify_time"]);
    foreach($r as $k=>$v)
      $this->$k=$v;    
  }
  
  public static function getById($id,$recurse=true) {
    $obj=new static();
    $obj->loadFrom($id,$recurse);
    return $obj;
  }
  public static function getAll() {
    $ret=array();
    $q=new DB_Query("select id from ".static::$__table);
    if($q->numRows<1)
      return $ret;
    while($r=$q->fetch())
      $ret[]=static::getById($r["id"]);
    return $ret;
  }
  
  //get all objects where a specific filter ("WHERE x=y") matches; supports prepared stmt
  //can also be used for pagination (LIMIT x,y) or sorting (ORDER BY)
  public static function getByFilter($filter) {
    $ret=array();
    
    $queryargs=func_get_args();
    $queryargs[0]="select id from ".static::$__table." ".$queryargs[0];
    
    //ugly hack, call_user_func_array does not support ctors
    $ref=new ReflectionClass("DB_Query");
    $q=$ref->newInstanceArgs($queryargs);
    if($q->numRows<1)
      return $ret;
    while($r=$q->fetch())
      $ret[]=static::getById($r["id"]);
    return $ret;
  }
  
  //fetches the objects with a specific one-to-many owner (like, CRM_Address::getByOwner("Customer",2) will
  //fetch all addresses which have customers_id = 2
  public static function getByOwner(DBObj $otherObj) {
    $other_col=$otherObj::$__table."_id";
    return static::getByFilter("where $other_col=?",$otherObj->id);
  }
  //get an array with a list of $otherObj objects which have a link-relationship
  public function getLinkedObjects($otherObj,$table) {
    $ret=array();
    $mytable=static::$__table;
    $othertable=$otherObj::$__table;
    
    $q=new DB_Query("select * from $table where {$mytable}_id=?",$this->id);
    if($q->numRows<1)
      return $ret;
    
    while($r=$q->fetch()) {
      if(isset($r["creator"])) {
        try {
          $u=User::getById($r["creator"],false);
          $r["creator"]=array("id"=>$u->id,"name"=>$u->name);
        } catch(DBObj_NotFoundException $e) {
          $r["creator"]=array("id"=>0,"name"=>"Unbekannt");
        }
      }
      if(isset($r["create_time"]))
        $r["create_time"]=date("d.m.Y H:i:s",$r["create_time"]);
      
      $r["obj"]=$otherObj::getById($r[$othertable."_id"]);
      
      $o=new stdClass();
      foreach($r as $k=>$v)
        $o->$k=$v;
      $ret[]=$o;
    }
    
    return $ret;
  }
  public static function fromScratch() {
    $q=new DB_Query("describe ".static::$__table);
    if($q->numRows<1)
      return;
    $o=new static();
    while($r=$q->fetch())
      $o->$r["Field"]=$r["Default"];
    $o->id=0;
    if(property_exists($o,"create_time"))
      $o->create_time=date("d.m.Y H:i:s");
    if(property_exists($o,"modify_time"))
      $o->modify_time=date("d.m.Y H:i:s");
    if(property_exists($o,"creator"))
      $o->creator=array("id"=>$_SESSION["user"]["id"],"name"=>$_SESSION["user"]["name"]);
    if(property_exists($o,"last_editor"))
      $o->last_editor=array("id"=>$_SESSION["user"]["id"],"name"=>$_SESSION["user"]["name"]);
    return $o;
  }
  public function commit() {
    $fields=array();
    $changes=array(); //this is used for the log data
    
    $q=new DB_Query("describe ".static::$__table);
    if($q->numRows<1)
      return;
    
    $queryargs=array();
    if($this->id===0) {
      $query="INSERT INTO ";
      $o=static::fromScratch();
    } else {
      $query="UPDATE ";
      $o=static::getById($this->id);
    }
    $query.=static::$__table." SET ";
    while($r=$q->fetch()) {
      //don't commit not-existing fields (these will be set to default)
      if(!property_exists($this,$r["Field"]))
        continue;
      //don't commit changes to the id field
      if($r["Field"]==="id")
        continue;
      //don't commit changes in the creator-field if the object already exists
      if($r["Field"]==="creator") {
        if($this->id!==0) //skip when object exists
          continue;
        $this->creator=$_SESSION["user"]["id"];
      } elseif($r["Field"]==="create_time") {
        if($this->id!==0)
          continue;
        $query.="create_time=UNIX_TIMESTAMP(NOW()),";
        continue;
      } elseif($r["Field"]==="last_editor") {
        $this->last_editor=$_SESSION["user"]["id"];
      } elseif($r["Field"]==="modify_time") {
        $query.="modify_time=UNIX_TIMESTAMP(NOW()),";
        continue;
      }
      
      //don't commit equal values
      if($o->$r["Field"]==$this->$r["Field"])
        continue;
      $query.="`".$r["Field"]."`=?,";
      $queryargs[]=$this->$r["Field"];
      
      //skip adding creator and last_editor to the diff, this messes up the logs
      if(!in_array($r["Field"],array("creator","last_editor")))
        $changes[$r["Field"]]=array("to"=>$this->$r["Field"],"from"=>$o->$r["Field"]);
    }
    //No change => we do not support touch()-like functionality
    if(sizeof($changes)==0)
      return;
    $query=substr($query,0,-1); //trim last comma
    if($this->id!==0) {
      $query.=" WHERE id=?";
      $queryargs[]=$this->id;
    }
    array_unshift($queryargs,$query);
    
    //ugly hack, call_user_func_array does not support ctors
    $ref=new ReflectionClass("DB_Query");
    $q=$ref->newInstanceArgs($queryargs);
    
    if($q->affectedRows!=1)
      throw new DBObj_NoChangeException();
    
    if($this->id===0) {
      $this->id=$q->insertId;
      $type="dbcreate";
    } else
      $type="dbchange";
    
    $this->loadFrom($this->id);
    
    if(class_exists("DBLog") && get_class($this)!=="DBLog") {
      $l=DBLog::fromScratch();
      $l->subject=get_class($this)."/".$this->id;
      $l->type=$type;
      $l->data=serialize($changes);
      $l->commit();
    }
  }
  
  //check if non-database constraints are met (double group memberships, whatever)
  //add the fields (keys of static::$elements!) that are wrong to $this->__invalidFields
  public function validate() {
    //override this in subclasses, if there's a need
    //but do not forget to call back here
  }
  
  //get a properly formatted property of an object
  public function getProperty($key,$escapeHTML=true) {
    //notice that we don't use any checking - php will throw an error, which will be converted to exception by us
    $elements=static::$elements;
    $element=$elements[$key];
    switch($element["mode"]) {
      case "select":
      case "radio":
        $vals=$element["data"];
        $vkey=$this->$element["dbkey"];
        if(!isset($vals[$vkey]))
          be_error(500,"be_index.php?mod=index","Unbekannter Wert für Key $vkey auf Property $key auf Objekt ".get_called_class()."/".$this->id);
        $val=$vals[$vkey];
        break;
      case "string":
      case "text":
        $val=$this->$element["dbkey"];
        break;
      case "process":
        $val=$this->processProperty($key);
        break;
      case "one2many":
        $dsid=$this->$element["dbkey"];
        if($dsid==0) { //0 = no "child" object set
          $val="(unbekannt)";
          break;
        } else
          $val=$element["data"]::getById($dsid)->toString();
      break;
      default:
        be_error(500,"be_index.php?mod=index","Unbekannter Modus ".$element["mode"]." für Key $key auf Objekt ".get_called_class()." angefragt");
    }
    if($escapeHTML)
      $val=esc($val);
    return $val;
  }
}
