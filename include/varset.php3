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

define("VARSET_PHP3_INC",1);

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
  
	# add variable to varset
	function add($varname, $type="text", $value="") {
    $this->vars["$varname"]= new Cvariable($varname, $type, $value);
	}	

	# set variable value
  function set($varname, $value){
    $v = $this->vars[$varname];
    $this->add($varname, $v->type, $value);   // it must be assigned this way, because $v is just copy
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
	function addArray($text_fields, $num_fields) {
    reset( $text_fields );
    while( list(,$name) = each($text_fields) )
      $this->add($name, "text"); 
    reset( $num_fields );
    while( list(,$name) = each($num_fields) )
      $this->add($name, "number"); 
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
/*
$Log$
Revision 1.1  2000/06/21 18:40:50  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.5  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>