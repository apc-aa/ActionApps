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


/** Default number of links on Link Manager page */
define("EDIT_ITEM_COUNT", 10);

/** Number of categories to show on linkedit page */
define('CATEGORIES_COUNT_TO_MANAGE', 15);

/** Category group used for special link field 'type' */
$LINK_TYPE_CONSTANTS = 'Ekolink_obecne_k';

define('LINKS_BASE_CAT','y');
define('LINKS_NOT_BASE_CAT','n');

/**
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 */
$LINKS_FIELDS = array (
    'id'                 => GetFieldDef( _m('Id'),                    'links_links.id',                'numeric'),
    'name'               => GetFieldDef( _m('Name'),                  'links_links.name',              'text'),
    'original_name'      => GetFieldDef( _m('Original name'),         'links_links.original_name',     'text'),
    'description'        => GetFieldDef( _m('Description'),           'links_links.description',       'text'),
    'type'               => GetFieldDef( _m('Link type'),             'links_links.type',              'text'),
    'rate'               => GetFieldDef( _m('Rate'),                  'links_links.rate',              'numeric'),
    'votes'              => GetFieldDef( _m('Votes'),                 'links_links.votes',             'numeric'),
    'created_by'         => GetFieldDef( _m('Author'),                'links_links.created_by',        'text'),
    'created'            => GetFieldDef( _m('Insert date'),           'links_links.created',           'date'),
    'edited_by'          => GetFieldDef( _m('Editor'),                'links_links.edited_by',         'text'),
    'last_edit'          => GetFieldDef( _m('Last edit date'),        'links_links.last_edit',         'date'),
    'checked_by'         => GetFieldDef( _m('Revised by'),            'links_links.checked_by',        'text'),
    'checked'            => GetFieldDef( _m('Revision date'),         'links_links.checked',           'date'),
    'initiator'          => GetFieldDef( _m('E-mail'),                'links_links.initiator',         'text'),
    'url'                => GetFieldDef( _m('Url'),                   'links_links.url',               'text'),
    'voted'              => GetFieldDef( _m('Last vote time'),        'links_links.voted',             'date'),
    'flag'               => GetFieldDef( _m('Flag'),                  'links_links.flag',              'numeric'),
    'note'               => GetFieldDef( _m('Editor\'s note'),        'links_links.note',              'text'),
    'org_city'           => GetFieldDef( _m('Organization city'),     'links_links.org_city',          'text'),
    'org_street'         => GetFieldDef( _m('Organization street'),   'links_links.org_street',        'text'),
    'org_post_code'      => GetFieldDef( _m('Organization post code'),'links_links.org_post_code',     'text'),
    'org_phone'          => GetFieldDef( _m('Organization phone'),    'links_links.org_phone',         'text'),
    'org_fax'            => GetFieldDef( _m('Organization fax'),      'links_links.org_fax',           'text'),
    'org_email'          => GetFieldDef( _m('Organization e-mail'),   'links_links.org_email',         'text'),
    'reg_id'             => GetFieldDef( _m('Region id'),             'links_regions.id',              'numeric', 'regions'),
    'reg_name'           => GetFieldDef( _m('Region name'),           'links_regions.name',            'text',    'regions'),
    'reg_level'          => GetFieldDef( _m('Region level'),          'links_regions.level',           'numeric', 'regions'),
    'lang_id'            => GetFieldDef( _m('Language id'),           'links_languages.id',            'numeric', 'languages'),
    'lang_name'          => GetFieldDef( _m('Language'),              'links_languages.name',          'text',    'languages'),
    'lang_short_name'    => GetFieldDef( _m('Language short name'),   'links_languages.short_name',    'text',    'languages'),
    'cat_id'             => GetFieldDef( _m('Category id'),           'links_link_cat.category_id',    'numeric'),
    'cat_name'           => GetFieldDef( _m('Category name'),         'links_categories.name',         'text'),
    'cat_deleted'        => GetFieldDef( _m('Category deleted'),      'links_categories.deleted',      'numeric'),
    'cat_path'           => GetFieldDef( _m('Category path'),         'links_categories.path',         'text'),
    'cat_link_count'     => GetFieldDef( _m('Category link count'),   'links_categories.link_count',   'numeric'),
    'cat_description'    => GetFieldDef( _m('Category description'),  'links_categories.decsription',  'text'),
    'cat_base'           => GetFieldDef( _m('Base'),                  'links_link_cat.base',           'text'),
    'cat_state'          => GetFieldDef( _m('State'),                 'links_link_cat.state',          'text'),
    'cat_proposal'       => GetFieldDef( _m('Change proposal'),       'links_link_cat.proposal',       'numeric'),
    'cat_proposal_delete'=> GetFieldDef( _m('To be deleted'),         'links_link_cat.proposal_delete','numeric'),
    'cat_priority'       => GetFieldDef( _m('Priority'),              'links_link_cat.priority',       'numeric'),
    'change'             => GetFieldDef( _m('Change'),                'links_changes.rejected',        'numeric', 'changes'));

/**
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 */
$LINKS_SEARCH_FIELDS = array('name','original_name','description','type', 'id','rate','votes',
                             'created_by','created','edited_by','last_edit',
                             'checked_by','checked','initiator','url','voted','note',
                         /*  'org_city','org_street','org_post_code','org_phone','org_fax','org_email', */
                         /*  'flag','reg_id',*/'reg_name',/*'reg_level','lang_id',*/
                             'lang_name','lang_short_name',/*'cat_id',*/'cat_name',
                         /*    'cat_deleted','cat_path','cat_link_count',*/
                             'cat_description'/*,'cat_base','cat_state',
                             'cat_proposal','cat_proposal_delete',
                             'cat_priority','change'*/);

