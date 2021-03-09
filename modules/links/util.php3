<?php
//$Id$

// Miscellaneous utility functions ---------------------------------------------
use AA\IO\DB\DB_AA;

/** Counts links in all categories */
function Links_CountAllLinks() {
    cattree::global_instance();  // makes sure $cattree instance is created
    global $cattree;
    return $cattree->count_all_links();
}

/** Get all informations about link
 * @param int $lid
 * @return string|array
 */
function GetLinkInfo( $lid ) {
    $ret = DB_AA::select([], 'SELECT * FROM `links_links`', [['id', $lid, 'i']]);
    return $ret ?: '';
}

/** Get path from category id
 * @param int $cid
 * @return string
 */
function GetCategoryPath( $cid ) {
    $ret = DB_AA::select1('path', 'SELECT path FROM `links_categories`', [['id', $cid, 'i']]);
    return $ret ?: '';
}

/** Get category id from path
 * @param string $path
 * @return string
 */
function GetCategoryFromPath( $path ) {
    return strrchr($path, ",") ? substr( strrchr($path, ","), 1) : $path;
}

/** Assign category to given parent category */
function Links_AssignCategory($category_id, $insertedId, $pri=10, $base='y', $state='visible') {
    DB_AA::sql("INSERT INTO links_cat_cat (category_id, what_id, base, state, priority, proposal, proposal_delete) VALUES ($category_id, $insertedId, '$base', '$state',  $pri, 'n', 'n')");
}

/** Add new category, copies parents permissions
 *  @returns id of new category
 */
function Links_AddCategory($name, $parent, $parentpath) {
    $db = getDB();
    $db->query( "INSERT INTO links_categories  ( name ) VALUES ('$name')" );
    $res =  $db->last_insert_id();

    // correct path
    $db->query( "UPDATE links_categories set path='$parentpath,$res' WHERE id=$res" );
    freeDB($db);

    ChangeCatPermAsIn($res, $parent);
    return $res;
}

