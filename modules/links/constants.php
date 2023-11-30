<?php
/**
 * constants definition file for links module
 *
 * Should be included to other scripts (as /modules/links/index.php)
 *
 * @package Links
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

// make sure LINKS module specific constants are defined - should be defined in config.php
use AA\Util\AbstractAliasgenerator;

defined('AA_LINKS_HELP_CATEGORY') OR define('AA_LINKS_HELP_CATEGORY', '');
defined('AA_LINKS_HELP_MAIN')     OR define('AA_LINKS_HELP_MAIN', '');
defined('AA_LINKS_HELP_LINK')     OR define('AA_LINKS_HELP_LINK', '');
defined('AA_LINKS_VOTE_THANKS')   OR define('AA_LINKS_VOTE_THANKS', '');
defined('AA_LINKS_VOTE_NOVOTE')   OR define('AA_LINKS_VOTE_NOVOTE', '');
defined('AA_LINKS_VOTE_ERROR')    OR define('AA_LINKS_VOTE_ERROR', '');

/** Number of categories to show on linkedit page */
const CATEGORIES_COUNT_TO_MANAGE = 15;

/** Category group used for special link field 'type' */
$LINK_TYPE_CONSTANTS = 'Ekolink_obecne_k';

const LINKS_BASE_CAT     = 'y';
const LINKS_NOT_BASE_CAT = 'n';

/** List of fields, which will be listed in searchbar in Links Manager (search)
 *  (modules/links/index.php)
 */
