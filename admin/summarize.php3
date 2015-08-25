<?php
/**
 *   This module generates a human readable output page that summarizes the slices on a system
 *   Feel free to modify it - but please talk to Mitra about any changes.
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
 * @author    Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 2003 Mitra Technology Consulting
 * @link      http://www.mitra.biz
 *
*/

require_once "../include/init_page.php3"; // Loads variables etc
require_once AA_INC_PATH."slice.class.php3";  // for slices
require_once AA_INC_PATH."formutil.php3";  // for FrmJavascriptCached()

//$debug = 1;

//$debugsummarize = 01;

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
 <title><?php echo _m("Summarize slice differences");?></title>
</head>

<?php
    require_once AA_INC_PATH."menu.php3";
    showMenu($aamenus, "aaadmin","summarize");

    echo "<h1><b>" . _m("AA - Summarize") . "</b></h1>";
    PrintArray($err);
    echo $Msg;
    initSummarize();
    $nocache=1;
    setnr();
    print("<p>");
    mapslice();
//    mapslices();
    sliceshortcuts(1);
    HtmlPageEnd();
    page_close();

/** initSummarize function
 *
 */
function initSummarize() {
    global $sao,$slicetablefields;
    $db = getDB();
    $SQL = "SELECT id FROM slice ORDER BY created_at";
    $db -> tquery($SQL);
    while ($db->next_record()) {
        $sao[unpack_id($db->f("id"))] = AA_Slice::getModule(unpack_id($db->f("id")));
    }
    $SQL = "SELECT * FROM slice";
    $slicetablefields = GetTable2Array($SQL,"id",1);
    freeDB($db);
}
/** ignored_slice function
 * @param $si
 * @return bool
 */
function ignored_slice($si) {
    global $ignored_sliceids;
    if (!$ignored_sliceids) {
        $ignored_sliceids = array( unpack_id("AA_Core_Fields.."),
            unpack_id("News_EN_tmpl...."),
            unpack_id("ReaderManagement"));
    }
    return !  (array_search($si,$ignored_sliceids) === false);
}
/** sliceshortcuts function
 * @param $ign
 */
function sliceshortcuts($ign) {
  global $sao;
  print("<table cellspacing=\"1\">\n");
  reset($sao);
  while (list($si,$so) = each($sao)) {
    //if ($debugsummarize) huhl("Slice Obj",$so);
    if (!$ign || !ignored_slice($si)) {
        $n = $so->name();
         print("<tr><td><a href=\"#$si\">$si</a></td><td>$n</td><td>" .
            ($so->deleted() ? "Deleted " : "") .
            editslicefields($si) . " " . comparewith($si) .
            "</td></tr>\n");
    }
  }
  print("</table>\n");
}
/** editsliceinfo function
 * @param $sid
 * @return string
 */
function editsliceinfo($sid) {
    global $AA_CP_Session;
    return "<a href=\"slicedit.php3?AA_CP_Session=$AA_CP_Session&change_id=$sid\">Edit slice info</a>";
}
/** editslicefields function
 * @param $sid
 * @return string
 */
function editslicefields($sid) {
    global $AA_CP_Session;
    return "<a href=\"se_fields.php3?AA_CP_Session=$AA_CP_Session&change_id=$sid\"> Edit fields </a>";
}
/** url_slicefieldcopy function
 * @param $field
 * @return string
 */
function url_slicefieldcopy($field) {
    global $AA_CP_Session,$nearest;
    return "<a href=\"summarize.php3?AA_CP_Session=$AA_CP_Session&nearest=$nearest&slicefieldcopy=$field\"> -> </a>";
}
/** url_copyfield function
 * @param $field
 * @return string
 */
function url_copyfield($field) {
    global $AA_CP_Session,$nearest;
    return "<a href=\"summarize.php3?AA_CP_Session=$AA_CP_Session&nearest=$nearest&copyfield=$field\"> Copy Field </a>";
}
/** url_showfield function
 * @param $field
 * @return string
 */
function url_showfield($field) {
    global $AA_CP_Session;
    return "not shown <a href=\"summarize.php3?AA_CP_Session=$AA_CP_Session&nearest=$sid&showfield=$field\"> (show it) </a>";
}
/** comparewith function
 * @param $sid
 * @return string
 */
function comparewith($sid) {
    global $AA_CP_Session;
    return "<a href=\"summarize.php3?AA_CP_Session=$AA_CP_Session&nearest=$sid\"> Compare </a>";
}
/** mapslice function
 *
 */
