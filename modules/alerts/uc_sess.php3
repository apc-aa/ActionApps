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

if (!defined ("AA_UC_SESS_INCLUDED"))
    define ("AA_UC_SESS_INCLUDED", 1);
else return;

require $GLOBALS[AA_INC_PATH]."locsess.php3";

class AA_UC_Session extends Session {
  var $classname = "AA_UC_Session";

  var $cookiename     = "";                ## defaults to classname
  var $magic          = "adwetdfgyr";      ## ID seed
  var $mode           = "get";          ## We propagate session IDs in URL
##  var $fallback_mode  
  var $lifetime       = 0;                 ## 0 = do session cookies, else minutes
  var $that_class     = "AA_CT_Sql"; ## name of data storage container
  var $gc_probability = 5;  
  var $auto_init; # auto init 
  var $allowcache     = "no"; # Control caching of session pages, if set to no (also the default), the page is not cached under HTTP/1.1 or HTTP/1.0; if set to public , the page is publically cached under HTTP/1.1 and HTTP/1.0; if set to private , the page is privately cached under HTTP/1.1 and not cached under HTTP/1.0 
  var $allowcache_expire = 1;    # When caching is allowed, the pages can be cached for this many minutes. 

  function start($sid = "") {
    global $HTTP_COOKIE_VARS, $HTTP_GET_VARS, $HTTP_HOST, $HTTPS;
   	$name = $this->that_class;
	$this->that = new $name;
	$this->that->ac_start();
	
	$this->name = $this->cookiename==""?$this->classname:$this->cookiename;
	
	if (   isset($this->fallback_mode)
      && ( "get" == $this->fallback_mode ) 
      && ( "cookie" == $this->mode )
      && ( ! isset($HTTP_COOKIE_VARS[$this->name]) ) ) {
 
      if ( isset($HTTP_GET_VARS[$this->name]) ) {
        $this->mode = $this->fallback_mode;
      } else {
        header("Status: 302 Moved Temporarily");
        $this->get_id($sid);
        $this->mode = $this->fallback_mode;
        if( isset($HTTPS) && $HTTPS == 'on' ){
          ## You will need to fix suexec as well, if you use Apache and CGI PHP
          $PROTOCOL='https';
        } else {
          $PROTOCOL='http';
        }
          header("Location: ". $PROTOCOL. "://". $HTTP_HOST.$this->self_url());
        exit;
      }
    }

    # Allowing a limited amount of caching, as suggested by
    # Padraic Renaghan on phplib@shonline.de.
    # Note that in HTTP/1.1 the Cache-Control headers override the Expires
    # headers and HTTP/1.0 ignores headers it does not recognize (e.g,
    # Cache-Control). Mulitple Cache-Control directives are split into 
    # mulitple headers to better support MSIE 4.x.
    switch ($this->allowcache) {

      case "public":
        $exp_gmt = gmdate("D, d M Y H:i:s", time() + $this->allowcache_expire * 60) . " GMT";
        $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";				
        header("Expires: " . $exp_gmt);
        header("Last-Modified: " . $mod_gmt);
        header("Cache-Control: public");
        header("Cache-Control: max-age=" . $this->allowcache_expire * 60);
        break;

      case "private":
        $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . $mod_gmt);
        header("Cache-Control: private");
        header("Cache-Control: max-age=" . $this->allowcache_expire * 60);
        break;

      default:
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-cache");
        header("Cache-Control: must-revalidate");
        header("Pragma: no-cache");
        break;
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

?>