function GetLinkFields() {  // function - we need trnslate _m() on use (not at include time)
    $searchfields = new AA\Util\Searchfields();
                   //   id                     $name,                        $field,                 operators='text', $table=false, $search_pri=false, $order_pri=false
    $searchfields->add('id'                  , _m('Id'),                    'links_links.id',                'numeric', false,          0,    0);
    $searchfields->add('name'                , _m('Name'),                  'links_links.name',              'text',    false,       1002, 1002);
    $searchfields->add('original_name'       , _m('Original name'),         'links_links.original_name',     'text',    false,       1004, 1004);
    $searchfields->add('description'         , _m('Description'),           'links_links.description',       'text',    false,       1005, 1005);
    $searchfields->add('type'                , _m('Link type'),             'links_links.type',              'text',    false,       2036, 2036);
    $searchfields->add('rate'                , _m('Rate'),                  'links_links.rate',              'numeric', false,       3041, 3041);
    $searchfields->add('votes'               , _m('Votes'),                 'links_links.votes',             'numeric', false,       3042, 3042);
    $searchfields->add('created_by'          , _m('Author'),                'links_links.created_by',        'text',    false,       1020, 1020);
    $searchfields->add('created'             , _m('Insert date'),           'links_links.created',           'date',    false,       1014, 1014);
    $searchfields->add('edited_by'           , _m('Editor'),                'links_links.edited_by',         'text',    false,       1024, 1024);
    $searchfields->add('last_edit'           , _m('Last edit date'),        'links_links.last_edit',         'date',    false,       1016, 1016);
    $searchfields->add('checked_by'          , _m('Revised by'),            'links_links.checked_by',        'text',    false,       1026, 1026);
    $searchfields->add('checked'             , _m('Revision date'),         'links_links.checked',           'date',    false,       1018, 1018);
    $searchfields->add('initiator'           , _m('E-mail'),                'links_links.initiator',         'text',    false,       1022, 1022);
    $searchfields->add('url'                 , _m('Url'),                   'links_links.url',               'text',    false,       1006, 1006);
    $searchfields->add('voted'               , _m('Last vote time'),        'links_links.voted',             'date',    false,       3044, 3044);
    $searchfields->add('flag'                , _m('Flag'),                  'links_links.flag',              'numeric', false,          0,    0);
    $searchfields->add('note'                , _m('Editor\'s note'),        'links_links.note',              'text',    false,       1028, 1028);
    $searchfields->add('org_city'            , _m('Organization city'),     'links_links.org_city',          'text',    false,          0,    0);
    $searchfields->add('org_street'          , _m('Organization street'),   'links_links.org_street',        'text',    false,          0,    0);
    $searchfields->add('org_post_code'       , _m('Organization post code'),'links_links.org_post_code',     'text',    false,          0,    0);
    $searchfields->add('org_phone'           , _m('Organization phone'),    'links_links.org_phone',         'text',    false,          0,    0);
    $searchfields->add('org_fax'             , _m('Organization fax'),      'links_links.org_fax',           'text',    false,          0,    0);
    $searchfields->add('org_email'           , _m('Organization e-mail'),   'links_links.org_email',         'text',    false,          0,    0);
    $searchfields->add('folder'              , _m('Folder'),                'links_links.folder',            'numeric', false,          0,    0);
    $searchfields->add('validated'           , _m('Last validation date'),  'links_links.validated',         'date',    false,          0,    0);
    $searchfields->add('valid_codes'         , _m('Validation codes'),      'links_links.valid_codes',       'text',    false,          0,    0);
    $searchfields->add('valid_rank'          , _m('Validity Rank'),         'links_links.valid_rank',        'numeric', false,       3040, 3040);
    $searchfields->add('reg_id'              , _m('Region id'),             'links_regions.id',              'numeric', 'regions',      0,    0);
    $searchfields->add('reg_name'            , _m('Region name'),           'links_regions.name',            'text',    'regions',   1008, 1008);
    $searchfields->add('reg_level'           , _m('Region level'),          'links_regions.level',           'numeric', 'regions',      0,    0);
    $searchfields->add('lang_id'             , _m('Language id'),           'links_languages.id',            'numeric', 'languages',    0,    0);
    $searchfields->add('lang_name'           , _m('Language'),              'links_languages.name',          'text',    'languages', 1010, 1010);
    $searchfields->add('lang_short_name'     , _m('Language short name'),   'links_languages.short_name',    'text',    'languages', 1012, 1012);
    $searchfields->add('cat_id'              , _m('Category id'),           'links_link_cat.category_id',    'numeric', false,          0,    0);
    $searchfields->add('cat_name'            , _m('Category name'),         'links_categories.name',         'text',    false,       2030, 2030);
    $searchfields->add('cat_deleted'         , _m('Category deleted'),      'links_categories.deleted',      'numeric', false,          0,    0);
    $searchfields->add('cat_path'            , _m('Category path'),         'links_categories.path',         'text',    false,          0,    0);
    $searchfields->add('cat_link_count'      , _m('Category link count'),   'links_categories.link_count',   'numeric', false,       2038, 2038);
    $searchfields->add('cat_description'     , _m('Category description'),  'links_categories.description',  'text',    false,       2032, 2032);
    $searchfields->add('cat_note'            , _m('Category editor\'s note'),'links_categories.note',        'text',    false,       2034, 2034);
    $searchfields->add('cat_base'            , _m('Base'),                  'links_link_cat.base',           'text',    false,          0,    0);
    $searchfields->add('cat_state'           , _m('State'),                 'links_link_cat.state',          'text',    false,          0,    0);
    $searchfields->add('cat_proposal'        , _m('Change proposal'),       'links_link_cat.proposal',       'numeric', false,          0,    0);
    $searchfields->add('cat_proposal_delete' , _m('To be deleted'),         'links_link_cat.proposal_delete','numeric', false,          0,    0);
    $searchfields->add('cat_priority'        , _m('Priority'),              'links_link_cat.priority',       'numeric', false,          0,    0);
    $searchfields->add('change'              , _m('Change'),                'links_changes.rejected',        'numeric', 'changes',      0,    0);
    return $searchfields;
}


/** cached link aliases
 *  @usage new AA_Aliases(new AA_Aliasgenerator_Link())
 */
class AA_Aliasgenerator_Link extends AbstractAliasgenerator {

