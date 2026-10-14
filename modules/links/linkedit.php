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

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/util.php";
require_once __DIR__ . "/../../modules/links/constants.php";
require_once __DIR__ . "/../../modules/links/cattree.php";
require_once __DIR__ . "/../../modules/links/util.php";

try {
    $sid = InputVars::idGETPOST('sid');
    $update = InputVars::boolGETPOST('update');
    $getOldV = InputVars::boolGETPOST('getOldV');
    $lid = InputVars::intGET('lid');
    $cancel = InputVars::boolGETPOST('cancel');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

function printChange($field, $change_arr, $original_value = ""): void
{
    global $created_by_change; // used for display of initiator of the change
    $i = 0;
    foreach ((array) $change_arr as $key => $val) {
        if (trim($original_value) !== trim($val)) {
            //            echo "$field:<br>".trim($original_value)."<br>".trim($val);

            $hidden_field = $field . '_hid' . $i++;
            FrmHidden($hidden_field, $val);
            $accept     = " <a class='btn' href=\"javascript:AcceptChange('$field','$hidden_field')\">" . _m('Accept') . '</a>';
            $changed_by = $created_by_change[$key] ? ' (' . $created_by_change[$key] . ')' : '';
            $new_val    = get_if(trim($val), '-- ' . _m('unfilled') . ' --');
            FrmStaticText(MarkChanged(_m('Change')), MarkChanged($new_val) . $changed_by . $accept, "", "", false);
        }
    }
}

function MarkChanged($txt): string
{
    return "<span class=\"change\">$txt</span>";
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id    = get_if(AA::$module_id, $sid);
$links_info   = GetModuleInfo($module_id, 'Links');
$select_start = get_if((int) $_GET['select_start'], (int) $links_info['select_start'], 2);   // used for anonymous forms
$select_depth = substr_count(Links_GetCategoryColumn($select_start, 'path'), ',');  // count tree start category depth

// load right langfile, if it is not loaded
if ($r_lang_file) {
    bind_mgettext_domain($r_lang_file);
}

// r_err and r_msg - passes messages between scripts
if (!isset($r_err)) {
    $sess->register('r_err');
    $sess->register('r_msg');
}

if ($cancel) {
    // page where to go after this script execution
    $cancelUrl = (Links_IsPublic() ? "/" : StateUrl(self_base() . "index.php"));
    go_url($cancelUrl);
}

// get lid via url
if ($lid > 0) {
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

if ($r_state['link_id']) {
    if (!($db_link = GetLinkInfo($r_state['link_id']))) {
        MsgPage(_m("Link does not exist or the base category is not set"));
        exit;
    }

    // no permission to change slice - doesn't matter - propose change
    //   how? - create new link and join it to existing by "changes" table
    //   (see linkedit2.php)

    /*  all users have the permissions to create link
    } elseif (!IsGlobalPerm( PS_LINKS_CREATE_LINK )) {
        MsgPage(StateUrl(self_base()."index.php"), _m('No permission to create link'));
        exit;
    */

}

// common lookups -------------------------------------------------------------

// lookup - all categories names
$tmp_translate = DB_AA::select(['id' => 'name'], 'SELECT id, name FROM `links_categories`', [['deleted', 'n']]);
$tmp_translate = array_map('safe', $tmp_translate);

// This link specific data -----------------------------------------------------

if ($getOldV) {  // error message - fill old values
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
    for ($selcatCount = $r_state['linkedit']['old']['selcatCount'], $sci = 0;
        $sci < $selcatCount; $sci++) {
        $selcatValue[$sci] = $r_state['linkedit']['old']["selcat$sci"];
        $selcatSelectValue[$sci] = $r_state['linkedit']['old']["selcatSelect$sci"];
        $selcatState[$sci] = $r_state['linkedit']['old']["selcatState$sci"];
    }

    // recoverring previously set values of regions and languages
    $reg  = $r_state['linkedit']['old']['reg'];
    $lang = $r_state['linkedit']['old']['lang'];

    foreach ($reg as $i => $singleReg) {
        $reg_checked[$reg[$i]] = true;
    }
    foreach ($lang as $i => $singleLang) {
        $lang_checked[$lang[$i]] = true;
    }
}

// get edited link data
if (!$getOldV && $r_state['link_id']) {
    $id            = $db_link['id'];
    $aa_name       = $db_link['name'];
    $original_name = $db_link['original_name'];
    $description   = $db_link['description'];
    $initiator     = $db_link['initiator'];
    $rate          = $db_link['rate'];
    $type          = $db_link['type'];
    $url           = $db_link['url'];
    $folder        = max(1, $db_link['folder']);
    $org_city      = $db_link['org_city'];
    $org_street    = $db_link['org_street'];
    $org_post_code = $db_link['org_post_code'];
    $org_phone     = $db_link['org_phone'];
    $org_fax       = $db_link['org_fax'];
    $org_email     = $db_link['org_email'];
    $note          = $db_link['note'];
}

// get edited link data
if ($r_state['link_id']) {
    $created     = $db_link['created'];
    $created_by  = $db_link['created_by'];
    $last_edit   = $db_link['last_edit'];
    $edited_by   = $db_link['edited_by'];
    $checked     = $db_link['checked'];
    $checked_by  = $db_link['checked_by'];
}

if ($r_state['link_id']) {                  // not new link
    // link region info
    foreach (DB_AA::select([], 'SELECT region_id FROM links_link_reg', [['link_id', $r_state['link_id'], 'i']]) as $row) {
        $region[$row['region_id']] = true;
    }

    // link language info
    foreach (DB_AA::select([], 'SELECT lang_id FROM links_link_lang', [['link_id', $r_state['link_id'], 'i']]) as $row) {
        $language[$row['lang_id']] = true;
    }

    // lookup - changes proposal
    $changes = DB_AA::select([], 'SELECT * FROM links_changes, links_links', [
        ['links_changes.proposal_link_id', 'links_links.id', 'j'],
        ['links_changes.changed_link_id', $r_state['link_id'], 'i'],
        ['links_changes.rejected', 'n'],
    ]);
    $delimiter = "";
    $change_no = 0;          // number of changes
    foreach ($changes as $row) {
        $aa_name_change[]       = $row['name'];
        $original_name_change[] = $row['original_name'];
        $description_change[]   = $row['description'];
        $type_change[]          = $row['type'];
        $url_change[]           = $row['url'];
        $initiator_change[]     = $row['initiator'];
        $org_city_change[]      = $row['org_city'];
        $org_street_change[]    = $row['org_street'];
        $org_post_code_change[] = $row['org_post_code'];
        $org_phone_change[]     = $row['org_phone'];
        $org_fax_change[]       = $row['org_fax'];
        $org_email_change[]     = $row['org_email'];
        $note_change[]          = $row['note'];

        $created_by_change[]    = perm_username($row['created_by']);  // used for display of initiator of the change

        $changeIds .= $delimiter . $row['id'];   // set of link id's which holds requested changes
        $delimiter = ',';
        $change_no++;
    }

    // lookup - proposal for region and language changes
    if ($changeIds) {
        //region
        $region_changes = [];
        foreach (DB_AA::select([], 'SELECT region_id FROM links_link_reg', [['link_id', explode(',', $changeIds), 'i']]) as $row) {
            $region_changes[$row['region_id']]++;   // count it
        }

        //language
        $language_changes = [];
        foreach (DB_AA::select([], 'SELECT lang_id FROM links_link_lang', [['link_id', explode(',', $changeIds), 'i']]) as $row) {
            $language_changes[$row['lang_id']]++;
        }   // count it
    }
}

// lookup - all region names
foreach (DB_AA::select([], 'SELECT * FROM links_regions', null, ['id']) as $row) {
    $fid           = $row['id'];
    $regId[]       = $fid;
    $regName[]     = (($row['level'] == 2) ? "&nbsp;&nbsp;&nbsp;" : "")
                     . safe($row['name']);
    $reg_changes[] = (($region[$fid]) // was this checkbox checked?
        ? (($region_changes[$fid] == $change_no) ? "" : MarkChanged(_m('Proposal to uncheck'))) // "" means 'Unchanged'
        : (($region_changes[$fid] == 0) ? "" : MarkChanged(_m('Proposal to check'))));    // "" means 'Unchanged'
    if (!$update) {
        if ($getOldV) {
            $regChecked[] = ($reg_checked[$fid] ? "checked" : ' ');
        } else {
            $regChecked[] = ($region[$fid] ? "checked" : ' ');
        }
    }
}

// lookup - all language names
foreach (DB_AA::select([], 'SELECT * FROM links_languages', null, ['id']) as $row) {
    $fid            = $row['id'];
    $langId[]       = $fid;
    $langName[]     = safe($row['name']);
    $lang_changes[] = (($language[$fid]) // was this checkbox checked?
        ? (($language_changes[$fid] == $change_no) ? "" : MarkChanged(_m('Proposal to uncheck'))) // "" means 'Unchanged'
        : (($language_changes[$fid] == 0) ? "" : MarkChanged(_m('Proposal to check'))));            // "" means 'Unchanged'
    if (!$update) {
        if ($getOldV) {
            $langChecked[] = ($lang_checked[$fid] ? "checked" : ' ');
        } else {
            $langChecked[] = ($language[$fid] ? "checked" : ' ');
        }
    }
}

// lookup - link types
$link_types = $LINK_TYPE_CONSTANTS ? GetConstants($LINK_TYPE_CONSTANTS) : [];

// fill assignments fields -----------------------------------------------------

// first regular proposals
$idx = 0;  // start with 1 ($idx=0 is for base category)
if ($r_state['link_id']) {                  // not new link
    // lookup - this link assignments
    $where = [['links_link_cat.category_id', 'links_categories.id', 'j'], ['what_id', $r_state['link_id'], 'i']];
    foreach (DB_AA::select([], 'SELECT id, path, base, proposal, proposal_delete, state FROM links_link_cat, links_categories', $where) as $row) {
        $i = (($row['base'] === 'y') ? 0 : ++$idx);
        $selcatValue[$i]             = NamePath($select_depth, $row['path'], $tmp_translate);
        $selcatSelectValue[$i]       = $row['id'];
        $getPathFromID[$row['id']]   = $row['path'];
        $selcatState[$i]             = $row['state'];
        $selcatPropAdd[$i]           = (($row['proposal'] === 'y')
                                       ? MarkChanged(_m('Proposal to add')) : ""); //_m('No proposal to add'));
        $selcatPropDel[$i]           = (($row['proposal_delete'] === 'y')
                                       ? MarkChanged(_m('Proposal to del')) : ""); //_m('No proposal to del'));
        $displayed_p[$row['id']]     = true;
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
            foreach ($assignment_changes as $k => $foo) {
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
for ($i = 0 ; $i < CATEGORIES_COUNT_TO_MANAGE; ++$i) {
    if (!$selcatValue[$i]) {
        $selcatValue[$i]       = "";
        $selcatSelectValue[$i] = "";
        $selcatPropAdd[$i]     = ""; // _m('No proposal to add');
        $selcatPropDel[$i]     = ""; // _m('No proposal to del');
    }
}

$selcatCount = max($idx, CATEGORIES_COUNT_TO_MANAGE);

if (isset($checked_url)) {
    $url = $checked_url;
}

// AND now display the form --------------------------------------------------

$r_state['cat_id'] = get_if($r_state['cat_id'], $_GET['cid']);   // used for anonymous forms

//huhl($links_info['select_start'],"----",$select_start );

// Print HTML start page (html begin, encoding, style sheet, no title)
HtmlPageBegin();
echo '<title>' . _m('ActionApps') . " - $pagename</title>";

$tree = new cattree($select_start, false, ' > ');
echo getFrmJavascriptFile('javascript/js_lib.min.js');
echo getFrmJavascriptFile('javascript/js_lib_links.min.js');   // js for category selection
$tree->printTreeData($links_info['tree_start']);

if (!$r_state['link_id']) {  // add new link
    $url = "https://";
    // select current caterory
    $on_load = 'onLoad="GoToCategoryID(\'' . $r_state['cat_id'] . '\', eval(document.f.tree), \'patharea\', \'\');'
               . 'MoveCategoryTo(\'' . $r_state['cat_id'] . '\', \'selcat0\', \'document.f.selcatSelect0\');"';
}

echo '
 <style>
  #body_white_color { color: #000000; }
 </style>
</head>
<body id="body_white_color" ' . $on_load . '>
 <h1>' . $pagename . '</h1>';
if (Links_IsPublic()) {
    echo '<div style="padding: 0 20px 10px;">' . _m('<!--NewPublicLinkText-->') . '</div>';
}

echo GetPrintArray($r_err);
echo GetPrintArray($r_msg);
unset($r_err);
unset($r_msg);


$form_buttons = [
    "submit",
    "cancel",
    "senderUrl" => ['value' => "linkedit.php"],
    "lid"       => ['value' => $r_state['link_id']],
];

echo '
<form name="f" method=post action="' . StateUrl("linkedit2.php") . '">';
$caption = _m('Link') . getFrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK, "formular-odkaz"));
FrmTabCaption($caption, $form_buttons);

$bin_names = get_bin_names();
if ($id) {
    FrmStaticText(_m('Id'), $id . ' (' . $bin_names[$folder] . ')', "", "", false);
}

echo '
    <tr>
      <th>' . _m('Url') . '&nbsp;<span class="aa-required">*</span></th>
      <td>
        <input type="text" name="url" size=50 value="' . $url . '">&nbsp;<a href="' . get_help_url(AA_LINKS_HELP_LINK, "url") . '" target="_blank">?</a>&nbsp;&nbsp;
        <input type="button" value="' . _m('Check url') . '" onclick="CheckURL()">&nbsp;<a href="' . get_help_url(AA_LINKS_HELP_LINK, "hledej-url") . '" target="_blank">?</a>&nbsp;&nbsp;
        <input type="button" value="' . _m('View') . '" onclick="window.open(document.f.url.value, \'blank\')">
        <div class="tabhlp">' . _m('You can check, if the page is not in database already') . '</div>
        <style>
           .skryto { display:none };
        </style>
        <div class="skryto">
          ' . _m('Do not fill, please:') . ' <input type="text" name="answer" value="">
        </div>
      </td>
    </tr>';
printChange('url', $url_change, $url);
FrmInputText(
    'aa_name',
    _m('Page name'),
    $aa_name,
    250,
    50,
    true,
    _m('English name of the page'),
    get_help_url(AA_LINKS_HELP_LINK, "nazev-odkazu"),
);
printChange('aa_name', $aa_name_change, $aa_name);
FrmInputText(
    'original_name',
    _m('Original page name'),
    $original_name,
    250,
    50,
    false,
    _m('Name of the page in original language'),
    get_help_url(AA_LINKS_HELP_LINK, "preklad-nazvu"),
);
printChange('original_name', $original_name_change, $original_name);
FrmTextarea(
    'description',
    _m('Description'),
    $description,
    5,
    60,
    false,
    _m('Do not use HTML tags and do not write words like "best page", ... The maximum length of the description should be about 250 characters.'),
    get_help_url(AA_LINKS_HELP_LINK, "popis-odkazu"),
);
printChange('description', $description_change, $description);

// do not show general categories to public (anonymous) users
if (!Links_IsPublic() && $link_types) {
    FrmInputSelect('type', _m('Link type'), $link_types, $type, false, _m('Select the type, if the link belongs to some special category'), get_help_url(AA_LINKS_HELP_LINK, "obecna-kategorie"));
    printChange('type', $type_change, $type);
} else {
    // we have to preserve current value (if we do not want to show it as
    // "proposal to change" => we put it in hidden field
    FrmHidden('type', $type);
}
// FrmInputText( 'rate', _m('Rating'). ' (1-10)',           $rate,  2, 5, false);
FrmInputEmail('initiator', _m('Author\'s e-mail'), $initiator, 250, 50, false, "", get_help_url(AA_LINKS_HELP_LINK, "e-mail"));
printChange('initiator', $initiator_change, $initiator);
FrmTextarea(
    'note',
    _m('Editor\'s note'),
    $note,
    3,
    60,
    false,
    _m('You can type any message here - it is never shown on the website'),
    get_help_url(AA_LINKS_HELP_LINK, "poznamka-odkaz"),
);
printChange('note', $note_change, $note);

if ($r_state['link_id']) {        // 'edit link', not 'add link'
    FrmStaticText(_m('Last checked'), date(_m('n/j/Y'), $checked) . ', ' . perm_username($checked_by), "", "", false);
    FrmStaticText(_m('Last changed'), date(_m('n/j/Y'), $last_edit) . ', ' . perm_username($edited_by), "", "", false);
    FrmStaticText(_m('Inserted'), date(_m('n/j/Y'), $created) . ', ' . perm_username($created_by), "", "", false);
}
if (!Links_IsPublic()) {
    FrmInputSelect('folder', _m('Folder'), $bin_names, $folder, true);
}


FrmTabSeparator(_m('Show in category') . getFrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK, "odkaz-dokategorii")));
echo '
      <tr>
       <td colspan="2">
        <table>
          <tr>
            <td width=255><b>' . _m('Category tree') . '</b><div class="tabhlp"><i>' . _m('select the category') . '</i></div>
               <input type=hidden name=selcatCount value=' . $selcatCount . '></td>
            <td width=60>&nbsp;</td>
            <td><b>' . _m('Selected categories') . '</b><div class="tabhlp"><i>' . _m('go to the category you want to select and click on the right arrow button to select') . '</i></div></td>
          </tr>
          <tr>
           <td colspan="3"><div id="patharea"> </div></td>
          </tr>
          <tr>
           <td>'
           . $tree->getFrmTree('dblclick', $select_start, 'patharea', '', false, '', 15, 'f');
if ($r_state['cat_id']) {
    echo $tree->goCategory($r_state['cat_id'], 'patharea', '', 'f');
}
echo      '</td>
           <td  colspan=2>
           <table>';

for ($i = 0; $i < CATEGORIES_COUNT_TO_MANAGE; ++$i) {
    echo '<tr><td width="1px"><a
                     href="javascript:MoveSelectedTo(\'document.f.tree\',\'selcat' . $i . '\',\'document.f.selcatSelect' . $i . '\')"><img
                     src="' . AA_INSTAL_PATH . 'images/right.gif" alt="select"></a></td><td width="1px"><a
                     href="javascript:DeleteField(\'' . $i . '\')"><img src="' . AA_INSTAL_PATH . 'images/bx.gif"
                     alt="delete"></a></td><td class="sel_title"><SMALL><DIV id=selcat' . $i . '>' . $selcatValue[$i] . '&nbsp;</DIV></SMALL>
                     </td><td><small>' . $selcatPropAdd[$i] . $selcatPropDel[$i] . '</small>
                     <input type="hidden" name="selcatSelect' . $i . '" value="' . $selcatSelectValue[$i] . '">
                     <input type="hidden" name="selcatState' . $i . '" value="' . $selcatState[$i] . '"></td></tr>';
}

echo '   </table>
            </td>
          </tr>
         </table>
        </td>
       </tr>';

// show the organization information only to real editors or to public, if
// the link is of special type
if (!Links_IsPublic() || ($r_state['link_id'] && $type)) {
    FrmTabSeparator(_m('Organization') . getFrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK, "kontakt")));
    FrmInputText('org_city', _m('City'), $org_city, 250, 50);
    printChange('org_city', $org_city_change, $org_city);
    FrmInputText('org_street', _m('Street'), $org_street, 250, 50);
    printChange('org_street', $org_street_change, $org_street);
    FrmInputText('org_post_code', _m('Post code'), $org_post_code, 250, 50);
    printChange('org_post_code', $org_post_code_change, $org_post_code);
    FrmInputText('org_phone', _m('Phone'), $org_phone, 250, 50);
    printChange('org_phone', $org_phone_change, $org_phone);
    FrmInputText('org_fax', _m('Fax'), $org_fax, 250, 50);
    printChange('org_fax', $org_fax_change, $org_fax);
    FrmInputEmail('org_email', _m('E-mail'), $org_email, 250, 50);
    printChange('org_email', $org_email_change, $org_email);
}


