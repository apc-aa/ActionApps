<?php

# adds variables passed by QUERY_STRING_UNESCAPED (or user $query_string) 
# to GLOBALS 
# alternatively, you can require aaa/include/util.php3, which contains this functions

# skips terminating backslashes
function DeBackslash($txt) {
	return str_replace('\\', "", $txt);        // better for two places
}   

function add_vars($query_string="", $debug="") {
  global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED;
  if ( $query_string ) 
    $varstring = $query_string;
  elseif (isset($REDIRECT_QUERY_STRING_UNESCAPED))
    $varstring = $REDIRECT_QUERY_STRING_UNESCAPED;
  else
    $varstring = $QUERY_STRING_UNESCAPED;

  $a = explode("&",$varstring);
  $i = 0;

  while ($i < count ($a)) {
    unset($index1); 
    unset($index2); 
    unset($lvalue); 
    unset($value); 
    $pos = strpos($a[$i], "=");
    if($pos) {
      $lvalue = substr($a[$i],0,$pos);
      $value  = urldecode (DeBackslash(substr($a[$i],$pos+1)));
    }  
    if (!ERegI("^(.+)\[(.*)\]", $lvalue, $c))   // is it array variable[]
      $GLOBALS[urldecode (DeBackslash($lvalue))]= $value;   # normal variable
    else {
      $index1 = urldecode (DeBackslash($c[2]));
      if (ERegI("^(.+)\[(.*)\]", $c[1], $d)) { // for double array variable[][]
        $index2  = urldecode (DeBackslash($d[2]));
        $varname = urldecode (DeBackslash($d[1]));  
      } else 
        $varname  = urldecode (DeBackslash($c[1]));  
      if( isset($index2) ) 
        $GLOBALS[$varname][$index2][$index1] = $value;
       else 
        $GLOBALS[$varname][$index1] = $value;
    }
    $i++;
  }
  return $i;
}

$L_MONTH = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 
'July', 'August', 'September', 'October', 'November', 'December');

add_vars();

function showSelectMonthYear ($yearMinus, $yearPlus)
{
    global $month, $year, $vid, $L_MONTH;
    
    echo "<select name='month' onChange='saveMonthYear();'>";
    for ($i=1; $i <= 12; ++$i) 
        echo "<option value=$i".($month == $i ? " selected" : "").">".$L_MONTH[$i];
    echo "</select>&nbsp;&nbsp;";
    echo "<select name='year' onChange='saveMonthYear();'>";
    $thisyear = getdate();
    $thisyear = $thisyear["year"];
    for ($y=$thisyear - $yearMinus; $y <= $thisyear + $yearPlus; ++$y) 
        echo "<option value=$y".($year == $y ? " selected": "").">$y";
    echo "</select>";
    $views = array ("301"=>"List view","317"=>"Table view","319"=>"Events list");
    echo "<select name='vid' onChange='saveMonthYear();'>";
    while (list ($id, $caption) = each ($views)) 
        echo "<option value='$id'".($vid == $id ? " selected" : "").">$caption";
    echo "</select>";
}

if ($month == 0) {
    $month = getdate();
    $month = $month ["mon"];
}
if ($year == 0) {
    $year = getdate();
    $year = $year ["year"];
}

echo "<form name='f' method='get' action='./calendar.shtml'>
        <input type=hidden name='set[301]'>
        <input type=hidden name='set[317]'>
        <input type=hidden name='set[318]'>
        <input type=hidden name='set[319]'>";
echo "Change to: ";
showSelectmonthYear(10,10);
echo "</form>";
echo "<h1>";
if ($day) echo "$day ";
echo $L_MONTH[$month]." ".$year."</h1>";

//showMonth ($month, $year);
?>
<SCRIPT LANGUAGE=javascript>
<!--
    function getSelected (selectBox) {
	    return selectBox.options [selectBox.selectedIndex].value;
    }

    function saveMonthYear()
    {   
        vid = getSelected (document.f.vid);
        document.f['set['+vid+']'].value = 'month-'+getSelected(document.f['month'])
            +',year-'+getSelected(document.f['year']);
        document.f.submit();
    }
//-->
</SCRIPT>