    public static function getAliasesFromParams(array $params): AA_Aliases {
        $aliases = new AA_Aliases;

        //  Standard aliases
        $aliases->addAlias(new AA_Alias('_#LINK_ID_', 'f_t', 'id', _m('Link id')));
        $aliases->addAlias(new AA_Alias('_#L_NAME__', 'f_t', 'name', _m('Link name')));
        $aliases->addAlias(new AA_Alias('_#L_O_NAME', 'f_t', 'original_name', _m('Link original name')));
        $aliases->addAlias(new AA_Alias('_#L_DESCRI', 'f_t', 'description', _m('Link description')));
        $aliases->addAlias(new AA_Alias('_#L_TYPE__', 'f_t', 'type', _m('Link type')));
        $aliases->addAlias(new AA_Alias('_#L_RATE__', 'f_t', 'rate', _m('Link rate')));
        $aliases->addAlias(new AA_Alias('_#L_VOTES_', 'f_t', 'vote', _m('Link votes')));
        $aliases->addAlias(new AA_Alias('_#L_VO_DTE', 'f_d:n/j/Y', 'voted', _m('Link - last vote date')));
        $aliases->addAlias(new AA_Alias('_#L_CR_BY_', 'f_t', 'created_by', _m('Link - created by')));
        $aliases->addAlias(new AA_Alias('_#L_CR_DTE', 'f_d:n/j/Y', 'created', _m('Link creation date')));
        $aliases->addAlias(new AA_Alias('_#L_ED_BY_', 'f_t', 'edited_by', _m('Link - last edited by')));
        $aliases->addAlias(new AA_Alias('_#L_ED_DTE', 'f_d:n/j/Y', 'last_edit', _m('Link - last edit date')));
        $aliases->addAlias(new AA_Alias('_#L_CH_BY_', 'f_t', 'checked_by', _m('Link - checked by')));
        $aliases->addAlias(new AA_Alias('_#L_CH_DTE', 'f_d:n/j/Y', 'checked', _m('Link - last checked date')));
        $aliases->addAlias(new AA_Alias('_#L_EMAIL_', 'f_m::::mailto:', 'initiator', _m('Link author\'s e-mail')));
        $aliases->addAlias(new AA_Alias('_#L_URL___', 'f_t', 'url', _m('Link url')));
        $aliases->addAlias(new AA_Alias('_#L_LINK__', 'f_m::::href:', 'url', _m('Link link')));
        $aliases->addAlias(new AA_Alias('_#L_FLAG__', 'f_t', 'flag', _m('Link flag')));
        $aliases->addAlias(new AA_Alias('_#L_VALID_', 'f_t', 'valid_rank', _m('Link - validity rank')));
        $aliases->addAlias(new AA_Alias('_#L_VA_DTE', '_d:n/j/Y', 'validated', _m('Link - last validation date')));
        $aliases->addAlias(new AA_Alias('_#L_NOTE__', 'f_t', 'note', _m('Link editor\'s note')));
        $aliases->addAlias(new AA_Alias('_#L_O_CITY', 'f_t', 'org_city', _m('Link organization city')));
        $aliases->addAlias(new AA_Alias('_#L_O_STRE', 'f_t', 'org_street', _m('Link organization street')));
        $aliases->addAlias(new AA_Alias('_#L_O_POST', 'f_t', 'org_post_code', _m('Link organization post code')));
        $aliases->addAlias(new AA_Alias('_#L_O_PHON', 'f_t', 'org_phone', _m('Link organization phone')));
        $aliases->addAlias(new AA_Alias('_#L_O_FAX_', 'f_t', 'org_fax', _m('Link organization fax')));
        $aliases->addAlias(new AA_Alias('_#L_O_EMIL', 'f_t', 'org_email', _m('Link organization e-mail')));
        $aliases->addAlias(new AA_Alias('_#L_FOLDER', 'f_t', 'folder', _m('Link folder (status code) - 1~Active, 2~Holding, 3~Trash')));
        $aliases->addAlias(new AA_Alias('_#L_R_ID__', 'f_h:, ', 'reg_id', _m('Link - ids of regions (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_R_NAME', 'f_h:, ', 'reg_name', _m('Link - names of regions (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_L_ID__', 'f_h:, ', 'lang_id', _m('Link - ids of languages (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_L_NAME', 'f_h:, ', 'lang_name', _m('Link - names of languages (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_L_SNAM', 'f_h:, ', 'lang_short_name', _m('Link - short names of languages (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_CATIDS', 'f_h:, ', 'cat_id', _m('Category ids (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_CATNAM', 'f_h:, ', 'cat_name', _m('Category names (comma separated)')));
        $aliases->addAlias(new AA_Alias('_#L_STATE_', 'f_t', 'cat_state', _m('State of link in this category (visible/highlighted)')));
        $aliases->addAlias(new AA_Alias('_#L_VCOLOR', 'f_e:link_valid', 'cat_id', _m('Link - validity color')));
        $aliases->addAlias(new AA_Alias('_#L_C_PATH', 'l_p:1:::<br>', 'cat_id', _m('Paths to the categories')));
        $aliases->addAlias(new AA_Alias('_#ID_COUNT', 'f_e:itemcount', 'cat_id', _m("Number of found Links")));
        $aliases->addAlias(new AA_Alias('_#EDITLINK', 'f_e:link_edit', 'cat_id', _m('Link to link editing page (for admin interface only)')));
        $aliases->addAlias(new AA_Alias('_#CATEG_GO', 'f_e:link_go_categ', 'cat_id', _m('Category listing with links (for admin interface only)')));

        return $aliases;
    }
}

/** List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php)
 */
function GetCategoryFields() {  // function - we need trnslate _m() on use (not at include time)
    return [
     'id'          => GetFieldDef( _m('Id'),          'links_categories.id',          'numeric', false,           10, 10),
     'name'        => GetFieldDef( _m('Name'),        'links_categories.name',        'text',    false,           20, 20),
     'path'        => GetFieldDef( _m('Path'),        'links_categories.path',        'text',    false,           30, 30),
     'link_count'  => GetFieldDef( _m('Link Count'),  'links_categories.link_count',  'numeric', false,           40, 40),
     'description' => GetFieldDef( _m('Description'), 'links_categories.description', 'text',    false,           50, 50),
     'note'        => GetFieldDef( _m('Note'),        'links_categories.note',        'text',    false,           60, 60),
     'priority'    => GetFieldDef( _m('Priority'),    'links_cat_cat.priority',       'numeric', 'links_cat_cat', 70, 70),
     'state'       => GetFieldDef( _m('State'),       'links_cat_cat.state',          'text',    'links_cat_cat', 80, 80)
    ];
}

/** cached category aliases
 *  @usage new AA_Aliases(new AA_Aliasgenerator_Category())
 */
class AA_Aliasgenerator_Category extends AbstractAliasgenerator {

    public static function getAliasesFromParams(array $params): AA_Aliases {
        $aliases = new AA_Aliases;

        //  Standard aliases
        $aliases->addAlias(new AA_Alias('_#CATEG_ID', 'f_t', 'id', _m('Category id')));
        $aliases->addAlias(new AA_Alias('_#C_NAME__', 'f_t', 'name', _m('Category name')));
        $aliases->addAlias(new AA_Alias('_#C_LCOUNT', 'f_t', 'link_count', _m('Number of links in category')));
        $aliases->addAlias(new AA_Alias('_#C_DESCRI', 'f_t', 'description', _m('Category description')));
        $aliases->addAlias(new AA_Alias('_#C_NOTE__', 'f_t', 'note', _m('Category editor\'s note')));
        $aliases->addAlias(new AA_Alias('_#C_CROSS_', 'l_b', 'path', _m('Crossreferenced category')));
        $aliases->addAlias(new AA_Alias('_#C_PATH__', 'l_p:1:: / :', 'path', _m('Path to current category')));
        $aliases->addAlias(new AA_Alias('_#C_GENERL', 'l_g', 'name', _m('Is this category general one? (1/0)')));
        $aliases->addAlias(new AA_Alias('_#C_GENPRI', 'l_o', 'name', _m('Print category priority, if category is general one.')));

        return $aliases;
    }
}


