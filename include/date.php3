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
		if(isset($GLOBALS["tdctr_" . $this->name . "_day"])) 
			$this->day = $GLOBALS["tdctr_" . $this->name . "_day"];
		if(isset($GLOBALS["tdctr_" . $this->name . "_month"])) 
			$this->month = $GLOBALS["tdctr_" . $this->name . "_month"];
		if(isset($GLOBALS["tdctr_" . $this->name . "_year"])) 
			$this->year = $GLOBALS["tdctr_" . $this->name . "_year"];
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

	# get stored date
	function getdate() {
		if(checkdate($this->month, $this->day, $this->year)) 
			return "$this->year-$this->month-$this->day";
		return "";
	}

	# get stored date
	function getdatetime() {
    if( "$this->year-$this->month-$this->day" == date("Y-m-d"))  // today
			return now();                                              // add time
    return $this->getdate();  
	}

  # check if date is valid  
  function ValidateDate($inputName, &$err)
  {
    if( $this->getdate()=="" )
    { $err["$this->name"] = MsgErr(L_ERR_IN." $inputName");
      return false;
    } return true;
  }  
                   
	# print select box for day
	function pdayselect() {
		echo "<select name=\"tdctr_" . $this->name . "_day\">";
		$at = getdate(time());
		$sel =  ($this->day != 0 ? $this->day : $at[mday]);
		for($i = 1; $i <= 31; $i++) {
			echo "<option value=\"$i\"";
			if($i == $sel) echo " selected";
			echo ">$i</option>";
		}
		echo "</select>";
	}	

	# print select box for month
	function pmonthselect() {
		global $l_month;
		echo "<select name=\"tdctr_" . $this->name . "_month\">";
		$at = getdate(time());
		$sel =  ($this->month != 0 ? $this->month : $at[mon]);
		for($i = 1; $i <= 12; $i++) {
			echo "<option value=\"$i\"";
			if($i == $sel) echo " selected";
			echo ">" . $l_month[$i] . "</option>";
		}
		echo "</select>";
	}	

	# print select box for year
	function pyearselect() {
		echo "<select name=\"tdctr_" . $this->name . "_year\">";
		$at = getdate(time());
		$sel = ((($this->year==0) OR ($this->from_now)) ? $at[year] : $this->year );
		for($i = $sel - $this->y_range_minus; $i <= $sel + $this->y_range_plus; $i++) {
			echo "<option value=\"$i\"";
			if($i == $this->year) echo " selected";
			echo ">", $i, "</option>";
		}
		echo "</select>";
	}	

	# print complete date control 
	function pselect () {
		$this->pdayselect();
		$this->pmonthselect();
		$this->pyearselect();
	}
}
/*
$Log$
Revision 1.2  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

Revision 1.1.1.1  2000/06/21 18:40:27  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:14  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.3  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>