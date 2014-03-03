<?php
/**
 * MLX MultiLingual eXtension for APC ActionApps
 *
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
 * @package   Include
 * @version   $Id$
 * @author    mimo/at/gn.apc.org for GreenNet
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 * @brief     MLX MultiLingual eXtension for ActionApps http://mimo.gn.apc.org/mlx
 *
*/
/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $mlxScriptsTable
* this maps names to scripts and the script direction
* extend at your will, stick to this table http://www.allegro-c.de/formate/sprachen.htm
* @note the names dont follow any standard (but are based on DIN) and can be extended
* the font face and align info should be in the stylesheet
*/
$mlxScriptsTable = array(
    "DR" => array("name"=>"Dari", "DIR"=>"RTL","FONT"=>"FACE=\"Pashto Kror Asiatype\"","ALIGN"=>"JUSTIFY"),
    "AR" => array("name"=>"Arabic","DIR"=>"RTL","ALIGN"=>"JUSTIFY"),
    "PS" => array("name"=>"Pashtoo","DIR"=>"RTL","FONT"=>"FACE=\"Pashto Kror Asiatype\"","ALIGN"=>"JUSTFIY"),
    "KU" => array("name"=>"Kurdish","DIR"=>"RTL","FONT"=>"FACE=\"Ali_Web_Samik\"")
);

/** name of column in table slice **/
define ('MLX_SLICEDB_COLUMN','mlxctrl'); //

/** deprecated: using mlxctrl instead
    the field type in the Control Slice whose name sets the
    field in the item in Content Slice that links to the Control Item **/
//define ('MLX_ITEM2MLX_FIELD','lang_code.......');
define ('MLX_CTRLIDFIELD','mlxctrl.........');


/** what field type stores <lang> => <item id> info
   ('text' means anything beginning with 'mlxctrl' **/
define ('MLX_LANG2ID_TYPE','mlxctrl');

/** the following settings are for debugging, performance
    you can overwrite them in your config.php3
**/

/** set to 1 to display trace info **/
if(!defined('MLX_TRACE')) {
    define ('MLX_TRACE',0);
}

/** there is a problem with views and caching mlx-ed results
    at least in combination with site module, disable at own
    risk (btw, I dont think this caching brings alot better
    performance) **/
if(!defined('MLX_NOVIEWCACHE')) {
    define ('MLX_NOVIEWCACHE',0);
}

if(!defined('MLX_OPTIMIZE')) {
    define ('MLX_OPTIMIZE',0);
}

/** HTML defines **/
define ('MLX_HTML_TABHD',"\n"
."<tr>\n"
."  <td colspan=\"5\">\n"
."    <table width=\"100%\" bgcolor=\"".COLOR_TABTITBG."\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\">\n"
."    <tbody>\n"
."    <tr>\n"
."      <td colspan=\"3\">\n"
."        <table bgcolor=\"".COLOR_TABTITBG."\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\">\n"
."        <tbody>\n"
."        <tr>\n"
."          <td >&nbsp;<b>MLX Language Control</b></td>\n"
."        </tr>\n"
."        <tr>\n"
."          <td><table border=\"0\" cellspacing=\"0\" cellpadding=\"1\">\n"
."            <tr>\n");
define ('MLX_HTML_TABFT',"\n"
."            </tr>\n"
."            </table>\n"
."          </td>\n"
."        </tr>\n"
."        </tbody>\n"
."        </table>\n"
."      </td>\n"
."    </tr>\n"
."    </tbody>\n"
."    </table>\n"
."  </td>\n"
."</tr>\n");

define('MLX_NOTRANSLATION','eMpTy');
/** __mlx_dbg function
 * @param $v
 * @param $label
 */
function __mlx_dbg($v,$label="")
{
    echo "<pre>$label\n";
    if(is_array($v))
        print_r($v);
    else
        print($v);
    echo "</pre>";
}
/** __mlx_trace function
 * @param $v
 */
function __mlx_trace($v)
{
    if(MLX_TRACE) {
        echo "<pre>";
        if(is_array($v))
            print_r($v);
        else
            print($v."\n");
        echo "</pre>";
    }
}
/** __mlx_fatal function
 * @param $msg
 */
function __mlx_fatal($msg) {
    global $err;
    global $sess;
    global $slice_id;
    if(is_array($msg)) {
        $msg = implode("<br>",$msg);
    }
    $err["MLX"] = MsgErr($msg);
    MsgPage(con_url($sess->url(self_base() ."index.php3"),
        "slice_id=$slice_id"),
        $err, "standalone");
    die;
}

/** isMLXSlice function
 * test if the slice is MLX enabled
 * @param $sliceobj
 */
function isMLXSlice($sliceobj) {
    //hook other replies here
    return (is_object($sliceobj) ? $sliceobj->getProperty(MLX_SLICEDB_COLUMN) : $sliceobj[MLX_SLICEDB_COLUMN]);
}

/** Stores information about translations (= ids of translations items)
 *  of current item (itemid)
 */
