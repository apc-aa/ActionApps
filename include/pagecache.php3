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
  //var $db;               # database identificator
  var $caller;           # just for debugging

  # PageCache class constructor
  function PageCache($ignoreddb, $ct=600, $cf=600,$caller="") {
    if ($GLOBALS[debugcache]) huhl("Cache:new:$caller:$ct,$cf");
    $this->caller = $caller; // Just for debugging
    //$this->db = $db;
    $this->cacheTime = $ct;
    $this->clearFreq = $cf;
  }

  # Local
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

  #returns cached informations or false
  function get($keyString) {
    if ($GLOBALS[debugcache]) {
        huhl("Cache:get:keystring=",htmlentities($keyString)); trace("p"); }
    if( ENABLE_PAGE_CACHE ) {
      $db = getDB();
      $SQL = "SELECT * FROM pagecache WHERE id='".md5($keyString)."'";
if ($GLOBALS[debugcache]) $GLOBALS[debug]=1;
      $db->tquery($SQL);
if ($GLOBALS[debugcache]) $GLOBALS[debug]=0;
      if($db->next_record()) {
        if ($GLOBALS[debugcache]) { huhl("Cache:get:Got a hit:time=",time()," ct=",$this->cacheTime," st=",$db->f("stored"),"t-ct=",time()-$this->cacheTime); }
        if( (time() - $this->cacheTime) < $db->f("stored") ) {
          if ($GLOBALS[debugcache]) huhl("found:str2find=".$db->f(str2find));
          $c = $db->f(content);
          freeDB($db);
          return $c;
        }
      }
      freeDB($db);
    }
    return false;
  }

  # cache informations based on $keyString
  function store($keyString, $content, $str2find="") {
    global $debugcache, $cache_nostore;
    if ($GLOBALS[debugcache]) {
        huhl("Cache:store:keystring=",htmlentities($keyString));
        huhl("$str2find:",$this->caller,htmlentities($content)); trace("p");
    }
    if( ENABLE_PAGE_CACHE AND !$cache_nostore) {  // $cache_nostore used when
      $db = getDB();                              // {user:xxxx} alias is used
      $tm = time();
      $SQL = "REPLACE pagecache SET id='".md5($keyString)."',
                                 str2find='". quote($str2find). "',
                                 content='". quote($content). "',
                                 stored='$tm',
                                 flag=''";
      $db->tquery($SQL);
      if( ($this->lastClearTime + $this->clearFreq) < $tm )
        $this->purge();
      freeDB($db);
    }
  }

  # clears all old cached data
  function purge() {
    if ($GLOBALS[debugcache]) huhl("Cache:purge:".$this->caller);
    $db = getDB();
    $tm = time();
    $SQL = "DELETE FROM pagecache WHERE stored<'".($tm - ($this->cacheTime))."'";
    $db->query($SQL);
    freeDB($db);
    $this->lastClearTime = $tm;
  }

  # remove cached informations for all rows which have the $cond in str2find
  function invalidateFor($cond) {
    if ($GLOBALS[debugcache]) huhl("Cache:invalidateFor:$cond");
    $db = getDB();

    // We do not want to report errors here. Sometimes this SQL leads to:
    //   "MySQL Error: 1213 (Deadlock found when trying to get lock; Try
    //    restarting transaction)" error.
    // It is not so big problem if we do not invalidate cache - much less than
    // halting the operation.
    $store_halt = $db->Halt_On_Error;
    $db->Halt_On_Error = "no";
    $SQL = "DELETE FROM pagecache WHERE str2find LIKE '%". quote($cond) ."%'";
    $db->query($SQL);
    $db->Halt_On_Error = $store_halt;

    freeDB($db);
  }

  # remove cached informations for all rows
  function invalidate() {
    if ($GLOBALS[debugcache]) huhl("Cache:invalidate");
    $db = getDB();
    $SQL = "DELETE FROM pagecache";
    $db->query($SQL);
    freeDB($db);
  }
}

$GLOBALS[pagecache] = new PageCache(null,CACHE_TTL,CACHE_PURGE_FREQ);

?>