/**
 * List of fields, which will be listed in searchbar in Links Manager (order)
 * (modules/links/index.php3)
 */
$LINKS_ORDER_FIELDS = array( 'name','original_name','description','type', 'id','rate','votes',
                             'created_by','created','edited_by','last_edit',
                             'checked_by','checked','initiator','url','voted','note',
                         /*  'org_city','org_street','org_post_code','org_phone','org_fax','org_email', */
                         /*  'flag','reg_id',*/'reg_name',/*'reg_level','lang_id',*/
                             'lang_name','lang_short_name',/*'cat_id',*/'cat_name',
                         /*    'cat_deleted','cat_path',*/'cat_link_count',
                             'cat_description','cat_base',/*'cat_state',*/
                             'cat_proposal',/*'cat_proposal_delete',
                             'cat_priority',*/'change');

/** Predefined aliases for links. For another aliases use 'inline' aliases. */
$LINK_ALIASES = array(
    "_#LINK_ID_" => GetAliasDef( "f_t",               "id",              _m('Link id')),
    "_#LINK_NAM" => GetAliasDef( "f_t",               "name",            _m('Link name')),
    "_#LINK_ONA" => GetAliasDef( "f_t",               "original_name",   _m('Link original name')),
    "_#LINK_DES" => GetAliasDef( "f_t",               "description",     _m('Link description')),
    "_#LINK_OTY" => GetAliasDef( "f_t",               "type",            _m('Link type')),
    "_#LINK_RAT" => GetAliasDef( "f_t",               "rate",            _m('Link rate')),
    "_#LINK_VOT" => GetAliasDef( "f_t",               "vote",            _m('Link votes')),
    "_#LINK_CRE" => GetAliasDef( "f_t",               "created_by",      _m('Link - created by')),
    "_#LINK_CRD" => GetAliasDef( "f_d:n/j/Y",         "created",         _m('Link creation date')),
    "_#LINK_EDT" => GetAliasDef( "f_t",               "edited_by",       _m('Link - last edited by')),
    "_#LINK_EDD" => GetAliasDef( "f_d:n/j/Y",         "last_edit",       _m('Link - last edit date')),
    "_#LINK_CHE" => GetAliasDef( "f_t",               "checked_by",      _m('Link - checked by')),
    "_#LINK_CHD" => GetAliasDef( "f_d:n/j/Y",         "checked",         _m('Link - last checked date')),
    "_#LINK_EML" => GetAliasDef( "f_m::::mailto:",    "initiator",       _m('Link author\'s e-mail')),
    "_#LINK_URL" => GetAliasDef( "f_t",               "url",             _m('Link url')),
    "_#LINK_LNK" => GetAliasDef( "f_m::::href:",      "url",             _m('Link link')),
    "_#LINK_VOD" => GetAliasDef( "f_d:n/j/Y",         "voted",           _m('Link - last vote date')),
    "_#LINK_FLG" => GetAliasDef( "f_t",               "flag",            _m('Link flag')),
    "_#LINK_NOT" => GetAliasDef( "f_t",               "note",            _m('Link editor\'s note')),
    "_#LINK_OCI" => GetAliasDef( "f_t",               "org_city",        _m('Link organization city')),
    "_#LINK_OST" => GetAliasDef( "f_t",               "org_street",      _m('Link organization street')),
    "_#LINK_OPC" => GetAliasDef( "f_t",               "org_post_code",   _m('Link organization post code')),
    "_#LINK_OPH" => GetAliasDef( "f_t",               "org_phone",       _m('Link organization phone')),
    "_#LINK_OFX" => GetAliasDef( "f_t",               "org_fax",         _m('Link organization fax')),
    "_#LINK_OEM" => GetAliasDef( "f_t",               "org_email",       _m('Link organization e-mail')),
    "_#LINK_R_I" => GetAliasDef( "f_h:, ",            "reg_id",          _m('Link - ids of regions (comma separated)')),
    "_#LINK_R_N" => GetAliasDef( "f_h:, ",            "reg_name",        _m('Link - names of regions (comma separated)')),
    "_#LINK_L_I" => GetAliasDef( "f_h:, ",            "lang_id",         _m('Link - ids of languages (comma separated)')),
    "_#LINK_L_N" => GetAliasDef( "f_h:, ",            "lang_name",       _m('Link - names of languages (comma separated)')),
    "_#LINK_L_S" => GetAliasDef( "f_h:, ",            "lang_short_name", _m('Link - short names of languages (comma separated)')),
    "_#LINK_L_I" => GetAliasDef( "f_t",               "id",              _m('Link - ids of languages')),
    "_#CAT_IDS_" => GetAliasDef( "f_h:, ",            "cat_id",          _m('Category ids (comma separated)')),
    "_#CAT_NAME" => GetAliasDef( "f_h:, ",            "cat_name",        _m('Category names (comma separated)')),
    "_#EDITLINK" => GetAliasDef( "f_e:link_edit",     "cat_id",          _m('Link to link editing page (for admin interface only)')),
    "_#CATEG_GO" => GetAliasDef( "f_e:link_go_categ", "cat_id",          _m('Category listing with links (for admin interface only)'))
);


/**
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 */
$CATEGORY_FIELDS = array(
     'id'=>              GetFieldDef( _m('Id'),          'links_categories.id',          'numeric'),
     'name'=>            GetFieldDef( _m('Name'),        'links_categories.name',        'text'),
     'path'=>            GetFieldDef( _m('Path'),        'links_categories.path',        'text'),
     'link_count'=>      GetFieldDef( _m('Link Count'),  'links_categories.link_count',  'numeric'),
     'description'=>     GetFieldDef( _m('Description'), 'links_categories.description', 'text'),
     'note'=>            GetFieldDef( _m('Note'),        'links_categories.note',        'text'));
?>
