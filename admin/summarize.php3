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

// These parameters effect how slices compare to each other
$scoreUnshown = 10;
$scoreAddOrMiss = 50;

HtmlPageBegin();
?>
 <TITLE><?php echo _m("Summarize slice differences");?></TITLE>
</HEAD>

<?php
    require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
    showMenu($aamenus, "aaadmin","summarize");
  
    echo "<H1><B>" . _m("AA - Summarize") . "</B></H1>";
    PrintArray($err);
    echo $Msg;  
    initSummarize();
    $nocache=1;
    sliceshortcuts(1);
    mapslices();
    HtmlPageEnd();
    page_close();

function initSummarize() {
    global $sao,$slicetablefields;
    $db = getDB();
    $SQL = "SELECT id FROM slice ORDER BY created_at";
    $db -> tquery($SQL);
    while ($db->next_record()) {
        $sliceids[] = unpack_id($db->f("id"));
    }
    $slicearr = new slices($sliceids);
    $sao=$slicearr->objarr();
    $SQL = "SELECT * FROM slice";
    $slicetablefields = GetTable2Array($SQL,"id",1);
    freeDB($db);
}

function ignored_slice($si) {
    global $ignored_sliceids;
    if (!$ignored_sliceids) 
        $ignored_sliceids = array( unpack_id128("AA_Core_Fields.."),
            unpack_id128("News_EN_tmpl...."),
            unpack_id128("ReaderManagement"));
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
    global $scoreAddOrMiss,$sao;
    $CoreFields = $sao[unpack_id128("AA_Core_Fields..")]->fields();
    $score = 0;
    $ft = $st->fields();
    $fm = $sm->fields();
    if ($pr) print("<ul>\n");
    $score += compareSliceTableFields($st,$sm,$pr);
    reset($ft[0]);
    while (list($ftn,$fta) = each($ft[0])) {
        if (! $fm[0][$ftn]) {
            compareFields($ftn,$ft[0][$ftn],$CoreFields[0][GetFieldType($ftn)],$pr,"Added",$st,$sm);
            $score += $scoreAddOrMiss;
        } else {
            $score = $score + compareFields($ftn,$ft[0][$ftn],$fm[0][$ftn],$pr,"Common",$st,$sm);
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

function compareFields($fn,$ft,$fm,$pr,$pre,$st,$sm) {
    global $scoreUnshown,$AA_CP_Session;
    $score = 0;
    $opened = 0;
    if ((($ft["input_show"] == 0) && ($ft["required"] == 0)) && (($fm["input_show"] == 1) || ($fm["required"] == 1))) {
        if ($pr) print("<li>$pre field: $fn : " . (($fm["input_show"] == 1) ? "not shown " : "") . (($fm["required"] == 1) ? "not required " : "")) . "</li>\n" ;
        $score += $scoreUnshown;
    } else {
      $fixer="";
      reset($ft);
      while(list($ftk,$ftv) = each($ft)) {
        if ($ftk == "slice_id") continue;
        if( EReg("^[0-9]*$", $ftk))
            continue;
        if ($ftv == $fm[$ftk]) continue; // They match
        if (EReg("^input_",$ftk) && ($ftv == $fm[$ftk] . ":")) continue;
        if (EReg("^input_",$ftk) && ($ftv . ":" == $fm[$ftk])) continue;
        // If alias or alias2 or alias3 not defined, then dont care about subsiduaries
        if (EReg("^alias_",$ftk) && (! $ft["alias"]))  continue; 
        if (EReg("^alias2_",$ftk) && (! $ft["alias2"]))  continue;
        if (EReg("^alias3_",$ftk) && (! $ft["alias3"]))  continue;
        if (!$opened && $pr) { print("<li>$pre field: $fn differs</li><ul>\n"); $opened = 1; }
        if($pr) {
            print("<li>$ftk: $fm[$ftk] -> $ftv</li>\n");
            $fixert .= "&$ftk=" . urlencode($fm[$ftk]);
            $fixerm .= "&$ftk=" . urlencode($ftv);
        }
        $score++;
      }
      if ($opened) { 
        $u1 = "se_inputform.php3?fid=$fn&AA_CP_Session=$AA_CP_Session&update=1&onlyupdate=1&return_url=summarize.php3";
        print("<li><a href=\"" . $u1
        . "&change_id=" . $st->unpacked_id()
        . $fixert ."\">Fix this slice</a>");
        if (!ignored_slice($sm->unpacked_id())) {
            print("<li>or <a href=\"" . $u1
            . "&change_id=" . $sm->unpacked_id()
            . $fixerm ."\">Fix slice '". $sm->name() . "'</a>");
        }
        print("</li></ul>\n"); 
      }
    }
    //huhl("Adding score for field = $score");
    return $score;
}
function compareSliceTableFields($st,$sm,$pr) {
    global $slicetablefields;
#    global $scoreUnshown,$AA_CP_Session;
    $score = 0;
    $opened = 0;
    $ft = $slicetablefields[$st->packed_id()];
    $fm = $slicetablefields[$sm->packed_id()];
      $fixer="";
      reset($ft);
      while(list($ftk,$ftv) = each($ft)) {
        $hf = 0;
        $unp = 0;
        if (($ftk == "id") || ($ftk == "name") || ($ftk == "created_at")
            || ($ftk == "created_by"))
             continue;    // Fields we expect to be different
        if (EReg("format",$ftk)) { $hf = 1; }
        if ($ftk == "owner") { $unp=1; }
        if( EReg("^[0-9]*$", $ftk))
            continue;
        if ($ftv == $fm[$ftk]) continue; // They match
        if (!$opened && $pr) { print("<li>slice fields differ<ul>\n"); $opened = 1; }
        if($pr) {
            print("<li>$ftk: " . qenc($fm[$ftk],$hf,$unp,"red")
                . " -> " . qenc($ftv,$hf,$unp,"purple")
                . "</li>\n");
            $fixert .= "&$ftk=" . urlencode($fm[$ftk]);
            $fixerm .= "&$ftk=" . urlencode($ftv);
        }
        $score++;
      } //while
      if ($opened) { 
        print("<li>Will fix to allow editing</li>");
//        $u1 = "se_inputform.php3?fid=$fn&AA_CP_Session=$AA_CP_Session&update=1&onlyupdate=1&return_url=summarize.php3";
//        print("<li><a href=\"" . $u1
//        . "&change_id=" . $st->unpacked_id()
//        . $fixert ."\">Fix this slice</a>");
//        if (!ignored_slice($sm->unpacked_id())) {
//            print("<li>or <a href=\"" . $u1
//            . "&change_id=" . $sm->unpacked_id()
//            . $fixerm ."\">Fix slice '". $sm->name() . "'</a>");
//        }
        print("</ul></li>\n"); 
      }
    //huhl("Adding score for field = $score");
    return $score;
}
function qenc($val,$htmlformat,$unp,$color) {
    return ("<FONT color=\"$color\">"
        . ( $htmlformat ? htmlentities($val)
            : ($unp ? unpack_id128($val) : $val))
        . "</font>"
    );
}

?>