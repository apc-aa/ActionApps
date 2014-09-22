<?php
//$Id$
// params:
//   lid  - id of edited link

// There are some rules, how links works:
// - links are visible to normal surfer after assigments.proposal='n'
// - new link is visible only to editor of first specified category
//   (proposal='y', state='visible', base='y')
// - after aproval of new link, it became visible to editors of all proposed
//   categories (proposal='y', state='visible', base='n')
// - proposal of link change have two parts: link data and link assignment
//   - new data is specified by changes table, which points to changed link data
//   - new assignments are joined to changed link via links_link_cat table where
//     proposal='y', state='visible', base=<any>


// Edit Link Page

require_once "../../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_BASE_PATH."modules/links/constants.php3";
require_once AA_BASE_PATH."modules/links/cattree.php3";
require_once AA_BASE_PATH."modules/links/util.php3";

function printChange($field, $change_arr, $original_value="") {
    global $created_by_change; // used for display of initiator of the change
    $i=0;
    foreach ( (array)$change_arr as $key => $val) {
        if ( trim($original_value) != trim($val) ) {
//            echo "$field:<br>".trim($original_value)."<br>".trim($val);

            $hidden_field = $field .'_hid'. $i++;
            FrmHidden($hidden_field, $val);
            $accept     = " <a href=\"javascript:AcceptChange('$field','$hidden_field')\">"._m('Accept').'</a>';
            $changed_by = $created_by_change[$key] ? ' ('.$created_by_change[$key].')' : '';
            $new_val    = get_if( trim($val), '-- '. _m('unfilled'). ' --');
            FrmStaticText( MarkChanged( _m('Change') ), MarkChanged( $new_val ) .$changed_by .$accept,
                           false, "", "", false);
        }
    }
}

