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

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tableview.html
// This file is meant to be included before any TableViews are defined.

$attrs_edit = array (
    "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'");
$attrs_browse = array (
    "table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
    "table_search" => "border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'");
$format = array (
    "hint" => array (
        "before" => "<i>",
        "after" => "</i>"),
    "caption" => array (
        "before" => "<b>",
        "after" => "</b>"));
/*
global $LANGUAGE_CHARSETS;
reset ($LANGUAGE_CHARSETS);
while (list ($l) = each ($LANGUAGE_CHARSETS))
    $langs[$l] = $l;
*/
global $LANGUAGE_NAMES;    
reset ($LANGUAGE_NAMES);
while (list ($l, $langname) = each ($LANGUAGE_NAMES)) {
    $biglangs[$l."_news_lang.php3"] = $langname;
    $langs[$l] = $langname;
}

// ----------------------------------------------------------------------------------        

function CreateWhereFromList ($column, $list, $type="number") {
    if (!is_array ($list)) return "1";
    if (count ($list) == 0) return "0";
    if ($type == "number") 
         return $column." IN (". join (",",$list). ")";
    else {
        $in = "";
        reset ($list);
        while (list (,$item) = each ($list)) {
            if ($in) $in .= ",";
            $in .= "'".addslashes ($item)."'";
        }
        return $column." IN ($in)";
    }
}

/**     
    @return array (unpacked module id => module name), e.g. to create a selectbox
    @param $all if you want all modules, otherwise only permitted are returned */
function SelectModule ($all = false) {
    global $db, $auth;
    if (IsSuperadmin() || $all) 
        $where = 1;
    else {
        $myslices = GetUsersSlices( $auth->auth["uid"] );
        reset ($myslices);
        while (list ($my_slice_id, $perms) = each ($myslices)) 
            if (strchr ($perms, PS_FULLTEXT))
                $restrict_slices[] = q_pack_id($my_slice_id);
        if (is_array ($restrict_slices)) 
            $where = "id IN ('".join("','",$restrict_slices)."')";
        else $where = 0;
    }
    
    $db->query("SELECT id, name FROM module
        WHERE $where AND type = 'Alerts'
        ORDER BY name");
    while ($db->next_record()) 
        $retval[unpack_id128($db->f("id"))] = $db->f("name");
    return $retval;
}

?>
