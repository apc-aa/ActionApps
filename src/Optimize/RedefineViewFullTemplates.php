<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - full**/
class RedefineViewFullTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine full template view"); }

    function _viewType() { return 'full'; }

    function _viewDefinition() {
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Fulltext view', type='full', `before`='<!-- Fulltext view is for viewing long items. It shows only one selected item with abstract and fulltext. -->\r\n\r\n<!-- top of the page -->\r\n<br>', even=NULL, odd='<h2><b>_#HEADLINE</b></h2>\r\n_#PUB_DATE, _#AUTHOR__\r\n<br>\r\n_#FULLTEXT<br>\r\n<div><a href=\"javascript:history.go(-1)\">Back</a></div>\r\n', even_odd_differ=NULL, after='', remove_string=NULL, group_title=NULL, order1=NULL, o1_direction=NULL, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=NULL, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<p>No item found.</p>', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
    }
}