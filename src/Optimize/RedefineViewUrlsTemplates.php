<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - urls**/
class RedefineViewUrlsTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine urls template view"); }

    function _viewType() { return 'urls'; }

    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='URLs listing', type='urls', `before`='<!-- view used for listing URLs of items -->', even=NULL, odd='<a href=\"http://www.example.org/index.stm?x=_#SITEM_ID\">_#SITEM_ID</a><br>\r\n', even_odd_differ=0, after='', remove_string='', group_title='', order1='', o1_direction=0, order2='', o2_direction=0, group_by1='', g1_direction=0, group_by2='', g2_direction=0, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=100000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}