FrmTabSeparator(_m('Regions and languages') . getFrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK, "region-jazyk")));
echo '
      <tr><td width="50%"><b>' . _m('Region') . '</b><div class="tabhlp"><i>' . _m('select up to 4 regions') . '</i></div></td>
          <td><b>' . _m('Language') . '</b><div class="tabhlp"><i>' . _m("select page's languages") . '</i></div></td></tr>
      <tr><td>';
if (isset($regId) && is_array($regId)) {
    foreach ($regId as $i => $singleRegId) {
        echo '<input type="checkbox" name="reg[]" value="' . $singleRegId . '" ' . $regChecked[$i] . '>' . $regName[$i] . ' ' . $reg_changes[$i] . '<br>';
    }
}
echo '</td>
           <td>';
if (isset($langId) && is_array($langId)) {
    foreach ($langId as $i => $singleLangId) {
        echo '<input type="checkbox" name="lang[]" value="' . $singleLangId . '" ' . $langChecked[$i] . '>' . $langName[$i] . ' ' . $lang_changes[$i] . '<br>';
    }
}

echo '</td></tr>';
FrmTabEnd($form_buttons);
echo '
 </form>
    <form name="f_hidden" method="get" action="check_url.php" target="message">
        <input type="hidden" name="url" value="">
        <input type="hidden" name="tree_start" value="' . $links_info['tree_start'] . '">
        <input type="hidden" name="checked_id" value="' . $r_state['link_id'] . '">'
    . StateHidden();
echo '
    </form>
 </body>
</html>';

unset($checked_url);

Session::page_close();