function MarkChanged($txt) {
    return "<span class=\"change\">$txt</span>";
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id    = get_if($slice_id,$sid);
$p_module_id  = q_pack_id($module_id); // packed to 16-digit as stored in database
$links_info   = GetModuleInfo($module_id,'Links');
$select_start = get_if( $_GET['select_start'], $links_info['select_start'], 2);   // used for anonymous forms
$select_depth = substr_count(Links_GetCategoryColumn( $select_start, 'path' ), ',');  // count tree start category depth

// load right langfile, if it is not loaded
bind_mgettext_domain(AA_INC_PATH."lang/".$r_lang_file);

// r_err and r_msg - passes messages between scripts
if ( !isset($r_err) ) {
    $sess->register('r_err');
    $sess->register('r_msg');
}

if ($cancel) {
    // page where to go after this script execution
    $cancelUrl = ( Links_IsPublic() ? "/" : $sess->url(self_base() ."index.php3"));
    go_url( $cancelUrl );
}

// get lid via url
if ( $lid ) {
    $r_state['link_id'] = $lid;
    $pagename = _m('Edit Link');
} else {
    // adding new link
    // prefill the values from last filling
    $r_state['link_id'] = "";
    $initiator = $r_state['linkedit']['last_autors_email'];
    $rate = $r_state['linkedit']['rate'];
    $pagename = _m('Add Link');
}

if ( $r_state['link_id'] ) {
    if ( !($db_link = GetLinkInfo( $r_state['link_id']))) {
        MsgPage($sess->url(self_base()."index.php3"), _m('Link do not exist or the base category is not set'));
        exit;
    }

    // no permission to change slice - doesn't matter - propose change
    //   how? - create new link and join it to existing by "changes" table
    //   (see linkedit2.php3)

/*  all users have the permissions to create link
} elseif (!IsGlobalPerm( PS_LINKS_CREATE_LINK )) {
    MsgPage($sess->url(self_base()."index.php3"), _m('No permission to create link'));
    exit;
*/

}

// common lookups -------------------------------------------------------------

// lookup - all categories names
$SQL= " SELECT id, name FROM links_categories WHERE deleted='n'";
$db->query($SQL);
while ($db->next_record()) {
    // just for translation from id to name
    $tmp_translate[$db->f('id')] = myspecialchars($db->f('name'));
}

// This link specific data -----------------------------------------------------

if ( $getOldV ){  // error message - fill old values
    $url           = $r_state['linkedit']['old']['url'];
    $aa_name       = $r_state['linkedit']['old']['aa_name'];
    $original_name = $r_state['linkedit']['old']['original_name'];
    $description   = $r_state['linkedit']['old']['description'];
    $initiator     = $r_state['linkedit']['old']['initiator'];
    $rate          = $r_state['linkedit']['old']['rate'];
    $type          = $r_state['linkedit']['old']['type'];
    $org_city      = $r_state['linkedit']['old']['org_city'];
    $org_street    = $r_state['linkedit']['old']['org_street'];
    $org_post_code = $r_state['linkedit']['old']['org_post_code'];
    $org_phone     = $r_state['linkedit']['old']['org_phone'];
    $org_fax       = $r_state['linkedit']['old']['org_fax'];
    $org_email     = $r_state['linkedit']['old']['org_email'];
    $note          = $r_state['linkedit']['old']['note'];

    // recoverring previously set values of categories
    for ($selcatCount=$r_state['linkedit']['old']['selcatCount'], $sci=0;
            $sci < $selcatCount; $sci++) {
        $selcatValue[$sci] = $r_state['linkedit']['old']["selcat$sci"];
        $selcatSelectValue[$sci] = $r_state['linkedit']['old']["selcatSelect$sci"];
        $selcatState[$sci] = $r_state['linkedit']['old']["selcatState$sci"];
    }

    // recoverring previously set values of regions and languages
    $reg  = $r_state['linkedit']['old']['reg'];
    $lang = $r_state['linkedit']['old']['lang'];

    for ( $i=0, $ino=count($reg); $i<$ino; ++$i) {
        $reg_checked[$reg[$i]]=true;
    }
    for ( $i=0, $ino=count($lang); $i<$ino; ++$i) {
        $lang_checked[$lang[$i]]=true;
    }
}

// get edited link data
if ( !$getOldV AND $r_state['link_id'] ) {
    $id            = $db_link['id'];
    $aa_name       = $db_link['name'];
    $original_name = $db_link['original_name'];
    $description   = $db_link['description'];
    $initiator     = $db_link['initiator'];
    $rate          = $db_link['rate'];
    $type          = $db_link['type'];
    $url           = $db_link['url'];
    $folder        = max( 1, $db_link['folder']);
    $org_city      = $db_link['org_city'];
    $org_street    = $db_link['org_street'];
    $org_post_code = $db_link['org_post_code'];
    $org_phone     = $db_link['org_phone'];
    $org_fax       = $db_link['org_fax'];
    $org_email     = $db_link['org_email'];
    $note          = $db_link['note'];
}

// get edited link data
if ( $r_state['link_id'] ) {
    $created     = $db_link['created'];
    $created_by  = $db_link['created_by'];
    $last_edit   = $db_link['last_edit'];
    $edited_by   = $db_link['edited_by'];
    $checked     = $db_link['checked'];
    $checked_by  = $db_link['checked_by'];
}

if ( $r_state['link_id'] ) {                  // not new link
    // link region info
    $SQL= "SELECT region_id FROM links_link_reg
            WHERE link_id=".$r_state['link_id'];
    $db->query($SQL);
    while ( $db->next_record() )
        $region[$db->f('region_id')]=true;

    // link language info
    $SQL= "SELECT lang_id FROM links_link_lang
            WHERE link_id=".$r_state['link_id'];
    $db->query($SQL);
    while ( $db->next_record() )
        $language[$db->f('lang_id')]=true;

    // lookup - changes proposal
    $SQL= "SELECT * FROM links_changes, links_links
            WHERE links_changes.proposal_link_id=links_links.id
              AND links_changes.changed_link_id=". $r_state['link_id'] ."
              AND links_changes.rejected='n'";
    $db->query($SQL);
    $delimiter = "";
    $change_no = 0;          // number of changes
    while ($db->next_record()) {
        $aa_name_change[]       = $db->f('name');
        $original_name_change[] = $db->f('original_name');
        $description_change[]   = $db->f('description');
        $type_change[]          = $db->f('type');
        $url_change[]           = $db->f('url');
        $initiator_change[]     = $db->f('initiator');
        $org_city_change[]      = $db->f('org_city');
        $org_street_change[]    = $db->f('org_street');
        $org_post_code_change[] = $db->f('org_post_code');
        $org_phone_change[]     = $db->f('org_phone');
        $org_fax_change[]       = $db->f('org_fax');
        $org_email_change[]     = $db->f('org_email');
        $note_change[]          = $db->f('note');

        $created_by_change[]    = perm_username($db->f('created_by'));  // used for display of initiator of the change

        $changeIds .= $delimiter.$db->f('id');   // set of link id's which holds requested changes
        $delimiter = ',';
        $change_no++;
    }

    // lookup - proposal for region and language changes
    if ($changeIds) {
        //region
        $SQL= "SELECT region_id FROM links_link_reg WHERE link_id IN ($changeIds)";
        $db->query($SQL);
        while ($db->next_record())
            $region_changes[$db->f('region_id')]++;   // count it

        //language
        $SQL= "SELECT lang_id FROM links_link_lang WHERE link_id IN ($changeIds)";
        $db->query($SQL);
        while ($db->next_record())
            $language_changes[$db->f('lang_id')]++;   // count it
    }
}

// lookup - all region names
$SQL= "SELECT * FROM links_regions ORDER BY id";
$db->query($SQL);
while ($db->next_record()) {
    $fid           = $db->f('id');
    $regId[]       = $fid;
    $regName[]     = (($db->f('level')==2) ? "&nbsp;&nbsp;&nbsp;" : "").
                     myspecialchars($db->f('name'));
    $reg_changes[] = (($region[$fid]) ?            // was this checkbox checked?
        (($region_changes[$fid] == $change_no ) ? "" : MarkChanged(_m('Proposal to uncheck'))) :    // "" means 'Unchanged'
        (($region_changes[$fid] == 0 )          ? "" : MarkChanged(_m('Proposal to check'))));    // "" means 'Unchanged'
    if (!$update) {
        if ($getOldV)
            $regChecked[] = ($reg_checked[$fid] ? "checked" : ' ');
        else
            $regChecked[] = ($region[$fid] ? "checked" : ' ');
    }
}

// lookup - all language names
$SQL= "SELECT * FROM links_languages ORDER BY id";
$db->query($SQL);
while ($db->next_record()) {
    $fid            = $db->f('id');
    $langId[]       = $fid;
    $langName[]     = myspecialchars($db->f('name'));
    $lang_changes[] = (($language[$fid]) ?        // was this checkbox checked?
        (($language_changes[$fid] == $change_no ) ? "" : MarkChanged(_m('Proposal to uncheck')) ) :   // "" means 'Unchanged'
        (($language_changes[$fid] == 0 )          ? "" : MarkChanged(_m('Proposal to check')) ));            // "" means 'Unchanged'
    if (!$update) {
        if ($getOldV)
            $langChecked[] = ($lang_checked[$fid] ? "checked" : ' ');
        else
            $langChecked[] = ($language[$fid] ? "checked" : ' ');
    }
}

// lookup - link types
$link_types = GetConstants($LINK_TYPE_CONSTANTS);

// fill assignments fields -----------------------------------------------------

// first regular proposals
$idx=0;  // start with 1 ($idx=0 is for base category)
if ( $r_state['link_id'] ) {                  // not new link
    // lookup - this link assignments
    $SQL= "SELECT id, path, base, proposal, proposal_delete, state
             FROM links_link_cat, links_categories
            WHERE links_link_cat.category_id=links_categories.id
              AND what_id=".$r_state['link_id'];
    $db->query($SQL);
    while ($db->next_record()) {
        $i = (( $db->f('base') == 'y' ) ? 0 : ++$idx);
        $selcatValue[$i]             = NamePath($select_depth,$db->f('path'), $tmp_translate);
        $selcatSelectValue[$i]       = $db->f('id');
        $getPathFromID[$db->f('id')] = $db->f('path');
        $selcatState[$i]             = $db->f('state');
        $selcatPropAdd[$i]           = (($db->f('proposal')=='y') ?
                                       MarkChanged(_m('Proposal to add')) : ""); //_m('No proposal to add'));
        $selcatPropDel[$i]           = (($db->f('proposal_delete')=='y') ?
                                       MarkChanged(_m('Proposal to del')) : ""); //_m('No proposal to del'));
        $displayed_p[$db->f('id')]   = true;
    }

    // now proposals from anonymous change link
    /*
    if ($changeIds) {
        $SQL= "SELECT path, id
                 FROM links_link_cat, links_categories
                WHERE links_link_cat.category_id=links_categories.id
                  AND what_id IN ($changeIds)";
        $db->query($SQL);
        while ( $db->next_record() ) {
            $assignment_changes[$db->f('id')]++;   // count it
            $getPathFromID[$db->f('id')] = $db->f('path');
        }

        // mark delete proposals
        for ( $i=0; $i<=$idx; ++$i ) {
            if ( $selcatSelectValue[$i] AND
               ($assignment_changes[$selcatSelectValue[$i]] <> $change_no) )
                $selcatPropDel[$i] = MarkChanged(_m('Proposal to del'));
        }

        // add new proposals
        if ( isset($assignment_changes) AND is_array($assignment_changes) ) {
            reset($assignment_changes);
            while (list($k,) = each($assignment_changes)) {
                if ( $displayed_p[$k] )
                    continue;         // already displayed
                $i = ++$idx;
                $selcatValue[$i]       = NamePath($links_info['tree_depth'],$getPathFromID[$k], $tmp_translate);
                $selcatSelectValue[$i] = $k;
                $selcatState[$i]       = "visible";   // I'm not sure with this setting
                $selcatPropAdd[$i]     = MarkChanged(_m('Proposal to add'));
                $selcatPropDel[$i]     = ""; // _m('No proposal to del');
            }
        }
    }
    */
}

$idx++;
// minimum number of selcats is 6
for ( $i=0 ; $i < CATEGORIES_COUNT_TO_MANAGE; ++$i ) {
    if ( !$selcatValue[$i] ) {
        $selcatValue[$i]       = "";
        $selcatSelectValue[$i] = "";
        $selcatPropAdd[$i]     = ""; // _m('No proposal to add');
        $selcatPropDel[$i]     = ""; // _m('No proposal to del');
    }
}

$selcatCount = max( $idx, CATEGORIES_COUNT_TO_MANAGE );

if (isset($checked_url))
    $url = $checked_url;

// AND now display the form --------------------------------------------------

$r_state['cat_id'] = get_if( $r_state['cat_id'], $_GET['cid'] );   // used for anonymous forms

//huhl($links_info['select_start'],"----",$select_start );

// Print HTML start page (html begin, encoding, style sheet, no title)
HtmlPageBegin();
echo '<title>'. _m('ActionApps') ." - $pagename</title>";

$tree = new cattree( $db, $select_start, false, ' > ');
FrmJavascriptFile('javascript/js_lib.js');
FrmJavascriptFile('javascript/js_lib_links.js');   // js for category selection
$tree->printTreeData($links_info['tree_start']);

if ( !$r_state['link_id'] ) {  // add new link
    $url = "http://";
    // select current caterory
    $on_load = 'onLoad="GoToCategoryID(\''.$r_state['cat_id'].'\', eval(document.f.tree), \'patharea\', \'\');'.
               'MoveCategoryTo(\''.$r_state['cat_id'].'\', \'selcat0\', \'document.f.selcatSelect0\');"';
}

echo '
 <style>
  #body_white_color { color: #000000; }
 </style>
</head>
<body id="body_white_color" '.$on_load.'>
 <H1><B>'. $pagename .'</B></H1>';
 if ( Links_IsPublic() ) {
     echo '<div style="padding: 0 20px 10px;">'. _m('<!--NewPublicLinkText-->'). '</div>';
 }

PrintArray($r_err);
PrintArray($r_msg);
unset($r_err);
unset($r_msg);


$form_buttons = array ( "submit",
                        "cancel",
                        "senderUrl" => array('value' => "linkedit.php3"),
                        "lid"       => array('value' => $r_state['link_id']) );

echo '
<form name="f" method=post action="'. $sess->url("linkedit2.php3") .'">';
$caption = _m('Link').FrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK,"formular-odkaz"),
                                      array("before"=>"(", "text"=>"?", "after"=>")"));
