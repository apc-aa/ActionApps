<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - links**/
class RedefineViewLinksTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine links template view"); }

    function _viewType() { return 'links'; }

    function _viewDefinition() {
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Links', type='links', `before`='<br>\r\n', even='', odd='<p><a href=\"_#L_URL___\" class=\"link\">_#L_NAME__ (_#L_O_NAME)</a><br>\r\n          _#L_DESCRI<br>\r\n          <a href=\"_#L_URL___\" class=\"link2\">_#L_URL___</a>\r\n     </p>\r\n', even_odd_differ=0, after='', remove_string='()', group_title='', order1='', o1_direction=0, order2=NULL, o2_direction=0, group_by1=NULL, g1_direction=0, group_by2=NULL, g2_direction=0, cond1field=NULL, cond1op='<', cond1cond=NULL, cond2field=NULL, cond2op='<', cond2cond=NULL, cond3field=NULL, cond3op='<', cond3cond=NULL, listlen=1000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<!-- no links in this category -->', group_bottom='', field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
    }
}