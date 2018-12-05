<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

use AA\FormArray;
use AA\Widget\Widget;

/** Saver / Grabber API
 *
 *   Defines AA_Grabber base class, which is used as abstraction for data input
 *   From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 */


/** Saver class - Used for filling items into AA
 *  @param AA_Grabber grabber          - provides source for item data
 *  @param $transformations - defines, how the source item should be transformed
 *                            into destination item
 */
class AA_Saver {
    protected $grabber;            /** @var AA_Grabber the object which deliveres the data */
    protected $transformations;    /** describes, what to do with data before storing */
    protected $slice_id;           /** id of destination slice */
    protected $store_mode;         /** store-policy - how to store - by_grabber | update | update_silent | update/insert | add | overwrite | insert_as_new | insert_new | insert | insert_if_new */
    protected $id_mode;            /** id-policy    - how to construct id - old | new | combined */

    private   $_new_ids        = array();  /** array of newly inserted ids (after run()) - the long ones */
    private   $_updated_ids    = array();  /** array of ids of rewritten items (after run()) - the long ones */
    private   $_updated_slices = array();  /** array of slice ids with rewritten items (after run()) - the long ones */

    protected $_messages       = array();


    /** AA_Saver function
     * @param AA_Grabber $grabber
     * @param AA_Transformation[] $transformations
     * @param string $slice_id
     * @param string $store_mode
     * @param string $id_mode
     */
    function __construct($grabber, $transformations=null, $slice_id=null, $store_mode='overwrite', $id_mode='old') {
        $this->grabber         = $grabber;
        $this->transformations = is_array($transformations) ? $transformations : array();
        $this->slice_id        = $slice_id;
        $this->store_mode      = $store_mode;
        $this->id_mode         = $id_mode;
    }

    /** resets item / slice counters */
    function clear_ids() {
        $this->_new_ids        = array();
        $this->_updated_ids    = array();
        $this->_updated_slices = array();
    }

    /** run function
     * Now import all items to the slice
     * @param bool $fire
     * @return int count of changed items
     */
    function run( $fire = true ) {

        $this->grabber->prepare();    // maybe some initialization in grabber

        // returns grabber prepared item of just parsed source structure, which have to be converted to item by transformations?
        $directfill = $this->grabber->isDirectfill();

        $ok_count = 0;

        while ($source_content4id = $this->grabber->getItem()) {

            if (!$fire) {
                $this->message("AA_Saver->run(): grabbed item: ");
                $this->message($source_content4id);
            }

            // check, if grabber returns already prepared item
            $content4id = $directfill ? $source_content4id : new ItemContent();

            // if we want some Transformations, do them here
            foreach($this->transformations as $field_id => $transformation) {
                $field_content = $transformation->transform($field_id, $source_content4id);
                if (!$field_content) {
                    // no need to change, or something goes wrong - missing parameter for transformation, ....
                    continue;
                }
                $field_content->removeDuplicates();
                $content4id->setAaValue($field_id, $field_content);
            }

            // now the $content4id should be filled in both cases - directfill as well as indirect

            $id_mode    = ($this->id_mode    == 'by_grabber') ? $this->grabber->getIdMode()    : $this->id_mode;
            $store_mode = ($this->store_mode == 'by_grabber') ? $this->grabber->getStoreMode() : $this->store_mode;

            if (!$fire) {
                $this->message("AA_Saver->run(): id_mode: $id_mode, store_mode: $store_mode");
            }

            switch ($id_mode) {
                // Create new item id (always the same for item-slice pair)
                case 'combined' : $new_item_id = string2id($content4id->getItemID(). $this->slice_id); break;

                // Use id from source
                case 'old'      : $new_item_id = $content4id->getItemID(); break;

                // Generate completely new id
                //default         :
                //case 'new'      : $new_item_id = new_id();                 break;


                case ''         :
                case 'new'      : $new_item_id = new_id();                 break;

                default         :
                    // should be allowed just to slice admins (for security reasons)
                    if ($store_mode == 'update/insert') {
                        // this allows you to update item based on the key fields.
                        // if $id_mode is ["relation.......1","year............"], then saver searches the slice for the item matching the two fields.
                        // If found, then the item is updated
                        // If not found, the item is_a created, but only with fields if $content4id (not whole insert)

                        $set           = new AA_Set(array($this->slice_id));
                        if (($id_mode[0] == '[') AND is_array($flds = json_decode($id_mode))) {
                            foreach ($flds as $fld) {
                                $set->addCondition( new AA_Condition($fld, '=', $content4id->getValue($fld)) );
                            }
                            $zids = $set->query(null, 1);
                            if (!$fire) {
                                $this->message("AA_Saver->run(): update/insert - condition:");
                                $this->message($set);
                                $this->message($zids);
                            }
                            $new_item_id = $zids->count() ? $zids->longids(0) : new_id();

                            break;
                        }
                    }
                    $new_item_id = new_id();
            }

            $old_item_id = $content4id->getItemID();

            // set the item to be recevied from remote node
            $content4id->setItemID($new_item_id);

            // @todo - move to translations
            if ( !is_null($this->slice_id) ) {
                // for AA_Grabber_Form we have the slice_id already filled
                $content4id->setSliceID($this->slice_id);
            }

            AA::$slice_id = $content4id->getSliceID();   // pass validator the current slice of added item for slice unique

            if (($store_mode == 'overwrite') OR (substr($store_mode,0,6) == 'insert')) {
                if (!$content4id->complete4Insert()) {
                    $this->message("AA_Saver->run(): complete4Insert failed: ". ItemContent::lastErrMsg());
                    continue;
                }
            } else { // @todo do validation for updates
                if (!$content4id->validate4Update()) {
                    $this->message("AA_Saver->run(): complete4Insert failed: ". ItemContent::lastErrMsg());
                    continue;
                }
            }

            $this->_updated_slices[$content4id->getOwnerId()] = true;

            if ($fire ) {
                // id_mode - overwrite or insert_if_new
                // (the $new_item_id should not be changed by storeItem)
                if (!($new_item_id = $content4id->storeItem($store_mode))) {     // invalidatecache, feed
                    // AA_Saver->run(): storeItem failed or skiped duplicate:
                    $this->message(ItemContent::lastErrMsg());
                } else {
                    // @todo better check of new ids
                    if ($store_mode == 'insert') {
                        $this->_new_ids[] = $new_item_id;
                    } else {
                        $this->_updated_ids[] = $new_item_id;
                    }
                    $ok_count++;

                    // Update relation table to show where came from
                    if ($new_item_id AND $old_item_id AND ($new_item_id != $old_item_id)) {
                        AddRelationFeed($new_item_id, $old_item_id, REL_FLAG_SOURCE);
                    }
                }
            } else {
                $this->message($new_item_id);
                $this->message($content4id);
            }
        } // while grabber->getItem()
        $this->grabber->finish();    // maybe some finalization in grabber
        print($this->report());
        return $ok_count;
    }

    /** Returns array of long new saved ids */
    function newIds() {
        return $this->_new_ids;
    }

    function changedIds() {
        return array_merge($this->_new_ids, $this->_updated_ids);
    }

