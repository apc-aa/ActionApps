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
define('CATEGORIES_COUNT_TO_MANAGE', 12);

/** Category group used for special link field 'type' */
define('LINK_TYPE_CONSTANTS', 'Ekolink_obecne_k');


/** 
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 */
$LINKS_FIELDS = array( 'id'=>              array( 'name'  => _m('Id'),
                                                  'field'=> 'links_links.id',
                                                  'operators'=> 'numeric'),
                       'name'=>            array( 'name'  => _m('Name'),
                                                  'field'=> 'links_links.name',
                                                  'operators'=> 'text'),
                       'original_name'=>   array( 'name'  => _m('Original name'),
                                                  'field'=> 'links_links.original_name',
                                                  'operators'=> 'text'),
                       'description'=>     array( 'name'  => _m('Description'),
                                                  'field'=> 'links_links.description',
                                                  'operators'=> 'text'),
                       'type'=>            array( 'name'  => _m('Link type'),
                                                  'field'=> 'links_links.type',
                                                  'operators'=> 'text'),
                       'rate'=>            array( 'name'  => _m('Rate'),
                                                  'field'=> 'links_links.rate',
                                                  'operators'=> 'numeric'),
                       'votes'=>           array( 'name'  => _m('Votes'),
                                                  'field'=> 'links_links.votes',
                                                  'operators'=> 'numeric'),
                       'created_by'=>      array( 'name'  => _m('Author'),
                                                  'field'=> 'links_links.created_by',
                                                  'operators'=> 'text'),
                       'created'=>         array( 'name'  => _m('Insert date'),
                                                  'field'=> 'links_links.created',
                                                  'operators'=> 'date'),
                       'edited_by'=>       array( 'name'  => _m('Editor'),
                                                  'field'=> 'links_links.edited_by',
                                                  'operators'=> 'text'),
                       'last_edit'=>       array( 'name'  => _m('Last edit date'),
                                                  'field'=> 'links_links.last_edit',
                                                  'operators'=> 'date'),
                       'checked_by'=>      array( 'name'  => _m('Revised by'),
                                                  'field'=> 'links_links.checked_by',
                                                  'operators'=> 'text'),
                       'checked'=>         array( 'name'  => _m('Revision date'),
                                                  'field'=> 'links_links.checked',
                                                  'operators'=> 'date'),
                       'initiator'=>       array( 'name'  => _m('E-mail'),
                                                  'field'=> 'links_links.initiator',
                                                  'operators'=> 'text'),
                       'url'=>             array( 'name'  => _m('Url'),
                                                  'field'=> 'links_links.url',
                                                  'operators'=> 'text'),
                       'voted'=>           array( 'name'  => _m('Last vote time'),
                                                  'field'=> 'links_links.voted',
                                                  'operators'=> 'date'),
                       'flag'=>            array( 'name'  => _m('Flag'),
                                                  'field'=> 'links_links.flag',
                                                  'operators'=> 'numeric'),
                       'org_city'=>        array( 'name'  => _m('Organization city'),
                                                  'field'=> 'links_links.org_city',
                                                  'operators'=> 'text'),
                       'org_street'=>      array( 'name'  => _m('Organization street'),
                                                  'field'=> 'links_links.org_street',
                                                  'operators'=> 'text'),
                       'org_post_code'=>   array( 'name'  => _m('Organization post code'),
                                                  'field'=> 'links_links.org_post_code',
                                                  'operators'=> 'text'),
                       'org_phone'=>       array( 'name'  => _m('Organization phone'),
                                                  'field'=> 'links_links.org_phone',
                                                  'operators'=> 'text'),
                       'org_fax'=>         array( 'name'  => _m('Organization fax'),
                                                  'field'=> 'links_links.org_fax',
                                                  'operators'=> 'text'),
                       'org_email'=>       array( 'name'  => _m('Organization e-mail'),
                                                  'field'=> 'links_links.org_email',
                                                  'operators'=> 'text'),
                       'reg_id'=>          array( 'name'  => _m('Region id'),
                                                  'field'=> 'links_regions.id',
                                                  'operators'=> 'numeric',
                                                  'table'=> 'regions'),
                       'reg_name'=>        array( 'name'  => _m('Region name'),
                                                  'field'=> 'links_regions.name',
                                                  'operators'=> 'text',
                                                  'table'=> 'regions'),
                       'reg_level'=>       array( 'name'  => _m('Region level'),
                                                  'field'=> 'links_regions.level',
                                                  'operators'=> 'numeric',
                                                  'table'=> 'regions'),
                       'lang_id'=>         array( 'name'  => _m('Language id'),
                                                  'field'=> 'links_languages.id',
                                                  'operators'=> 'numeric',
                                                  'table'=> 'languages'),
                       'lang_name'=>       array( 'name'  => _m('Language'),
                                                  'field'=> 'links_languages.name',
                                                  'operators'=> 'text',
                                                  'table'=> 'languages'),
                       'lang_short_name'=> array( 'name'  => _m('Language short name'),
                                                  'field'=> 'links_languages.short_name',
                                                  'operators'=> 'text',
                                                  'table'=> 'languages'),
                       'cat_id'=>           array( 'name'  => _m('Category id'),
                                                  'field'=> 'links_link_cat.category_id',
                                                  'operators'=> 'numeric'),
                       'cat_name'=>        array( 'name'  => _m('Category name'),
                                                  'field'=> 'links_categories.name',
                                                  'operators'=> 'text'),
                       'cat_deleted'=>     array( 'name'  => _m('Category deleted'),
                                                  'field'=> 'links_categories.deleted',
                                                  'operators'=> 'numeric'),
                       'cat_path'=>        array( 'name'  => _m('Category path'),
                                                  'field'=> 'links_categories.path',
                                                  'operators'=> 'text'),
                       'cat_link_count'=>  array( 'name'  => _m('Category link count'),
                                                  'field'=> 'links_categories.link_count',
                                                  'operators'=> 'numeric'),
                       'cat_description'=> array( 'name'  => _m('Category description'),
                                                  'field'=> 'links_categories.decsription',
                                                  'operators'=> 'text'),
                       'cat_base'=>        array( 'name'  => _m('Base'),
                                                  'field'=> 'links_link_cat.base',
                                                  'operators'=> 'text'),
                       'cat_state'=>       array( 'name'  => _m('State'),
                                                  'field'=> 'links_link_cat.state',
                                                  'operators'=> 'text'),
                       'cat_proposal'=>    array( 'name'  => _m('Change proposal'),
                                                  'field'=> 'links_link_cat.proposal',
                                                  'operators'=> 'numeric'),
                       'cat_proposal_delete'=> array( 'name'  => _m('To be deleted'),
                                                  'field'=> 'links_link_cat.proposal_delete',
                                                  'operators'=> 'numeric'),
                       'cat_priority'=>    array( 'name'  => _m('Priority'),
                                                  'field'=> 'links_link_cat.priority',
                                                  'operators'=> 'numeric'),
                       'change'=>          array( 'name'  => _m('Change'),
                                                  'field'=> 'links_changes.rejected',
                                                  'operators'=> 'numeric',
                                                  'table'=> 'changes'));

                                                  
                                                  
                                                  
                                                  
                                                  
