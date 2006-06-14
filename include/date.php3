<?php // -*-mode: Fundamental; tab-width: 4; -*-
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

// javascript.php3 defines getTriggers, which is used by Add / Edit item page
require_once AA_INC_PATH."javascript.php3";

//
//	Date form element
//
//	Dropdown lists for Date, Month, Year
//

class datectrl {
    var $name;
    var $time;
    var $day;
    var $month;
    var $year;
    var $y_range_plus;    // how many years + display in year select list
    var $y_range_minus;   // how many years + display in year select list
  var $from_now;        // year range is in relation to today's date/selected date
  var $display_time;    // display time too

    // constructor
    // name identifies control on a form
    function datectrl($name, $y_range_minus=5, $y_range_plus=5, $from_now=false,
                    $display_time=false) {
        $this->name = $name;
        $this->y_range_plus  = $y_range_plus;
        $this->y_range_minus = $y_range_minus;
        $this->from_now = $from_now;
        $this->display_time = $display_time;
        $this->update();
    }

    // process form data
    function update() {
        $timevar  = chop($GLOBALS["tdctr_" . $this->name . "_time"]);
        $dayvar   = chop($GLOBALS["tdctr_" . $this->name . "_day"]);
        $monthvar = chop($GLOBALS["tdctr_" . $this->name . "_month"]);
        $yearvar  = chop($GLOBALS["tdctr_" . $this->name . "_year"]);
        if ( $timevar )
            $this->time = $timevar;
        if ( $dayvar)
            $this->day = $dayvar;
        if ( $monthvar )
            $this->month = $monthvar;
        if ( $yearvar )
            $this->year = $yearvar;
        return ( $timevar OR $dayvar OR $monthvar OR $yearvar );
    }

    // set date, format form db
    function setdate($date) {
        if (ereg("([[:digit:]]{4}) *- *([[:digit:]]{1,2}) *- *([[:digit:]]{1,2}) *(.*$)",
                $date, $regs)) {
            $this->year = $regs[1];
            $this->month = $regs[2];
            $this->day = $regs[3];
            $this->time = $regs[4];
    }
        if (checkdate($this->month, $this->day, $this->year))
            return "$this->year-$this->month-$this->day";
        return "";
    }

    // set date, format form integer
    function setdate_int($date) {
        $d = getdate($date);
        $this->year = $d["year"];
        $this->month = $d["mon"];
        $this->day = $d["mday"];
        $this->time = $d["hours"].":".$d["minutes"].":".$d["seconds"] ;
    }

    // get stored date as integer
    function get_date() {
        // time is not set ?
        if (!$this->year) {
            // we have to return 0, beacause mktime(0,0,0,0,0,0) == 943916400
            // (at least from php 5.1.2)
            return 0;
        }
        $t = explode( ':', $this->time ?  $this->time : "0:0:0");
        return mktime ($t[0],$t[1],$t[2],(int)$this->month,(int)$this->day,(int)$this->year);
    }

    // get stored date as integer
    function get_datestring() {
        return  $this->day. " - ". $this->month." - ".$this->year." ". $this->time;
    }

    // check if date is valid and possibly set date to "default" value if it is
    // not required and default value is specified
    function ValidateDate($inputName, &$err, $required=true, $deafult='0')  {
        if (( $this->get_date() > 0  ) OR ($this->get_date()==-3600))
            return true;
        if ($required) {
            $err[$this->name] = MsgErr(_m("Error in")." $inputName");
            return false;
        }
        $this->setdate_int($deafult);
        return (( $this->get_date() > 0  ) OR ($this->get_date()==-3600));
    }

    // print select box for day
    function getdayselect() {
        $at = getdate(time());
        $sel =  ($this->day != 0 ? $this->day : $at["mday"]);
        for ($i = 1; $i <= 31; $i++)
            $ret .= "<option value=\"$i\"".
              (($i == $sel) ? ' selected class="sel_on"' : "") . ">$i</option>";
        return "<select name=\"tdctr_" . $this->name . "_day\"".getTriggers("select",$this->name).">$ret</select>";
    }

    // print select box for month
    function getmonthselect() {
        $L_MONTH = monthNames();
        $at = getdate(time());
        $sel =  ($this->month != 0 ? $this->month : $at["mon"]);
        for ($i = 1; $i <= 12; $i++) {
            $ret .= "<option value=\"$i\"". (($i == $sel) ? ' selected class="sel_on"' : "") . ">".
             $L_MONTH[$i] ."</option>";
        }
        return "<select name=\"tdctr_" . $this->name . "_month\"".getTriggers("select",$this->name).">$ret</select>";
    }

    // print select box for year
    function getyearselect() {
        $at = getdate(time());
        $from = ( $this->from_now ? $at["year"] - $this->y_range_minus :
                                $this->y_range_minus );
        $to   = ( $this->from_now ? $at["year"] + $this->y_range_plus :
                                $this->y_range_plus );
        $selectedused = false;
        for ($i = $from; $i <= $to; $i++) {
            $ret .= "<option value=\"$i\"";
            if ($i == $this->year) {
                $ret .= ' selected class="sel_on"';
                $selectedused = true;
            }
            $ret .= ">$i</option>";
        }

        // now add all values, which is not in the array, but field has this value
        if ($this->year AND !$selectedused) {
            $ret .= "<option value=\"". htmlspecialchars($this->year) ."\" selected class=\"sel_missing\">".htmlspecialchars($this->year)."</option>";
        }

        return "<select name=\"tdctr_" . $this->name . "_year\"".getTriggers("select",$this->name).">$ret</select>";
    }

    // print select box for time
    function gettimeselect() {
    switch( $this->display_time ) {
      case 2:   //display time as is - hour:minutes:seconds
              return "<input type=text name=\"tdctr_". $this->name ."_time\"
                     value=\"". safe($this->time) ."\" size=8 maxlength=8>";
      case 1:   //display time as hour:minutes - if time is 00:00, it shows nothing
      case 3:   //display time as hour:minutes
              $t = explode( ":", $this->time );
              if ( !is_array($t) ) $t = array( '00','00');
              if ( !$t[0] ) $t[0] = "00";
              if ( !$t[1] ) $t[1] = "00";
              if ( strlen( $t[1] ) == '1' )   // minutes should be two nubers
                $t[1] = "0" . $t[1];
              $timestr = $t[0] .":". $t[1];
              if ( ($this->display_time == 1) AND ($timestr == "00:00") )
                $timestr = "";
              return "<input type=text name=\"tdctr_". $this->name ."_time\"
                     value=\"". safe($timestr). "\" size=8 maxlength=8".getTriggers("input",$this->name).">";
    }
    return "";
    }

    // print complete date control
    function getselect () {
        return $this->get_datestring().$this->getdayselect(). $this->getmonthselect(). $this->getyearselect(). $this->gettimeselect();
    }

    // print complete date control
    function pselect () {
        echo $this->getselect();
    }
}

function datum($name, $val, $y_range_minus=5, $y_range_plus=5, $from_now=false,
               $display_time=false) {
    $dc = new datectrl($name, $y_range_minus, $y_range_plus, $from_now, $display_time);
    $dc->setdate_int($val);
    return $dc->getselect();
}


?>