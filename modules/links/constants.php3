<?php
/**
 * constants definition file for links module
 *
 * Should be included to other scripts (as /modules/links/index.php3)
 *
 * @package Links
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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
if (!defined("LINKS_CONSTANTS_INCLUDED"))
     define ("LINKS_CONSTANTS_INCLUDED",1);
else return;

/** Number of categories to show on linkedit page */
define('CATEGORIES_COUNT_TO_MANAGE', 15);

/** Category group used for special link field 'type' */
$LINK_TYPE_CONSTANTS = 'Ekolink_obecne_k';

define('LINKS_BASE_CAT','y');
define('LINKS_NOT_BASE_CAT','n');

/** List of fields, which will be listed in searchbar in Links Manager (search)
 *  (modules/links/index.php3)
 */
function GetLinkFields() {  // function - we need trnslate _m() on use (not at include time)
    return array (
                // function GetFieldDef( $name,                        $field,                 operators='text', $table=false, $search_pri=false, $order_pri=false
    'id'                  => GetFieldDef( _m('Id'),                    'links_links.id',                'numeric', false,          0,    0),
    'name'                => GetFieldDef( _m('Name'),                  'links_links.name',              'text',    false,       1002, 1002),
    'original_name'       => GetFieldDef( _m('Original name'),         'links_links.original_name',     'text',    false,       1004, 1004),
    'description'         => GetFieldDef( _m('Description'),           'links_links.description',       'text',    false,       1005, 1005),
    'type'                => GetFieldDef( _m('Link type'),             'links_links.type',              'text',    false,       2036, 2036),
    'rate'                => GetFieldDef( _m('Rate'),                  'links_links.rate',              'numeric', false,       3041, 3041),
    'votes'               => GetFieldDef( _m('Votes'),                 'links_links.votes',             'numeric', false,       3042, 3042),
    'created_by'          => GetFieldDef( _m('Author'),                'links_links.created_by',        'text',    false,       1020, 1020),
    'created'             => GetFieldDef( _m('Insert date'),           'links_links.created',           'date',    false,       1014, 1014),
    'edited_by'           => GetFieldDef( _m('Editor'),                'links_links.edited_by',         'text',    false,       1024, 1024),
    'last_edit'           => GetFieldDef( _m('Last edit date'),        'links_links.last_edit',         'date',    false,       1016, 1016),
    'checked_by'          => GetFieldDef( _m('Revised by'),            'links_links.checked_by',        'text',    false,       1026, 1026),
    'checked'             => GetFieldDef( _m('Revision date'),         'links_links.checked',           'date',    false,       1018, 1018),
    'initiator'           => GetFieldDef( _m('E-mail'),                'links_links.initiator',         'text',    false,       1022, 1022),
    'url'                 => GetFieldDef( _m('Url'),                   'links_links.url',               'text',    false,       1006, 1006),
    'voted'               => GetFieldDef( _m('Last vote time'),        'links_links.voted',             'date',    false,       3044, 3044),
    'flag'                => GetFieldDef( _m('Flag'),                  'links_links.flag',              'numeric', false,          0,    0),
    'note'                => GetFieldDef( _m('Editor\'s note'),        'links_links.note',              'text',    false,       1028, 1028),
    'org_city'            => GetFieldDef( _m('Organization city'),     'links_links.org_city',          'text',    false,          0,    0),
    'org_street'          => GetFieldDef( _m('Organization street'),   'links_links.org_street',        'text',    false,          0,    0),
    'org_post_code'       => GetFieldDef( _m('Organization post code'),'links_links.org_post_code',     'text',    false,          0,    0),
    'org_phone'           => GetFieldDef( _m('Organization phone'),    'links_links.org_phone',         'text',    false,          0,    0),
    'org_fax'             => GetFieldDef( _m('Organization fax'),      'links_links.org_fax',           'text',    false,          0,    0),
    'org_email'           => GetFieldDef( _m('Organization e-mail'),   'links_links.org_email',         'text',    false,          0,    0),
    'folder'              => GetFieldDef( _m('Folder'),                'links_links.folder',            'numeric', false,          0,    0),
    'validated'           => GetFieldDef( _m('Last validation date'),  'links_links.validated',         'date',    false,          0,    0),
    'valid_codes'         => GetFieldDef( _m('Validation codes'),      'links_links.valid_codes',       'text',    false,          0,    0),
    'valid_rank'          => GetFieldDef( _m('Validity Rank'),         'links_links.valid_rank',        'numeric', false,       3040, 3040),
    'reg_id'              => GetFieldDef( _m('Region id'),             'links_regions.id',              'numeric', 'regions',      0,    0),
    'reg_name'            => GetFieldDef( _m('Region name'),           'links_regions.name',            'text',    'regions',   1008, 1008),
    'reg_level'           => GetFieldDef( _m('Region level'),          'links_regions.level',           'numeric', 'regions',      0,    0),
    'lang_id'             => GetFieldDef( _m('Language id'),           'links_languages.id',            'numeric', 'languages',    0,    0),
    'lang_name'           => GetFieldDef( _m('Language'),              'links_languages.name',          'text',    'languages', 1010, 1010),
    'lang_short_name'     => GetFieldDef( _m('Language short name'),   'links_languages.short_name',    'text',    'languages', 1012, 1012),
    'cat_id'              => GetFieldDef( _m('Category id'),           'links_link_cat.category_id',    'numeric', false,          0,    0),
    'cat_name'            => GetFieldDef( _m('Category name'),         'links_categories.name',         'text',    false,       2030, 2030),
    'cat_deleted'         => GetFieldDef( _m('Category deleted'),      'links_categories.deleted',      'numeric', false,          0,    0),
    'cat_path'            => GetFieldDef( _m('Category path'),         'links_categories.path',         'text',    false,          0,    0),
    'cat_link_count'      => GetFieldDef( _m('Category link count'),   'links_categories.link_count',   'numeric', false,       2038, 2038),
    'cat_description'     => GetFieldDef( _m('Category description'),  'links_categories.description',  'text',    false,       2032, 2032),
    'cat_note'            => GetFieldDef( _m('Category editor\'s note'),'links_categories.note',        'text',    false,       2034, 2034),
    'cat_base'            => GetFieldDef( _m('Base'),                  'links_link_cat.base',           'text',    false,          0,    0),
    'cat_state'           => GetFieldDef( _m('State'),                 'links_link_cat.state',          'text',    false,          0,    0),
    'cat_proposal'        => GetFieldDef( _m('Change proposal'),       'links_link_cat.proposal',       'numeric', false,          0,    0),
    'cat_proposal_delete' => GetFieldDef( _m('To be deleted'),         'links_link_cat.proposal_delete','numeric', false,          0,    0),
    'cat_priority'        => GetFieldDef( _m('Priority'),              'links_link_cat.priority',       'numeric', false,          0,    0),
    'change'              => GetFieldDef( _m('Change'),                'links_changes.rejected',        'numeric', 'changes',      0,    0)
    );
}

