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

if (0) {
  print("<ul>\n");
  while (list($si,$so) = each($slicearr->objarr())) {
    if ($debugsummarize) huhl("Slice Obj",$so);
    $n = $so->name();
    print("<li>$si:$n:" . $so->deleted() . "</li>\n");
    $f = $so->fields();
    //huhl($f);
    //exit;
  }
  print("</ul>\n");
}

$sao=$slicearr->objarr();
$CoreFields = $sao["41415f436f72655f4669656c64732e2e"]->fields();
//$score = compareSlices($sao["c17f7dc366af4d932aa45d41ddbdd4e5"],$sao["4e6577735f454e5f746d706c2e2e2e2e"],1);
//print("<li>Compare score=$score</li>\n");
//print("Nearest = ". nearestSlice($sao["c17f7dc366af4d932aa45d41ddbdd4e5"],$sliceids) );

mapslices();

freeDB($db);

function mapslices() {
    global $sao;
    print("<ul>");
    while (list($sid,$so) = each($sao)) {
        if (! $so->deleted()) {
            if($pm) {   // first time round no model
                $nearest = nearestSlice($so,$pm,1);
                $sno = $sao[$nearest];
                print("<li>$sid: '" . $so->name() . "' closest to $nearest '" . $sno->name()."'");
                compareSlices($so,$sno,1);
                print("</li>");
            }
            $pm[] = $sid;
        }
    }
    print("</ul>");
}

if ($debugsummarize) huhl("Starting summarize");

function nearestSlice($st,$possmodels) {
    global $sao;
    $sc_min = 999999;
    $sm_min = "";
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
    while (list($ftn,$fta) = each($ft[0])) {
        if (! $fm[0][$ftn]) {
            compareFields($ftn,$ft[0][$ftn],$CoreFields[0][GetFieldType($ftn)],$pr,"Added");
            $score += $scoreAddOrMiss;
        } else {
            $score = $score + compareFields($ftn,$ft[0][$ftn],$fm[0][$ftn],$pr,"Common");
        }
    }
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
      while(list($ftk,$ftv) = each($ft)) {
        if ($ftk == "slice_id") continue;
        if( EReg("^[0-9]*$", $ftk))
            continue;
        if ($ftv == $fm[$ftk]) continue;
        if (EReg("_func$",$ftk) && ($ftv == $fm[$ftk] . ":")) continue;
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
