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

# Classes for manipulating views,
# viewobj has static info about views, not anything dependent on parameters
#
# Author and Maintainer: Mitra mitra@mitra.biz
#
# And yes, I'll move the docs to phpDocumentor as soon as someone explains how
# to use it!
#
# It is intended - and you are welcome - to extend this to bring into
# one place the functions for working with views.
#
# A design goal is to use lazy-evaluation wherever possible, i.e. to only
# go to the database when something is needed.

#require_once "../include/config.php3";
//require_once $GLOBALS["AA_INC_PATH"]."locsess.php3"; #DB_AA etc

class view {
    var $id;
    var $fields; // Array of fields

    function view($id,$rec=null) {
        $this->id = $id;
        if (isset($rec)) $this->fields = $rec;
    }

    function f($k=null) {
        if (!isset($this->fields)) {
          $db = getDB();
          $db->tquery("SELECT view.*, module.deleted, module.lang_file FROM view, module
                        WHERE module.id=view.slice_id
                          AND view.id='".$this->id."'");
          if ($db->next_record())
            $this->fields = DBFields($db);
          else $this->fields = null;
          freeDB($db);
        }
        if (isset($k))
            return $this->fields[$k];
        else
            return $this->fields;
    }

    function setfields($rec) {
        $this->fields = $rec;
    }
    function xml_serialize($t,$i,$ii,$a) {
        $f = $this->f();
        return xml_serialize("view",$f,$i.$ii,$ii,"tname=\"$t\" ".$a);
    }
}

// Companion of view->xml_serialize
function VIEW_xml_unserialize($n,$a) {
    $v = new view($n,$a);
    return $v;
}

class views {
    var $a = array();

    function views() {
    }

    function xml_serialize($t,$i,$ii,$a) {
        return xml_serialize("views",$this->a,$i.$ii,$ii,
            "tname=\"$t\" ".$a);
    }

    function GetViewInfo($vid) {
        if(!isset($this->a[$vid]))
            $this->a[$vid] = new view($vid);
        if (! $this->a[$vid])
            return null;
        return $this->a[$vid]->f();
    }
}
$allviews = new views();

function VIEWS_xml_unserialize($n,$a) {
    $vs = new views();
    $vs->a = $a;
    #huhl("created VIEWS ",$vs);
    return $vs;
}
function GetViewInfo($vid) {
    global $allviews;
    return $allviews->GetViewInfo($vid);
}

// Return an array of views matching the sql, caching results in allviews
// this does not return views from deleted slices
function GetViewsWhere($sql="") {
    global $allviews;
    $db = getDB();
    $db->tquery("SELECT view.*,slice.deleted FROM view, slice WHERE slice.id=view.slice_id AND slice.deleted != 1" . ($sql ? (" AND ".$sql) : ""));
    $a = array();
    while ($db->next_record()) {
        $id = $db->f("id");
        if (!isset($allviews->a[$id]))
            $allviews->a[$id] = new view($id,DBFields($db));
        else
            $allviews->a[$id]->setfields(DBFields($db));
        $a["$id"] = &$allviews->a[$id];
    }
    freeDB($db);
    #huhl("VO:GVW:",$a);
    return $a;
}



#$foo = GetViewInfo(18);
#huhl("VO:GVI:",$foo,$allviews);

?>