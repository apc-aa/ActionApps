<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

#
#	Cvarset - class for storing variables
#         - simplifies database manipulation
#

class Cvariable {
  var $name;
  var $type;
  var $value;
  /** Is it a key value? key values are used in UPDATE -> WHERE, INSERT -> VALUES.
      See also makeINSERTorUPDATE. */
  var $iskey;

    # constructor
    function Cvariable($name, $type, $value, $iskey=false) {
    $this->name = $name;
    $this->type = $type;
    $this->value = $value;
    $this->iskey = $iskey;
    }

  function getValue() {
    return $this->value;
  }

  function getSQLValue() {
      switch( $this->type )
      {
        case "number": if( $this->value == "")   // grrr: if $var=0 then $var=""!!!
                         return "0";
                       else
                         return $this->value;
                       break;
        case "unpacked":
            return "'" . q_pack_id($this->value) ."'";
            break;
        case "quoted":
            return "'" . $this->value ."'";
            break;
        case "date":
        case "text":
        default: return "'" . quote($this->value) ."'";
      }
  }

  function huh() {
    echo "$this->name($this->type) -> $this->value <br>\n";
  }
}

class Cvarset {
    var $vars;  // array of variables

    # constructor
    function Cvarset() {
    }

    # clears whole varset
    function clear() {
      $this->vars="";
    }

    # get variable value
    function get($varname) {
      $cv = $this->vars["$varname"];
      return ( $cv ? $cv->getValue() : false);
    }

    function getSQLvalue ($varname) {
        $cv = $this->vars[$varname];
        return ( $cv ? $cv->getSQLvalue() : false);
    }

    # add variable to varset
    function add($varname, $type="text", $value="") {
      $this->vars[$varname]= new Cvariable($varname, $type, $value);
    }

    # add global variables to varset (names in $arr)
    function addglobals($arr, $type="quoted") {
        if ( isset($arr) AND is_array($arr) ) {
            foreach ( $arr as $varname ) {
                $this->vars[$varname]= new Cvariable($varname, $type, $GLOBALS[$varname]);
            }
        }
    }

    # add key variable to varset (see Cvariable)
    function addkey($varname, $type="text", $value="") {
      $this->vars[$varname]= new Cvariable($varname, $type, $value, true);
    }

    # remove variable from varset
    function remove($varname) {
      unset ($this->vars[$varname]);
    }

    # set variable value
  function set($varname, $value, $type=""){
    if( $type=="" ) {
      $v = $this->vars[$varname];
      $type = $v->type;
    }
    $this->add($varname, $type, $value);   // it must be assigned this way, because $v is just copy
  }

  # if undefined - set
  function ifnoset($varname, $value, $type="") {
    if( !($this->get($varname)) )
      $this->add($varname, ($type ? $type : "quoted"), $value);
  }

    # return variable value
  function value($varname){
    $v = $this->vars["$varname"];
    return $v->value;
  }

    # set variables values due to array
  function setFromArray($arr){
    reset( $this->vars );
    while ( list( $varname, $variable ) = each($this->vars) ){
      $this->set($varname, $arr[$varname]);
    }
  }

    # add variable to varset
    function addArray($text_fields, $num_fields="") {
    if( isset($text_fields) AND is_array($text_fields)) {
      reset( $text_fields );
      while( list(,$name) = each($text_fields) )
        $this->add($name, "text");
    }
    if( isset($num_fields) AND is_array($num_fields)) {
      reset( $num_fields );
      while( list(,$name) = each($num_fields) )
        $this->add($name, "number");
    }
    }

  # makes SQL INSERT clause from varset
  function makeINSERT($tablename = "")
  {
    reset($this->vars);
    if ($tablename)
        $foo = "INSERT INTO $tablename";
    else $foo = "";
    $predznak = " ( ";
    while ( list( $varname, $variable ) = each($this->vars) )
    {
      $foo .= $predznak . $varname;
      $predznak = ", ";
    }
    reset($this->vars);
    $predznak = " ) VALUES ( ";
    while ( list( $varname, $variable ) = each($this->vars) )
    {
      $foo .= $predznak . $variable->getSQLValue();
      $predznak = ", ";
    }
    return $foo . " ) " ;
  }

  # makes SQL UPDATE clause from varset
  function makeUPDATE($tablename = "")
  {
    reset($this->vars);
    while ( list( $varname, $variable ) = each($this->vars) ) {
      //echo $varname." -> ".$variable->getSQLValue()."<br>";
      if (!$variable->iskey)
          $updates[] = $varname ."=". $variable->getSQLValue();
    }
    if ($tablename)
        $retval = "UPDATE $tablename SET";
    $retval .= " " . join (", ", $updates);
    $where = $this->makeWHERE();
    if ($where)
        $retval .= " WHERE ".$where;
    return $retval;
  }

  function makeSELECT ($table)
  {
    $where = $this->makeWHERE();
    if (!$where)
      return "SELECT * FROM $table";
    else return "SELECT * FROM $table WHERE ".$where;
  }

  function makeDELETE ($table)
  {
    $where = $this->makeWHERE();
    if (!$where)
      return "Error";
    else return "DELETE FROM $table WHERE ".$where;
  }

  function makeWHERE ($table="")
  {
    reset ($this->vars);
    $where = "";
    while (list ($varname, $variable) = each ($this->vars))
      if ($variable->iskey) {
        if ($where) $where .= " AND ";
        if ($table) $varname = $table.".".$varname;
        $where .= $varname ."=". $variable->getSQLValue();
      }
    return $where;
  }

  /// This function looks into the given table and if the row exists
  /// it is updated, if not then inserted. Add always all key fields
  /// by addkey() to the varset before using this function.

  function makeINSERTorUPDATE($table)
  {
    global $db;
    $sql = $this->makeSELECT($table);
    $db->query($sql);
    switch ($db->num_rows()) {
        case 0: return $this->makeINSERT($table);
        case 1: return $this->makeUPDATE($table);
        default:
             // Error: there are several rows with the same key variables
            return "Error using makeINSERTorUPDATE: " . $db->num_rows(). " rows match the query $sql";
    }
  }

  function huh($txt="") {
    echo "Varset: $txt";
    reset( $this->vars );
    while ( list( $varname, $variable ) = each($this->vars) )
      $variable->huh();
  }
}
?>