<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - categories**/
class RedefineViewCategoriesTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine categories template view"); }

    function _viewType() { return 'categories'; }

    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Catategories', type='categories', `before`='     <br><b>_#C_PATH__</b><br><br>\r\n', even='', odd='<br>&#8226; <a href=\"?cat=_#CATEG_ID\" class=\"link\">_#C_NAME___#C_CROSS_</a>&nbsp;&nbsp;<b>(_#C_LCOUNT)</b>\r\n', even_odd_differ=0, after='', remove_string='', group_title='', order1='', o1_direction=0, order2='', o2_direction=0, group_by1='', g1_direction=0, group_by2='', g2_direction=0, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=1000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<!-- no categories in this category -->', group_bottom='', field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}