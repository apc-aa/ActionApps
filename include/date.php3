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
#	Date form element
#
#	Dropdown lists for Date, Month, Year
#

define("DATE_PHP3_INC",1);

class datectrl {
	var $name;
	var $day;
	var $month;
	var $year;
	var $y_range_plus;    // how many years + display in year select list
	var $y_range_minus;   // how many years + display in year select list
  var $from_now;        // year range is in relation to today's date/selected date
  
	# constructor
	# name identifies control on a form
	function datectrl($name, $y_range_minus=5, $y_range_plus=5, $from_now=false) {
		$this->name = $name;
		$this->y_range_plus  = $y_range_plus;
		$this->y_range_minus = $y_range_minus;
		$this->from_now = $from_now;
		$this->update();
	}

	# process form data
	function update() {
    $dayvar   = "tdctr_" . $this->name . "_day";
    $monthvar = "tdctr_" . $this->name . "_month";
    $yearvar  = "tdctr_" . $this->name . "_year";
		if(isset($GLOBALS[$dayvar])) 
			$this->day = $GLOBALS[$dayvar];
		if(isset($GLOBALS[$monthvar])) 
			$this->month = $GLOBALS[$monthvar];
		if(isset($GLOBALS[$yearvar])) 
			$this->year = $GLOBALS[$yearvar];
    return (    isset($GLOBALS[$dayvar])
             OR isset($GLOBALS[$monthvar]) 
             OR isset($GLOBALS[$yearvar]) );
	}	
	
	# set date, format form db
	function setdate($date) {
		if(ereg("([[:digit:]]{4}) *- *([[:digit:]]{1,2}) *- *([[:digit:]]{1,2})", 
				$date, $regs)) {
			$this->year = $regs[1];
			$this->month = $regs[2];
			$this->day = $regs[3];
		}
		if(checkdate($this->month, $this->day, $this->year)) 
			return "$this->year-$this->month-$this->day";
		return "";
	}

	# set date, format form integer
	function setdate_int($date) {
    $d = getdate($date);
  	$this->year = $d[year];
		$this->month = $d[mon];
		$this->day = $d[mday];
	}

	# get stored date
	function get_datetime() {
    if( "$this->year-$this->month-$this->day" == date("Y-m-d"))  // today
			return now();                                              // add time
    return $this->get_date();  
	}

  # get stored date as integer
	function get_date() {
    return  mktime (0,0,0,$this->month,$this->day,$this->year);
	}

  # get stored date as integer
	function get_datestring() {
    return  $this->day. " - ". $this->month. " - ". $this->year;
	}

  # check if date is valid  
  function ValidateDate($inputName, &$err)
  {
    if( $this->get_date() > 0 ) 
      return true;
    $err[$this->name] = MsgErr(L_ERR_IN." $inputName");
    return false;
  }  
                   
	# print select box for day
	function getdayselect() {
		$at = getdate(time());
		$sel =  ($this->day != 0 ? $this->day : $at[mday]);
		for($i = 1; $i <= 31; $i++)
			$ret .= "<option value=\"$i\"". 
              (($i == $sel) ? " selected" : "") . ">$i</option>";
		return "<select name=\"tdctr_" . $this->name . "_day\">$ret</select>";
	}	

	# print select box for month
	function getmonthselect() {
		global $L_MONTH;
		$at = getdate(time());
		$sel =  ($this->month != 0 ? $this->month : $at[mon]);
		for($i = 1; $i <= 12; $i++) {
			$ret .= "<option value=\"$i\"". (($i == $sel) ? " selected" : "") . ">".
             $L_MONTH[$i] ."</option>";
		}
		return "<select name=\"tdctr_" . $this->name . "_month\">$ret</select>";
	}	

	# print select box for year
	function getyearselect() {
		$at = getdate(time());
		$sel = ((($this->year==0) OR ($this->from_now)) ? $at[year] : $this->year );
		for($i = $sel - $this->y_range_minus; $i <= $sel + $this->y_range_plus; $i++) {
			$ret .= "<option value=\"$i\"" . (($i == $this->year) ? " selected":""). 
			       ">$i</option>";
		}
    return "<select name=\"tdctr_" . $this->name . "_year\">$ret</select>";
	}	

	# print complete date control 
	function getselect () {
		return $this->getdayselect(). $this->getmonthselect(). $this->getyearselect();
	}
	
	# print complete date control 
	function pselect () {
		echo $this->getselect();
	}

}
/*
$Log$
Revision 1.7  2001/06/03 16:00:49  honzam
multiple categories (multiple values at all) for item now works

Revision 1.6  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.5  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.4  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.1.1.1  2000/06/21 18:40:27  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:14  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.3  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>