class MLXCtrl
{
    var $itemid;       /** itemid in MLX control slice **/
    var $translations; /** (0=>("EN"=>"itemid"),..) **/
    /** MLXCtrl function
     * @param $itemid
     * @param $mlxObj
     */
    function MLXCtrl($itemid, $mlxObj) {
        $this->itemid = $itemid;
        $content      = GetItemContent($itemid);
        // get all fields from MLX control (language) slice
        $fields       = $mlxObj->getCtrlFields();
        foreach( $fields as $v ) {
            // is it mlxctrl field (field for one af the languages)?
            if (isset($v['id']) && ( strpos($v['id'],MLX_LANG2ID_TYPE)) === 0) {
                $this->translations[] = array($v['name'] => $content[$itemid][$v['id']][0]['value']);
            }
        }
    }
    /** getDefLangName function
     *
     */
    function getDefLangName() {
        return key($this->translations[0]);
    }
    /** getFirstNonEmpty function
     *
     */
    function &getFirstNonEmpty() {
        foreach ($this->translations as $v) {
            if ((list($itemid,)=array_values($v)) && $itemid) {
                return $v;
            }
        }
        return false;
    }
};

/** MLX - MultiLingual eXtension
 *  How it works:
 *     There are two slices - "Content" and "MLX Control Slice". The Content
 *     slice stores all items and its translations, "MLX Control Silce" stores
 *     relations - stores, which item in "Content" slice is translation
 *     of another item.
 *
 *  There is an example, how it should look in database:
 *
 *  "MLX Control Slice"
 *    field name: ID                  EN                  CZ
 *    field id:   id..............    mlxctrl.........    mlxctrl.........
 *    -----------------------------------------------------------------------------------------------
 *    content:    mlxid_packed_id_    content_pack_id1    content_pack_id2
 *
 *  "Content slice"
 *    field name: ID                  Language            MLX control         Headline          [...]
 *    field id:   id..............    lang_code.......    mlxctrl.........    headline........  [...]
 *    -----------------------------------------------------------------------------------------------
 *    content 1:  content_pack_id1    EN                  mlxid_packed_id_    How MLX works     [...]
 *    content 2:  content_pack_id2    CZ                  mlxid_packed_id_    Jak MLX pracuje   [...]
 *
 *  @see http://mimo.gn.apc.org/mlx for more details
 */
class MLX
{
//private:
    var $ctrlFields = 0;
    var $slice = 0;
    var $langSlice = 0;
//public:
    function MLX(&$slice) {
        $this->slice = $slice;
        $this->langSlice = unpack_id($this->slice->getProperty(MLX_SLICEDB_COLUMN));
        list($this->ctrlFields,) = GetSliceFields($this->langSlice);
    }
    /** getCtrlFields function
     *
     */
    function &getCtrlFields() {
        return $this->ctrlFields;
    }

    /** update function
     *  Stores translations ids to MXL control slice and also sets value of
     *  mlxctrl......... field in content4id
     *   @param $content4id  - currently stored item
     *   @param $cntitemid   - id of currently stored item (it must be known also
     *                        for new (= inserted) items
     *   @param $action
     *   @param $mlxl        - language of current item
     *   @param $mlxid       - id of control item in MLX control slice
     */
    function update(&$content4id, $cntitemid, $action, $mlxl, $mlxid) {

        $content4mlxid    = array();
        $oldcontent4mlxid = array();
        $insert = false;
        if ((($action == "insert") || ($action == "update")) && $mlxl && $mlxid) {
            $this->trace("Updating control data..");
            $oldcontent4mlx   = GetItemContent($mlxid);
            $oldcontent4mlxid = $oldcontent4mlx[$mlxid];
            $id   = $mlxid;
            $lang = $mlxl;
        } elseif ((!$content4id->getValue(MLX_CTRLIDFIELD)) || ($action == "insert")) {
        // create the meta data
            $this->trace("Creating new control data..");
            $id     = new_id();
            $lang   = $content4id->getValue('lang_code.......');
            $insert = true;
        } else {
            $this->fatal("MLX update: duno what to do");
        }
        $qp_cntitemid = pack_id($cntitemid);
        foreach ($this->ctrlFields as $k => $v) {
            if ($v['name'] == $lang) {
                $content4mlxid[(string)$k] = array(array('value' => $qp_cntitemid));
            } elseif ($oldcontent4mlxid && ($oldcontent4mlxid[(string)$k][0]['value'] == $qp_cntitemid)) {
                $content4mlxid[(string)$k] = 0;
            }
        }

        //huhl("update(", $content4mlxid, "$id, $lang, $qp_cntitemid, $cntitemid, ".unpack_id($qp_cntitemid)." ".strlen($qp_cntitemid)." ".pack_id($content4id->getItemID()));
        //die;
        if (empty($content4mlxid)) {
            $this->fatal("Creating the Control Language Data failed. "
                ."Maybe you have to select a Language Code, "
                ."or create the chosen Language Code in the Control Slice Fields.");
        }
        //      			echo "<pre>"; print_r($content4mlxid); echo "</pre>";
        //      			die;
        //$this->dbg($content4mlxid);
        //$GLOBALS[errcheck] = 1;
        //$GLOBALS[debugsi] = 5;

        $content4mlxid["publish_date...."][0]['value'] = time();
        $content4mlxid["expiry_date....."][0]['value'] = time() + 200*365*24*60*60;
        $content4mlxid["status_code....."][0]['value'] = 1;
        $p_id = pack_id($id);
        if((strlen($id) != 32) || (strlen($p_id)!=16)) {
            $this->fatal("MLX update: mlxid corrupted: $id ".strlen($id).", $p_id");
        }

        $added = StoreItem($id,$this->langSlice,$content4mlxid, $this->ctrlFields,$insert,true,true);

        $content4id->setValue(MLX_CTRLIDFIELD, $p_id);
        $this->trace("done. id=$id ($added)");
    }

