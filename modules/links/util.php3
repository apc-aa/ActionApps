<?php
//$Id$

# Miscellaneous utility functions ---------------------------------------------

# Counts links in category subtree (only real) and stores value to db
function CountCategLinks($path, $cid) {
  global $db;
  $SQL= " SELECT count(*) as links_count FROM links_categories, links_link_cat
  	WHERE links_link_cat.category_id = links_categories.id
  	  AND links_link_cat.proposal = 'n'
  	  AND (links_categories.path = '$path' OR links_categories.path like '$path,%')";
  $db->query($SQL);

  if ($db->next_record()) {
    $links_count = $db->f('links_count');
    $SQL= "UPDATE LOW_PRIORITY links_categories SET link_count = '$links_count'
            WHERE id='$cid'";
    $db->query($SQL);
  }
  return $links_count;
}

# Counts links in all subcategories
function CountAllLinks() {
  $db2  = new DB_AA;   // can't use $db - colision with CountCategLinks
  $SQL= "SELECT id, path FROM links_categories";
  $db2->query($SQL);
  while($db2->next_record())
    CountCategLinks($db2->f('path'), $db2->f('id'));
}

# Counts links in each category and returns array[category]=link_count
function CountLinks4Each() {
  global $db;
  $SQL= " SELECT category_id, count(*) as links_count FROM links_link_cat
  	WHERE links_link_cat.proposal = 'n'
    GROUP BY category_id";
  $db->query($SQL);

  while ($db->next_record())
    $links_count[$db->f('category_id')] = $db->f('links_count');
  return  $links_count;
}

# Get all informations about link
function GetLinkInfo( $lid ) {
  global $db;
  $SQL = "SELECT * FROM links_links WHERE id = '$lid'";
  $db->query($SQL);
  return ( $db->next_record() ? $db->Record : "");
}

# Get path from category id
function GetCategoryPath( $cid ) {
  global $db;
  $db->query("SELECT path FROM links_categories WHERE id=$cid");
  return ( $db->next_record() ? $db->f('path') : "");
}

# Get category id from path
function GetCategoryFromPath( $path ) {
  if( strrchr ($path, ",") )
    return   substr (strrchr ($path, ","), 1);
  return $path;
}

/** Assign category to given parent category */
function Links_AssignCategory($category_id, $insertedId, $pri=10, $base='y', $state='visible') {
	global $db;

	$SQL = "INSERT INTO links_cat_cat
           (category_id, what_id, base, state, priority, proposal, proposal_delete)
   	VALUES ($category_id, $insertedId, '$base', '$state',  $pri, 'n', 'n')";

	$db->query( $SQL );
}

/** Add new category, copies parents permissions
 *  @returns id of new category
 */
function Links_AddCategory($name, $parent, $parentpath, $template="") {
	global $db;
  	$SQL = "INSERT INTO links_categories  ( name, html_template )
	                         VALUES ('$name', '$template')";
	$db->query( $SQL );
  	$db->query( "select LAST_INSERT_ID() as id" );

  	if(!$db->next_record()) {
    	huh("Error - Last inserted ID is lost");
	    exit;
  	}

  	$res = $db->f('id');

    // correct path
	$SQL = "UPDATE links_categories set path='$parentpath,$res' WHERE id=$res";
  	$db->query( $SQL );

  	ChangeCatPermAsIn($res, $parent);
  	return $res;
}