FrmTabCaption($caption, '','', $form_buttons, $sess, $slice_id);

$bin_names = get_bin_names();
if ( $id ) {
    FrmStaticText( _m('Id'),  $id .' ('.$bin_names[$folder].')', false, "", "", false);
}

echo '
    <tr>
      <td class=tabtxt valign="top"><b>'. _m('Url') .'</b>&nbsp;*</td>
      <td align="left">
        <input type="text" name="url" size=50 value="'.$url.'">&nbsp;<a href="'.get_help_url(AA_LINKS_HELP_LINK,"url").'" target="_blank">?</a>&nbsp;&nbsp;
        <input type="button" value="'. _m('Check url') .'" onclick="CheckURL()">&nbsp;<a href="'.get_help_url(AA_LINKS_HELP_LINK,"hledej-url").'" target="_blank">?</a>&nbsp;&nbsp;
        <input type="button" value="'. _m('View') .'" onclick="window.open(document.f.url.value, \'blank\')">
        <div class="tabhlp">'. _m('You can check, if the page is not in database already') .'</div>
        <style type="text/css">
           .skryto { display:none };
        </style>
        <div class="skryto">
          '. _m('Do not fill, please:') .' <input type="text" name="answer" value="">
        </div>
      </td>
    </tr>';
    printChange('url', $url_change, $url);
    FrmInputText( 'aa_name', _m('Page name'),           $aa_name,  250, 50, true,
                   _m('English name of the page'), get_help_url(AA_LINKS_HELP_LINK,"nazev-odkazu"));
    printChange('aa_name', $aa_name_change, $aa_name);
    FrmInputText( 'original_name', _m('Original page name'), $original_name,  250, 50, false,
                   _m('Name of the page in original language'), get_help_url(AA_LINKS_HELP_LINK,"preklad-nazvu"));
    printChange('original_name', $original_name_change, $original_name);
    FrmTextarea(  'description',  _m('Description'),    $description, 5, 60, false,
                  _m('Do not use HTML tags and do not write words like "best page", ... The maximum length of the description should be about 250 characters.'),
                  get_help_url(AA_LINKS_HELP_LINK,"popis-odkazu"));
    printChange('description', $description_change, $description);

    // do not show general categories to public (anonymous) users
    if ( !Links_IsPublic() ) {
        FrmInputSelect( 'type', _m('Link type'), $link_types, $type, false,
                       _m('Select the type, if the link belongs to some special category'),
                       get_help_url(AA_LINKS_HELP_LINK,"obecna-kategorie"));
        printChange('type', $type_change, $type);
    } else {
        // we have to preserve current value (if we do not want to show it as
        // "proposal to change" => we put it in hidden field
        FrmHidden('type', $type );
    }
    // FrmInputText( 'rate', _m('Rating'). ' (1-10)',           $rate,  2, 5, false);
    FrmInputText( 'initiator', _m('Author\'s e-mail'),           $initiator,  250, 50, false,
                  "", get_help_url(AA_LINKS_HELP_LINK,"e-mail"));
    printChange('initiator', $initiator_change, $initiator);
    FrmTextarea(  'note',  _m('Editor\'s note'),    $note, 3, 60, false,
                  _m('You can type any message here - it is never shown on the website'),
                  get_help_url(AA_LINKS_HELP_LINK,"poznamka-odkaz"));
    printChange('note', $note_change, $note);