    /** itemform function
     * @param $lang_control
     * @param $params
     * @param $content4id
     * @param $action
     * @param $lang
     * @param $mlxid
     *   Returns array of:
     *     - HTML code which will be displayed at the top of the form with
     *       language selections,
     *     - language
     *     - mlxid - id of the main mlx artice (where other items are joined)
     */
    function itemform($lang_control, $params, $content4id, $action, $lang, $mlxid)
    {
        global $DOCUMENT_URI;
        global $PHP_SELF;
        global $err;
        $mlxout   = MLX_HTML_TABHD;
        $mlx_url  = ($DOCUMENT_URI != "" ? $DOCUMENT_URI : $PHP_SELF . ($return_url ? "?return_url=".urlencode($return_url) : ''));
        $mlx_url .= "?".$this->getparamstring($params);
        switch ($action) {
            case "update":
            case "edit":
                $lang  = $content4id['lang_code.......'][0][value];
                $mlxid = unpack_id($content4id[MLX_CTRLIDFIELD][0][value]);
                break;
            case "insert":
            case "add":
            default:
                $mlxCtrl =  new MLXCtrl($mlxid, $this);
                // get first non empty set of translations of current item ($mlxid)
                $defCntId = $mlxCtrl->getFirstNonEmpty();
                if ($defCntId) {
                    $tritemid    = unpack_id(array_shift($defCntId));
                    $itemcontent = GetItemContent($tritemid);
                    $content4form = $itemcontent[$tritemid];
                    foreach ($this->slice->fields('record') as $slfield) {
                        $kstr = 'v'.unpack_id($slfield[id]);
                        unset($GLOBALS[$kstr]);
                        unset($GLOBALS[$kstr."html"]);
                        if (!$slfield[input_show]) {
                            continue;
                        }
//						$this->dbg($slfield);
                        $itcnt = $content4form[$slfield[id]];
                        $GLOBALS[$kstr."html"] = ($v[0]['flag']==65 ? 1 : 0); //TODO fix
                        if ($slfield['multiple'] && is_array($itcnt)) {
                            foreach ($itcnt as $vai) {
                                $GLOBALS[$kstr][] = addslashes($vai['value']);
                            }
                        } else {
                            $GLOBALS[$kstr] = addslashes($itcnt[0]['value']);
                        }
                    }
                    //$this->dbg($GLOBALS);
                }
                $GLOBALS['v'. unpack_id(MLX_CTRLIDFIELD)]= q_pack_id($mlxid);
                //check if lang is set, set to default from MLX
                if (!$lang) {
                    $GLOBALS['v'. unpack_id('lang_code.......')] = $mlxCtrl->getDefLangName();
                } else {
                    $GLOBALS['v'. unpack_id('lang_code.......')] = $lang;
                }
                //$this->dbg($GLOBALS['v'. unpack_id('lang_code.......')]);
                //$this->dbg(GetItemContent($mlxid));
                break;
        }
        if (!$GLOBALS['g_const_Lang']) {
            $GLOBALS['g_const_Lang'] = GetConstants('lt_languages',0);
        }
        foreach( $this->ctrlFields as $k => $v ) {
            $href   = "";
            $modstr = "";
            $extra  = "";
            // check if id begins with 'mlxctrl'
            if (!isset($v[id]) || ( strpos($v[id],MLX_LANG2ID_TYPE) !== 0)) {
                continue;
            }
            $mlxout .= "<td valign='top' class='tabs";
            if (($action == "edit") || ($action == "update")) {
                $langid = $this->getLangItem($v['name'], $content4id, $content4mlxid);
            } elseif ($mlxid) {
                $langid = $this->getLangItem($v['name'], $mlxid, $content4mlxid);
            }
            $this->trace($action." ".$v[id]." ".$langid);
            if ($lang != $v['name']) {
                if ($langid) {
                    $href    = "id=$langid&edit=1&";
                    $modstr  = _m("Edit")." ";
                    $mlxout .= "active mlx-edit";
                } else {
                    $href    = "add=1&mlxl=".$v['name']."&";
                    $modstr  = _m("Add")." ";
                    $mlxout .= "active mlx-add";
                }
                $href .= "mlxid=$mlxid&";
            } else {
                $mlxout .= "nonactive' bgcolor='".COLOR_TABBG;
            }
            if ($langid) {
                $extra = "\n<span class='mlx-view'><a href='../slice.php3"
                    ."?slice_id=".$this->slice->unpackedid."&sh_itm=".$langid
                    ."&o=1&mlxl=".$v['name']."' target='_blank'>("._m("view").")</a>"
                    ."</span>\n";
            }
            $mlxout .= "'>&nbsp;";
            if ($href) { //TODO implement something that saves on switch
                $mlxout .= "<a href='$mlx_url$href'>";
             // $mlxout .= "<a href='javascript:mlx_saveOnSwitch($mlx_url$href)'>";
            }
            $langName = $GLOBALS['g_const_Lang'][$v['name']];
            $mlxout  .= $modstr.($langName ? $langName : $v['name']). ($href ? "</a>" : ""). $extra;
            $mlxout  .= "&nbsp;</td>\n ";
        }
        //this is a hack
        if ($GLOBALS['mlxScriptsTable'][(string)$lang] && $GLOBALS['mlxScriptsTable'][(string)$lang]['DIR']) {
            $GLOBALS['mlxFormControlExtra'] = " DIR=".$GLOBALS['mlxScriptsTable'][(string)$lang]['DIR']." ";
        }
        return array($mlxout.MLX_HTML_TABFT, (string)$lang, (string)$mlxid);//"\n</tr>\n</tbody>\n</table>\n<br>\n";
    }