# Get specified column for base category of specified link
function Links_GetBaseCategoryColumn( $lid, $col ) {
  global $db;
  $db->query("SELECT $col as retcol FROM links_categories, links_link_cat
               WHERE links_categories.id = links_link_cat.category_id
                 AND what_id='$lid'
                 AND links_link_cat.base='y'");
  return ( $db->next_record() ? $db->f('retcol') : "");
}

# Get base path from link id
function GetBaseCategoryPath( $lid ) {
    return Links_GetBaseCategoryColumn( $lid, 'path' );
}

# Transforms path to named path with links ( <a href=...>Base</a> > <a ...)
#   based on $translate array; skips first "skip" fields
#   url: ""      - do not make links on categories
#        url     - make links to categories except the last one
#   whole - if set, make links to all categories

function NamePath($skip, $path, $translate, $separator = " > ", $url="", $whole=false, $target="") {
  $target_atrib = $target != "" ? " target=\"$target\" " : "";
  $ids = explode(",",$path);
  if( isset($ids) AND is_array($ids)) {
    $last=end($ids);
    reset($ids);
    if( $url ) {
      while(list(,$catid) = each($ids)) {
        if(--$skip >= 0)
          continue;
        if( ($catid != $last) OR $whole )  // do not make link for last category
          $name .= $delimeter."<a href=\"$url$catid\" $target_atrib>".$translate[$catid]."</a>";
         else
          $name .= $delimeter.$translate[$catid];
        $delimeter = $separator;
      }
    }else{
      while(list(,$catid) = each($ids)) {
        if(--$skip >= 0)
          continue;
        $name .= $delimeter.$translate[$catid];
        $delimeter = $separator;
      }
    }
  }
  return $name;
}

# Returns HTML code for image link to specified url
function AHrefImg($url, $src, $width="", $height="", $alt="") {
  if($url)
    return "<a href=\"$url\"><img src=\"$src\" width=\"$width\" height=\"$height\" alt=\"$alt\" border=\"0\"></a>";
  return "<img src=\"$src\" width=\"$width\" height=\"$height\" alt=\"$alt\" border=\"0\">";
}

# returns url of requested file
function ThisFileName() {
  if( $GLOBALS['SERVER_PROTOCOL']=='INCLUDED' ) {
    return $GLOBALS['DOCUMENT_URI'];
  }
  return $GLOBALS['PHP_SELF'];
}

function FillCategoryInfo($category) {
	global $db, $r_category_id, $r_category_path, $r_category_template;
  	$SQL= "SELECT * FROM links_categories WHERE id = $category";
  	$db->query($SQL);
  	if($db->next_record()) {
    	$r_category_id       = $db->f(id);
    	$r_category_template = $db->f(html_template);
    	$r_category_path     = $db->f(path);
  	}
}

# get information from view table, where information for viewing are stored
function Links_GetViewInfo($view_id, $server_url) {
	global $db;

	if ($view_id)
		$test = " (id = $view_id) ";
    elseif (!$server_url)
  	    $test = " (id = ".DEFAULT_BASE_CATEGORY." )";
    elseif (strlen($server_url) <= 4)
		$test = " (server_name = '$server_url') ";
    else
        $test = ( (StrCaseCmp(SubStr($server_url, 0, 4), "www.") == 0) ?
			" (server_name = '". Substr($server_url,4) . "') " :
      " (server_name = '$server_url') " );

	$SQL = " SELECT * FROM links_views WHERE $test";
	$db->query($SQL);

	if ($db->next_record())
       return $db->Record;

	return false;
}

# get information from profile table, where user setting are stored
function GetProfileInfo($uid) {
	global $db;

  if( !$uid )
    $uid = "nobody";

	$SQL = "SELECT * FROM links_profiles WHERE uid = '$uid'";
	$db->query($SQL);
	if ($db->next_record())
    return $db->Record;

  # if user not exist - get nobody's settings
	$SQL = "SELECT * FROM links_profiles WHERE uid = 'nobody'";
	$db->query($SQL);
	if ($db->next_record())
    return $db->Record;
	return false;
}

function TestBaseCat($ctg, $base_cat, $ctg_path) {
	$cats = explode(",", $ctg_path);
	for ($found = false, reset($cats); current($cats); next($cats))
		if (current($cats) == $base_cat) {
			$found = true; break;
		}

	return ($found ? $ctg : $base_cat);
}

/**
 * Slice id for each category in Links module is not random - it is predictable:
 * <category_id>'Links'<shorted AA_ID>
 * @returns packed slice id
 */
function Links_Category2SliceID($cid) {
    return unpack_id128(substr( $cid.'Links'.q_pack_id(AA_ID), 0, 16 ));
}

/**
 * Just helper function for storing data from database to Abstract Data Structure
 */
function StoreTable2Content(&$db, &$content, $SQL, $prefix, $id_field) {
    $db->tquery($SQL);
    while( $db->next_record() ) {
        $foo_id = $db->f($id_field);
        reset( $db->Record );
        while( list( $key, $val ) = each( $db->Record )) {
            if( !is_int($key))
                $content[$foo_id][$prefix . $key][] = array('value' => $val);
        }
    }
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
    global $db;

    if (!is_object($db))
        $db = new DB_AA;

    if( !$zids )
        return false;

    // construct WHERE clausule
    $sel_in = $zids->sqlin( false );

    // get not rejected changes
    $SQL = "SELECT changed_link_id, proposal_link_id FROM links_changes
             WHERE changed_link_id $sel_in
               AND rejected='n'";              // get only not rejected changes
    $db->tquery($SQL);
    while( $db->next_record() ) {
        $changes_ids[] = $db->f('proposal_link_id');
        $changes_map[$db->f('proposal_link_id')] = $db->f('changed_link_id');
    }

    if( isset($changes_ids) AND is_array($changes_ids) )
        $changes_where = ' OR id '. (count($changes_ids)>1 ?
        'IN ('. implode( ",", $changes_ids ). ')' : "='". $changes_ids[0] ."'");

    // get link data (including data of link changes)
    $SQL = "SELECT * FROM links_links WHERE id $sel_in $changes_where";
    $db->tquery($SQL);
    while( $db->next_record() ) {
        $foo_id = $db->f('id');
        reset( $db->Record );
        while( list( $key, $val ) = each( $db->Record )) {
            if( is_int($key))
                continue;
            if( $changes_map[$foo_id] )    // this link is just change-link
                $content[$changes_map[$foo_id]]["change_$key"][] = array('value' => $val);
            else
                $content[$foo_id][$key][] = array('value' => $val);
        }
    }

    // get language data for links
    $SQL = "SELECT links_languages.*, links_link_lang.link_id
              FROM links_link_lang, links_languages
             WHERE links_languages.id = links_link_lang.lang_id
               AND links_link_lang.link_id $sel_in";
    StoreTable2Content($db, $content, $SQL, 'lang_', 'link_id');

    // get region data for links
    $SQL = "SELECT links_regions.*, links_link_reg.link_id
              FROM links_link_reg, links_regions
             WHERE links_regions.id = links_link_reg.region_id
               AND links_link_reg.link_id $sel_in";
    StoreTable2Content($db, $content, $SQL, 'reg_', 'link_id');

    // get categories data for links
    $SQL = "SELECT * FROM links_link_cat, links_categories
             WHERE links_categories.id = links_link_cat.category_id
               AND links_link_cat.what_id $sel_in";
    StoreTable2Content($db, $content, $SQL, 'cat_', 'what_id');
    return $content;
}



/**
 * Loads data from database for given category ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param array $zids array if ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 */
function Links_GetCategoryContent($zids) {
    global $db;

    if (!is_object($db))   $db = new DB_AA;
    if ( !$zids )          return false;

    // construct WHERE clausule
    $sel_in = $zids->sqlin( false );

    // get category data (including data of link changes)
    $SQL = "SELECT * FROM links_categories WHERE id $sel_in";
    $db->tquery($SQL);
    while( $db->next_record() ) {
        $foo_id = $db->f('id');
        reset( $db->Record );
        while( list( $key, $val ) = each( $db->Record )) {
            if( is_int($key))
                continue;
            $content[$foo_id][$key][] = array('value' => $val);
        }
    }

    return $content;
}



/**
 * Is current user anonymous (=public) user?
 * @return boolean true or false
 */
function Links_IsPublic() {
    global $perms_roles;

    return $GLOBALS['perms_roles']['AUTHOR']['perm'] ==
           $GLOBALS['permission_to']["slice"][$GLOBALS['slice_id']];
}

/**
 *  Parses folder string (like folder3) and returns folder number (3) or false
 */
function Links_GetFolder($type) {
    if( substr($type,0,6)=='folder' ) {
        return substr($type,6);
    } else {
        return false;
    }
}

/**
 *  Helper function which writes link assignment into database
 */
function Links_AssignLink($cat_id, $link_id, $base='y', $state='visible',$prop='n',$prop_del='n') {
    global $db;
    $SQL = "INSERT INTO links_link_cat
    (category_id, what_id, base, state, priority, proposal, proposal_delete)
    VALUES ($cat_id,    $link_id, '$base','$state', 1.0,   '$prop',    '$prop_del')";
    $db->query($SQL);
}


/**
 *  Assign link to categories specified in $categs
 *  @param int  $lid          link id to assign
 *  @param int/array $categs  array of ids or just single id of categories to add link
 *  @param bool $proposal     if the link is just proposal to change
 */
function Links_Assign2Category($lid, $categs, $proposal=false) {

    if( !isset($categs) ) {
        return;
    }

    // create array of categories, where we try to add link
    $categories = is_array($categs) ? $categs : array($categs);

    // what is the state of this link - proposal: y|n|'' (= not base category)
    $base_proposal = Links_GetBaseCategoryColumn( $lid, 'proposal' );

    foreach( $categories as $cid ) {
        if( $proposal ) {   // link to change - just propose as change
          //Links_AssignLink($cat_id, $link_id, $base, $state, $prop,$prop_del)
            Links_AssignLink($cid, $lid, 'n', 'hidden', 'y','n');
            continue;
        }

        switch( $base_proposal ) {
            case 'n':          # adding to base category was sucesfull
                if( IsCatPerm( PS_LINKS_ADD_LINK, GetCategoryPath( $cid ) )) {
                    Links_AssignLink($cid, $lid, 'n', 'visible', 'n','n'); # directly add not base link
                } else {
                    Links_AssignLink($cid, $lid, 'n', 'visible', 'y','n'); # add not base link as proposal
                }
                break;
            case 'y':      # add to base category wasn't sucesfull because of perm
                Links_AssignLink($cid, $lid, 'n', 'hidden', 'y','n'); # just propose as change
                break;
            default:            # try to add base link
                if( IsCatPerm( PS_LINKS_ADD_LINK, GetCategoryPath( $cid ))) {
                    Links_AssignLink($cid, $lid, 'y', 'visible', 'n','n'); # directly add base link
                    $base_proposal = 'n';   # for next assignments
                } else {
                    Links_AssignLink($cid, $lid, 'y', 'visible', 'y','n'); # add base link as proposal
                    $base_proposal = 'y';   # for next assignments
                }
                break;
        }
    }
}

/**
 *  Returns $type, if the category type belongs to 'General categories'
 *  @param string $type      - category type
 */
function Links_IsGlobalCategory($type) {
    global $LINK_TYPE_CONSTANTS, $LINK_TYPE_CONSTANTS_ARR;
    if ( !$LINK_TYPE_CONSTANTS )
        return false;
    if ( !$LINK_TYPE_CONSTANTS_ARR )   // array is cached (=stored to globals)
        $LINK_TYPE_CONSTANTS_ARR = GetConstants($LINK_TYPE_CONSTANTS);
    return $LINK_TYPE_CONSTANTS_ARR[$type] ? $type : false;
}

?>
