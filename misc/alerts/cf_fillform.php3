<?php 
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

/* Prepares and activates Javascript filling a Collection Form.

   Parameters: 
        $uid or $alerts[userid] .. user ID
        $cid or $alerts[collectionid] .. collection ID (if you want to refill collection info)
*/

require_once "../../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3"; 
require_once "cf_common.php3";

if (!isset ($uid)) $uid = $alerts["userid"];
if (!isset ($cid)) $cid = $alerts["collectionid"];
if (!$uid) return;

$db = new DB_AA;
$alerts = "";

$db->query("SELECT * FROM alerts_user WHERE id=$uid");
$db->next_record();
reset ($cf_fields);
while (list ($fname, $fprop) = each ($cf_fields)) 
    if ($fprop["userinfo"])
        $alerts[$fname] = $db->f($fname);
     
$alerts["lang"] = $db->f("lang");

echo "
<SCRIPT language=\"javascript\" src=\"".$AA_INSTAL_PATH."javascript/fillform.js\">
</SCRIPT>
<SCRIPT language=\"javascript\"><!--\n";

reset ($alerts);
while (list ($field, $value) = each ($alerts)) 
    echo "setControl ('cf".$cid."', 'alerts[$field]', '".str_replace("'","\\'",$value)."');\n";

if ($cid) {
    $db->query("SELECT * FROM alerts_user_collection WHERE userid=$uid AND collectionid=$cid");
    if ($db->next_record()) {
        $alerts["howoften"] = $db->f("howoften");
        $allfilters = $db->f("allfilters");
        
        $db->query("SELECT * FROM alerts_user_collection_filter WHERE userid=$uid AND collectionid=$cid");
        while ($db->next_record())
            $filters[$db->f("filterid")] = "1";
            
        // go through the remaining filters
        $db->query("SELECT * FROM alerts_collection_filter WHERE collectionid=$cid");
        while ($db->next_record()) 
            setdefault ($filters[$db->f("filterid")], $allfilters);
        reset ($filters);
        while (list ($fid, $value) = each ($filters))
            echo "setControl ('cf".$cid."', 'alerts[filters][".$fid."]', $value);\n";    
    }
}  

echo "setControl ('cf".$cid."', 'alerts[userid]', $uid);

    function validate() {
        var myform = document.cf".$cid.";
        if (myform['md5[alerts][chpwd]'] != null 
            && myform['md5[alerts][chpwd]'].value != myform['md5[alerts][chpwd2]'].value) {
            alert ('"._m("The two given passwords differ.")."');
            myform['md5[alerts][chpwd]'].focus();
            return false;
        }
        return true;
     }
// -->
</SCRIPT>
";
?>