if ( $r_state['link_id'] ) {        // 'edit link', not 'add link'
    FrmStaticText( _m('Last checked'), date(_m('n/j/Y'), $checked). ', '. perm_username($checked_by),  false, "", "", false);
    FrmStaticText( _m('Last changed'), date(_m('n/j/Y'), $last_edit). ', '.  perm_username($edited_by), false, "", "", false);
    FrmStaticText( _m('Inserted'),     date(_m('n/j/Y'), $created). ', '. perm_username($created_by),  false, "", "", false);
}
if ( !Links_IsPublic() ) {
    FrmInputSelect('folder', _m('Folder'), $bin_names, $folder, true);
}


    FrmTabSeparator( _m('Show in category').FrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK,"odkaz-dokategorii"),
                                                        array("before"=>"(", "text"=>"?", "after"=>")")) );
echo '
      <tr>
       <td colspan="2">
        <table width=100%>
          <tr valign="top">
            <td width=255 align=center valign="top"><b>'. _m('Category tree') .'</b><div class="tabhlp"><i>'. _m('select the category') .'</i></div>
               <input type=hidden name=selcatCount value='.$selcatCount.'></td>
            <td width=60>&nbsp;</td>
            <td align=center valign="top"><b>'. _m('Selected categories') .'</b><div class="tabhlp"><i>'. _m('go to the category you want to select and click on the right arrow button to select') .'</i></div></td>
          </tr>
          <tr>
           <td colspan="3"><div id="patharea"> </div></td>
          </tr>
          <tr>
           <td align="CENTER" valign="TOP">'.
           $tree->getFrmTree(false, 'dblclick', $select_start, 'patharea', '', false, '', 15, 'f');
           if ( $r_state['cat_id'] ) {
                echo $tree->goCategory( $r_state['cat_id'], 'patharea', '', 'f' );
           }
