<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - calendar**/
class RedefineViewCalendarTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine calendar template view"); }

    function _viewType() { return 'calendar'; }

    function _viewDefinition() {
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Calendar', type='calendar', `before`='<table border=1>\r\n<tr><td>Mon</td><td>Tue</td><td>Wen</td><td>Thu</td><td>Fri</td><td>Sat</td><td>Sun</td></tr>', even=NULL, odd='_#STARTDAT-_#END_DATE <b>_#HEADLINE</b>', even_odd_differ=1, after='</table>', remove_string='', group_title='<td><font size=+2><a href=\"calendar.shtml?vid=319&cmd[319]=c-1-_#CV_TST_2-2-_#CV_TST_1&month=_#CV_NUM_M&year=_#CV_NUM_Y&day=_#CV_NUM_D\"><b>_#CV_NUM_D</b></a></font></td>', order1='', o1_direction=0, order2='', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='publish_date....', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=5, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional='<td><font size=+2>_#CV_NUM_D</font></td>', aditional2='', aditional3='bgcolor=\"_#COLOR___\"', aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='There are no events in this month.', group_bottom='', field1='start_date.....1', field2='end_date.......1', field3=NULL, calendar_type='mon_table'";
    }
}