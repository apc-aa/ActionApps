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

/** Saver / Grabber API
 *
 *   Defines AA_Grabber base class, which is used as abstraction for data input
 *   From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 */


/** Saver class - Used for filling items into AA
 *  @param grabber          - provides source for item data
 *  @param $transformations - defines, how the source item should be transformed
 *                            into destination item
 */
class AA_Saver {
    var $grabber;            /** the object which deliveres the data */
    var $transformations;    /** describes, what to do with data before storing */
    var $slice_id;           /** id of destination slice */
    var $store_mode;         /** store-policy - how to store - overwrite | insert_if_new */
    var $id_mode;            /** id-policy    - how to construct id - old | new | combined */

    function AA_Saver(&$grabber, &$transformations, $slice_id, $store_mode='overwrite', $id_mode='old') {
        $this->grabber         = $grabber;
        $this->transformations = $transformations;
        $this->slice_id        = $slice_id;
        $this->store_mode      = $store_mode;
        $this->id_mode         = $id_mode;
    }

    /** Now import all items to the slice */
    function run() {
        global $debugfeed;

        $this->grabber->prepare();    // maybe some initialization in grabber
        while ($content4id = $this->grabber->getItem()) {

            if ($debugfeed >= 8) {
                print("\n<br>AA_Saver->run(): we have item to store, hurray! -- ". $content4id->getItemID());
            }

            switch ($this->id_mode) {
                // Create new item id (always the same for item-slice pair)
                case 'combined' : $new_item_id = string2id($content4id->getItemID(). $this->slice_id); break;

                // Use id from source
                case 'old'      : $new_item_id = $content4id->getItemID(); break;

                // Generate completely new id
                default         :
                case 'new'      : $new_item_id = new_id();                 break;
            }

            $old_item_id = $content4id->getItemID();

            // set the item to be recevied from remote node
            $content4id->setItemID($new_item_id);

            // TODO - move to translations
            $content4id->setSliceID($this->slice_id);

            if ($debugfeed >= 3) print("\n<br>      ". $content4id->getValue('headline........'));
            if ($debugfeed >= 8) { print("\n<br>xmlUpdateItems:content4id="); huhl($content4id); }

            // id_mode - overwrite or insert_if_new
            // (the $new_item_id should not be changed by storeItem)
            if (!($new_item_id = $content4id->storeItem($this->store_mode))) {     // invalidatecache, feed
                print("\n<br>AA_Saver->run(): storeItem failed or skiped duplicate");
            } else {
                if ($debugfeed >= 1) print("\n<br>  + stored OK: ". $content4id->getValue('headline........'));
                // Update relation table to show where came from
                if ($new_item_id AND $old_item_id AND ($new_item_id != $old_item_id)) {
                    AddRelationFeed($new_item_id, $content4id->getItemID());
                }
            }
        } // while grabber->getItem()
        $this->grabber->finish();    // maybe some finalization in grabber
    }

    /** Toexecutelater - special function called from toexecute class
     *  - used for queued tasks (runed from cron)
     *  You can use $toexecute->later($saver, array(), 'feed_external') instead
     *  of calling $saver->run() - the saving will be planed for future
     */
    function toexecutelater() {
        return $this->run();
    }
}


/** AA_Grabber - Base class, which is used as abstraction for data input
 *  From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 *
 *  @todo this class should be abstract after we switch to PHP5
 */
class AA_Grabber {
    var $messages = array();

    /** Name of the grabber - used for grabber selection box */
    function name() {}

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() {}

    /** HTML code for parameters - defines parameters of this grabber.
     *  Each grabber could have its own parameters (like separator for CSV, ...)
     */
    function htmlSetting($input_prefix, $params) {}

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {}

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {}

    /** Function called by AA_Saver after we get the last item from the data
     *  input
     */
    function finish()  {}

    /** Records Error/Information messaage */
    function message($text) {
        $this->messages[] = $text;
    }

    /** Print Error/Information messaages */
    function report()       {
        return join('<br>', $this->messages);
    }

    function clear_report() {
        unset($this->messages);
        $this->messages = array();
    }
}

/** AA_Grabber_Csv - CSV (Comma Separated Values) format grabber
 *  From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 *
 *  @todo this class should be abstract after we switch to PHP5
 */
class AA_Grabber_Csv {

    /** Name of the grabber - used for grabber selection box */
    function name() { return _m('CSV'); }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() { return _m('Import data from CSV (Comma Separated Values) format'); }

    /** HTML code for parameters - defines parameters of this grabber.
     *  Each grabber could have its own parameters (like separator for CSV, ...)
     */
    function htmlSetting($input_prefix, $params) {}

    /** Method called by the AA_Saver to get next item from the data input */
    function getItem() {}

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {}

    /** Function called by AA_Saver after we get the last item from the data
     *  input
     */
    function finish()  {}
}


class AA_Grabber_Aarss extends AA_Grabber {
    var $feed_id;
    var $feed;
    var $name;
    var $slice_id;
    var $aa_rss;
    var $channel;
    var $map;
    var $cat_field_id;
    var $status_code_id;
    var $ext_categs;
    var $l_categs;
    var $r_slice_id;
    var $fire;

