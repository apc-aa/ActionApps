<?php
/*  $Id$

    This is an example of the top part of a calendar page.
    The script shows select boxes to choose month and year and a heading with
    the chosen month and year. It uses JavaScript to prepare view parameters
    for a calendar view which should be SSI included on this page.

    An example of the appropriate .shtml page:

    <html><body>
    <!--#include virtual="/apc-aa/doc/script/calendar.php3"-->
    <!--#include virtual="/apc-aa/view.php3?vid=317"-->
    </body></html>

    (c) Jakub Adámek, May 2002
*/

/* First you need to add variables from URL --- this is not automatical when including PHP from .shtml
    The function add_vars is copied from in aaa/include/util.php3 */

// skips terminating backslashes
function DeBackslash($txt) {
    return str_replace('\\', "", $txt);        // better for two places
}

function add_vars($query_string="", $debug="") {
    if ( $query_string ) {
        $varstring = $query_string;
    } elseif (isset($_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'])) {
        $varstring = $_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'];
    } else {
        $varstring = $_SERVER['QUERY_STRING_UNESCAPED'];
    }

  $a = explode("&",$varstring);
  $i = 0;

  while ($i < count ($a)) {
    unset($index1);
    unset($index2);
    unset($lvalue);
    unset($value);
    $pos = strpos($a[$i], "=");
    if ($pos) {
      $lvalue = substr($a[$i],0,$pos);
      $value  = urldecode (DeBackslash(substr($a[$i],$pos+1)));
    }
    if (!preg_match("/^(.+)\[(.*)\]/i", $lvalue, $c))   // is it array variable[]
      $GLOBALS[urldecode (DeBackslash($lvalue))]= $value;   // normal variable
    else {
      $index1 = urldecode (DeBackslash($c[2]));
      if (preg_match("/^(.+)\[(.*)\]/i", $c[1], $d)) { // for double array variable[][]
        $index2  = urldecode (DeBackslash($d[2]));
        $varname = urldecode (DeBackslash($d[1]));
      } else
        $varname  = urldecode (DeBackslash($c[1]));
      if ( isset($index2) )
        $GLOBALS[$varname][$index2][$index1] = $value;
       else
        $GLOBALS[$varname][$index1] = $value;
    }
    $i++;
  }
  return $i;
}

add_vars();

/* H E R E  begins the main script */

$L_MONTH = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June',
'July', 'August', 'September', 'October', 'November', 'December');

// set default month and year to current date

if ($month == 0) {
    $month = getdate();
    $month = $month ["mon"];
}

if ($year == 0) {
    $year = getdate();
    $year = $year ["year"];
}

echo "<form name='f' method='get' action='./calendar.shtml'>";

// the hidden field with view parameters:
echo "<input type=hidden name='set[]'>";

/* show the select boxes for month and year */

echo "Change to: ";
echo "<select name='month' onChange='saveMonthYear();'>";
for ($i=1; $i <= 12; ++$i)
    echo "<option value=$i".($month == $i ? " selected" : "").">".$L_MONTH[$i];
echo "</select>&nbsp;&nbsp;";

echo "<select name='year' onChange='saveMonthYear();'>";
$thisyear = getdate();
$thisyear = $thisyear["year"];
// show 1 year before and 10 years after the current year
for ($y=$thisyear - 1; $y <= $thisyear + 10; ++$y)
    echo "<option value=$y".($year == $y ? " selected": "").">$y";
echo "</select>";

echo "</form>";

/* show the (day and) month and year heading */

echo "<h1>".($day ? $day : "").$L_MONTH[$month]." ".$year."</h1>";
?>

<SCRIPT LANGUAGE=javascript>
<!--
    function getSelected (selectBox) {
        return selectBox.options [selectBox.selectedIndex].value;
    }

    // prepare the view parameters
    function saveMonthYear()
    {
        mytext = 'month-'+getSelected(document.f['month'])
            +',year-'+getSelected(document.f['year']);
        document.f['set[]'].value = mytext;
        document.f.submit();
    }
//-->
</SCRIPT>
