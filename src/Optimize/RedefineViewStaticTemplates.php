<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - static**/
class RedefineViewStaticTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine static template view"); }

    function _viewType() { return 'static'; }

    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Static page', type='static', `before`=NULL, even=NULL, odd='<!-- Static page view is used for creating and viewing static pages like Contacts or About us.', even_odd_differ=NULL, after=NULL, remove_string=NULL, group_title=NULL, order1=NULL, o1_direction=NULL, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=NULL, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg=NULL, group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}