    function AA_Grabber_Aarss($feed_id, &$feed, $fire) {
        global $debugfeed;

        /** Process one feed and returns parsed RSS (both AA and other)
         *  in $this->aa_rss */
        $this->slice_id   = unpack_id128($feed['slice_id']);        // local slice id
        $this->r_slice_id = unpack_id128($feed['remote_slice_id']); // remote slice id
        $this->feed_id    = $feed_id;
        $this->feed       = $feed;
        $this->fire       = $fire;
    }


    function name() {
        return _m("AA RSS");
    }

    function description() {
        return _m("Grabs data from generic RSS or AA RSS (used for item exchange between different AA installations)");
    }

    function setUrl($url)    { $this->feed['server_url']  = $url; }
    function setTime($time)  { $this->feed['newest_item'] = $time; }

    function _getRssData() {
        return http_fetch($this->feed['server_url']);
    }

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

    function _getExactData() {
        global $debugfeed;

        // get local item list (with last edit times)
        $slice       = AA_Slices::getSlice($this->slice_id);
        $local_list  = new LastEditList();
        $local_list->setFromSlice('', $slice);  // no conditions - all items
        $local_pairs = $local_list->getPairs();

        if ($debugfeed > 8) { huhl('_getExactData() - Local pairs:', $local_pairs); }

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

        if ($debugfeed > 8) { huhl('_getExactData() - Remote pairs:', $remote_pairs); }

        // Get all ids, which was updated later than items in local slice
        $ids = array();   // initialize
        foreach ($remote_pairs as $id => $time) {
            if (!isset($local_pairs[$id]) OR ($local_pairs[$id] < $time)) {
                $ids[] = $id;  // array of ids to ask for
            }
        }

        if ($debugfeed >= 2) { huhl(' Local items: ', count($local_pairs), ' Remote items: ', count($remote_pairs), ' Asked for update: ', count($ids)); }

        // No items to fed?
        if (count($ids) <= 0) {
            return '';
        }

        $finish        = $base;
        $finish['ids'] = implode('-',$ids);

        if ($debugfeed > 8) { huhl('_getExactData() - http_fetch:', $this->feed['server_url'], $finish); }

        return http_fetch($this->feed['server_url'], $finish);
    }

    /** Fetch data and parse it **/
    function prepare() {
        global $DEFAULT_RSS_MAP, $debugfeed;

        // just shortcut
        $feed_type = $this->feed['feed_type'];

        set_time_limit(240); // Allow 4 minutes per feed

        // Get XML Data
        if ($debugfeed >= 1) {
            $slice           = AA_Slices::getSlice($this->slice_id);
            $feed_debug_name = 'Feed #'. $this->feed_id .' ('. getFeedTypeName($feed_type).'): '.
                               $this->feed['name'] .' : ' .$this->feed['remote_slice_name'].
                               ' -> '.$slice->name();
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
            writeLog("CSN","No data returned for $feed_debug_name");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name: no data returned");
            return false;
        }
        if ($debugfeed >= 8) huhl("Fetched data=",htmlspecialchars($xml_data));

        // if an error occured, write it to the LOG
        if (substr($xml_data,0,1) != "<") {
            writeLog("CSN","Feeding mode: $xml_data");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name:bad data returned: $xml_data");
            return false;
        }

        /** $g_slice_encoding is passed to aa_rss_parse() - it defines output character encoding */
        $GLOBALS['g_slice_encoding'] = getSliceEncoding($this->slice_id);

        if (!( $this->aa_rss = aa_rss_parse( $xml_data ))) {
            writeLog("CSN","Feeding mode: Unable to parse XML data");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name:$feed[server_url]:unparsable: <hr>".htmlspecialchars($xml_data)."<hr>");
            return false;
        }

        if ($debugfeed >= 5) { print("\n<br>Parses ok"); }

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
            updateFieldsMapping($this->feed_id, $this->slice_id, $this->r_slice_id, $this->aa_rss['channels'][$this->r_slice_id]['fields'],$this->aa_rss['fields']);
        }

        // Find channel definition
        if (!($this->channel = $aa_rss['channels'][$this->r_slice_id])) {
            while (list(,$this->channel) = each($this->aa_rss['channels'])) {
                if ($this->channel) {
                   break;
                }
            }
        }

        list(,$map) = GetExternalMapping($this->slice_id,$this->r_slice_id);
        if (!$map && ($feed_type == FEEDTYPE_RSS)) {
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
        $item2fed = new item($item['fields_content'], array());

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
                                while (list($k,$v2) = each($values)) {
                                    $values[$k]['value'] = $v2['value'];
                                }
                                $content4id[$to_field_id] = $values;
                            }
                            break;
            } // switch
        } // while each($map)

        next($this->aa_rss['items']);

        $ic = new ItemContent($content4id);
        $ic->setItemValue('externally_fed', $this->feed['name']);  // TODO - move one layer up - to saver transactions
        $ic->setItemId($item_id);
        return $ic;
    }

    function finish() {
        if ($this->feed['feed_type'] == FEEDTYPE_APC) {
            $db = getDB();
            //update the newest item
            $SQL = "UPDATE external_feeds SET newest_item='".quote($this->aa_rss['channels'][$this->r_slice_id]['timestamp'])."'
                     WHERE feed_id='".quote($this->feed_id)."'";
            $db->tquery($SQL);
            freeDB($db);
        }
    }
}

?>