    // helper functions
    //protected:
    /** getLangItem function
     * @param $lang
     * @param $content4id
     * @param $content4mlxid
     */
    function getLangItem($lang, &$content4id, &$content4mlxid)
    {
        global $err;
        if (is_array($content4id)) {
            $mlxid = unpack_id($content4id[MLX_CTRLIDFIELD][0]['value']);
        } else {
            $mlxid = $content4id;
        }
        //mlxid should now hold the itemid of the mlx info
        if (!$mlxid) {
            return false;
        }
        //__mlx_dbg($mlxid,"mlxid");
        if (empty($content4mlxid) && (isset($mlxid))) {
            $content = GetItemContent($mlxid);
            //var_dump($content);
            if (!$content) {
                $this->fatal(_m("Bad item ID %1", array($mlxid)).var_export($mlxid));
            }
            $content4mlxid = $content[$mlxid];
        }
        if (empty($content4mlxid)) {
            $this->fatal(_m("No ID for MLX"));
        }
        foreach ($this->ctrlFields as $v) {
            if ($v['name'] == $lang) {
                return unpack_id($content4mlxid[$v['id']][0]['value']);
            }
        }
        return false;
    }
    /** getparamstring function
     * @param $params
     */
    function getparamstring(&$params)
    {
        foreach($params as $k => $v) {
            $mlx_url .= "$k=";
            switch(gettype($v)) {
                case 'boolean':
                    $mlx_url .= ($v)?"true":"false";
                    break;
                default:
                    $mlx_url .= $v;
            }
            $mlx_url .= "&";
        }
        return $mlx_url;
    }
    /** fatal function
     * @param $msg
     */
    function fatal($msg) {
        __mlx_fatal($msg);
    }
    /** dbg function
     * @param $v
     */
    function dbg($v) {
        __mlx_dbg($v);
    }
    /** trace function
     * @param $v
     */
    function trace($v) {
        __mlx_trace($v);
    }
};