/** 
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 */
$LINKS_SEARCH_FIELDS = array('name','original_name','description','type', 'id','rate','votes',
                             'created_by','created','edited_by','last_edit',
                             'checked_by','checked','initiator','url','voted',
                         /*  'org_city','org_street','org_post_code','org_phone','org_fax','org_email', */                                                  
                         /*  'flag','reg_id',*/'reg_name',/*'reg_level','lang_id',*/
                             'lang_name','lang_short_name',/*'cat_id',*/'cat_name',
                         /*    'cat_deleted','cat_path',*/'cat_link_count',
                             'cat_description','cat_base',/*'cat_state',*/
                             'cat_proposal',/*'cat_proposal_delete',
                             'cat_priority',*/'change');

/** 
 * List of fields, which will be listed in searchbar in Links Manager (order)
 * (modules/links/index.php3)
 */
$LINKS_ORDER_FIELDS = array( 'name','original_name','description','type', 'id','rate','votes',
                             'created_by','created','edited_by','last_edit',
                             'checked_by','checked','initiator','url','voted',
                         /*  'org_city','org_street','org_post_code','org_phone','org_fax','org_email', */                                                  
                         /*  'flag','reg_id',*/'reg_name',/*'reg_level','lang_id',*/
                             'lang_name','lang_short_name',/*'cat_id',*/'cat_name',
                         /*    'cat_deleted','cat_path',*/'cat_link_count',
                             'cat_description','cat_base',/*'cat_state',*/
                             'cat_proposal',/*'cat_proposal_delete',
                             'cat_priority',*/'change');

/** 
 * Predefined aliases for links. For another aliases use 'inline' aliases. 
 */