function mapslice() {
    global $sao,$slice_id,$nr,$nearest;
    $sid = $slice_id;
    $so = $sao[$sid];
    if (!$nearest) {
        $nearest = $nr[$slice_id];
    }
    if ($nearest) {
        $sno = $sao[$nearest];
        print("<a name=\"$sid\"></a><font color=purple>$sid: '" . $so->name() . "'</font> closest to <font color=red><a href=\"#$nearest\">$nearest</a> '" . $sno->name()."'</font><br>".editslicefields($sid));
        // Here is where we do any actions that change things, before doing
        // a comparisom
        if ($GLOBALS["copyfield"]) {
            do_copyfield();
        }
        if ($GLOBALS["showfield"]) {
            do_showfield();
        }
        // and now the comparisom
        compareSlices($so,$sno,1);
    } else {
        print "summarize.php3 needs configuring for closest slice to $sid";
    }
}
/** copyslicefield function
 * @param $new
 */
function copyslicefield($new) {
  global $slice_id, $slicefieldcopy;
  $db  = getDB();
  $SQL = "UPDATE slice SET ".$slicefieldcopy."='".quote($new)."' WHERE id='".q_pack_id($slice_id)."'";
  $db -> tquery($SQL);
  freeDB($db);
}
/** do_showfield function
 *
 */
function do_showfield() {
  global $showfield,$slice_id;
  $db = getDB();
  $GLOBALS['debug']=1;
  $SQL = "UPDATE field SET input_show=1 WHERE (id='".$showfield."') AND (slice_id='".q_pack_id($slice_id)."')";
  $db -> tquery($SQL);
  $GLOBALS['debug']=0;
  freeDB($db);
}
/** do_copyfield function
 * executes a set of SQL commands
 */
function do_copyfield() {
  global $copyfield,$slice_id,$nearest;
  $db = getDB();
  $SQL = "CREATE TEMPORARY TABLE temp1 SELECT * FROM field WHERE (id='".$copyfield."') AND (slice_id='".q_pack_id($nearest)."')";
  $db -> tquery($SQL);
  $SQL = "UPDATE temp1 SET slice_id = '".q_pack_id($slice_id)."'";
  $db -> tquery($SQL);
  $SQL = "INSERT INTO field SELECT * FROM temp1";
  $db -> tquery($SQL);
  $SQL = "DROP TABLE temp1";
  $db -> tquery($SQL);
  print "<p>Copied field $copyfield from $nearest</p>";
  freeDB($db);
}
/** mapslices function
 * prints a ul list
 */