class MLXView
{
    // the language code to default to
    var $language = array();
    // the mode to use: MLX  -> use defaulting: if lang not available fall back
    //                          to another translation of same article (MultiLingual)
    //                  ONLY -> show only items available in this language (like conds[lc]=lang)
    //                  ALL  -> show all articles regardless of language (like without MLX)
    var $mode = "MLX";
    //var $translations;
    ///@param mlx is the thing set in the URL
    ///@param slice_id is a fallback in case mlx is missing
    /** MLXView function
     * @param $mlx
     * @param $slice_id
     */
    function MLXView($mlx,$slice_id=0) {
        if($slice_id) {
            unset($GLOBALS['MLX_TRANSLATIONS'][(string)$slice_id]);
        }
//          __mlx_dbg(var_dump(func_get_args(),true),__FUNCTION__);
        $supported_modes = array("MLX","ONLY","ALL");
        if($mlx) {
            $arr = explode("-",$mlx);
            foreach($arr as $av) {
                $av = strtoupper($av);
                if(in_array($av,$supported_modes))
                    $this->mode = $av;
                else
                    $this->language[] = $av;
            }
        } else { //mlx is not set for some reason, get default prios
            $aPrio = $this->getPrioTranslationFields($slice_id);
            $this->language = array_keys($aPrio);
        }
    }
    /** preQueryZIDs function
     * @param $ctrlSliceID
     * @param $conds
     * @param $slices
     */
    function preQueryZIDs($ctrlSliceID,&$conds,&$slices)
    {
        switch($this->mode) {
            case 'ONLY': //add to conds
                $translations = $this->getPrioTranslationFields($ctrlSliceID);
                $value = key($translations);
                $conds[] = array('lang_code.......'=>$value,
                    'value'=>$value,
                    'operator'=>"=");
                break;
// 		__mlx_dbg($conds,"conds".__FUNCTION__);
            default:
//                 __mlx_dbg(var_dump(func_get_args(),true),__FUNCTION__);
                break;
        }
    }
    /** postQueryZIDs function
     *   optimised postQueryZIDs -- using join and tagging minimises SQL queries
     *
     *   This filters a list of item_ids by checking translations
     *   and removing duplicates, only keeping either desired or
     *   prioritised translation
     * @param $zidsObj
     * @param $ctrlSliceID
     * @param $slice_id
     * @param $conds
     * @param $sort
     * @param $group_by
     * @param $type
     * @param $slices
     * @param $neverAllItems
     * @param $restrict_zids
     * @param $defaultCondsOperator
     * @param $nocache
     * @param $cachekeyextra
     */
    function postQueryZIDs(&$zidsObj,$ctrlSliceID,$slice_id, $conds, $sort,
        $group_by,$type, $slices, $neverAllItems, $restrict_zids,
        $defaultCondsOperator,$nocache,$cachekeyextra="")
    {
        global $QueryIDsCount, $debug;

        if($this->mode != "MLX") {
            return;
        }
        if($zidsObj->count() == 0) {
            return;
        }

        $translations = $this->getPrioTranslationFields($ctrlSliceID,$slice_id);
        $arr = array();
        foreach($zidsObj->a as $packedid) {
            $arr[(string)$packedid] = 1;
        }
        $db = getDB();
        reset($arr);
        while(list($upContId,$count) = each($arr)) {
            if($count > 1) // already primary
                continue;
            //speedup
//             if(MLX_OPTIMIZE > 5) {
//                 reset($translations);
//                 $sql = "SELECT * from `content`"
//                     ." WHERE `text`='".quote($upContId)."'"
//                     ." AND field_id='".current($translations)."' LIMIT 1";
//                 $db->tquery($sql);
//                 if($db->num_rows() > 0) {
//                     if($GLOBALS['mlxdbg']) {
//                         huhl($sql);
//                         __mlx_dbg($db->Record,"MLXQUICK");
//                     }
//                     $arr[(string)$upContId]++;
//                     continue;
//                 }
//             }
            //conservative
            $sql = "SELECT  c2.field_id,c2.text FROM `content` AS c1" //`field_id`,`text`
//				." LEFT JOIN `content` AS c2 ON ("
                ." INNER JOIN `content` AS c2 ON ("
                ." c2.item_id=c1.text )"
                ." WHERE (c1.item_id='".quote($upContId)."'"
                ." AND c1.field_id='".MLX_CTRLIDFIELD."'"
                ." AND c2.field_id RLIKE '".MLX_LANG2ID_TYPE."') LIMIT ".count($translations);
            $db->tquery($sql);
            unset($aMlxCtrl);
            while( $db->next_record() ) { //get all translations
                $aMlxCtrl[(string)$db->Record[0]] = $db->Record[1];
                /*
                $GLOBALS['MLX_ALT'][(string)unpack_id($db->Record[1])] = array(
                'lang' => array_search((string)$db->Record[0],$translations),
                'id' => (string)unpack_id($upContId));
                */
            }
            //__mlx_dbg($aMlxCtrl,"aMlxCtrl");
            $bFound = false;
            foreach($translations as $tr) {
                $fieldSearch = $aMlxCtrl[$tr];
                if(!$fieldSearch) {
                    continue;
                }
                /*$GLOBALS['MLX_ALT'][(string)unpack_id($fieldSearch)][] = array($l=>
                    unpack_id($upContId));
                */
                if($bFound) {
                    unset($arr[(string)$fieldSearch]);
                    //__mlx_dbg($arr,"arr");
                } else {
                    $arr[(string)$fieldSearch]++; //tag as primary
                    $bFound = true;
                }
            }
        }
        //__mlx_dbg($arr,"return");
        freeDB($db);
        $QueryIDsCount = count($arr);
        $zidsObj->a    = array_keys($arr);
    }
    /** getAlternatives function
     *   at the moment this is not useful unless you write your own
     *   stringexpand function to produce useable output
     *   ideally, this would use the a view from some slice to display
     *   the languages -- it doesnt have to know the itemdids since
     *   mlx can handle "wrong" itemids
     * @param $p_itemid
     * @param $slice_id
     */
    function getAlternatives($p_itemid,$slice_id) {
        if(!$p_itemid) {
            if($GLOBALS['errcheck']) {
                huhl("MLXView::getAlternatives zero id passed");
            }
            return "";
        }
        $ctrlSliceID = $GLOBALS['MLX_TRANSLATIONS'][(string)unpack_id($slice_id)];
        if(!$ctrlSliceID) {
            return "";
        }
        $translations = $this->getPrioTranslationFields($ctrlSliceID);
        $db  = getDB();
        $sql = "SELECT c2.text,c2.field_id FROM `content` AS c1" //c2.text c2.field_id,
//			." LEFT JOIN `content` AS c2 ON ("
            ." INNER JOIN `content` AS c2 ON ("
            ." c2.item_id=c1.text )"
            ." WHERE (c1.item_id='".quote($p_itemid)."'"
            ." AND c1.field_id='".MLX_CTRLIDFIELD."'"
            ." AND c2.field_id RLIKE '".MLX_LANG2ID_TYPE."') ";/*LIMIT ".count($translations);*/
        $db->tquery($sql);
        $o   = "";
        $cnt = 0;
        while( $db->next_record() ) { //get all translations
            if($db->Record[0] == $p_itemid) {
                continue;
            }
            if($cnt++ > 0) {
                $o .= "-";
            }
            $o .= array_search((string)$db->Record[1],$translations)."=";
            $o .= unpack_id($db->Record[0]);
            //__mlx_dbg($db->Record);
            //__mlx_dbg(unpack_id($db->Record[1]));
        }
        return $o;
    }
    /** getTranslations function
     *  the mlx mini view generator
     * @param $p_itemid
     * @param $slice_id
     * @param $params
     */
    function getTranslations($p_itemid,$slice_id,$params) {
        //__mlx_dbg(unpack_id($p_itemid),"itemid");
        //__mlx_dbg(unpack_id($slice_id),"slice_id");
        //__mlx_dbg($params,"params");
        if(!$p_itemid) {
            if($GLOBALS['errcheck']) huhl("MLXView::getAlternatives zero id passed");
            return "";
        }
        $ctrlSliceID = $GLOBALS['MLX_TRANSLATIONS'][(string)unpack_id($slice_id)];
        if(!$ctrlSliceID) {
//             $GLOBALS['errcheck'] = true;
//              __mlx_dbg(func_get_args(),__FUNCTION__);
            $sliceobj = AA_Slices::getSlice(unpack_id($slice_id));
            $ctrlSliceID = $sliceobj->getProperty(MLX_SLICEDB_COLUMN);
            if(!$ctrlSliceID) {
                return "MLXView::getTranslations no ctrlSliceID";
            }
            $GLOBALS['MLX_TRANSLATIONS'][(string)unpack_id($slice_id)] = unpack_id($ctrlSliceID);
        }
        $translations = $this->getPrioTranslationFields($ctrlSliceID);
        $db  = getDB();
        $sql = "SELECT c2.text,c2.field_id FROM `content` AS c1" //c2.text c2.field_id,
//			." LEFT JOIN `content` AS c2 ON ("
            ." INNER JOIN `content` AS c2 ON ("
            ." c2.item_id=c1.text )"
            ." WHERE (c1.item_id='".quote($p_itemid)."'"
            ." AND c1.field_id='".MLX_CTRLIDFIELD."'"
            ." AND c2.field_id RLIKE '".MLX_LANG2ID_TYPE."') LIMIT ".count($translations);
        $db->tquery($sql);
        $o    = "";
        $tmpl = $params[0];
        if(!$tmpl) {
            return "mlx_view: forgot the view template..";
        }
        while( $db->next_record() ) { //get all translations
            if($db->Record[0] == $p_itemid) {
                continue;
            }
            //$o .= array_search((string)$db->Record[1],$translations)."=";
            //$o .= unpack_id($db->Record[0]);
            $lang    = array_search((string)$db->Record[1],$translations);
            $itemid  = unpack_id($db->Record[0]);
            $o      .= str_replace(array("%lang","%itemid"),array($lang,"$itemid"),
                $tmpl);
            //__mlx_dbg($db->Record);
        }
        return $o;
    }
    /** getPrioTranslationFields function
     * @param $ctrlsliceID
     * @param $slice_id
     */
    function getPrioTranslationFields($ctrlSliceID,$slice_id=0) {
        if($GLOBALS['MLX_TRANSLATIONS'][(string)$ctrlSliceID]) {
            return $GLOBALS['MLX_TRANSLATIONS'][(string)$ctrlSliceID];
        }
        list($fields,) = GetSliceFields($ctrlSliceID);
        $translations  = array();
        if(!is_array($fields)) {
            return $translations;
        }
        foreach( $fields as $v ) {
            if(isset($v['id']) && ( strpos($v['id'],MLX_LANG2ID_TYPE)) === 0) {
                $tmptrans[(string)$v['name']] = $v['id'];
            }
        }
        foreach( $this->language as $lang) {
            if(!$tmptrans[(string)$lang]) {
                continue;
            }
            $translations[(string)$lang] = $tmptrans[(string)$lang];
            unset($tmptrans[(string)$lang]);
        }
        foreach( $tmptrans as $lang => $langfield) {
            $translations[(string)$lang] = $langfield;
        }
        $GLOBALS['MLX_TRANSLATIONS'][(string)$ctrlSliceID] = $translations;
        if($slice_id) {
            $GLOBALS['MLX_TRANSLATIONS'][(string)$slice_id] = (string)$ctrlSliceID;
        }
        return $translations;
    }
    /** getLangByIdx function
     * @param $idx
     */
    function getLangByIdx($idx) {
        if($idx > count($this->language)) {
            return false;
        }
        return $this->language[$idx];
    }
    /** getCurrentLang function
     *
     */
    function getCurrentLang() {
        return $this->language[0];
    }
}
class MLXEvents
{
    /** MLXEvents function
     *
     */
    function MLXEvents()
    {
    }
    /** itemsBeforeDelete function
     * @param $item_ids
     * @param $slice_id
     */
    function itemsBeforeDelete(&$item_ids,$slice_id)
    {
        if(empty($item_ids)) {
            return;
        }
        $sliceobj = AA_Slices::getSlice($slice_id);
        if(!IsMLXSlice($sliceobj)) {
            return;
        }
//		echo "<h2>called</h2><pre>";
//		print_r($item_ids);
//		print("$slice_id");
        // dont use this unless you use the special field type mlxctrl everywhere
        $rm_itemids = array();
        $db         = getDB();
        foreach($item_ids as $itemid) {
            $db->query("SELECT item_id FROM content WHERE ( `field_id` "
                    ."RLIKE '".MLX_LANG2ID_TYPE."' AND "
                    ."`text`='".quote($itemid)."')");
            while( $db->next_record() ) {
                $rm_itemids[] = $db->f("item_id");
            }
            $db->query("DELETE FROM content WHERE ( `field_id` "
                    ."RLIKE '".MLX_LANG2ID_TYPE."' AND "
                    ."`text`='".quote($itemid)."')");
            }
        foreach($rm_itemids as $itemid) {
            $db->query("SELECT * FROM content WHERE ("
                ." `item_id`='".quote($itemid)."' "
                ." AND `field_id` "
                ."RLIKE '".MLX_LANG2ID_TYPE."')");
            if($db->num_rows() === 0) {
                $db->query("DELETE FROM content WHERE "
                    ." `item_id`='".quote($itemid)."' ");
                $db->query("DELETE FROM item WHERE "
                    ." `id`='".quote($itemid)."' ");
            }
        }
        freeDB($db);
//		echo "</pre>";
    }
};
class MLXGetText
{
    var $domains = array();
    var $currentDomain;
    var $currentDomainRef;
    var $currentLang = 0;
    //var $currentSlice = 0;
    /** MLXGetText function
     *
     */
    function MLXGetText() {
        $ca = array('global');
        $this->setdomain($ca);
    }
    /** translate function
     * @param $args
     * @param $lang
     * @param $slice_id
     */
    function translate(&$args,$lang,$slice_id=0) {
//		__mlx_dbg($args,"translate args");
//		__mlx_dbg($slice_id,"translate slice");
        $retval = $this->currentDomainRef[$lang][$args[0]];
        //__mlx_dbg("retval",$retval);
        if(($this->currentDomainRef['mode'] && 1)
            && ($lang == $this->currentDomainRef['defaultLang'])) {
            if($retval == MLX_NOTRANSLATION) {
                $retval = $args[0];
            } else if(!$retval) {
                $this->addtext($args);
                $this->currentDomainRef[$lang][$args[0]] = MLX_NOTRANSLATION;
            }
        }
        if(!$retval) {
            $retval = $args[0];
        }
        $count = 1;
        while($param = next($args)) {
            $retval = str_replace("%$count",$param,$retval);
            $count++;
        }
        return $retval;
    }
    /** command function
     * @param $args
     * @param $slice_id
     */
    function command(&$args,$slice_id=0) {
//		__mlx_dbg($args,"command args");
//		__mlx_dbg($slice_id,"command slice");
        call_user_func_array(array(&$this, $args[0]),array(&$args,&$slice_id));
        return "";
    }
    ///\param slice_id 	unpacked id of slice containing translations
    ///\param lang 		language to add to for MLXGetText
    ///\param domain 	domain to add this slice to (default=global)