/** Predefined aliases for links. For another aliases use 'inline' aliases. */
function GetLinkAliases() {  // function - we need trnslate _m() on use (not at include time)
  return array(
    "_#LINK_ID_" => GetAliasDef( "f_t",               "id",              _m('Link id')),
    "_#L_NAME__" => GetAliasDef( "f_t",               "name",            _m('Link name')),
    "_#L_O_NAME" => GetAliasDef( "f_t",               "original_name",   _m('Link original name')),
    "_#L_DESCRI" => GetAliasDef( "f_t",               "description",     _m('Link description')),
    "_#L_TYPE__" => GetAliasDef( "f_t",               "type",            _m('Link type')),
    "_#L_RATE__" => GetAliasDef( "f_t",               "rate",            _m('Link rate')),
    "_#L_VOTES_" => GetAliasDef( "f_t",               "vote",            _m('Link votes')),
    "_#L_VO_DTE" => GetAliasDef( "f_d:n/j/Y",         "voted",           _m('Link - last vote date')),
    "_#L_CR_BY_" => GetAliasDef( "f_t",               "created_by",      _m('Link - created by')),
    "_#L_CR_DTE" => GetAliasDef( "f_d:n/j/Y",         "created",         _m('Link creation date')),
    "_#L_ED_BY_" => GetAliasDef( "f_t",               "edited_by",       _m('Link - last edited by')),
    "_#L_ED_DTE" => GetAliasDef( "f_d:n/j/Y",         "last_edit",       _m('Link - last edit date')),
    "_#L_CH_BY_" => GetAliasDef( "f_t",               "checked_by",      _m('Link - checked by')),
    "_#L_CH_DTE" => GetAliasDef( "f_d:n/j/Y",         "checked",         _m('Link - last checked date')),
    "_#L_EMAIL_" => GetAliasDef( "f_m::::mailto:",    "initiator",       _m('Link author\'s e-mail')),
    "_#L_URL___" => GetAliasDef( "f_t",               "url",             _m('Link url')),
    "_#L_LINK__" => GetAliasDef( "f_m::::href:",      "url",             _m('Link link')),
    "_#L_FLAG__" => GetAliasDef( "f_t",               "flag",            _m('Link flag')),
    "_#L_VALID_" => GetAliasDef( "f_t",               "valid_rank",      _m('Link - validity rank')),
    "_#L_VA_DTE" => GetAliasDef( "f_d:n/j/Y",         "validated",       _m('Link - last validation date')),
    "_#L_NOTE__" => GetAliasDef( "f_t",               "note",            _m('Link editor\'s note')),
    "_#L_O_CITY" => GetAliasDef( "f_t",               "org_city",        _m('Link organization city')),
    "_#L_O_STRE" => GetAliasDef( "f_t",               "org_street",      _m('Link organization street')),
    "_#L_O_POST" => GetAliasDef( "f_t",               "org_post_code",   _m('Link organization post code')),
    "_#L_O_PHON" => GetAliasDef( "f_t",               "org_phone",       _m('Link organization phone')),
    "_#L_O_FAX_" => GetAliasDef( "f_t",               "org_fax",         _m('Link organization fax')),
    "_#L_O_EMIL" => GetAliasDef( "f_t",               "org_email",       _m('Link organization e-mail')),
    "_#L_FOLDER" => GetAliasDef( "f_t",               "folder",          _m('Link folder (status code) - 1~Active, 2~Holding, 3~Trash')),
    "_#L_R_ID__" => GetAliasDef( "f_h:, ",            "reg_id",          _m('Link - ids of regions (comma separated)')),
    "_#L_R_NAME" => GetAliasDef( "f_h:, ",            "reg_name",        _m('Link - names of regions (comma separated)')),
    "_#L_L_ID__" => GetAliasDef( "f_h:, ",            "lang_id",         _m('Link - ids of languages (comma separated)')),
    "_#L_L_NAME" => GetAliasDef( "f_h:, ",            "lang_name",       _m('Link - names of languages (comma separated)')),
    "_#L_L_SNAM" => GetAliasDef( "f_h:, ",            "lang_short_name", _m('Link - short names of languages (comma separated)')),
    "_#L_CATIDS" => GetAliasDef( "f_h:, ",            "cat_id",          _m('Category ids (comma separated)')),
    "_#L_CATNAM" => GetAliasDef( "f_h:, ",            "cat_name",        _m('Category names (comma separated)')),
    "_#L_STATE_" => GetAliasDef( "f_t",               "cat_state",       _m('State of link in this category (visible/highlighted)')),
    "_#L_VCOLOR" => GetAliasDef( "f_e:link_valid",    "cat_id",          _m('Link - validity color')),
    "_#L_C_PATH" => GetAliasDef( "l_p:1:::<br>",      "cat_id",          _m('Paths to the categories')),
    "_#ID_COUNT" => GetAliasDef( "f_e:itemcount",     "cat_id",          _m("Number of found Links")),
    "_#EDITLINK" => GetAliasDef( "f_e:link_edit",     "cat_id",          _m('Link to link editing page (for admin interface only)')),
    "_#CATEG_GO" => GetAliasDef( "f_e:link_go_categ", "cat_id",          _m('Category listing with links (for admin interface only)'))
    );
}


