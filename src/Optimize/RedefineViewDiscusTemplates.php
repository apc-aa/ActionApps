<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;
use const AA_BASE_DIR;

/** Recreates the template view - discus **/
class RedefineViewDiscusTemplates extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string {
        return _m("Redefine discus template view");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("Deletes and recreates that view, which is used as template view of that type");
    }


    function _viewType() {
        return 'discus';
    }

    function _viewDefinition() {
        $AA_IMG_URL = '/' . AA_BASE_DIR . 'images/';
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Discussion ...', type='discus', `before`='<table><tr><td><table><tr><td colspan=8><big>Comments</big></td></tr>', even='<table  width=500><tr><td colspan=2><hr></td></tr><tr><td width=\"20%\"><b>Date:</b></td><td> _#DATE####</td></tr><tr><td><b>Comment:</b></td><td> _#SUBJECT#</td></tr><tr><td><b>Author:</b></td><td><A href=mailto:_#EMAIL###>_#AUTHOR##</a></td></tr><tr><td><b>WWW:</b></td><td><A href=_#WWW_URL#>_#WWW_DESC</a></td></tr><tr><td><b>IP:</b></td><td>_#IP_ADDR#</td></tr><tr><td colspan=2>&nbsp;</td></tr><tr><td colspan=2>_#BODY####</td></tr><tr><td colspan=2>&nbsp;</td></tr><tr><td colspan=2><a href=_#URLREPLY>Reply</a></td></tr></table><br>', odd='<tr><td width=\"10\">&nbsp;</td><td><font size=-1>_#CHECKBOX</font></td><td width=\"10\">&nbsp;</td><td><small>_#DATE####</small></td><td width=\"20\">&nbsp;</td><td>_#AUTHOR## </td><td><table><tr><td>_#TREEIMGS</td><td><img src=" . $AA_IMG_URL . "blank.gif width=2 height=21></td><td>_#SUBJECT#</td></tr></table></td><td width=\"20\">&nbsp;</td></tr>', even_odd_differ=1, after='</table></td></tr></table>_#BUTTONS#', remove_string='<script>function checkData() { var text=\"\"; if(!document.f.d_subject.value) { text+=\"subject \" } if (text!=\"\") { alert(\"Please, fill the field: \" + text);  return false; } return true; }</script><form name=f method=post action=\"/apc-aa/filldisc.php\" onSubmit=\" return checkData()\"><p>Author<br><input type=text name=d_author > <p>Subject<br><input type=text name=d_subject value=\"_#SUBJECT#\"><p>E-mail<br><input type=text name=d_e_mail><p>Comment<br><textarea rows=\"5\" cols=\"40\" name=d_body ></textarea><p>WWW<br><input type=text name=d_url_address value=\"https://\"><p>WWW description<br><input type=text name=d_url_description><br><input type=submit value=Send><input type=hidden name=d_parent value=\"_#DISC_ID#\"><input type=hidden name=d_item_id value=\"_#ITEM_ID#\"><input type=hidden name=url value=\"_#DISC_URL\"></FORM>', group_title=NULL, order1=NULL, o1_direction=0, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=NULL, scroller=NULL, selected_item=0, modification=23, parameter=NULL, img1='<img src=${AA_IMG_URL}i.gif width=9 height=21>', img2='<img src=${AA_IMG_URL}l.gif width=9 height=21>', img3='<img src=${AA_IMG_URL}t.gif width=9 height=21>', img4='<img src=${AA_IMG_URL}blank.gif width=12 height=21>', flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'";
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }

    /** Test function @return bool */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM view', [
            ['slice_id', 'AA_Core_Fields..'],
            ['type', $this->_viewType()],
        ]);
        if ($row_count <> 1) {
            $this->message(_m('"%1" template view is not defined', [$this->_viewType()]));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $this->message(_m('Deleting the view template "%1"', [$this->_viewType()]));
        $this->query("DELETE FROM view WHERE slice_id='AA_Core_Fields..' AND type='" . $this->_viewType() . "' ");

        $this->message(_m('Inserting the view "discus" template'));
        $this->query($this->_viewDefinition());

        $this->message(_m('view "%1" redefinition - done.', [$this->_viewType()]));
        return true;
    }
}