// Get specified column for base category of specified link
function Links_GetBaseCategoryColumn( $lid, $col ) {
    $db = getDB();
    $db->query("SELECT $col as retcol FROM links_categories, links_link_cat
               WHERE links_categories.id = links_link_cat.category_id
                 AND what_id='$lid'
                 AND links_link_cat.base='y'");
    $ret = ( $db->next_record() ? $db->f('retcol') : "");
    freeDB($db);
    return $ret;
}


/**
 * @param int $cid
 * @param string $col
 * @return string
 */
function Links_GetCategoryColumn( $cid, $col ) {
    return DB_AA::select1('retcol', "SELECT $col as retcol FROM links_categories", [['id', $cid, 'i']]);
}

/** Get base path from link id
 * @param int $lid
 * @return mixed|string
 */
function GetBaseCategoryPath( $lid ) {
    return Links_GetBaseCategoryColumn( $lid, 'path' );
}

// Transforms path to named path with links ( <a href=...>Base</a> > <a ...)
//   based on $translate array; skips first "skip" fields
//   url: ""      - do not make links on categories
//        url     - make links to categories except the last one
//   whole - if set, make links to all categories

function NamePath($skip, $path, $translate, $separator = " > ", $url="", $whole=false, $target="") {
    $target_atrib = $target != "" ? " target=\"$target\" " : "";
    $ids       = explode(",",$path);
    $name      = '';
    $delimiter = '';
    if ( isset($ids) AND is_array($ids)) {
        $last=end($ids);
        if ( $url ) {
            foreach ($ids as $catid) {
                if (--$skip >= 0) {
                    continue;
                }
                if ( ($catid != $last) OR $whole ) { // do not make link for last category
                    $name .= $delimiter."<a href=\"$url$catid\" $target_atrib>".$translate[$catid]."</a>";
                } else {
                    $name .= $delimiter.$translate[$catid];
                }
                $delimiter = $separator;
            }
        } else {
            foreach ($ids as $catid) {
                if (--$skip >= 0) {
                    continue;
                }
                $name .= $delimiter.$translate[$catid];
                $delimiter = $separator;
            }
        }
    }
    return $name;
}

// Returns HTML code for image link to specified url
function AHrefImg($url, $src, $width="", $height="", $alt="") {
    if ($url) {
        return "<a href=\"$url\"><img src=\"$src\" width=\"$width\" height=\"$height\" alt=\"$alt\" border=\"0\"></a>";
    }
    return "<img src=\"$src\" width=\"$width\" height=\"$height\" alt=\"$alt\" border=\"0\">";
}

// returns url of requested file
function ThisFileName() {
    return ($_SERVER['SERVER_PROTOCOL']=='INCLUDED') ? $_SERVER['DOCUMENT_URI'] : $_SERVER['PHP_SELF'];
}

function FillCategoryInfo($category) {
    global $r_category_id, $r_category_path;
    $db = getDB();
    $db->query("SELECT * FROM links_categories WHERE id = $category");
    if ($db->next_record()) {
        $r_category_id       = $db->f('id');
        $r_category_path     = $db->f('path');
    }
    freeDB($db);
}

// get information from profile table, where user setting are stored
function GetProfileInfo($uid) {
    if ( !$uid ) {
        $uid = "nobody";
    }

    $db = getDB();
    $db->query("SELECT * FROM links_profiles WHERE uid = '$uid'");
    if ($db->next_record()) {
        $ret = $db->record();
        freeDB($db);
        return $ret;
    }

    // if user not exist - get nobody's settings
    $db->query("SELECT * FROM links_profiles WHERE uid = 'nobody'");
    if ($db->next_record()) {
        $ret = $db->record();
        freeDB($db);
        return $ret;
    }
    freeDB($db);
    return false;
}

function TestBaseCat($ctg, $base_cat, $ctg_path) {
    $cats = explode(",", $ctg_path);
    for ($found = false, reset($cats); current($cats); next($cats)) {
        if (current($cats) == $base_cat) {
            $found = true;
            break;
        }
    }

    return ($found ? $ctg : $base_cat);
}

/**
 * Slice id for each category in Links module is not random - it is predictable:
 * <category_id>'Links'<shorted AA_ID>
 * @returns packed slice id
 */
function Links_Category2SliceID($cid) {
    return unpack_id(substr( $cid.'Links'.pack_id(AA_ID), 0, 16 ));
}

/** Get base category from slice id (reverse to Links_Category2SliceID())
 * @param $sl_id
 * @return int
 */
function Links_SliceID2Category($sl_id) {
    $psl_id = q_pack_id($sl_id);
    return (int) substr( $psl_id, 0, strspn($psl_id, "1234567890"));
}

/**
 * Loads data from database for given link ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param array $zids array if ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 */
function Links_GetLinkContent($zids) {
    if (!$zids OR $zids->count()<1) {
        return [];
    }

    $db = getDB();
    // construct WHERE clausule
    $sel_in = $zids->sqlin( false );

    // get not rejected changes
    $SQL = "SELECT changed_link_id, proposal_link_id FROM links_changes
             WHERE changed_link_id $sel_in
               AND rejected='n'";              // get only not rejected changes
    $db->query($SQL);
    while ( $db->next_record() ) {
        $changes_ids[] = $db->f('proposal_link_id');
        $changes_map[$db->f('proposal_link_id')] = $db->f('changed_link_id');
    }

    if ( isset($changes_ids) AND is_array($changes_ids) ) {
        $changes_where = ' OR id '. (count($changes_ids)>1 ? 'IN ('. implode( ",", $changes_ids ). ')' : "='". $changes_ids[0] ."'");
    }

    // get link data (including data of link changes)
    $SQL = "SELECT * FROM links_links WHERE id $sel_in $changes_where";
    $db->query($SQL);
    while ( $db->next_record() ) {
        $foo_id = $db->f('id');
        $rec = $db->record();
        foreach ($rec as $key => $val ) {
            if ( $changes_map[$foo_id] ) {    // this link is just change-link
                $content[$changes_map[$foo_id]]["change_$key"][] = ['value' => $val];
            } else {
                $content[$foo_id][$key][] = ['value' => $val];
            }
        }
    }

    freeDB($db);

    // get language data for links
    $SQL = "SELECT links_languages.*, links_link_lang.link_id
              FROM links_link_lang, links_languages
             WHERE links_languages.id = links_link_lang.lang_id
               AND links_link_lang.link_id $sel_in";
    StoreTable2Content($content, $SQL, 'lang_', 'link_id');

    // get region data for links
    $SQL = "SELECT links_regions.*, links_link_reg.link_id
              FROM links_link_reg, links_regions
             WHERE links_regions.id = links_link_reg.region_id
               AND links_link_reg.link_id $sel_in";
    StoreTable2Content($content, $SQL, 'reg_', 'link_id');

    // get categories data for links
    $SQL = "SELECT * FROM links_link_cat, links_categories
             WHERE links_categories.id = links_link_cat.category_id
               AND links_link_cat.what_id $sel_in ORDER BY base DESC";
    StoreTable2Content($content, $SQL, 'cat_', 'what_id');
    return $content;
}

/**
 * Loads data from database for given category ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @param zids $zids ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 * @noinspection PhpUnused
 * @see GetItemContent(), itemview class, item class
 */
function Links_GetCategoryContent($zids) {
    $content = [];
    if ($zids AND !$zids->isEmpty()) {
        $categs = DB_AA::select([], 'SELECT * FROM `links_categories`', [['id', $zids->getArray(), 'i']]);
        foreach ($categs as $record) {
            $foo_id = $record['id'];
            foreach ($record as $key => $val) {
                $content[$foo_id][$key][] = ['value' => $val];
            }
        }
    }
    return $content;
}

/**
 * Is current user anonymous (=public) user?
 * @return boolean true or false
 */
function Links_IsPublic() {
    return $GLOBALS['perms_roles']['AUTHOR']['perm'] == $GLOBALS['permission_to']["slice"][$GLOBALS['slice_id']];
}

/**
 *  Parses folder string (like folder3) and returns folder number (3) or false
 */
function Links_GetFolder($type) {
    if ( substr($type,0,6)=='folder' ) {
        return substr($type,6);
    } else {
        return false;
    }
}

/**
 *  Helper function which writes link assignment into database
 */
function Links_AssignLink($cat_id, $link_id, $base='y', $state='visible',$prop='n',$prop_del='n') {
    DB_AA::sql("INSERT INTO links_link_cat (category_id, what_id, base, state, priority, proposal, proposal_delete) VALUES ($cat_id,    $link_id, '$base','$state', 1.0,   '$prop',    '$prop_del')");
}


/**
 *  Assign link to categories specified in $categs
 *  @param int  $lid          link id to assign
 *  @param int/array $categs  array of ids or just single id of categories to add link
 *  @param bool $proposal     if the link is just proposal to change
 */
function Links_Assign2Category($lid, $categs, $proposal=false) {
    if ( !isset($categs) ) {
        return;
    }

    // create array of categories, where we try to add link
    $categories = is_array($categs) ? $categs : [$categs];

    // what is the state of this link - proposal: y|n|'' (= not base category)
    $base_proposal = Links_GetBaseCategoryColumn( $lid, 'proposal' );

    foreach ( $categories as $cid ) {
        if ( $proposal ) {   // link to change - just propose as change
          //Links_AssignLink($cat_id, $link_id, $base, $state, $prop,$prop_del)
            Links_AssignLink($cid, $lid, 'n', 'hidden', 'y','n');
            continue;
        }

        switch( $base_proposal ) {
            case 'n':          // adding to base category was sucesfull
                if ( IsCatPerm( PS_LINKS_ADD_LINK, GetCategoryPath( $cid ) )) {
                    Links_AssignLink($cid, $lid, 'n', 'visible', 'n','n'); // directly add not base link
                } else {
                    Links_AssignLink($cid, $lid, 'n', 'visible', 'y','n'); // add not base link as proposal
                }
                break;
            case 'y':      // add to base category wasn't sucesfull because of perm
                Links_AssignLink($cid, $lid, 'n', 'hidden', 'y','n'); // just propose as change
                break;
            default:            // try to add base link
                if ( IsCatPerm( PS_LINKS_ADD_LINK, GetCategoryPath( $cid ))) {
                    Links_AssignLink($cid, $lid, 'y', 'visible', 'n','n'); // directly add base link
                    $base_proposal = 'n';   // for next assignments
                } else {
                    Links_AssignLink($cid, $lid, 'y', 'visible', 'y','n'); // add base link as proposal
                    $base_proposal = 'y';   // for next assignments
                }
                break;
        }
    }
}

$GENERAL_CATS['Neziskov� organizace']                       = ['pri' => 1010, 'super' => 'Organizace'];
$GENERAL_CATS['Nadace a nada�n� fondy']                     = ['pri' => 1020, 'super' => 'Organizace'];
$GENERAL_CATS['St�tn� spr�va a samospr�va']                 = ['pri' => 1030, 'super' => 'Organizace'];
$GENERAL_CATS['Firmy']                                      = ['pri' => 1100, 'super' => 'Organizace'];
$GENERAL_CATS['Politick� strany a hnut�']                   = ['pri' => 1110, 'super' => 'Organizace'];
$GENERAL_CATS['Mezist�tn� organizace']                      = ['pri' => 1120, 'super' => 'Organizace'];
$GENERAL_CATS['Profesn� sdru�en�']                          = ['pri' => 1130, 'super' => 'Organizace'];
$GENERAL_CATS['V�deck� a v�zkumn� organizace']              = ['pri' => 1140, 'super' => 'Organizace'];
$GENERAL_CATS['Vzd�l�vac� a v�chovn� organizace']           = ['pri' => 1150, 'super' => 'Organizace'];
$GENERAL_CATS['C�rkve a n�bo�ensk� spole�nosti']            = ['pri' => 1160, 'super' => 'Organizace'];
$GENERAL_CATS['Zdravotnick� organizace']                    = ['pri' => 1170, 'super' => 'Organizace'];
$GENERAL_CATS['Kulturn� organizace']                        = ['pri' => 1180, 'super' => 'Organizace'];

$GENERAL_CATS['Zpravodajsk� weby, noviny a �asopisy']       = ['pri' => 2001, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Rozhlas a televize']                         = ['pri' => 2010, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['E-mailov� konference a diskusn� skupiny']    = ['pri' => 2020, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['N�zory a koment��e']                         = ['pri' => 2030, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Knihovny, knihkupectv�, literatura']         = ['pri' => 2040, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Adres��e, datab�ze']                         = ['pri' => 2050, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Katalogy odkaz�, rozcestn�ky, vyhledava�e']  = ['pri' => 2060, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Pr�vn� p�edpisy']                            = ['pri' => 2070, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Studie, statistiky, hodnot�c� zpr�vy']       = ['pri' => 2080, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];
$GENERAL_CATS['Dokumenty, publikace, studijn� texty']       = ['pri' => 2090, 'super' => 'Zpravodajstv�, informa�n� zdroje, dokumenty'];

$GENERAL_CATS['Odborn� konference, semin��e, kurzy']        = ['pri' => 3001, 'super' => 'Akce'];
$GENERAL_CATS['Politick� jedn�n� a konference']             = ['pri' => 3010, 'super' => 'Akce'];
$GENERAL_CATS['V�stavy a veletrhy']                         = ['pri' => 3020, 'super' => 'Akce'];
$GENERAL_CATS['T�bory a v�kendovky']                        = ['pri' => 3030, 'super' => 'Akce'];
$GENERAL_CATS['Festivaly']                                  = ['pri' => 3040, 'super' => 'Akce'];
$GENERAL_CATS['Kalend��e akc�']                             = ['pri' => 3050, 'super' => 'Akce'];
$GENERAL_CATS['Ostatn� akce']                               = ['pri' => 3060, 'super' => 'Akce'];

 /** Fills $LINK_TYPE_CONSTANTS_ARR by general categories (if not filled, yet) */
function  Links_LoadGlobalCategories() {
    global $LINK_TYPE_CONSTANTS, $LINK_TYPE_CONSTANTS_ARR;
    if ( !$LINK_TYPE_CONSTANTS )
        return false;
    if ( !$LINK_TYPE_CONSTANTS_ARR ) {  // array is cached (=stored to globals)
        $LINK_TYPE_CONSTANTS_ARR = GetConstants($LINK_TYPE_CONSTANTS, 'pri', 'pri');
        // General categories names could be stored not only in 'Value' of
        // constants, but also in 'Description'. The general categories in
        // 'Description' field are the categs are not shown in selectbox
        $tmp = GetConstants($LINK_TYPE_CONSTANTS, 'pri', 'pri', 'description');
        if ( is_array($tmp) ) {
            foreach ( $tmp as $k => $pri ) {
                if ( trim($k) != "" ) {
                    $LINK_TYPE_CONSTANTS_ARR[$k] = $pri;
                }
            }
        }
    }
    return true;
}

/**
 *  Returns $type, if the category type belongs to 'General categories'
 * @param string $type - category type
 * @return bool|string
 */
function Links_IsGlobalCategory($type) {
    global $LINK_TYPE_CONSTANTS_ARR;
    if ( !Links_LoadGlobalCategories() OR !$LINK_TYPE_CONSTANTS_ARR[$type] ) {
        return false;
    }
    return $type;
}

/** Returns priority of general category (for sorting) */
function  Links_GlobalCatPriority($type) {
    global $LINK_TYPE_CONSTANTS_ARR;
    if ( !Links_LoadGlobalCategories() OR !$LINK_TYPE_CONSTANTS_ARR[$type] ) {
        return 0;
    }
    return $LINK_TYPE_CONSTANTS_ARR[$type];
}

/** Returns priority of general category (for sorting) */
function Links_GlobalCatSuper($type) {
    return $GLOBALS['GENERAL_CATS'][$type]['super'];
}

/** Returns listing of links with the same URL */
function Links_getUrlReport($url, $format_strings, $checked_id, $tree_start=false) {
    if ( substr( $url, -1 ) == '/' ) {
        $url = substr( $url, 0, strlen($url)-1 );  // remove last '/'
    }

    $url   = addslashes($url);
    $conds = [
        [
            'value'    => "$url OR ${url}_",
                           'operator' => 'XLIKE',
                           'url'      => 1
        ],
                    [
                        'value'    => $checked_id,  // we do not want to find
                           'operator' => '<>',         // the link we are asking to
                           'id'       => 1
                    ]
    ];
    $sort  = '';
    // 1 - base category - look for all links in the database (no matter in which subtree)
    $start_cat_path = ($tree_start ? GetCategoryPath( $tree_start ) : 1);

    // we have to look for unassigned links (not assigned to some category),
    // as well as for assinged ones
    $links_zids    = Links_QueryZIDs($start_cat_path, $conds, $sort, true, 'all');
    $links_zids->add(Links_QueryZIDs($start_cat_path, $conds, $sort, true, 'unasigned'));

    // url nahore
    // zarazeno v kategorii
    $out = "";
    if ( $links_zids->count() != 0 ) {
        $itemview = new itemview($format_strings, GetLinkAliases(), $links_zids, 0, 100, '', '', 'Links_GetLinkContent');
        $out = $itemview->get_output("view");
    }
    return $out;
}

