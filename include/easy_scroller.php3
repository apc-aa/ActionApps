<?php # -*-mode: Fundamental; tab-width: 4; -*-
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
#	Class easy scroller
#	Implements navigation bar for scrolling through long lists
# No SQL filters support (as in scroller.php3)
#

define("EASY_SCROLLER_PHP3_INC",1);

class easy_scroller {
	var $classname = "easy_scroller";
	var $persistent_slots = Array("current", "id", "sortdir", 
  		"sortcol", "filters", "itmcnt", "metapage", "variables", "urldefault");
	var $current = 1;		# current page
	var $id;				    # scroller id
  var $itmcnt;        # total item count
	var $metapage = 10;	# "metapage" size
	var $urldefault;		# cache self url
	
	# constructor
	# $id identifies scroller on a web page
	# $pgcnt is the number of pages to scroll
	function easy_scroller($id = "", $url = "", $metapage=10, $itmcnt = 0) {
		$this->id = $id;
		$this->metapage = $metapage;
		$this->urldefault = $url;
		$this->itmcnt = $itmcnt;
    $this->current = 1;
	}

	# return part of a query string for move of $pages relative of current position
	function Relative($pages) {
		return urlencode("scr_" . $this->id . "_Mv") . "=" . urlencode($pages);
	}

	# return part of a query string for move to absolute position $page
	function Absolute($page) {
		return urlencode("scr_" . $this->id . "_Go") . "=" . urlencode($page);
	}
	
	function pageCount() {
    return floor(($this->itmcnt - 1) / max(1,$this->metapage)) + 1;
  }  
  
  # keep current page within bounds
	function checkBounds() {	
		if($this->current < 1) $this->current = 1;
    $pages = $this->pageCount();
		if($this->current > $pages) $this->current = $pages;
	}
	
	# adjust number of pages depends on item count and metapage
	function countPages($itmcnt) {
    $this->itmcnt = $itmcnt;
		$this->checkBounds();
  }
		
  # process query string and execute commands for this scroller
	# query string is taken from global variables
  # (based on $itmcnt)
	function update($url = "", $itmcnt = "") { 
		if($itmcnt)
      $this->countPages($itmcnt);
		if($url) $this->urldefault = $url;
		if($GLOBALS["scr_" . $this->id . "_Go"]) 
			$this->current = $GLOBALS["scr_" . $this->id . "_Go"];
		if($GLOBALS["scr_" . $this->id . "_Mv"]) 
			$this->current += $GLOBALS["scr_" . $this->id . "_Mv"];
		$this->checkBounds();
  }
   
	# return navigation bar as a hash
	# labels as keys, query string fragments a values
	function navarray() {
		if(!$this->itmcnt) return array();
    $pgcnt = $this->pageCount();
		$mp = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
		$from = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
		$to = min(($mp + 1) * SCROLLER_LENGTH + 1, $pgcnt);
		if($this->current > 1)
			$arr[L_PREV] = $this->Relative(-1);
		if($from > 1) $arr["1"] = $this->Absolute(1);
		if($from > 2) $arr[".. "] = "";
		for($i = $from; $i <= $to; $i++) {
			$arr[(string)$i] = ($i == $this->current ? "" : 
				$this->Absolute($i));
		}	
		if($to < $pgcnt - 1) $arr[" .."] = "";
		if($to < $pgcnt) 
			$arr[(string) $pgcnt] = $this->Absolute($pgcnt);
		if($this->current < $pgcnt)
			$arr[L_NEXT] = $this->Relative(1);
		return $arr;
	}

	# convert array provided by navarray into HTML code
	# commands are added to $url
	function pnavbar($url = "") {
		if(!$url)
      $url = $this->urldefault;
		$i = 0;
		$arr = $this->navarray();
		while(list($k, $v) = each($arr)) {
      if($i++) echo " | ";	
			if($v) 
				echo "<a href=\"". $url. "scrl=1&". $v. "\" class=\"scroller\">$k</a>";
			else 
				echo "<span class=\"scroller\">$k</span>";
		}
 	}
}  


class view_scroller {
	var $classname = "view_scroller";
	var $persistent_slots = Array("current", "id", "itmcnt", "metapage", "urldefault");
	var $current = 1;		# current page
	var $id;				    # scroller id
  var $itmcnt;        # total item count
	var $metapage = 10;	# "metapage" size
	var $urldefault;		# cache self url
	
	# constructor
	# $id identifies scroller on a web page
	# $pgcnt is the number of pages to scroll
	function view_scroller($id="", $url="", $metapage=10, $itmcnt=0, $curr=0) {
		$this->id = $id;
		$this->metapage = $metapage;
		$this->urldefault = $url;
		$this->itmcnt = $itmcnt;
    $this->current = floor( $curr/$this->metapage ) + 1;
	}

	# return part of a query string for move to absolute position $page
	function Absolute($page) {
		return urlencode("scr_" . $this->id . "_Go") . "=" . urlencode($page);
	}
	
	function pageCount() {
    return floor(($this->itmcnt - 1) / max(1,$this->metapage)) + 1;
  }  
  
  # keep current page within bounds
	function checkBounds() {	
		if($this->current < 1) $this->current = 1;
    $pages = $this->pageCount();
		if($this->current > $pages) $this->current = $pages;
	}
	
	# adjust number of pages depends on item count and metapage
	function countPages($itmcnt) {
    $this->itmcnt = $itmcnt;
		$this->checkBounds();
  }
		
	# return navigation bar as a hash
	# labels as keys, query string fragments a values
	function navarray() {
    $this->CheckBounds();
		if(!$this->itmcnt) 
      return array();
    $pgcnt = $this->pageCount();
		$mp = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
		$from = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
		$to = min(($mp + 1) * SCROLLER_LENGTH + 1, $pgcnt);
		if($this->current > 1)
			$arr[L_PREV] = $this->Absolute($this->current-1);
		if($from > 1) $arr["1"] = $this->Absolute(1);
		if($from > 2) $arr[".. "] = "";
		for($i = $from; $i <= $to; $i++) {
			$arr[(string)$i] = ($i == $this->current ? "" : 
				$this->Absolute($i));
		}	
		if($to < $pgcnt - 1) $arr[" .."] = "";
		if($to < $pgcnt) 
			$arr[(string) $pgcnt] = $this->Absolute($pgcnt);
		if($this->current < $pgcnt)
			$arr[L_NEXT] = $this->Absolute($this->current+1);
		return $arr;
	}

	# convert array provided by navarray into HTML code
	# commands are added to $url
	function get($begin='', $end='', $add='class="scroller"', $nopage='') {
    $url = con_url($this->urldefault,"scrl=".$this->id);

    if( $GLOBALS['apc_state'] )                
      $url .= '&apc='.$GLOBALS['apc_state']['state'];

		$i = 0;
		$arr = $this->navarray();
    
    if( count($arr) <= 1 )
      return $nopage;
    
		while(list($k, $v) = each($arr)) {
      if($i++) 
        $out .= " | ";	
  		$out .= ( $v ? "<a href=\"$url&$v\" $add>$k</a>" : $k);
		}
    
    return $begin.$out.$end;
 	}
}  


?>
