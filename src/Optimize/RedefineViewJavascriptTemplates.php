<?php

namespace AA\Optimize;

use function _m;
use const AA_INSTAL_PATH;

/** Recreates the template view - javascript**/
class RedefineViewJavascriptTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine javascript template view"); }

    function _viewType() { return 'javascript'; }

    function _viewDefinition() {
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Javascript ...', type='javascript', `before`='/* output of this script can be included to any page on any server by adding:&lt;script src=\"" . AA_INSTAL_PATH . "view.php3?vid=3\"&gt; &lt;/script&lt; or such.*/', even=NULL, odd='document.write(\"_#HEADLINE\");', even_odd_differ=NULL, after='// script end ', remove_string=NULL, group_title=NULL, order1='', o1_direction=0, order2='', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=8, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'";
    }
}