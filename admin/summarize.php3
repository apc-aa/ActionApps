<?php
//$Id$
/*
Copyright (C) 2003 Mitra Technology Consulting
http://www.mitra.biz

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


/*
    This module generates a human readable output page that summarizes the slices on a system
    Feel free to modify it - but please talk to Mitra about any changes.
*/



require_once "../include/init_page.php3"; // Loads variables etc
require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";  // for slices

#$debug = 1;

#$debugsummarize = 01;

// Quick test to show contents of slice record
if (0) {
    $db->query("SELECT * FROM slice LIMIT 1");
    $db->next_record();
    huhl(GetTable2Array("SELECT * FROM slice LIMIT 1",NoCoLuMn,1));
}

$scoreUnshown = 10;
$scoreAddOrMiss = 50;

if ($debugsummarize) huhl("Starting summarize");
$db = getDB();
$SQL = "SELECT id FROM slice ORDER BY created_at";
$db -> tquery($SQL);
while ($db->next_record()) {
    $sliceids[] = unpack_id($db->f("id"));
}
if ($debugsummarize) huhl($sliceids);

$slicearr = new slices($sliceids);
if ($debugsummarize) huhl($slicearr);

$sao=$slicearr->objarr();
//$score = compareSlices($sao["c17f7dc366af4d932aa45d41ddbdd4e5"],$sao["4e6577735f454e5f746d706c2e2e2e2e"],1);
//print("<li>Compare score=$score</li>\n");
//print("Nearest = ". nearestSlice($sao["c17f7dc366af4d932aa45d41ddbdd4e5"],$sliceids) );
$ignored_sliceids = array( unpack_id128("AA_Core_Fields.."),
unpack_id128("News_EN_tmpl...."),
unpack_id128("ReaderManagement"));
$CoreFields = $sao[unpack_id128("AA_Core_Fields..")]->fields();

//huhl("Test pack",$ignored_sliceids[1],":::",pack_id128($ignored_sliceids[1]));

$nocache=1;
sliceshortcuts(1);
mapslices();

freeDB($db);

function ignored_slice($si) {
    global $ignored_sliceids;
    return !  (array_search($si,$ignored_sliceids) === false);
}
function sliceshortcuts($ign) {
  global $sao;
  print("<table cellspacing=1>\n");
  reset($sao);
  while (list($si,$so) = each($sao)) {
    //if ($debugsummarize) huhl("Slice Obj",$so);
    if (!$ign || !ignored_slice($si)) {
        $n = $so->name();
         print("<tr><td><a href=\"#$si\">$si</a></td><td>$n</td><td>" .
            ($so->deleted() ? "Deleted " : "") . 
//            pack_id128($si) .
            editslicefields($si) . " " .
            "</td></tr>\n");
    }
  }
  print("</table>\n");
}

function editslicefields($sid) {
    global $AA_CP_Session;
    return "<a href=\"se_fields.php3?AA_CP_Session=$AA_CP_Session&change_id=$sid\"> Edit fields </a>";
}
function mapslices() {
    global $sao;
    print("<ul>");
    reset($sao);
    while (list($sid,$so) = each($sao)) {
        if (! $so->deleted() && !ignored_slice($sid)) {
            if($pm) {   // first time round no model
                $nearest = nearestSlice($so,$pm,1);
                $sno = $sao[$nearest];
                print("<li><a name=\"$sid\"></a>$sid: '" . $so->name() . "' closest to <a href=\"#$nearest\">$nearest</a> '" . $sno->name()."'<br>".editslicefields($sid));
                compareSlices($so,$sno,1);
                print("</li>");
            }
        }
        $pm[] = $sid;
    }
    print("</ul>");
}

if ($debugsummarize) huhl("Starting summarize");

function nearestSlice($st,$possmodels) {
    global $sao;
    $sc_min = 999999;
    $sm_min = "";
    reset($possmodels);
    while(list(,$sm) = each($possmodels)) {
        if ($sm == $st->unpacked_id()) continue;
        $sc = compareSlices($st,$sao[$sm],0);
        if ($sc < $sc_min) {
            $sm_min = $sm;
            $sc_min = $sc;
        }
    }
    return $sm_min;
}
function compareSlices($st,$sm,$pr) {
    global $scoreAddOrMiss,$CoreFields;
    $score = 0;
    $ft = $st->fields();
    $fm = $sm->fields();
    if ($pr) print("<ul>\n");
    reset($ft[0]);
    while (list($ftn,$fta) = each($ft[0])) {
        if (! $fm[0][$ftn]) {
            compareFields($ftn,$ft[0][$ftn],$CoreFields[0][GetFieldType($ftn)],$pr,"Added");
            $score += $scoreAddOrMiss;
        } else {
            $score = $score + compareFields($ftn,$ft[0][$ftn],$fm[0][$ftn],$pr,"Common");
        }
    }
    reset($fm[0]);
    while (list($fmn,$fma) = each($fm[0])) {
        if (! $ft[0][$fmn]) {
            if ($pr) print("<li>Missing field $fmn</li>\n");
            $score += $scoreAddOrMiss;
        }
    }
    if ($pr) print("</ul>\n");
    return $score;
}

function compareFields($fn,$ft,$fm,$pr,$pre) {
    global $scoreUnshown;
    $score = 0;
    $opened = 0;
    if ((($ft["input_show"] == 0) && ($ft["required"] == 0)) && (($fm["input_show"] == 1) || ($fm["required"] == 1))) {
        if ($pr) print("<li>$pre field: $fn : " . (($fm["input_show"] == 1) ? "not shown " : "") . (($fm["required"] == 1) ? "not required " : "")) . "</li>\n" ;
        $score += $scoreUnshown;
    } else {
      reset($ft);
      while(list($ftk,$ftv) = each($ft)) {
        if ($ftk == "slice_id") continue;
        if( EReg("^[0-9]*$", $ftk))
            continue;
        if ($ftv == $fm[$ftk]) continue;
        if (EReg("^input_",$ftk) && ($ftv == $fm[$ftk] . ":")) continue;
        if (EReg("^input_",$ftk) && ($ftv . ":" == $fm[$ftk])) continue;
        if (EReg("^alias_",$ftk) && (! $ft["alias"]))  continue; // Just report the changed alias, not unused _func etc
        if (EReg("^alias2_",$ftk) && (! $ft["alias2"]))  continue;
        if (EReg("^alias3_",$ftk) && (! $ft["alias3"]))  continue;
        if (!$opened && $pr) { print("<li>$pre field: $fn differs</li><ul>\n"); $opened = 1; }
        if($pr) print("<li>$ftk: $fm[$ftk] -> $ftv</li>\n");
        $score++;
      }
      if ($opened) { print("</ul>\n"); }
    }
    //huhl("Adding score for field = $score");
    return $score;
}

?>