    function changedModules() {
        return array_keys($this->_updated_slices);
    }

    /** toexecutelater function
     *  Toexecutelater - special function called from toexecute class
     *  - used for queued tasks (runed from cron)
     *  You can use $toexecute->later($saver, array(), 'feed_external') instead
     *  of calling $saver->run() - the saving will be planed for future
     */
    function toexecutelater() {
        return $this->run();
    }

    /** message function
     *  Records Error/Information message
     * @param $text
     */
    function message($text) {
        $this->_messages[] = (is_object($text) && !is_callable(array($text,"__toString"))) ? print_r($text, true) : $text;
    }

    /** report function
     * Print Error/Information messaages
     */
    function report()       {
        return join("<br>\n", $this->_messages);
    }
    /** clear_report function
     *
     */
    function clear_report() {
        unset($this->_messages);
        $this->_messages = array();
    }
}


/** AA_Grabber - Base class, which is used as abstraction for data input
 *  From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 *
 *  @todo this class should be abstract after we switch to PHP5
 */
class AA_Grabber {
    protected $_messages = array();

    /** */
    static function factoryFromFile($file) {
        $grabbers = AA_Components::getClassNames('AA_Grabber');
        exit;
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations */
    function isDirectfill() { return true; }

    /** name function
     *  Name of the grabber - used for grabber selection box
     */
    function name() {}

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() {}

    /** htmlSetting function
     *  HTML code for parameters - defines parameters of this grabber.
     *  Each grabber could have its own parameters (like separator for CSV, ...)
     * @param $input_prefix
     * @param $params
     * @return string
     */
    function htmlSetting($input_prefix, $params) {     // $input_prefix - here grabber_id
        $properties = static::getClassProperties();
        $form = new AA_Form;
        foreach ( $properties as $id => $property) {
            $property->addPropertyFormrows($form);
        }
        $content = new AA_Content();
        $content->setId($input_prefix);
        return $form->getRowsHtml($content);
    }

    /** getClassProperties function of AA_Serializable - used for htmlSetting()
     */
    static function getClassProperties() { return array(); }

    /** getItem function
     *  Method called by the AA_Saver to get next item from the data input
     * @return ItemContent
     */
    function getItem() {}

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     *  @see also getStoreMode() method
     */
    function getIdMode() {
        return 'combined';
    }

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     *  @see also getIdMode() method
     */
    function getStoreMode() {
        return 'insert_if_new';
    }

    /** prepare function
     *  Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {}

    /** get data encoding - like UTF-8, windows-1250, ... */
    function getCharset() {
        return 'UTF-8';
    }

    /** finish function
     *  Function called by AA_Saver after we get the last item from the data
     *  input
     */
    function finish()  {}

    /** message function
     *  Records Error/Information message
     * @param $text
     */
    function message($text) {
        $this->_messages[] = $text;
    }

    /** report function
     * Print Error/Information messaages
     */
    function report()       {
        return join('<br>', $this->_messages);
    }
    /** clear_report function
     *
     */
    function clear_report() {
        unset($this->_messages);
        $this->_messages = array();
    }
}

/** AA_Grabber_XML
 */
class AA_Grabber_Xml extends AA_Grabber {

    protected $file;                /** file to grab - internal array */
    protected $item_xpath;
    private   $_items;