echo      '</td>
           <td  align="LEFT" valign="TOP" colspan=2>
           <table border="0" width="100%" cellpadding="0" cellspacing="3">';

                for ( $i=0; $i<CATEGORIES_COUNT_TO_MANAGE; ++$i ) {
                     echo '<tr><td width="1px"><a
                     href="javascript:MoveSelectedTo(\'document.f.tree\',\'selcat'.$i.'\',\'document.f.selcatSelect'.$i.'\')"><img
                     src="'.AA_INSTAL_PATH.'images/right.gif" border="0" alt="select"></a></td><td width="1px"><a
                     href="javascript:DeleteField(\''.$i.'\')"><img src="'.AA_INSTAL_PATH.'images/bx.gif" border="0"
                     alt="delete"></a></td><td class="sel_title"><SMALL><DIV id=selcat'.$i.'>'.$selcatValue[$i].'&nbsp;</DIV></SMALL>
                     </td><td><small>'.$selcatPropAdd[$i].$selcatPropDel[$i].'</small>
                     <input type="hidden" name="selcatSelect'.$i.'" value="'.$selcatSelectValue[$i].'">
                     <input type="hidden" name="selcatState'.$i.'" value="'.$selcatState[$i].'"></td></tr>';
                }

    echo '   </table>
            </td>
          </tr>
         </table>
        </td>
       </tr>';

