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


class DB_AA extends DB_Sql {
  var $Host     = DB_HOST;
  var $Database = DB_NAME;
  var $User     = DB_USER;
  var $Password = DB_PASSWORD;
  function halt($msg) {
    if( $this->Halt_On_Error == "no" )
      return;
    printf("</td></table><b>Database error:</b> %s<br>\n", $msg);
    printf("<b>MySQL Error</b>: %s (%s)<br>\n",
      $this->Errno, $this->Error);
    echo("Please contact ". ERROR_REPORTING_EMAIL ." and report the ");
    printf("exact error message.<br>\n");    
    if( $this->Halt_On_Error == "yes" )
      die("Session halted.");
  }  
}

class AA_CT_Sql extends CT_Sql {	## Container Type for Session is SQL DB
  var $database_class = "DB_AA";          ## Which database to connect...
  var $database_table = "active_sessions"; ## and find our session data in this table.
}


#class Example_CT_Shm extends CT_Shm {
#  var $max_sessions   = 500;               ## number of maximum sessions
#  var $shm_key        = 0x123754;          ## unique shm identifier
#  var $shm_size       = 64000;             ## size of segment
#}

#class Example_CT_Ldap extends CT_Ldap {
#	var $ldap_host = "localhost";
#	var $ldap_port = 389;
#	var $basedn    = "dc=your-domain, dc=com";
#	var $rootdn    = "cn=root, dc=your-domain, dc=com";
#	var $rootpw    = "secret";
#	var $objclass  = "phplibdata";
#}

#class Example_CT_Dbm extends CT_Dbm {
#	var $dbm_file  = "must_exist.dbm";
#}

#class Example_Session extends Session {
#  var $classname = "Example_Session";
#
#  var $cookiename     = "";                ## defaults to classname
#  var $magic          = "Hocuspocus";      ## ID seed
#  var $mode           = "cookie";          ## We propagate session IDs with #cookies
#  var $fallback_mode  = "get";
#  var $lifetime       = 0;                 ## 0 = do session cookies, else #minutes
#  var $that_class     = "AA_CT_Sql"; ## name of data storage container
#  var $gc_probability = 5;  
#}

# skips terminating backslashes
function DeBackslash2($txt) {
	return str_replace('\\', "", $txt);        // better for two places
//  return EReg_Replace("[\]*$", "", $foo);
}   


class AA_SL_Session extends Session {
  var $classname = "AA_SL_Session";

  var $cookiename     = "";                ## defaults to classname
  var $magic          = "adwetdfgyr";      ## ID seed
  var $mode           = "get";          ## We propagate session IDs via get method
  var $fallback_mode  = "get";
  var $lifetime       = 0;                 ## 0 = do session cookies, else minutes
  var $that_class     = "AA_CT_Sql"; ## name of data storage container
  var $gc_probability = 5;  
  
  //rewriten to return URL of shtml page that includes this script instead to return self url of this script. If noquery parameter is true, session id is not added    
   function MyUrl($SliceID=0, $Encap=true, $noquery=false){  //SliceID is here just for compatibility with MyUrl function in extsess.php3
      global $HTTP_HOST, $HTTPS, $DOCUMENT_URI, $REDIRECT_DOCUMENT_URI;
      if (isset($HTTPS) && $HTTPS == 'on') {
         ## You will need to fix suexec as well, if you use Apache and CGI PHP
         $PROTOCOL='https';
      } else {
         $PROTOCOL='http';
      }
      if (isset($REDIRECT_DOCUMENT_URI)) {  ## CGI --enable-force-cgi-redirect
         $foo = $PROTOCOL. "://". $HTTP_HOST.$REDIRECT_DOCUMENT_URI;
      } else {
         $foo = $PROTOCOL. "://". $HTTP_HOST.$DOCUMENT_URI;
      }
      switch ($this->mode) {
         case "get":  
            if (!$noquery){$foo .= "?".urlencode($this->name)."=".$this->id;}
            break;
         default:
            break;
      }
      return $foo;
   }
  
  # adds variables passesd by QUERY_STRING_UNESCAPED to HTTP_GET_VARS
  # SSI patch - passes variables to SSIed script
  function expand_getvars() {
  global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED, $HTTP_GET_VARS;   
    if (isset($REDIRECT_QUERY_STRING_UNESCAPED)) {
      $varstring = $REDIRECT_QUERY_STRING_UNESCAPED;
      # $REDIRECT_QUERY_STRING_UNESCAPED
      #  - necessary for cgi version compiled with --enable-force-cgi-redirect      
    } else {  
      $varstring = $QUERY_STRING_UNESCAPED;
    }  

    $a = explode("&",$varstring);
    $i = 0;

    while ($i < count ($a)) {
      $b = explode ('=', $a [$i]);
      $b[0] = DeBackslash2($b[0]);
      $b[1] = DeBackslash2($b[1]);
      $HTTP_GET_VARS[urldecode ($b [0])]= urldecode ($b [1]);
      $i++;
    }
    return $i;
  }
  
