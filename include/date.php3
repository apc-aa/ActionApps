<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Jiri Hejsek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
// javascript.php3 defines getTriggers, which is used by Add / Edit item page
require_once AA_INC_PATH."javascript.php3";


/** monthnames function
 *  @return array month names
 */
function monthNames() {
    return array( 1 => _m('January'), _m('February'), _m('March'), _m('April'), _m('May'), _m('June'), _m('July'), _m('August'), _m('September'), _m('October'), _m('November'), _m('December'));
}


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

    /** datectrl function
     *  constructor
     *  name identifies control on a form
     * @param $name
     * @param $y_range_minus = 5
     * @param $y_range_plus = 5
     * @param $from_now = false
     * @param $display_time = false
     */
    function datectrl($name, $y_range_minus=5, $y_range_plus=5, $from_now=false, $display_time=false) {
        $this->name          = $name;
        $this->y_range_plus  = $y_range_plus;
        $this->y_range_minus = $y_range_minus;
        $this->from_now      = $from_now;
        $this->display_time  = $display_time;
        $this->update();
    }
    /** update function
     * process form data
     */
    function update() {
        $timevar  = chop($GLOBALS["tdctr_" . $this->name . "_time"]);
        $dayvar   = chop($GLOBALS["tdctr_" . $this->name . "_day"]);
        $monthvar = chop($GLOBALS["tdctr_" . $this->name . "_month"]);
        $yearvar  = chop($GLOBALS["tdctr_" . $this->name . "_year"]);
        if ( $timevar ) {
            $this->time = $timevar;
        }
        if ( $dayvar) {
            $this->day = $dayvar;
        }
        if ( $monthvar ) {
            $this->month = $monthvar;
        }
        if ( $yearvar ) {
            $this->year = $yearvar;
        }
        return ( $timevar OR $dayvar OR $monthvar OR $yearvar );
    }

    /** setdate function
     *  set date, format form db
     * @param $date
     */
    function setdate($date) {
        $regs = array();
        if (preg_match("/([0-9]{4}) *- *([0-9]{1,2}) *- *([0-9]{1,2}) *(.*$)/", $date, $regs)) {
            $this->year  = $regs[1];
            $this->month = $regs[2];
            $this->day   = $regs[3];
            $this->time  = $regs[4];
        }
        if (checkdate($this->month, $this->day, $this->year)) {
            return "$this->year-$this->month-$this->day";
        }
        return "";
    }

    /** setdate_int function
     *  set date, format form integer
     * @param $date
     */
    function setdate_int($date) {
        $d           = datectrl::isTimestamp($date) ? getdate($date) : getdate();
        $this->year  = $d["year"];
        $this->month = $d["mon"];
        $this->day   = $d["mday"];
        $this->time  = $d["hours"].":".$d["minutes"].":".$d["seconds"] ;
    }

    /** we check, if the value is not so big (becauce we solved problem, when
     *  the date was entered as 230584301025887115 - which is too big and it
     *  takes ages for PHP to evaluate the date() function then. (php 5.2.6))
     *  it is perfectly possible to increase the max value, however
     */
    function isTimestamp($timestamp) {
        return is_numeric($timestamp) AND ($timestamp > -2147483647) AND ($timestamp < 2147483648);
    }

    /** get_date function
     *  get stored date as integer
     */
    function get_date() {
        // time is not set ?
        if (!$this->year) {
            // we have to return 0, beacause mktime(0,0,0,0,0,0) == 943916400
            // (at least from php 5.1.2)
            return 0;
        }
        $t = explode( ':', $this->time ?  $this->time : "0:0:0");
        return mktime($t[0],$t[1],$t[2],(int)$this->month,(int)$this->day,(int)$this->year);
    }

    /** get_datestring function
     *  get stored date as integer
     */
    function get_datestring() {
        return  $this->day. " - ". $this->month." - ".$this->year." ". $this->time;
    }

    /** ValidateDate function
     *  check if date is valid and possibly set date to "default" value if it is
     *  not required and default value is specified
     * @param $inputName
     * @param $err
     * @param $required = true
     * @param $default = '0'
     */
    function ValidateDate($inputName, &$err, $required=true, $deafult='0')  {
        if (( $this->get_date() > 0  ) OR ($this->get_date()==-3600)) {
            return true;
        }
        if ($required) {
            $err[$this->name] = MsgErr(_m("Error in")." $inputName");
            return false;
        }
        $this->setdate_int($deafult);
        return (( $this->get_date() > 0  ) OR ($this->get_date()==-3600));
    }


    function getDayOptions() {
        $at  = getdate(time());
        $sel = ($this->day != 0 ? $this->day : $at["mday"]);
        for ($i = 1; $i <= 31; $i++) {
            $ret .= "<option value=\"$i\"". (($i == $sel) ? ' selected class="sel_on"' : "") . ">$i</option>";
        }
        return $ret;
    }

    /** getdayselect function
     * print select box for day
     * @return string
     */
    function getdayselect() {
        return "<select name=\"tdctr_" . $this->name . "_day\"".getTriggers("select",$this->name).">".$this->getDayOptions()."</select>";
    }


    function getMonthOptions() {
        $L_MONTH = monthNames();
        $at      = getdate(time());
        $sel     = ($this->month != 0 ? $this->month : $at["mon"]);
        for ($i = 1; $i <= 12; $i++) {
            $ret .= "<option value=\"$i\"". (($i == $sel) ? ' selected class="sel_on"' : "") . ">". $L_MONTH[$i] ."</option>";
        }
        return $ret;
    }

    /** getmonthselect function
     * print select box for month
     * @return string
     */
    function getmonthselect() {
        return "<select name=\"tdctr_" . $this->name . "_month\"".getTriggers("select",$this->name).">".$this->getMonthOptions()."</select>";
    }

    function getYearOptions($required=true) {
        $at           = getdate(time());
        $from         = ( $this->from_now ? $at["year"] - $this->y_range_minus : $this->y_range_minus );
        $to           = ( $this->from_now ? $at["year"] + $this->y_range_plus  : $this->y_range_plus );
        $selectedused = false;
        $ret          = '';

        if (!$required) {
            $ret .= "<option value=\"0\" ".($this->year ? '' : 'selected class="sel_on" ').">----</option>";
        }

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
        return $ret;
    }

    /** getyearselect function
     * print select box for year
     * @return string
     */
    function getyearselect() {
        return "<select name=\"tdctr_" . $this->name . "_year\"".getTriggers("select",$this->name).">".$this->getYearOptions()."</select>";
    }

    function isTimeDisplayed() {
        return $this->display_time;
    }

    function getTimeString() {
        $t = explode( ":", $this->time );
        $time_string = '';

        switch( $this->display_time ) {
            case 1: $time_string = sprintf("%d:%02d",$t[0], $t[1]);
                    if ($time_string == "0:00") {
                        $time_string = '';
                    }
                    break;
            case 2: $time_string = sprintf("%d:%02d:%02d",$t[0], $t[1]);
                    break;
            case 3: $time_string = sprintf("%d:%02d",$t[0], $t[1]);
                    break;
        }
        return $time_string;
    }

    /** gettimeselect function
     * print select box for time
     * @return string
     */
    function gettimeselect() {
        if (!$this->isTimeDisplayed()) {
            return "";
        }
        return "<input type=\"text\" name=\"tdctr_". $this->name ."_time\"  value=\"". safe($this->getTimeString()). "\" size=\"8\" maxlength=\"8\"".getTriggers("input",$this->name).">";
    }

    /** getselect function
     * print complete date control
     * @return string
     */
    function getselect () {
        return $this->get_datestring().$this->getdayselect(). $this->getmonthselect(). $this->getyearselect(). $this->gettimeselect();
    }

    /** pselect function
     * print complete date control
     * @return string
     */
    function pselect () {
        echo $this->getselect();
    }
}

?>