    function __construct($file, $item_xpath='') {
        $this->file       = $file;
        $this->item_xpath = $item_xpath;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('XML'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('General XML file'); }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations */
    function isDirectfill() { return false; }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     */
    static function getClassProperties() {
        $properties = array();//                        id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['file']       = new AA_Property( 'file',        _m("URL of the file"), 'string', false, true);
        $properties['item_xpath'] = new AA_Property( 'item_xpath',  _m("XPath to one item"), 'string', false, true, 'string', false, _m("Specify, how to grab one item from the file using XPath expression"), '', _m("//item"));
        return $properties;
    }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        if (false === ($data = file_get_contents($this->file))) {
            throw new Exception('file not loaded: '. $this->file);
        }
        // remove namespaces (it is pain to work with it in simple XML interface)
        // $data = str_replace(array('<rsp:', '</rsp:'), array('<rsp_', '</rsp_'), $data);
        if (false === ($xml  = simplexml_load_string($data))) {
            throw new Exception('xml parse error: '. $data);
        }

        if ($this->item_xpath) {
            $splitted = $xml->xpath($this->item_xpath);
        } else {
            $splitted = $xml;
            //$items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        }

        $this->_items = array();
        foreach ($splitted as $item) {
            $this->_items[] = $item;
        }
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {

        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        // huhl($this,count($this->_items), $i, $polozka->asXML());
        // if (!--$i) {
        //     exit;
        // }

        $item = new ItemContent();
        $item->setValue('initem', $polozka->asXML());    // stav importu
        return $item;
    }
}

/** AA_Grabber_Csv - CSV (Comma Separated Values) format grabber */
class AA_Grabber_Csv extends AA_Grabber {

    protected $file;                /** file to grab - internal array */
    protected $item_xpath;


    /** name function
     * Name of the grabber - used for grabber selection box
     */
    function name() {
        return _m('CSV');
    }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() {
        return _m('Import data from CSV (Comma Separated Values) format');
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     */
    static function getClassProperties() {
        $properties = array();   //                   id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['file']      = new AA_Property( 'file',       _m("URL of the file"), 'string', false, true);
        $properties['delimiter'] = new AA_Property( 'delimiter',  _m("Delimiter of fields"), 'string', false, true, array('enum',array(','  => ', (comma)', ';'  => '; (semicolon)', '\t' => '\t (tabulator)', '|'  => '| (pipe)', '~'  => '~ (tilde)', ''   => 'other')), false, '', '', ',');
        $properties['enclosure'] = new AA_Property( 'enclosure',  _m("Enclosure"), 'string', false, true, array('enum',array('"'  => '" (double quote )', "'"  => '\' (single quote)')), false, '', '', '"');
        $properties['caption']   = new AA_Property( 'caption',    _m("Use first row as field names"), 'bool', false, true);
        return $properties;
    }


    /** getItem function
     * Method called by the AA_Saver to get next item from the data input
     */
    function getItem() {}

    /** prepare function
     *  Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {}

    /** finish function
     *  Function called by AA_Saver after we get the last item from the data
     *  input
     */
    function finish()  {}
}


class AA_Grabber_Aarss extends AA_Grabber {
    protected $feed_id;
    protected $feed;
    protected $name;
    protected $slice_id;
    protected $aa_rss;
    protected $channel;
    protected $map;
    protected $cat_field_id;
    protected $status_code_id;
    protected $ext_categs;
    protected $l_categs;
    protected $r_slice_id;
    protected $fire;

    /** AA_Grabber_Aarss function
     * @param $feed_id
     * @param $feed
     * @param $fire
     */
    function __construct($feed_id, &$feed, $fire) {

        /** Process one feed and returns parsed RSS (both AA and other)
         *  in $this->aa_rss */
        $this->slice_id   = unpack_id($feed['slice_id']);        // local slice id
        $this->r_slice_id = unpack_id($feed['remote_slice_id']); // remote slice id
        $this->feed_id    = $feed_id;
        $this->feed       = $feed;
        $this->fire       = $fire;
    }

    /** name function
     *
     */
    function name() {
        return _m("AA RSS");
    }
    /** description function
     *
     */
    function description() {
        return _m("Grabs data from generic RSS or AA RSS (used for item exchange between different AA installations)");
    }
    /** setUrl function
     * @param $url
     */
    function setUrl($url) {
        $this->feed['server_url']  = $url;
    }
    /** setTime function
     * @param $time
     */
    function setTime($time) {
        $this->feed['newest_item'] = $time;
    }
    /** _getRssData function
     *
     */
    function _getRssData() {
        return trim(http_fetch($this->feed['server_url']),"\xef\xbb\xbf \t\n\r\0\x0B"); // remove also BOM (the first three characters)
    }
    /** _getApcData function
     *
     */
    function _getApcData() {
        // for APC feeds we need to list all categories, which we want receive
        $this->ext_categs = GetExternalCategories($this->feed_id); // we will need it later

        // select external categories in format
        // array('unpacked_cat_id'=> array( 'value'=>, 'name'=>, 'approved'=>, 'target_category_id'=>))
        $cat_ids = array();
        if ($this->ext_categs AND is_array($this->ext_categs)) {
            foreach ( $this->ext_categs as $ext_cat_id => $ext_cat ) {
                if ( $ext_cat['target_category_id'] ) {  // the feeding is set for this categor
                    $cat_ids[] = $ext_cat_id;
                }
            }
        }

        /* Mention, that $cat_ids now contain also AA_Other_Categor, which is
           used on oposite site of feeding as command to send all categories.
           The category list is sent from historical reasons (AA before 2.8
           do not send category informations without this array().
           Current AA use another approach for APC feeds - we will get all items
           regardless on category. The filtering we will do after that.
           This approach means more data to be transfered, but on the other hand
           there is no need to update filters after any category addition
           (Honzam 04/26/04) */

        // now we have cat_ids[] array => we can ask for data
        $categories2fed = implode(" ", $cat_ids);

        return xml_fetch($this->feed['server_url'], ORG_NAME, $this->feed['password'], $this->feed['user_id'], $this->r_slice_id, $this->feed['newest_item'], $categories2fed);
    }
    /** _getExactData function
     *
     */
    function _getExactData() {
        global $debugfeed;

        // get local item list (with last edit times)
        $slice       = AA_Slice::getModule($this->slice_id);
        $local_list  = new LastEditList();
        $local_list->setFromSlice('', $slice);  // no conditions - all items
        $local_pairs = $local_list->getPairs();

        if ($debugfeed > 8) {
            huhl('_getExactData() - Local pairs:', $local_pairs);
        }

        $base                    = array();
        $base["node_name"]       = ORG_NAME;
        $base["password"]        = $this->feed['password'];
        $base["user"]            = $this->feed['user_id'];
        $base["slice_id"]        = $this->r_slice_id;
        $base["start_timestamp"] = $this->feed['newest_item'];
        $base["exact"]           = 1;

        $init = $base;
        $init['conds[0][last_edit.......]'] = 1;
        $init['conds[0][value]']            = iso8601_to_unixstamp($this->feed['newest_item']);
        $init['conds[0][operator]']         = '>=';

        $remote_list  = new LastEditList();
        $remote_list->setList(http_fetch($this->feed['server_url'], $init));
        $remote_pairs = $remote_list->getPairs();

        if ($debugfeed > 8) {
            huhl('_getExactData() - Remote pairs:', $remote_pairs);
        }

        // Get all ids, which was updated later than items in local slice
        $ids = array();   // initialize
        foreach ($remote_pairs as $id => $time) {
            if (!isset($local_pairs[$id]) OR ($local_pairs[$id] < $time)) {
                $ids[] = $id;  // array of ids to ask for
            }
        }

        if ($debugfeed >= 2) {
            huhl(' Local items: ', count($local_pairs), ' Remote items: ', count($remote_pairs), ' Asked for update: ', count($ids));
        }

        // No items to fed?
        if (count($ids) <= 0) {
            return '';
        }

        $finish        = $base;
        $finish['ids'] = implode('-',$ids);

        if ($debugfeed > 8) {
            huhl('_getExactData() - http_fetch:', $this->feed['server_url'], $finish);
        }

        return http_fetch($this->feed['server_url'], $finish);
    }

    /** prepare function
     *  Fetch data and parse it
     */
    function prepare() {
        global $DEFAULT_RSS_MAP, $debugfeed;

        // just shortcut
        $feed_type = $this->feed['feed_type'];

        set_time_limit(240); // Allow 4 minutes per feed

        $slice           = AA_Slice::getModule($this->slice_id);
        $feed_debug_name = 'Feed #'. $this->feed_id .' ('. getFeedTypeName($feed_type).'): '.
                           $this->feed['name'] .' : ' .$this->feed['remote_slice_name'].
                           ' -> '.$slice->name();
        // Get XML Data
        if ($debugfeed >= 1) {
            print("\n<br>$feed_debug_name");
        }

        switch($feed_type) {
            case FEEDTYPE_RSS:   $xml_data = $this->_getRssData();   break;
            case FEEDTYPE_EXACT: $xml_data = $this->_getExactData(); break;
            case FEEDTYPE_APC:
            default:             $xml_data = $this->_getApcData();   break;
        }

        // Special option - it only dispays fed data
        if ($this->fire == 'display') {
            echo $xml_data;
            return false;
        }

        if (!$xml_data) {
            AA_Log::write("CSN","No data returned for $feed_debug_name");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name: no data returned");
            return false;
        }
        if ($debugfeed >= 8) huhl("Fetched data=",myspecialchars($xml_data));

        // if an error occured, write it to the LOG
        if (($first_char=substr($xml_data,0,1)) != "<") {
            AA_Log::write("CSN","Feeding mode ($feed_debug_name): $xml_data");
            if ($debugfeed >= 1) {
                print("\n<br>$feed_debug_name:bad data returned (first character &quot;$first_char&quot; (ord=".ord($first_char).") is not &quot;&lt;&quot;):$xml_data");
            }
            return false;
        }

        /** $g_slice_encoding is passed to aa_rss_parse() - it defines output character encoding */
        $GLOBALS['g_slice_encoding'] = $slice->getCharset();

        if (!( $this->aa_rss = aa_rss_parse( $xml_data ))) {
            AA_Log::write("CSN","Feeding mode ($feed_debug_name): Unable to parse XML data");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name:".$this->feed['server_url'].":unparsable: <hr>".myspecialchars($xml_data)."<hr>");
            return false;
        }

        if ($debugfeed >= 5) {
            print("\n<br>Parses ok");
        }

        //  --- output parsed - great! - we are going to store

        $this->l_categs   = GetGroupConstants($this->slice_id);       // category definitions
                                                          // - used only for FEEDTYPE_APC

        if ($feed_type == FEEDTYPE_APC) {
            // Update the slice categories in the ef_categories table,
            // that is, if the set of possible slice categories has changed
            updateCategories($this->feed_id, $this->l_categs, $this->ext_categs, $this->aa_rss['channels'][$this->r_slice_id]['categories'], $this->aa_rss['categories']);
        }

        if (($feed_type == FEEDTYPE_APC) OR ($feed_type == FEEDTYPE_EXACT)) {
            //Update the field names and add new fields to feedmap table
            updateFieldsMapping($this->slice_id, $this->r_slice_id, $this->aa_rss['channels'][$this->r_slice_id]['fields'],$this->aa_rss['fields']);
        }

        // Find channel definition
        if (!($this->channel = $this->aa_rss['channels'][$this->r_slice_id])) {
            foreach ($this->aa_rss['channels'] as $ch ) {
                if ($this->channel = $ch) {  // assignment
                   break;
                }
            }
        }

        list(,$map) = GetExternalMapping($this->slice_id,$this->r_slice_id);
        if ($debugfeed >= 7) {
            print("\n<br>Mapping: $map, $this->slice_id, $this->r_slice_id");
        }
        if (!$map && ($feed_type == FEEDTYPE_RSS)) {
            if ($debugfeed >= 2) {
                print("\n<br>using default mapping");
            }
            $map = $DEFAULT_RSS_MAP;
        }
        $this->map = $map;

        // Use the APC specific fields from the item
        if ($feed_type == FEEDTYPE_APC) {
            $this->cat_field_id   = GetBaseFieldId( $this->aa_rss['fields'], "category" );
            $this->status_code_id = GetBaseFieldId( $this->aa_rss['fields'], "status_code" );
        }

        if (is_array($this->aa_rss['items'])) {
            reset($this->aa_rss['items']);
        }

    }
    /** getItem function
     *
     */
    function getItem() {
        if (!is_array($this->aa_rss['items'])) {
            return false;
        }

        if (!($item = current($this->aa_rss['items']))) {
            return false;
        }
        $item_id = key($this->aa_rss['items']);

        // A series of steps to make field specific edits
        // set fulltext field back from the content field, where it was put by
        // APC for RSS compatability
        if ($fulltext_field_id = GetBaseFieldId($this->aa_rss['fields'],"full_text")) {
            $item['fields_content'][$fulltext_field_id][0] = contentvalue($item);
        }

        /** Apply filters - rename categories and bin (approved/holding/trash) */
        if ($this->feed['feed_type'] == FEEDTYPE_APC) { // Use the APC specific fields from the item

            // apply categories mapping. $item is updated accordingly
            $approved = translateCategories( $this->cat_field_id, $item, $this->ext_categs, $this->l_categs );

            // set status_code - according to the settings of ef_categories table
            // RSS feeds have approved set from DEFAULT_RSS_MAP
            $item['fields_content'][$this->status_code_id][0]['value'] = $approved ? 1 : 2;
        }


        // create item from source data (in order we can unalias)
        $item2fed = new AA_Item($item['fields_content'], array());

        foreach ( $this->map as $to_field_id => $v) {
            switch ($v['feedmap_flag']) {
                case FEEDMAP_FLAG_VALUE:
                            // value could contain {switch()} and other {constructs}
                            $content4id[$to_field_id][0]['value'] = $item2fed->unalias($v['value']);
                            break;
                case FEEDMAP_FLAG_EXTMAP:   // Check this really works when val in from_field_id
                case FEEDMAP_FLAG_RSS:
                            $values = map1field($v['value'],$item,$this->channel);
                            if (isset($values) && is_array($values)) {
                                foreach($values as $k=>$v2) {
                                    $values[$k]['value'] = $v2['value'];
                                }
                                $content4id[$to_field_id] = $values;
                            }
                            break;
            } // switch
        }

        next($this->aa_rss['items']);

        $ic = new ItemContent($content4id);
        $ic->setValue('externally_fed..', $this->feed['name']);  // TODO - move one layer up - to saver transactions
        $ic->setItemID($item_id);
        return $ic;
    }
    /** finish function
     *
     */
    function finish() {
        if ($this->feed['feed_type'] == FEEDTYPE_APC) {
            $db = getDB();
            //update the newest item
            $SQL = "UPDATE external_feeds SET newest_item='".quote($this->aa_rss['channels'][$this->r_slice_id]['timestamp'])."'
                     WHERE feed_id='".quote($this->feed_id)."'";
            $db->query($SQL);
            freeDB($db);
        }
    }
}

/** AA_Grabber_Form - Grabbs data POSTed by AA form
*
*  The format of the data is followiing
*  (this is new format, which allows to fill or modify more items at once
*
*   Format is:
*       aa[i<long_item_id>][modified_field_id][]
*   Note:
*      first brackets contain
*          'u'+long_item_id when item is edited (the field is rewriten, rest
*                           of item is untouched)
*          'i'+long_item_id when item is edited (the value is added to current
*                           value of the field, rest of item is untouched)
*          'n<number>_long_slice_id' if you want to add the item to slice_id
*                                    <number> is used to add more than one
*                                    item at the time
*      modified_field_id is field_id, where all dots are replaced by '_'
*      we always add [] at the end, so it becames array at the end
*   Example:
*       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
*       aa[i63556a45e4e67b654a3a986a548e8bc9][relation_______1][]
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
*
*   There could be also compound widgets, which consists from more than one
*   input - just like date selector. In such case we use following syntax:
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
*   where "dte" points to the AA\Widget\DteWidget. The method AA\Widget\DteWidget::getValue()
*   is called to grab the value (or multivalues) from the submitted form
*/
class AA_Grabber_Form extends AA_Grabber {
    private   $_items;
    private   $_last_store_mode;

    function __construct() {
        $this->_items = array();
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('Form'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Grabbs data POSTed by AA form'); }

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     *  @see also getStoreMode() method
     */
    function getIdMode() {
        return 'old';
    }

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     *  @see also getIdMode() method
     */
    function getStoreMode() {
        switch ($this->_last_store_mode) {
            case 'add':    return 'add';
            case 'update': return 'update';
        }
        // case 'new':
        return 'insert';
    }

    private function rearangeAaFilesArray(array $files) {
        $ret = array();
        foreach ($files as $type => $items) {
            foreach ($items as $item => $fields) {
                foreach ($fields as $field => $f) {
                    foreach ((array)$f['fil']['var'] as $k => $value) {
                            $arr = array($item=>array($field=>array('fil'=>array('var'=>array("_$k"=>array($type => $value))))));
                            $ret = array_merge_recursive( $ret, $arr);

                    }
                }
            }
        }
        return $ret;
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        if (!($tostore = current($this->_items))) {
            return false;
        }
        next($this->_items);
        $this->_last_store_mode = $tostore[1];
        return $tostore[0];
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $this->_items = array();
        if (!isset($_POST['aa']) OR !is_array($_POST['aa'])) {
            if (!isset($_FILES['aa']) OR !is_array($_FILES['aa'])) {
                return;
            }
        }

        /** the item ids are in the form of i<item_id> for edited items,
         *  or n<number>_<slice_id> for new item.
         *  We have to construct translation table of the ids
         */
        $id_trans_table = array();

        $aa = $_POST['aa'];
        if (!is_array($aa)) {
            $aa = array();
        }

        // uploaded files are not $_POST variable.
        // Also it is stored as array...[name][file___________1][...] array...[type][file___________1][...]
        // so the information about one file is quite far in the structure
        if (isset($_FILES['aa'])) {
            // collect together the informations about one file - array(name, type, tmp_name, error, size)
            //$files = array_merge_recursive($_FILES['aa']['name'], $_FILES['aa']['type'], $_FILES['aa']['tmp_name'], $_FILES['aa']['error'], $_FILES['aa']['size']);
            $files = $this->rearangeAaFilesArray($_FILES['aa']);
            // add it to POSTed variables
            $aa    = array_merge_recursive($aa, $files);
        }

        // just prepare ids, in order we can expand
        // You can use _#n1_623553373823736362372726 as value, which stands for
        // item id of the item
        foreach ( $aa as $dirty_item_id => $item_fields) {
            if ( $dirty_item_id{0} == 'n' ) {
                $id_trans_table['_#'.$dirty_item_id] = new_id();
            }
        }
        $trans_item_alias = array_keys($id_trans_table);
        $trans_item_ids   = array_values($id_trans_table);

        $UPDATE_MODES = array('u'=>'update', 'i'=>'add', 'r'=>'update');
        foreach ( $aa as $dirty_item_id => $item_fields) {

            // common fields
            if ($dirty_item_id == 'all' ) {
                continue;
            }

            // 'u': edited item - update = field content is changed to new value
            // 'r': edited item - remove value from field
            // 'i': edited item - insert = field content is added to the existing content of the field
            if ( $store_mode = $UPDATE_MODES[$dirty_item_id{0}] ) {
                $item_id    = substr($dirty_item_id, 1);
                $item       = AA_Item::getItem($item_id);
                $item_fields['slice_id________'] = pack_id($item->getSliceID());
            }
            // new items
            else {
                $item_id    = $id_trans_table['_#'.$dirty_item_id];
                $store_mode = 'new';

                //grabb slice_id of new item
                $item_slice_id = substr($dirty_item_id, strpos($dirty_item_id, '_')+1);
                // and add slice_id field to the item
                $item_fields['slice_id________'] = array(pack_id($item_slice_id));
            }
            $id_trans_table[$dirty_item_id] = $item_id;

            // now fill the ItemContent for each item and tepmorary store it into $this->_items[]
            $item = new ItemContent();
            $item->setItemID($item_id);

            // join common fields (the specific fields win in the battle of common and specific content)
            if ( isset($aa['all']) ) {
                $item_fields = array_merge($aa['all'], $item_fields);
            }

            if ($dirty_item_id{0} == 'r') {
                $oldcontent4id    = new ItemContent();
                $oldcontent4id->setByItemID(new zids($item_id), true);     // ignore password
                if (!$oldcontent4id->getSliceID() OR !$oldcontent4id->getItemID()) {
                    continue;
                }
            }

            foreach ($item_fields as $dirty_field_id => $val_array) {
                // create full_text......1 from full_text______1
                $field_id = FormArray::getFieldIdFromVar($dirty_field_id);

                // get the content of the field (values and flags)
                $aa_value = Widget::getValue($val_array);
                $aa_value->replaceInValues($trans_item_alias, $trans_item_ids);

                if ($dirty_item_id{0} == 'r') {
                    $aa_value = $oldcontent4id->getAaValue($field_id)->removeValues($aa_value->getValues());
                }
                $item->setAaValue($field_id, $aa_value);
            }
            $this->_items[] = array($item, $store_mode);
        }
        reset ($this->_items);
    }

    /** Function called by AA_Saver after we get the last item from the data
     *  input
     */
    function finish()  {
        $this->_items = array();
    }


    public static function grab() {
    }
}


/** AA_Objectgrabber_Form - Grabbs data POSTed by AA form
*
*  The format of the data is followiing
*  (this is new format, which allows to fill or modify more objects at once
*
*   Format is:
*       aa[i<long_object_id>_<object_type>][modified_field_id][]
*   Note:
*      first brackets contain
*          'u'+long_object_id when object is edited (the field is rewriten, rest
*                             of object is untouched)
*          'i'+long_object_id when object is edited (the value is added to current
*                           value of the field, rest of object is untouched)
*          'n<number>_long_owner_id' if you want to add the object for an owner
*                                    (slice_id) <number> is used to add more
*                                    than one object at the time
*      object_type - type of object like - AA_Scroller
*                  - if not specified, AA_Item is used
*      modified_field_id is field_id, where all dots are replaced by '_'
*      we always add [] at the end, so it becames array at the end
*   Example:
*       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
*       aa[i63556a45e4e67b654a3a986a548e8bc9_AA_Conds][relation_______1][]
*       aa[n1_54343ea876898b6754e3578a8cc544e6_AA_Conds][publish_date____][]
*
*   There could be also compound widgets, which consists from more than one
*   input - just like date selector. In such case we use following syntax:
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
*       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
*   where "dte" points to the AA\Widget\DteWidget. The method AA\Widget\DteWidget::getValue()
*   is called to grab the value (or multivalues) from the submitted form
*/
class AA_Objectgrabber_Form extends AA_Grabber {
    private   $_content          = array();
    private   $_last_store_mode;

    function __construct() {}

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('Form'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Grabbs data POSTed by AA form'); }

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the object.
     *  @see also getStoreMode() method
     */
    function getIdMode() {
        return 'old';
    }

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the object.
     *  @see also getIdMode() method
     */
    function getStoreMode() {
        switch ($this->_last_store_mode) {
            case 'add':    return 'add';
            case 'update': return 'update';
        }
        return 'insert';
    }

    /** Method called by the AA_Saver to get next object from the data input */
    function getContent() {
        if (!($tostore = current($this->_content))) {
            return false;
        }
        next($this->_content);
        $this->_last_store_mode = $tostore[1];
        return $tostore[0];
    }

    /** Possibly preparation of grabber - it is called directly before getObject()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $this->_content = array();
        if (!isset($_POST['aa']) OR !is_array($_POST['aa'])) {
            return;
        }

        /** the object ids are in the form of i<object_id> for edited objects,
         *  or n<number>_<slice_id> for new object.
         *  We have to construct translation table of the ids
         */
        $id_trans_table = array();

        $aa = $_POST['aa'];

        // just prepare ids, in order we can expand
        // You can use _#n1_623553373823736362372726 as value, which stands for
        // object id of the object
        foreach ( $aa as $dirty_obj_id => $obj_fields) {
            if ( $dirty_obj_id{0} == 'n' ) {
                $id_trans_table['_#'.$dirty_obj_id] = new_id();
            }
        }
        $trans_obj_alias = array_keys($id_trans_table);
        $trans_obj_ids   = array_values($id_trans_table);

        foreach ( $aa as $dirty_obj_id => $obj_fields) {

            // common fields
            if ($dirty_obj_id == 'all' ) {
                continue;
            }

            $ident = AA_Objectgrabber_Form::_parseDirtyId($dirty_obj_id, $id_trans_table);

            $id_trans_table[$dirty_obj_id] = $ident['id'];

            // now fill the AA_Content for each obj and tepmorary store it into $this->_content[]
            $content = new AA_Content();
            $content->setId($ident['id']);

            // join common fields (the specific fields win in the battle of common and specific content)
            if ( isset($aa['all']) ) {
                $obj_fields = array_merge($aa['all'], $obj_fields);
            }
            foreach ($obj_fields as $field_id => $val_array) {
                // get the content of the field (values and flags)
                $aa_value = Widget::getValue($val_array);
                $aa_value->replaceInValues($trans_obj_alias, $trans_obj_ids);
                $content->setAaValue($field_id, $aa_value);
            }
            $this->_content[] = array($content, $ident['store_mode']);
        }

        reset ($this->_content);
    }

    /** Function called by AA_Saver after we get the last object from the data
     *  input
     */
    function finish()  {
        $this->_content = array();
    }


    /** parses i<long_object_id>_<object_type> id form $aa array to array:
     *    store_mode  - add | update | new
     *    id
     *    class
     *    owner
     */
    private static function _parseDirtyId($dirty_obj_id, $id_trans_table) {
        $ret = array();
        switch ($dirty_obj_id{0}) {
        case 'u':
            $ret['store_mode'] = 'update';
            // no break!
        case 'i':
            $ret['store_mode'] = 'add';
            $ret['id']         = substr($dirty_obj_id, 1, 32);
//            $ret['class']      = substr($dirty_obj_id, 34);
            break;
        case 'n':
            $ret['store_mode'] = 'new';
            $ret['id']         = $id_trans_table['_#'.$dirty_obj_id];
            $owner_start_char  = strpos($dirty_obj_id, '_') + 1;
            $ret['owner']      = substr($dirty_obj_id, $owner_start_char, 32);
//            $ret['class']      = substr($dirty_obj_id, $owner_start_char+33);
            break;
        }
        // deafult class is AA_Item
//        if (!$ret['class']) {
//            $ret['class'] = 'AA_Item';
//        }
        return $ret;
    }
}

/** AA_Grabber_Iekis_Xml - grabs data from XML files in one directory
 */
class AA_Grabber_Iekis_Xml extends AA_Grabber {

    protected $dir;                   /** directory, where teh files are */
    private   $_files;                /** list if files to grab - internal array */

    function __construct($dir) {
        $this->dir = $dir;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('iEKIS XML files'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('special iEKIS XML files in one directory'); }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $this->_files = array();
        // instance of class iCalFile (external file ical.php)
        if ($handle = opendir($this->dir)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $this->_files[] = $file;
                }
            }
            closedir($handle);
        }
        sort($this->_files);
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        if (!($file = current($this->_files))) {
            return false;
        }
        next($this->_files);

        $xml = simplexml_load_file($this->dir .$file);
        $att = $xml->attributes();

        foreach ($xml->text as $key => $text) {
            $text_att = $text->attributes();
            switch ($text_att['typ']) {
                case 'otazka':  $t0a = $text_att; $t0 = (string)$text; break;
                case 'odpoved': $t1a = $text_att; $t1 = (string)$text; break;
            }
        }

        $item = new ItemContent();
        $item->setValue('number.........1', $att['idp']);
        $item->setValue('unspecified.....', $att['obor']);
        $item->setValue('publish_date....', strtotime($att['datum'].' '.$att['cas']));
        $item->setValue('post_date.......', strtotime($att['datum'].' '.$att['cas']));
        $item->setValue('unspecified....1', $att['stav']);
        $item->setValue('unspecified....2', $att['kod']);
        $item->setValue('unspecified....3', $att['pristupne']);
        $item->setValue('headline........', $att['predmet']);
        $item->setValue('text...........1', $t0a['jmeno']);
        $item->setValue('con_email.......', $t0a['email']);
        $item->setValue('address.........', $t0a['kontakt']);
        $item->setValue('unspecified....4', $t0a['predmet']);
        $item->setValue('start_date......', strtotime($t1a['datum'].' '.$t1a['cas']));
        $item->setValue('unspecified....5', $t1a['expert']);
        $item->setValue('unspecified....6', $t1a['predmet']);
        $item->setValue('text...........4', $t0);
        $item->setValue('text...........5', $t1);
        $item->setValue('edit_note.......', 'importovano ze stareho i-ekis.cz - '. $file);
        $item->setValue('switch.........2', '1');  // ulozit pro mpo
        $item->setValue('switch..........', '0');  // storno

        $TEMAS = array(
                     '1' => 'b2269049caf8e47737b718e62ccc905c',   // Územní energetické koncepce
                     '2' => 'b2269049caf8e47737b718e62ccc905c',   // Akční plány
                     '3' => 'afbab0cf8bfb524ce48e50862fb8fa88',   // Energetické audity a průkazy (99)
                     '4' => 'b2325f0658cab17838f8ce6e49a26688',   // Kotle a kotelny (524)
                     '5' => '09952314df2e76d4fc9cd1dc8baec9ae',   // Energie slunce (237)
                     '6' => '144f1726c94eb7e86a491dea2bc84021',   // Energie vody (131)
                     '7' => 'ae02cc88721e77af713d30c766834e9f',   // Energie větru (180)
                     '8' => '1754f99f9d83562c81d99a28818cf585',   // Energie biomasy (332)
                     '9' => '1754f99f9d83562c81d99a28818cf585',   // Využití bioplynů (35)
                    '10' => '18804c2b8e611fba74ceb46038494e68',   // Využití odpadního tepla (13)
                    '11' => '442340d0d7ff8a68c76902a2da083d44',   // Tepelná čerpadla (153)
                    '12' => '5f4fe6025c68814d449f29e3f1cdd01a',   // Palivové články (6)
                    '13' => '49282fdb6ee5d9c524cd5552417b855a',   // Elektrické vytápění (247)
                    '14' => '1b2fbed9465f609835c51841620d8b6a',   // Kogenerace, trigenerace (35)
                    '15' => '5941e5f49d61ac033130660ea394122d',   // Měření a regulace (594)
                    '16' => 'b2269049caf8e47737b718e62ccc905c',   // Rekonstrukce rozvodů sídlištního celku
                    '17' => 'b2269049caf8e47737b718e62ccc905c',   // Rekonstrukce otopné soustavy v objektu
                    '18' => '5f93ea7654fafea8cf29ddb8b80351ef',   // Úsporná opatření v průmyslu (12)
                    '19' => 'b2269049caf8e47737b718e62ccc905c',   // Monitoring a targeting (1)
                    '20' => 'b2269049caf8e47737b718e62ccc905c',   // Moderní postupy, technologie a materiály
                    '21' => 'a8e078b5bf57ec58a2c7245f71f0d3d4',   // Zateplování objektů (2101)
                    '22' => '15efaadbec739b0965a098da205a0d31',   // Nízkoenergetické a pasivní domy (179)
                    '23' => '3c3e0d17813eccc67afd3a267329595a',   // Projekty energetických služeb se zárukou
                    '24' => '3c3e0d17813eccc67afd3a267329595a',   // Financování z fondů Evropské unie
                    '25' => 'b2269049caf8e47737b718e62ccc905c'    // Ostatní (536)
                    );

        if ($TEMAS[(string)$att['obor']]) {
            $item->setValue('relation.......2', $TEMAS[(string)$att['obor']]);
        }

        return $item;
    }
}

/** AA_Grabber_Slice - grabs data from slice based on AA_Set
 *  Right now we use it mainly for apc-aa/admin/se_export.php
 */
class AA_Grabber_Slice extends AA_Grabber {

    protected $set;                 /** AA_Set specifies the slice, conds and sort */
    protected $restrict_zids;       /** possible subset of ids grabbed */
    protected $restrict_fields;     /** possible subset of fields - used in {updateitem:...} for field value update */
    private   $_longids;            /** list if files to grab - internal array */
    private   $_content_cache;      /**  */
    private   $_index;              /**  */

    function __construct($set, $restrict_zids=null, $restrict_fields=null) {
        $this->set             = $set;
        $this->restrict_zids   = $restrict_zids;
        $this->restrict_fields = is_array($restrict_fields) ? $restrict_fields : false;
        $this->_longids        = array();
        $this->_content_cache  = array();
        $this->_index          = 0;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('Items from slice'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('grabs items from some slice'); }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $zids                 = $this->set->query($this->restrict_zids);
        $this->_longids       = $zids->longids();
        $this->_content_cache = array();
        $this->_index         = 0;
        reset($this->_longids);   // go to first long id
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        if (!($longid = $this->_longids[$this->_index])) {
            return false;
        }
        if (empty($this->_content_cache[$longid])) {
            $this->_fillCache();
        }

        $this->_index++;
        return new ItemContent($this->_content_cache[$longid]);
    }

    /** speedup */
    function _fillCache() {
        // read next 100 items (we can laborate with cache size in future to get even better performance)
        $this->_content_cache = GetItemContent(new zids( array_slice($this->_longids, $this->_index, 100), 'l'), '', false, $this->restrict_fields);
    }

    function getCharset() {
        $modules = $this->set->getModules();
        if ($module = AA_Slice::getModule($modules[0])) {
            return $module->getCharset();
        }
        return 'UTF-8';
    }

    function finish() {
        $this->_longids = array();
    }
}

/** AA_Grabber_Discussion - grabs data from slices discussion based on AA_Set
 *  Right now we use it mainly for apc-aa/admin/se_export.php
 */
class AA_Grabber_Discussion extends AA_Grabber {

    protected $set;                 /** AA_Set specifies the slice, conds and sort */
    private   $_longids;            /** list if files to grab - internal array */
    private   $_content_cache;      /**  */
    private   $_index;              /**  */

    function __construct($set) {
        $this->set            = $set;
        $this->_longids       = array();
        $this->_content_cache = array();
        $this->_index         = 0;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('Discussion from slice'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('grabs discussion comments for items from some slice'); }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        // get all discussion comments ids
        $item_zids            = $this->set->query();
        $item_long_ids        = $item_zids->longids();
        $this->_longids       = array();
        foreach ($item_long_ids as $item_id) {
            $zids = QueryDiscussionZIDs($item_id);
            $this->_longids = array_merge($this->_longids, $zids->longids());
        }
        $this->_content_cache = array();
        $this->_index         = 0;
        reset($this->_longids);   // go to first long id
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        if (!($longid = $this->_longids[$this->_index])) {
            return false;
        }
        if (empty($this->_content_cache[$longid])) {
            $this->_fillCache();
        }

        $this->_index++;
        return new ItemContent($this->_content_cache[$longid]);
    }

    /** speedup */
    function _fillCache() {
        // read next 100 items (we can laborate with cache size in future to get even better performance)
        $this->_content_cache = GetDiscussionContent(new zids( array_slice($this->_longids, $this->_index, 100), 'l'));
    }

    function finish() {
        $this->_longids = array();
    }
}

/** AA_Grabber_Ical - iCal  calendar format grabber
 *  could be called like
 *     $a = new AA_Grabber_Ical('http://example.org/calendar.ics');
 */
class AA_Grabber_Ical extends AA_Grabber {

    protected $url;                   /** URL of .ics file */
    protected $ical;                  /** instance iCalFile */
    protected $vCalPos;               /** pointer of vCalendar */
    protected $vComponentsTypePos;    /** pointer of type components */
    protected $vComponentsPos;        /** pointer of components */


    function __construct($url){
        $this->url=$url;
    }
      /** Name of the grabber - used for grabber selection box */
    function name() { return _m('iCal'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Import data from iCal (.ics) format'); }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        // instance of class iCalFile (external file ical.php)
        $this->ical = new iCalFile($this->url);
        // set pointers
        $this->vCalPos = 0;
        $this->vComponentsPos =0;
        $this->vComponentsTypePos =0;
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        // set pointers
        $vCalPos =0;
        $vComponentsPos =0;
        $vComponentsTypePos =0;

        //TODO solve empty ical->components exception ( case of empty or wrong ics file)

        //check if ical->components isn't empty (case of empty or wrong ics file)
        if (isset($this->ical->components['VCALENDAR'])){
            //get $vcalendar index
            foreach ($this->ical->components['VCALENDAR'] as $vcalKey => $vcalItem ) {
                if ($vCalPos < $this->vCalPos) {$vCalPos++; continue;}
                    //chek if vcalItem-> isn't empty
                    if (isset($vcalItem->components)){
                        //get keys of components type
                        foreach ($vcalItem->components as $componentTypeKey => $componentTypeItem) {

                            if ($vComponentsTypePos < $this->vComponentsTypePos)  { $vComponentsTypePos++; $vComponentsPos=0; continue;}
                            //insert type of component
                            $content4id['type'][0]['value']=$componentTypeKey;
                                //get index of components key
                            foreach ($componentTypeItem as $componentKey => $componentItem) {

                                if ($vComponentsPos < $this->vComponentsPos) {$vComponentsPos++;  continue; }
                                //check if is set componentItem->components
                                if (isset( $componentItem->components )) {
                                    //get keys of properties
                                    foreach ($componentItem->components as $valueKey => $valueArray) {
                                        //get index of properties
                                        foreach ($valueArray as $valueIndex => $valueItem) {
                                            //check if exists method get_value()
                                            if (method_exists($valueItem, 'get_value')) {
                                                //fill array $content4id
                                                $content4id[$valueKey][$valueIndex]['value']=$valueItem->get_value();
                                            }
                                        }
                                    }

                                    // instance of ItemContent with array content4id
                                    $ic = new ItemContent($content4id);
                                    //unset array content4id
                                    unset($content4id);

                                    $vComponentsPos++;
                                    $this->vCalPos=$vCalPos;
                                    $this->vComponentsPos=$vComponentsPos;
                                    $this->vComponentsTypePos=$vComponentsTypePos;
                                    return $ic;
                                }

                                $vComponentsPos++;
                            }

                            $vComponentsTypePos++;
                            $vComponentsPos=0;
                            if ($vComponentsTypePos > $this->vComponentsTypePos) {$this->vComponentsPos=0;}
                        }

                    }
                    $vCalPos++;
                    $vComponentsPos=0;
                    $vComponentsTypePos =0;
            }
        }
        return 0;
    }
}


/** AA_Grabber_Pohoda_Stocks - Imports stock from POHODA accounting system
 */
class AA_Grabber_Pohoda_Stocks extends AA_Grabber {

    protected $file;                /** list if files to grab - internal array */
    private   $_items;
    private   $_last_store_mode;

    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('Pohoda (www.stormware.cz) - Stocks'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Imports stock from POHODA accounting system'); }

    /** If AA_Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     *  @see also getIdMode() method
     */
    function getStoreMode() {
        return  $this->_last_store_mode;
    }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $data = file_get_contents($this->file);
        // remove namespaces (it is pain to work with it in simple XML interface)
        $data = str_replace(array('<rsp:', '</rsp:', '<lst:', '</lst:', '<stk:', '</stk:', '<typ:', '</typ:'), array('<rsp_', '</rsp_', '<lst_', '</lst_', '<stk_', '</stk_', '<typ_', '</typ_'), $data);
        $xml  = simplexml_load_string($data);

        $items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        foreach($items as $v) {
          $this->_items[] = $v->stk_stockHeader;
        }

//        $this->_items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
//       huhl($this->_items);
    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        $VAT_RATES = array('none'=>0, 'low'=>10, 'high'=>20);

        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $set  = new AA_Set('3c121c4f40ded336375a6206c85baf1e', new AA_Condition('number.........1', '=', '"'.$polozka->stk_PLU.'"'), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
        $zids = $set->query();

        $item = new ItemContent();
        $item->setValue('source.........1', (string)$polozka->stk_name);
        $item->setValue('source.........2', (string)$polozka->stk_count);               // stav zasob
        $item->setValue('source.........3', (string)$polozka->stk_countReceivedOrders); // objednavky
        $item->setValue('source.........4', (string)$polozka->stk_reservation);         // rezervace
        $item->setValue('source.........5', (string)$polozka->stk_sellingPrice);        // cena bez DPH

        $dan  = $VAT_RATES[(string)$polozka->stk_sellingRateVAT];
        $cena = round((string)$polozka->stk_sellingPrice * (($dan+100.0)/100.0),1);
        $cena = ((float)round($cena) == (float)$cena) ? round($cena) : $cena;
        $jedn = (string)$polozka->stk_unit;

        $item->setValue('number..........', $dan);         // DPH

        if ($jedn == 'ks') {
            $item->setValue('price..........1', $cena);
            $item->setValue('price..........2', '');
        } else {
            $item->setValue('price..........1', '');
            $item->setValue('price..........2', $cena);
        }

        // new PLUs INSERT into database - current just update (price, stock)
        if ($zids->count()) {
            $this->_last_store_mode = 'update';
            $item_id = $zids->longids(0);
        } else {
            $this->_last_store_mode = 'insert';
            $item_id = new_id();
            $item->setValue('number.........1', (string)$polozka->stk_PLU);
            $item->setValue('headline........', (string)$polozka->stk_name);
            $item->setValue('status_code.....', '2');  // nove importujeme do zasobniku
        }
        $item->setItemID($item_id);

        return $item;
    }
}


/** AA_Grabber_Pohoda_Stocks - Imports stock from POHODA accounting system
 */
class AA_Grabber_Pohoda_Orders_Result extends AA_Grabber {

    protected $file;                /** list if files to grab - internal array */
    private   $_items;

    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('Pohoda (www.stormware.cz) - Orders import result'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Process Order import results from POHODA accounting system'); }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $data = file_get_contents($this->file);
        // remove namespaces (it is pain to work with it in simple XML interface)
        $data = str_replace(array('<rsp:', '</rsp:'), array('<rsp_', '</rsp_'), $data);
        $xml  = simplexml_load_string($data);

        $this->_items = array();
        //$items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        foreach($xml as $v) {
          $this->_items[] = $v;
        }

    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $response_att = $polozka->attributes();


        //$zids     = new zids($response_att['id'], 's');
        $item_id  = AA::Stringexpander()->unalias('{item:'.$response_att['id'].':_#ITEM_ID_}');
        $poznamka = AA::Stringexpander()->unalias('{item:'.$response_att['id'].':source.........2}');

        $item = new ItemContent();
        $item->setItemID($item_id);

        $item->setValue('source.........1', (string)$response_att['state']);    // stav importu
        // poznamku pripojujeme na zacatek
        $item->setValue('source.........2', date('ymd-His'). (string)$response_att['note']. "\n". $poznamka);     // import poznamka
        return $item;
    }

}


/** AA_Grabber_CTK - Imports items from CTK xml export
 */
class AA_Grabber_CTK extends AA_Grabber {

    protected $file;                /** list if files to grab - internal array */
    private   $_items;

    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('CTK - xml import'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Process import xml file xported from CTK'); }

     /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        #$data = file_get_contents($this->file);
        #$xml  = simplexml_load_string($data);
        $xml = simplexml_load_file($this->file);

        $this->_items = array();
        //$items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        foreach($xml->NewsItem as $v) {
           $this->_items[] = $v;
        }

    }

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {
        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $domicil = '';
        foreach ($polozka->DescriptiveMetadata->Location->Property as $prop) {
          switch((string) $prop['FormalName']) { // Get attributes as element indices
          case 'City':
            $domicil = (string)$prop['Value'];
            break;
          }
        }

        $ftext = '';
        foreach($polozka->ContentItem->DataContent->body->p as $f) {
          $ftext .= $f->asXML()."\n";
        };

        $item = new ItemContent();
        $item->setItemID(new_id());
        $item->setValue('headline........', iconv('UTF-8', 'windows-1250', (string)$polozka->NewsComponent->NewsLines->Headline));      // Název
        $item->setValue('place..........3', iconv('UTF-8', 'windows-1250', mb_strtoupper($domicil, 'UTF-8')));                          // Domicil
        $item->setValue('publish_date....', strtotime($polozka->NewsManagement->ThisRevisionCreated));                                  // Datum zveřejnění - 20160127T145301+01:00
//      $item->setValue('expiry_date.....', strtotime($polozka->NewsManagement->ThisRevisionCreated)+3600*24*365);                      // Datum expirace - rok po zveřejnění - zruseno dle emailu 7.12.2016
        $item->setValue('expiry_date.....', '1577836800');                                                                              // Datum expirace - 1.1.2020 - tedy do konce smlouvy s CTK
        $item->setAaValue('full_text......1', new AA_Value(iconv('UTF-8', 'windows-1250', $ftext), FLAG_HTML));                         // Plný text
        $item->setValue('source.........1', iconv('UTF-8', 'windows-1250','ČTK'));                                                                                     // Zdroj
        $item->setValue('source_href.....', 'http://www.ctk.cz');                                                                       // URL zdroje
        $item->setValue('status_code.....', 2);                                                                                         // Do zasobniku
        return $item;
/*
*/

    }

}