    ///\param mode 		mode=learn automatically add items for
    ///			unknown texts (also set nocache=1, and be in default language)
    /** addslice function
     * @param $args
     * @param $slice_id
     */
    function addslice(&$args,$slice_id=0) {
        //xdebug_start_profiling();
        list(,$slice2add,$lang,$domain,$mode) = $args;
        $scda = array($domain);
        $this->setdomain($scda);
        if($this->currentDomainRef['slices'][$slice2add]) {
            return $slice2add;
        }
        $mode = ($mode=='learn'?1:0);
        $isModeActive = ($mode && 1);
        $this->currentDomainRef['mode'] = $mode;
        $this->currentDomainRef['slices'][$slice2add] = array();
        list($fields,) = GetSliceFields($slice2add);
        //__mlx_dbg($fields);
        $lang = strtoupper($lang);
        foreach($fields as $fname=>$fdata) {
//			__mlx_dbg($fdata[name]);
            if($fname == 'headline........') {
                if($this->currentDomainRef['defaultLang']
                    && $this->currentDomainRef['defaultLang'] != $fdata['name']) {
                    if($GLOBALS['errcheck']) {
                        huhl('MLXGetText mixing different default languages in one domain: $domain');
                    }
                }
                $this->currentDomainRef['defaultLang'] = $fdata['name'];
            }
            if($fdata['name'] == $lang) {
                $langField = $fname;
                break;
            }
        }
        $isDefLang = ($langField == 'headline........');
        if($isDefLang && !$isModeActive) {
            return; //default language, nonactive mode
        }
        if($isModeActive) {
            $this->currentDomainRef['slices'][$slice2add]['fields'] = $fields;
        }
        $db  = getDB();
        $sql = "SELECT  c1.item_id,c1.field_id,c1.text FROM `content` AS c1" //`field_id`,`text`c2.field_id,c2.text
                ." LEFT JOIN `item` AS c2 ON ("
                ." c2.id=c1.item_id )"
                ." WHERE ( c2.slice_id='".q_pack_id($slice2add)."'"
                ." AND c2.status_code=1"
                ." AND ( c1.field_id='headline........'"
                .(!$isDefLang?" OR c1.field_id='".$langField."'":"")
                ."))";
            $db->tquery($sql);
        while( $db->next_record() ) {
            list($item_id,$field_id,$text) = $db->Record;
            $item_id  = unpack_id($item_id);
            $refSlice = &$this->currentDomainRef[$lang];
            if($field_id == 'headline........') {
                if($refSlice[$item_id]) {
                    $refSlice[$text] = $refSlice[$item_id];
                    unset($refSlice[$item_id]);
                } else {
                    if($isDefLang) {
                        $refSlice[$text] = MLX_NOTRANSLATION;
                    } else {
                        $refSlice[$item_id] = $text;
                    }
                }
            } else if($field_id == $langField) {
                if($refSlice[$item_id]) {
                    if($isModeActive) {
                        $refSlice[$refSlice[$item_id]] = $text;
                    } else {
                        if($text != '') {
                            $refSlice[$refSlice[$item_id]] = $text;
                        }
                    }
                    unset($refSlice[$item_id]);
                } else {
                    $refSlice[$item_id] = $text;
                }
            }
        }
        freeDB($db);
//		__mlx_dbg($current);
//		__mlx_dbg($this->domains);
    }
    /** setdomain function
     * @param $args
     * @param $slice_id
     */
    function setdomain(&$args,$slice_id=0) {
        if( $args[1] ) { // make sure we stay in a domain
            $this->currentDomain = $args[1];
        }
        $this->currentDomainRef = &$this->domains[$this->currentDomain];
    }
    /** setLang function
     *  set the current language in case we are not in a view
     * @param $args
     * @param $slice_id
     */
    function setlang(&$args,$slice_id=0) {
        $this->currentLang = strtoupper($args[1]);
        if($GLOBALS['mlxdbg']) {
            huhl("MLX setting language to <b>".$this->currentLang."</b>");
        }
    }
    /** debug function
     * @param $args
     * @param $slice_id
     */
    function debug(&$args,$slice_id=0) {
        __mlx_dbg($this->domains);
    }
    /** addtext function
     *  we are adding the item to the last slice added
     *  this could also be used for manually adding items
     * @param $args
     * @param $slice_id
     */
    function addtext(&$args,$slice_id=0) {
        global $event;
        __mlx_dbg($this->domains);
        __mlx_dbg($args,__function__);
        $old_varset = $GLOBALS['varset'];
        $old_itemvarset = $GLOBALS['itemvarset'];
        if(!is_callable('StoreItem')) {
            require_once('itemfunc.php3');
        }
        $sliceid = end(array_keys($this->currentDomainRef['slices']));
        if(!$sliceid) {
            return;
        }
        //__mlx_dbg($sliceid);
        if($GLOBALS['mlxdbg']) {
            huhl("MLX adding translateable item for <b>".$args[0]."</b> to $sliceid");
            $GLOBALS['debugsi'] = 1;
        }
        $content4id['headline........'][0]['value'] = $args[0];
        $content4id['post_date.......'][0]['value'] = time();
        $content4id['publish_date....'][0]['value'] = time();
        $content4id['expiry_date.....'][0]['value'] = time()+3600*24*365*10;
        StoreItem( new_id(), $sliceid, $content4id,
            $this->currentDomainRef['slices'][$sliceid]['fields'],
            true,true,false);
        $GLOBALS['varset'] = $old_varset;
        $GLOBALS['itemvarset'] = $old_itemvarset;
    }
};
/** stringexpand_m function
 *
 */
function stringexpand__m() { //the second _ is not pretty here, but is in {_:
    global $errcheck;
    $arg_list = func_get_args();
    if(!$GLOBALS['mlxGetText']) {
        if($errcheck) {
            huhl("mlxGetText not initialised");
        }
        return DeQuoteColons($arg_list[0]);
    }
    $lang = $GLOBALS['mlxGetText']->currentLang;
    if(!$lang) {
        if(!$GLOBALS['mlxView']) {
            if($errcheck) {
                huhl("MLX no global mlxView set: this shouldnt happen in: ".__FILE__.",".__LINE__);
            }
            return  DeQuoteColons($arg_list[0]);
        }
        $lang = $GLOBALS['mlxView']->getLangByIdx(0);
    }
    return $GLOBALS['mlxGetText']->translate($arg_list,$lang);
}
/** stringexpand_mlx function
 *
 */
function stringexpand_mlx() {
    global $errcheck;
    $arg_list = func_get_args();
    if(!$GLOBALS['mlxGetText']) {
        $GLOBALS['mlxGetText'] = new MLXGetText;
    }
    if($errcheck) {
        huhl("mlxGetText initialised");
    }
    return $GLOBALS['mlxGetText']->command($arg_list);
}
?>