// show the organization information only to real editors or to public, if
// the link is of special type
if ( !Links_IsPublic() OR ($r_state['link_id'] AND $type) ) {
       FrmTabSeparator( _m('Organization') .FrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK,"kontakt"),
                                                        array("before"=>"(", "text"=>"?", "after"=>")")) );
       FrmInputText( 'org_city', _m('City'), $org_city,  250, 50, false);
       printChange( 'org_city', $org_city_change, $org_city );
       FrmInputText( 'org_street', _m('Street'), $org_street,  250, 50, false);
       printChange( 'org_street', $org_street_change, $org_street );
       FrmInputText( 'org_post_code', _m('Post code'), $org_post_code,  250, 50, false);
       printChange( 'org_post_code', $org_post_code_change, $org_post_code );
       FrmInputText( 'org_phone', _m('Phone'), $org_phone,  250, 50, false);
       printChange( 'org_phone', $org_phone_change, $org_phone );
       FrmInputText( 'org_fax', _m('Fax'), $org_fax,  250, 50, false);
       printChange( 'org_fax', $org_fax_change, $org_fax );
       FrmInputText( 'org_email', _m('E-mail'), $org_email,  250, 50, false);
       printChange( 'org_email', $org_email_change, $org_email );
}


       FrmTabSeparator( _m('Regions and languages').
           FrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK,"region-jazyk"), array("before"=>"(", "text"=>"?", "after"=>")")) );
   echo '
      <tr><td width="50%" align=center><b>'. _m('Region') .'</b><div class="tabhlp"><i>'. _m('select up to 4 regions') .'</i></div></td>
          <td align=center><b>'. _m('Language') .'</b><div class="tabhlp"><i>'. _m('select pege\'s languages') .'</i></div></td></tr>
      <tr><td valign="top">';
    if ( isset($regId) AND is_array($regId) ) {
        for ( $i=0, $ino=count($regId); $i<$ino; ++$i) {
            echo '<input type="checkbox" name="reg[]" value="'.$regId[$i].'" '.$regChecked[$i].'>'.$regName[$i].' '.$reg_changes[$i].'<br>';
        }
    }
    echo '</td>
           <td valign="top">';
    if ( isset($langId) AND is_array($langId) ) {
        for ( $i=0, $ino=count($langId); $i<$ino; ++$i) {
            echo '<input type="checkbox" name="lang[]" value="'.$langId[$i].'" '.$langChecked[$i].'>'.$langName[$i].' '.$lang_changes[$i].'<br>';
        }
    }

    echo '</td></tr>';
    FrmTabEnd( $form_buttons, $sess, $slice_id);
    echo '
 </form>
    <form name="f_hidden" method="get" action="check_url.php3" target="message">
        <input type="hidden" name="url" value="">
        <input type="hidden" name="tree_start" value="'.$links_info['tree_start'].'">
        <input type="hidden" name="checked_id" value="'.$r_state['link_id'].'">';
        $sess->hidden_session();
echo '
    </form>
 </body>
</html>';

unset($checked_url);

page_close();
?>