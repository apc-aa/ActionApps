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

// this allows to require this script any number of times - it will be read only once
if (!defined ("VARSET_INCLUDED"))
	define ("VARSET_INCLUDED",1);
else return;

#
#	Cvarset - class for storing variables
#         - simplifies database manipulation 
#

class Cvariable {
  var $name;
  var $type;
  var $value;

	# constructor
	function Cvariable($name, $type, $value) {
    $this->name = $name;
    $this->type = $type;
    $this->value = $value;
	}
  
  function getValue() {
    return $this->value;
  }  

  function huh() {
    echo "$this->name($this->type) -> $this->value <br>\n";
  }
}  

class Cvarset {
	var $vars;  // array of varialbes

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
    
	# add variable to varset
	function add($varname, $type="text", $value="") {
    $this->vars["$varname"]= new Cvariable($varname, $type, $value);
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
  function makeINSERT()
  {
    reset($this->vars);
    $foo = "";
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
      switch( $variable->type )
      {
        case "number": 
          if( $variable->value == "" )
            $foo .= $predznak . "0"; 
           else 
            $foo .= $predznak . $variable->value;
          break;
        case "unpacked":
            $foo .= $predznak . " '". q_pack_id($variable->value) ."'";
            break;
        case "quoted":
            $foo .= $predznak . " '". $variable->value ."'";
            break;
        case "text":
        case "date":
        default:       $foo .= $predznak . " '". quote($variable->value) ."'";
      } 
      $predznak = ", ";
    }  
    return $foo . " ) " ;  
  }

  # makes SQL UPDATE clause from varset
  function makeUPDATE()
  {
    reset($this->vars);
    $foo = "";
    $predznak = "";
    while ( list( $varname, $variable ) = each($this->vars) )
    { 
      switch( $variable->type )
      {
        case "number": if( $variable->value == "")   // grrr: if $var=0 then $var=""!!!
                         $foo .= $predznak . $varname . "= 0";
                        else 
                         $foo .= $predznak . $varname . "=" . $variable->value;
                       break;
        case "unpacked":
            $foo .= $predznak . $varname . "='" . q_pack_id($variable->value) ."'";
            break;
        case "quoted":
            $foo .= $predznak . $varname . "='" . $variable->value ."'";
            break;
        case "date":
        case "text":
        default:       $foo .= $predznak . $varname . "='" . quote($variable->value) ."'";
      } 
      $predznak = ", ";
    }  
    return " " . $foo ;  
  }
  
  function huh($txt="") {
    echo "Varset: $txt";
    reset( $this->vars );
    while ( list( $varname, $variable ) = each($this->vars) )
      $variable->huh();
  }
}
?>