$LINK_ALIASES = array( 
    "_#LINK_ID_" => array("fce" => "f_t",
                          "param" => "id",
                          "hlp" => _m('Link id')),
    "_#LINK_NAM" => array("fce" => "f_t",
                          "param" => "name",
                          "hlp" => _m('Link name')),
    "_#LINK_ONA" => array("fce" => "f_t",
                          "param" => "original_name",
                          "hlp" => _m('Link original name')),
    "_#LINK_DES" => array("fce" => "f_t",
                          "param" => "description",
                          "hlp" => _m('Link description')),
    "_#LINK_OTY" => array("fce" => "f_t",
                          "param" => "type",
                          "hlp" => _m('Link type')),
    "_#LINK_RAT" => array("fce" => "f_t",
                          "param" => "rate",
                          "hlp" => _m('Link rate')),
    "_#LINK_VOT" => array("fce" => "f_t",
                          "param" => "vote",
                          "hlp" => _m('Link votes')),
    "_#LINK_CRE" => array("fce" => "f_t",
                          "param" => "created_by",
                          "hlp" => _m('Link - created by')),
    "_#LINK_CRD" => array("fce" => "f_d:n/j/Y",
                          "param" => "created",
                          "hlp" => _m('Link creation date')),
    "_#LINK_EDT" => array("fce" => "f_t",
                          "param" => "edited_by",
                          "hlp" => _m('Link - last edited by')),
    "_#LINK_EDD" => array("fce" => "f_d:n/j/Y",
                          "param" => "last_edit",
                          "hlp" => _m('Link - last edit date')),
    "_#LINK_CHE" => array("fce" => "f_t",
                          "param" => "checked_by",
                          "hlp" => _m('Link - checked by')),
    "_#LINK_CHD" => array("fce" => "f_d:n/j/Y",
                          "param" => "checked",
                          "hlp" => _m('Link - last checked date')),
    "_#LINK_EML" => array("fce" => "f_m::::mailto:",
                          "param" => "initiator",
                          "hlp" => _m('Link author\'s e-mail')),
    "_#LINK_URL" => array("fce" => "f_m::::href:",
                          "param" => "url",
                          "hlp" => _m('Link url')),
    "_#LINK_VOD" => array("fce" => "f_d:n/j/Y",
                          "param" => "voted",
                          "hlp" => _m('Link - last vote date')),
    "_#LINK_FLG" => array("fce" => "f_t",
                          "param" => "flag",
                          "hlp" => _m('Link flag')),
    "_#LINK_OCI" => array("fce" => "f_t",
                          "param" => "org_city",
                          "hlp" => _m('Link organization city')),
    "_#LINK_OST" => array("fce" => "f_t",
                          "param" => "org_street",
                          "hlp" => _m('Link organization street')),
    "_#LINK_OPC" => array("fce" => "f_t",
                          "param" => "org_post_code",
                          "hlp" => _m('Link organization post code')),
    "_#LINK_OPH" => array("fce" => "f_t",
                          "param" => "org_phone",
                          "hlp" => _m('Link organization phone')),
    "_#LINK_OFX" => array("fce" => "f_t",
                          "param" => "org_fax",
                          "hlp" => _m('Link organization fax')),
    "_#LINK_OEM" => array("fce" => "f_t",
                          "param" => "org_email",
                          "hlp" => _m('Link organization e-mail')),
    "_#LINK_R_I" => array("fce" => "f_h:, ",
                          "param" => "reg_id",
                          "hlp" => _m('Link - ids of regions (comma separated)')),
    "_#LINK_R_N" => array("fce" => "f_h:, ",
                          "param" => "reg_name",
                          "hlp" => _m('Link - names of regions (comma separated)')),
    "_#LINK_L_I" => array("fce" => "f_h:, ",
                          "param" => "lang_id",
                          "hlp" => _m('Link - ids of languages (comma separated)')),
    "_#LINK_L_N" => array("fce" => "f_h:, ",
                          "param" => "lang_name",
                          "hlp" => _m('Link - names of languages (comma separated)')),
    "_#LINK_L_S" => array("fce" => "f_h:, ",
                          "param" => "lang_short_name",
                          "hlp" => _m('Link - short names of languages (comma separated)')),
    "_#LINK_L_I" => array("fce" => "f_t",
                          "param" => "id",
                          "hlp" => _m('Link - ids of languages')),
    "_#CAT_IDS_" => array("fce" => "f_h:, ",
                          "param" => "cat_id",
                          "hlp" => _m('Category ids (comma separated)')),
    "_#CAT_NAME" => array("fce" => "f_h:, ",
                          "param" => "cat_name",
                          "hlp" => _m('Category ids (comma separated)')),
    "_#EDITLINK" => array("fce" => "f_e:link_edit",
                          "param" => "cat_id",
                          "hlp" => _m('Link to link editing page (for admin interface only)')),
    "_#CATEG_GO" => array("fce" => "f_e:link_go_categ",
                          "param" => "cat_id",
                          "hlp" => _m('Category listing with links (for admin interface only)'))
);                              
?>