  function get_id($id = "") {
    global $HTTP_COOKIE_VARS, $HTTP_GET_VARS, $QUERY_STRING;
    $newid=true;
 
	$this->name = $this->cookiename==""?$this->classname:$this->cookiename;
 
    if ( "" == $id ) {
      $newid=false;
      switch ($this->mode) {
        case "get":
          $this->expand_getvars(); ## ssi patch   
          $id = isset($HTTP_GET_VARS[$this->name]) ? $HTTP_GET_VARS[$this->name] : "";
        break;
        case "cookie":
          $id = isset($HTTP_COOKIE_VARS[$this->name]) ? $HTTP_COOKIE_VARS[$this->name] : "";
        break;
        default:
          die("This has not been coded yet.");
        break;
      }
    }
 
    if ( "" == $id ) {
      $newid=true;
	  $id = $this->that->ac_newid(md5(uniqid($this->magic)), $this->name);
    }
 
    switch ($this->mode) {
      case "cookie":
        if ( $newid && ( 0 == $this->lifetime ) ) {
          SetCookie($this->name, $id, 0, "/");
        }
        if ( 0 < $this->lifetime ) {
          SetCookie($this->name, $id, time()+$this->lifetime*60, "/");
        }
      break;
      case "get":
        if ( isset($QUERY_STRING) ) {
          $QUERY_STRING = ereg_replace(
            "(^|&)".quotemeta(urlencode($this->name))."=".$id."(&|$)",
            "\\1", $QUERY_STRING);
        }
      break;
      default:
        ;
      break;
    }
 
    $this->id = $id;
  }
  
  function start($sid = "") {
    global $HTTP_COOKIE_VARS, $HTTP_GET_VARS, $HTTP_HOST, $HTTPS;
        $this->expand_getvars(); ## ssi patch   
	$name = $this->that_class;
	$this->that = new $name;
	$this->that->ac_start();
	
	$this->name = $this->cookiename==""?$this->classname:$this->cookiename;
	
	if ( isset($this->fallback_mode)
      && ( "get" == $this->fallback_mode ) 
      && ( "cookie" == $this->mode )
      && ( ! isset($HTTP_COOKIE_VARS[$this->name]) ) ) {
 
      if ( isset($HTTP_GET_VARS[$this->name]) ) {
        $this->mode = $this->fallback_mode;
      } else {
        ##header("Status: 302 Moved Temporarily");
        $this->get_id($sid);
        $this->mode = $this->fallback_mode;
        if( isset($HTTPS) && $HTTPS == 'on' ){
          ## You will need to fix suexec as well, if you use Apache and CGI PHP
          $PROTOCOL='https';
        } else {
          $PROTOCOL='http';
        }
          ##header("Location: ". $PROTOCOL. "://". $HTTP_HOST.$this->self_url());
        exit;
      }
    }

    
    $this->get_id($sid);
 
    $this->thaw();
    
    ## Garbage collect, if necessary
    srand(time());
    if ((rand()%100) < $this->gc_probability) {
      $this->gc();
    }
  }    
}
/*
$Log$
Revision 1.10  2001/10/01 16:21:38  honzam
bugs with non existant tables in sql_update fixed

Revision 1.9  2001/05/18 13:55:04  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.8  2001/01/26 15:06:50  honzam
Off-line filling - first version with WDDX (then we switch to APC RSS+)

Revision 1.7  2000/11/08 12:23:55  honzam
Fixed problem with bad AA_SL_Sess id - bad copy QUERY_STRING to HTTP_GET_VARS

Revision 1.6  2000/10/10 10:04:24  honzam
better backslashes handling for Query string parsing

Revision 1.5  2000/08/23 12:29:58  honzam
fixed security problem with inc parameter to slice.php3

Revision 1.4  2000/08/22 12:30:06  honzam
fixed problem with lost session id AA_SL_Session in cgi (PHP4) instalation.

Revision 1.3  2000/08/07 15:27:45  kzajicek
Added missing semicolon in global statement

Revision 1.2  2000/07/21 15:28:46  kzajicek
When PHP (CGI version) is configured with --enable-force-cgi-redirect,
most of standard environmental variables are moved to REDIRECT_variable_name.

Revision 1.1.1.1  2000/06/21 18:40:37  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:23  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.9  2000/06/12 19:58:35  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.8  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc
*/
?>