/** List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 */
function GetCategoryFields() {  // function - we need trnslate _m() on use (not at include time)
    return array(
     'id'          => GetFieldDef( _m('Id'),          'links_categories.id',          'numeric', false,           10, 10),
     'name'        => GetFieldDef( _m('Name'),        'links_categories.name',        'text',    false,           20, 20),
     'path'        => GetFieldDef( _m('Path'),        'links_categories.path',        'text',    false,           30, 30),
     'link_count'  => GetFieldDef( _m('Link Count'),  'links_categories.link_count',  'numeric', false,           40, 40),
     'description' => GetFieldDef( _m('Description'), 'links_categories.description', 'text',    false,           50, 50),
     'note'        => GetFieldDef( _m('Note'),        'links_categories.note',        'text',    false,           60, 60),
     'priority'    => GetFieldDef( _m('Priority'),    'links_cat_cat.priority',       'numeric', 'links_cat_cat', 70, 70),
     'state'       => GetFieldDef( _m('State'),       'links_cat_cat.state',          'text',    'links_cat_cat', 80, 80)
               );
}

/** Predefined aliases for links. For another aliases use 'inline' aliases. */
function GetCategoryAliases() {  // function - we need trnslate _m() on use (not at include time)
    return array(
    "_#CATEG_ID" => GetAliasDef( "f_t", "id",          _m('Category id')),
    "_#C_NAME__" => GetAliasDef( "f_t", "name",        _m('Category name')),
    "_#C_LCOUNT" => GetAliasDef( "f_t", "link_count",  _m('Number of links in category')),
    "_#C_DESCRI" => GetAliasDef( "f_t", "description", _m('Category description')),
    "_#C_NOTE__" => GetAliasDef( "f_t", "note",        _m('Category editor\'s note')),
    "_#C_CROSS_" => GetAliasDef( "l_b", "path",        _m('Crossreferenced category')),
    "_#C_PATH__" => GetAliasDef( "l_p:1:: / :", "path",        _m('Path to current category')),
    "_#C_GENERL" => GetAliasDef( "l_g", "name",        _m('Is this category general one? (1/0)')),
    "_#C_GENPRI" => GetAliasDef( "l_o", "name",        _m('Print category priority, if category is general one.'))
    );
}

?>
