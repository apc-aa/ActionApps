<?php

namespace AA\Optimize;

use function _m;

/** Recreates the template view - rss**/
class RedefineViewRssTemplates extends \AA\Optimize\RedefineViewDiscusTemplates {

    public static function name(): string { return _m("Redefine rss template view"); }

    function _viewType() { return 'rss'; }

    function _viewDefinition() {
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='rss', type='rss', `before`='<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\" \"http://my.netscape.com/publish/formats/rss-0.91.dtd\"> <rss version=\"0.91\"> <channel>  <title>_#RSS_TITL</title>  <link>_#RSS_LINK</link>  <description>_#RSS_DESC</description>  <lastBuildDate>_#RSS_DATE</lastBuildDate> <language></language>', even=NULL, odd=' <item> <title>_#RSS_IT_T</title> <link>_#RSS_IT_L</link> <description>_#RSS_IT_D</description> </item>', even_odd_differ=NULL, after='</channel></rss>', remove_string=NULL, group_title=NULL, order1='publish_date....', o1_direction=0, order2='headline........', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='source..........', cond1op='', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=15, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional='NULL', aditional2='NULL', aditional3='NULL', aditional4='NULL', aditional5='NULL', aditional6='NULL', noitem_msg='<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\" \"http://my.netscape.com/publish/formats/rss-0.91.dtd\"> <rss version=\"0.91\"> <title>_#RSS_TITL</title>  <link>_#RSS_LINK</link>  <description>_#RSS_DESC</description>  <lastBuildDate>_#RSS_DATE</lastBuildDate> <language></language><channel></channel></rss>', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
    }
}