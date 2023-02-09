<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - const**/
class RedefineViewConstTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine const template view"); }

    function _viewType() { return 'const'; }

    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Constant view ...', type='const', `before`='<table>', even='', odd='<tr><td>_#VALUE###</td></tr>', even_odd_differ=0, after='</table>', remove_string=NULL, group_title=NULL, order1='value', o1_direction=0, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=10, scroller=NULL, selected_item=0, modification=NULL, parameter='lt_languages', img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'"; }

    /**
     * @inheritDoc
     */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }
}