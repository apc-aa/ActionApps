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

# --------------------------------------------------------
# pagecache.php3 - defines PageCache class used for caching informations into database
# uses table: 
#    CREATE TABLE pagecache (
#      id varchar(32) NOT NULL,    (md5 crypted keystring used as database primary key (for quicker database searching)
#      str2find text NOT NULL,     (string used to find record on manual invalidate cache record - could be keystring)
#      content mediumtext,         (cached information)
#      stored bigint,              (timestamp of information storing)
#      flag int,                   (flag - not used for now)
#      PRIMARY KEY (id), KEY (stored)
#  );

if (!defined ("PAGECACHE_INCLUDED"))
	define ("PAGECACHE_INCLUDED",1);
else return;

class PageCache  {
  var $cacheTime=600;    # number of seconds to store cached informations
  var $lastClearTime=0;  # timestamp of last purging of cache database (removed obsolete cache informations) 
  var $clearFreq=600;    # number of seconds between database cleaning
  var $db;               # database identificator
  
  # PageCache class constructor
  function PageCache($db, $ct=600, $cf=600) {
    $this->db = $db;
    $this->cacheTime = $ct;
    $this->clearFreq = $cf;
  }  

  # returns keystring 
  # $keyVars - array of names of global variables which identifies cached information
  function getKeystring($keyVars) {
    if( isset($keyVars) and is_array($keyVars) ) {
      reset($keyVars);
      while( list( ,$var) = each($keyVars)) 
        $ks .= $var."=".$GLOBALS[$var];
    }
    return $ks;
  }  

  # returns cached informations or false
  function get($keyString) {
    if( ENABLE_PAGE_CACHE ) {
      $db = $this->db;
      $SQL = "SELECT * FROM pagecache WHERE id='".md5($keyString)."'";
      if( $GLOBALS['debug'] ) 
        $db->dquery($SQL);
      else 
      $db->query($SQL);
      if($db->next_record()) {
//  echo 'c-+ ';
        if( (time() - $this->cacheTime) < $db->f("stored") ) {
//  echo 'c-0 ';
          return $db->f(content);
        }  
//  echo 'c-X ';    
      }
    }  
    return false;    
  }
  
  # cache informations based on $keyString
  function store($keyString, $content, $str2find="") {
    if( ENABLE_PAGE_CACHE ) {
      $db = $this->db;
      $tm = time();
      $SQL = "REPLACE pagecache SET id='".md5($keyString)."', 
                                 str2find='". quote($str2find). "',
                                 content='". quote($content). "',
                                 stored='$tm',
                                 flag=''";
      $db->query($SQL);
      if( ($this->lastClearTime + $this->clearFreq) < $tm )
        $this->purge();
    }    
  }      

  # clears all old cached data
  function purge() {
    $db = $this->db;
    $tm = time();
    $SQL = "DELETE FROM pagecache WHERE stored<'".($tm - ($this->cacheTime))."'";
    $db->query($SQL);
    $this->lastClearTime = $tm;
  }  

  # remove cached informations for all rows which have the $cond in str2find
  function invalidateFor($cond) {
    $db = $this->db;
    $SQL = "DELETE FROM pagecache WHERE str2find LIKE '%". quote($cond) ."%'";
    $db->query($SQL);
  }  

  # remove cached informations for all rows
  function invalidate() {
    $db = $this->db;
    $SQL = "DELETE FROM pagecache";
    $db->query($SQL);
  }  
}
?>