function mapslices() {
    global $sao;
    print("<ul>");
    reset($sao);
    while (list($sid,$so) = each($sao)) {
        if (! $so->deleted() && !ignored_slice($sid)) {
            if ($pm) {   // first time round no model
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

if ($debugsummarize) {
    huhl("Starting summarize");
}
/** nearestSlice function
 * @param $st
 * @param $possmodels
 */
function nearestSlice($st,$possmodels) {
    global $sao;
    $sc_min = 999999;
    $sm_min = "";
    reset($possmodels);
    while (list(,$sm) = each($possmodels)) {
        if ($sm == $st->getId()) {
            continue;
        }
        $sc = compareSlices($st,$sao[$sm],0);
        if ($sc < $sc_min) {
            $sm_min = $sm;
            $sc_min = $sc;
        }
    }
    return $sm_min;
}
/** compareSlices
 * @param $st
 * @param $sm
 * @param $pr
 * @return $score
 */
function compareSlices($st,$sm,$pr) {
    global $scoreAddOrMiss,$sao;
    $CoreFields = $sao[unpack_id("AA_Core_Fields..")]->fields();
    $score = 0;
    $ft = $st->fields();
    $fm = $sm->fields();
    if ($pr) {
        print("<ul>\n");
    }
    $score += compareSliceTableFields($st,$sm,$pr);
    reset($ft[0]);
    while (list($ftn,$fta) = each($ft[0])) {
        if (! $fm[0][$ftn]) {
            compareFields($ftn,$ft[0][$ftn],$CoreFields[0][AA_Fields::getFieldType($ftn)],$pr,"Added",$st,$sm);
            $score += $scoreAddOrMiss;
        } else {
            $score = $score + compareFields($ftn,$ft[0][$ftn],$fm[0][$ftn],$pr,"Common",$st,$sm);
        }
    }
    reset($fm[0]);
    while (list($fmn,$fma) = each($fm[0])) {
        if (! $ft[0][$fmn]) {
            if ($pr) print("<li>Missing field $fmn".url_copyfield($fmn)."\n");
            $score += $scoreAddOrMiss;
        }
    }
    if ($pr) print("</ul>\n");
    return $score;
}
/** compareFields function
 * @param $fn
 * @param $ft
 * @param $fm
 * @param $pr
 * @param $pre
 * @param $st
 * @param $sm
 */
function compareFields($fn,$ft,$fm,$pr,$pre,$st,$sm) {
    global $scoreUnshown,$AA_CP_Session;
    $score = 0;
    $opened = 0;
    if ((($ft["input_show"] == 0) && ($ft["required"] == 0)) && (($fm["input_show"] == 1) || ($fm["required"] == 1))) {
        if ($pr) print("<li>$pre field: $fn : " . (($fm["input_show"] == 1) ? url_showfield($fn) : "") . (($fm["required"] == 1) ? "not required " : "")) . "</li>\n" ;
        $score += $scoreUnshown;
    } else {
      reset($ft);
      while (list($ftk,$ftv) = each($ft)) {
          if ($ftk == "slice_id") {
              continue;
          }
          if ( EReg("^[0-9]*$", $ftk)) {
              continue;
          }
          if ($ftv == $fm[$ftk]) {
              continue; // They match
          }
          if (EReg("^input_",$ftk) && ($ftv == $fm[$ftk] . ":")) {
              continue;
          }
          if (EReg("^input_",$ftk) && ($ftv . ":" == $fm[$ftk])) {
              continue;
          }
          // If alias or alias2 or alias3 not defined, then dont care about subsiduaries
          if (EReg("^alias_",$ftk) && (! $ft["alias"])) {
              continue;
          }
          if (EReg("^alias2_",$ftk) && (! $ft["alias2"])) {
              continue;
          }
          if (EReg("^alias3_",$ftk) && (! $ft["alias3"])) {
              continue;
          }
          if (!$opened && $pr) {
              print("<li>$pre field: $fn differs</li><ul>\n"); $opened = 1;
          }
          if ($pr) {
              print("<li>$ftk: ".qenc($fm[$ftk],true,false,"red")." -&gt; ".qenc($ftv,true,false,"purple") . "</li>\n");
              $fixert .= "&$ftk=" . urlencode($fm[$ftk]);
              $fixerm .= "&$ftk=" . urlencode($ftv);
          }
          $score++;
      }
      if ($opened) {
        $u1 = "se_inputform.php3?fid=$fn&AA_CP_Session=$AA_CP_Session&update=1&onlyupdate=1&return_url=summarize.php3";
        print("<li><a href=\"" . $u1
        . "&change_id=" . $st->getId()
        . $fixert ."\">Fix this slice</a>");
        if ($pre == "Added") {
            print("<li>Or add field to ".$sm->name(). " by hand</a>");
        } else {
          if (!ignored_slice($sm->getId())) {
            print("<li>or <a href=\"" . $u1
            . "&change_id=" . $sm->getId()
            . $fixerm ."\">Fix slice '". $sm->name() . "'</a>");
          }
        }
        print("</li></ul>\n");
      }
    }
    //huhl("Adding score for field = $score");
    return $score;
}

/** compareSliceTableFields function
 * @param $st sliceobj target
 * @param $sm sliceob master
 * @param $pr true if print difference
 * @return $score
 */
function compareSliceTableFields($st,$sm,$pr) {
    global $slicetablefields;
//    global $scoreUnshown,$AA_CP_Session;
    $score = 0;
    $opened = 0;
    $ft = $slicetablefields[pack_id($st->getId())];
    $fm = $slicetablefields[pack_id($sm->getId())];
      reset($ft);
      while (list($ftk,$ftv) = each($ft)) {
          $hf = 0;
          $unp = 0;
          if (($ftk == "id") || ($ftk == "name") || ($ftk == "created_at")
          || ($ftk == "created_by")) {
               continue;    // Fields we expect to be different
          }
          if (EReg("format",$ftk)) {
              $hf = 1;
          }
          if ($ftk == "owner") {
              $unp=1;
          }
          if ( EReg("^[0-9]*$", $ftk)) {
              continue;
          }
          if ($ftv == $fm[$ftk]) {
              continue; // They match
          }
          if (!$opened && $pr) {
              print("<li>slice fields differ (".editsliceinfo($ft)."</a>)<ul>\n"); $opened = 1;
          }
          if ($pr) {
            if ($GLOBALS["slicefieldcopy"] == $ftk) {
              copyslicefield($fm[$ftk]);
              print("<li>$ftk copied</li>\n");
            } else {
              print("<li>$ftk: " . qenc($fm[$ftk],$hf,$unp,"red")
                    . url_slicefieldcopy($ftk)
                  . qenc($ftv,$hf,$unp,"purple")
                  . "</li>\n");
              $fixert .= "&$ftk=" . urlencode($fm[$ftk]);
              $fixerm .= "&$ftk=" . urlencode($ftv);
            }
          }
          $score++;
      } //while
      if ($opened) {
        print("<li>Will fix to allow editing</li>");
//        $u1 = "se_inputform.php3?fid=$fn&AA_CP_Session=$AA_CP_Session&update=1&onlyupdate=1&return_url=summarize.php3";
//        print("<li><a href=\"" . $u1
//        . "&change_id=" . $st->getId()
//        . $fixert ."\">Fix this slice</a>");
//        if (!ignored_slice($sm->getId())) {
//            print("<li>or <a href=\"" . $u1
//            . "&change_id=" . $sm->getId()
//            . $fixerm ."\">Fix slice '". $sm->name() . "'</a>");
//        }
        print("</ul></li>\n");
      }
    //huhl("Adding score for field = $score");
    return $score;
}
/** qenc function
 * @param $val
 * @param $htmlformat
 * @param $unp
 * @param $color
 * @return string
 */
function qenc($val,$htmlformat,$unp,$color) {
    return ("<font color=\"$color\">"
        . ( $htmlformat ? myspecialchars($val)
            : ($unp ? unpack_id($val) : $val))
        . "</font>"
    );
}

/** setnr function
 * This part needs configuring, eventually each slice can have the slice it was
 * built from as a field in the database.
 */
//
function setnr() {
    global $nr;
// This is a good place to set some short names for your templates
 $blog = "7a74a7bf81661ea18537e05136adf6c9"; //bbkm
 $import = "db1452fab6282a0da83050e7d844fb69"; // km
 $directory = "480fddb2820269b80555042d1a8c8dbb"; // bin
 $calendar = "22b754b09bbadfbf12d9755817819021"; //bbkm
 $faq = "6279726f6e6261796b6d666171666171";
 $newstemplate = "4e6577735f454e5f746d706c2e2e2e2e";
 $photos = "70616e2e70686f746f732e2e2e2e2e2e";
 $photosections = "70616e2e70686f746f732e7365637469";
 $blogfaq = "4b88f1c5e5a94e1e379d12f247a252b3";
 $alerts = "c338bb154f445afb84307f35f5facd9d"; //bbkm

 //AppTour
 $nr["7bb93902675177d09b65183c49ea1e23"] = $directory;
//BIN
 $nr[$blogfaq] = $blog;
 $nr["1b5d8a892fc9e6867ab841bec079984d"] = $import;
 $nr[$directory] = $blog;
//BBKM
 $nr[$calendar] = $newstemplate;
 $nr[$blog] = $newstemplate;
 $nr[$faq] = $blog;
 $nr["074f14863bd4cd348baf901f762d7a9b"] = $directory;
// Events
 $nr["aefcbfdbe065cf09d6dd51753c7c0a97"] = $blogfaq;
 $nr["6b509741ed05cbd59f9a708ed817996a"] = $calendar;
// KM
 $nr["665c74e7fc97171dc2c6fecfca3b80a2"] = $newstemplate;
 $nr["3fe8a82dd09ab2d1dea85e15088daafe"] = $blog;
 $nr[$import] = $blog;
// PAN
 $nr["26ca387aa4ef6616c64fa42c71a51013"] = $calendar;
 $nr[$photos] = $blog;
 $nr["11b6fc5706ba0601552d0cb40602efda"] = $blog;
 $nr["d562cc4dd82cdfbb72d9868af9781c6c"] = $faq;
 $nr[$photosections] = $blog;
//SART
 $nr["e8e885b0143c1ccee94d576d488210da"] = $import;
 $nr["420a65b68496d0f869b5519ff0f7b0c0"] = $blogfaq;
 $nr["cd516ce72af5d7c74f6e3d6531a6c48c"] = $directory;
//Alerts
 $nr["98122b72f41dcd4ac5724f91e9bd85c0"] = $alerts;
 $nr["635a1206228867bfc5d7e3feb1d950c8"] = $alerts;
}
?>