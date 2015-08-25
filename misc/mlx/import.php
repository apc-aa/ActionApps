<?php
/// MLX MultiLingual eXtension for ActionApps
//$Id$
/// @brief Import for MLX MultiLingual eXtension for ActionApps http://mimo.gn.apc.org/mlx
/// @author mimo/at/gn.apc.org for GreenNet
/// (C)2004 Michael Moritz

///set this to false unless you know what you are doing
define('ALLOW_DROP',false);

if ( !defined(AA_INC_PATH) ) {
    require_once "../../include/config.php3";
}
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."mlx.php";

class MLXImport {
//public:
    ///@param $slice_id (string) unpacked slice id of content slice (not control slice)
    function MLXImport($slice_id) {
        $this->slice_id = $slice_id;

        $this->sliceObj = AA_Slice::getModule($slice_id);

        list($this->fields,) = GetSliceFields($this->slice_id);
        //do sanity checks
        $this->ctrlSlice = IsMLXSlice($this->sliceObj);
        if(!$this->ctrlSlice)
            $this->fatal("Not an MLX content slice, MLX control slice id not set");
        if(!$this->fields[MLX_CTRLIDFIELD])
            $this->fatal("MLX content slice is missing the mlxctrl field");
        if(!$this->fields['lang_code.......'])
            $this->fatal("MLX content slice is missing the language field");
        //build a list of required fields
        foreach($this->fields as $k => $v) {
            if(!$v['required'])
                continue;
            // i think these get added by storeitem
            if($v['in_item_tbl'])
                continue;
            $this->required[] = (string)$k;
        }
//		__mlx_dbg($this->required,'required fields:');
        //huhl($this->fields);
        ///TODO MLX doesnt do any sanity checks
        $this->mlxObj = new MLX($this->sliceObj);
    }

