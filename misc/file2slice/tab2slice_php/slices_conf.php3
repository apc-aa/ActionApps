<?php
/***************************************************************
Read a configuration file with slice names and slice_id,
field names and their apc-aa field names
****************************************************************/

$config = file("slices.conf");

$i = 0;
while (list ($line_num, $line) = each ($config)) {
 
  $line = str_replace("\r", "", $line);
  $line = str_replace("\n", "", $line);

  $i++;
		
	$aline = explode(" ", $line);
  if ($aline[0] == "") {
    $i = 0;
	}
  if ($i == 1 && $search_slice == $aline[0]) {
// found slice name	
	  $i++;				
    $slice_id = $aline[1];
  }
  if ($i > 1) {
// fieldname -- aa-fieldname
    $field[$aline[0]] = $aline[1];
  }
}
?>