    function drop() {
        if (! ALLOW_DROP) {
            $this->fatal("drop is not enabled on this system");
        }
        $numDeleted = array();
        $slice_ids = array($this->ctrlSlice,$this->slice_id);
        foreach($slice_ids as $sliceid) {
            $db = mysql_connect(DB_HOST,DB_USER,DB_PASSWORD);
            mysql_select_db(DB_NAME,$db);
            $p_sid= q_pack_id($sliceid);
            $result = mysql_query("SELECT * FROM item where slice_id='$p_sid'",$db);
        //      print "$result\n";
        //      die;
            while ($row = mysql_fetch_array($result)) {
                //echo "Deleting: ".unpack_id($row["id"]);
                $iid=q_pack_id(unpack_id($row["id"]));
                mysql_query("DELETE FROM content where item_id='$iid'",$db);
                $num = mysql_affected_rows();
                if($num)
                    $numDeleted["$sliceid"]['content']['deleted'] += $num;
                else
                    $numDeleted["$sliceid"]['content']['failed']++;
                mysql_query("DELETE FROM item where id='$iid'",$db);
                $num = mysql_affected_rows();
                if($num)
                    $numDeleted["$sliceid"]['item']['deleted'] += $num;
                else
                    $numDeleted["$sliceid"]['item']['failed']++;
            }
            mysql_free_result($result);
        }
        return $numDeleted;
    }
    ///@param $data (array) data to import, see test below for structure
    ///TODO support for $action='update'
    ///TODO support for $action='replace' and unique fields
    function import(&$data,$action = 'insert') {
        $defaults["display_count..."][0]['value'] = 0;
        $defaults["status_code....."][0]['value'] = 1;
        $defaults["flags..........."][0]['value'] = ITEM_FLAG_OFFLINE;
        $defaults["posted_by......."][0]['value'] = 0;
        $defaults["edited_by......."][0]['value'] = 0;
        $defaults["publish_date...."][0]['value'] = time();
        $defaults["expiry_date....."][0]['value'] = time() + 200*365*24*60*60;

//		define('MLX_TRACE',1);
//		$GLOBALS['errcheck'] = true;
//		$GLOBALS[debugsi] = 6;
        $mlxid = 0;
        $added = 0;
        foreach($data as $mlxl=>$content) {
            foreach($defaults as $k=>$v)
                if(!$content[$k])
                    $content[$k] = $v;
            foreach($this->required as $k)
                if(!$content[$k])
                    $this->fatal("required field $k missing in content: ".$content['headline........'][0][value]);
//			__mlx_dbg($content,$lang);
            if(!$content['lang_code.......'][0][value])
                $content['lang_code.......'][0][value] = $mlxl;
            else if($content['lang_code.......'][0][value] != $mlxl)
                $this->fatal("different language in item from data! $mlxl!=".var_export($content,true));
            // get an id for the content
            $cntitemid = new_id();
            // use the mlx object to create the control data item
            // or update it (this is the slow way of doing things)
            $this->mlxObj->update($content,$cntitemid,$action,$mlxl,$mlxid);
            if(!$mlxid) {
                // save the item id of the the mlx control item
                $mlxid = unpack_id($content[MLX_CTRLIDFIELD][0][value]);
                // preserve info for rollback (not implemented)
                $this->added[$mlxid]['mlxid'] = $mlxid;
                if($mlxl == 'EN') //q&d
                    $this->added[$mlxid]['headline........'] = $content['headline........'][0][value];
            }
            // store the content
            $added += StoreItem( $cntitemid, $this->slice_id, $content, ($action=='insert') );     // invalidatecache, feed
            // preserve info for rollback (not implemented)
            $this->added[$mlxid][$mlxl] = $cntitemid;
        }
        //__mlx_dbg($this->added,'rollback data');
        //__mlx_dbg($added,'added');
        return $added;
    }
    function rollback($steps) {
        while($steps > 0) {
            //TODO write this
        }
    }
    function last_added() {
        return end($this->added);
//		return key($this->added);
    }
    function forget_added() {
        unset($this->added);
    }
    function find(&$content) { // in "content" format
        foreach($content as $field=>$arVal) {
            $conds[$field] = $arVal[0]['value'];
        }
        $zids = QueryZIDs($this->slice_id, $conds,'','ACTIVE',0,false, '=');
        return ($GLOBALS['QueryIDsCount'] == 0) ? false : $zids->longids();
    }
//protected:
    function fatal($msg) {
        if(is_array($msg))
            $msg = implode("<br>",$msg);
        $err["MLX"] = MsgErr($msg);
        echo $err["MLX"];
        //MsgPage("",$err["MLX"]);
        die;
    }
//private:
    var $slice_id;
/*
    var $varset;
    var $itemvarset;
    var $db;
*/
    var $sliceObj;
    var $ctrlSlice;
    var $fields;
    var $required;
    var $mlxObj;
    var $added;
};
function mlx_import($slice_id,&$data) {
    if(!$GLOBALS['mlxImport']) {
        $GLOBALS['mlxImport'] = new MLXImport($slice_id);
    }
    return $GLOBALS['mlxImport']->import($data);
}

/// handler for unit tests
///@return true uf works
function test_misc_mlx_import($slice_id='f44255369e0d5e6c938c91ab929fd060') {
    $testData = array(
        'EN' => array(
            'headline........' => array(array('value'=>'headline of imported bla')),
            'full_text.......' => array(array('value'=>'fulltext of imported bla'))
            ),
        'DE' => array(
            'headline........' => array(array('value'=>'deutscher Titel des importierten bla')),
            'full_text.......' => array(array('value'=>'deutscher Volltext des importierten bla'))
            )
    );
    return (mlx_import($slice_id,$testData) == 2);
}
if($_REQUEST['test']) {
    if($GLOBALS['unit_test_array']['mlx_content_slice_id'])
        $retval = test_misc_mlx_import($GLOBALS['unit_test_array']['mlx_content_slice_id']);
    else
        $retval = test_misc_mlx_import();
    echo "test ".($retval?'ok':'failed');
}
?>
