<?php
/**
 * File contains definition of searchbar class - it handles search and order bar
 * in AA admin interface (Link Manager page for example)
 *
 * Should be included to other scripts (as /modules/links/index.php3)
 *
 * @package Links
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/
if (!defined("INCLUDE_SEARCHBAR_CLASS_INCLUDED"))
     define ("INCLUDE_SEARCHBAR_CLASS_INCLUDED",1);
else return;

require_once $GLOBALS[AA_INC_PATH] . "statestore.php3";


/** helper function to sort search fields */
function searchfields_cmp($a, $b) {
    if ($a['search_pri'] == $b['search_pri']) return 0;
    return ($a['search_pri'] < $b['search_pri']) ? -1 : 1;
}

/** helper function to sort order fields */
function orderfields_cmp($a, $b) {
    if ($a['order_pri'] == $b['order_pri']) return 0;
    return ($a['order_pri'] < $b['order_pri']) ? -1 : 1;
}

/**
 * searchbar class - handles search and order bar in AA admin interface
 * (on Links Manager page, for example)
 */
class searchbar extends storable_class{
    var $search_fields;   // fields (options) used in search selectboxes
    var $search_operators;// operators used for fields in search selectboxes
    var $order_fields;    // fields (options) used in order selectboxes
    var $fields;          // fields definitions
    var $form_name;       // name of the form (for submit)

    var $search_row;     // internal array - stores current state of search rows
    var $order_row;      // internal array - stores current state of order rows

    // state variables (class settings)
    var $search_row_count_min;
    var $order_row_count_min;
    var $add_empty_search_row;

    // PHPLib variables - used to store class instances into sessions
	var $classname = "searchbar";    // required - class name
	var $persistent_slots =          // required - object's slots to save
        array("search_fields", 'search_operators', "order_fields", 'fields',
              "form_name", "search_row", "order_row", "search_row_count_min",
              "order_row_count_min", "add_empty_search_row");

    function searchbar($fields, $f, $srcm=1, $orcm=1, $aesr=1) { // constructor
        $this->fields               = $fields;
        if( isset($fields) AND is_array($fields) ) {
            uasort ($fields, "searchfields_cmp");
            foreach ( $fields as $fid => $v) {
                if ($v['search_pri'] > 0 ) {           // not searchable fields
                    // searchfields could be splited into groups
                    // one group is allways with search_pri 0-999, 1000-1999, ...
                    if ( $last_pri AND (floor($last_pri/1000) != floor($v['search_pri']/1000)) ) {
                        $this->search_fields['foo'.$last_pri] = '---------------';
                        $this->search_operators[$fid] = 'text';
                    }
                    $last_pri = $v['search_pri'];
                    $this->search_fields[$fid]    = $v['name'];
                    $this->search_operators[$fid] = $v['operators'];

                }
            }
            uasort ($fields, "orderfields_cmp");
            $last_pri = 0;
            foreach ( $fields as $fid => $v) {
                if ($v['order_pri'] > 0 ) {
                    // orderfields could be splited into groups
                    // one group is allways with order_pri 0-999, 1000-1999, ...
                    if ( $last_pri AND (floor($last_pri/1000) != floor($v['order_pri']/1000)) ) {
                        $this->order_fields['foo'.$last_pri] = '---------------';
                    }
                    $last_pri = $v['search_pri'];
                    $this->order_fields[$fid]     = $v['name'];
                }
            }
        }
        $this->form_name            = $f;
        $this->search_row_count_min = $srcm;
        $this->order_row_count_min  = $orcm;
        $this->add_empty_search_row = $aesr;
    }

    /**
     * Updates internal search_row and order_row variables from data posted from
     * form (in $_POST[])
     */
    function update() {
        if ( !$_POST['srchbr_akce'] )     // no searchbar action
            return;
        // set the searchbar from form values
        $this->setOrder( $_POST['srchbr_order'], $_POST["srchbr_order_dir"]);
        $this->setSearch($_POST['srchbr_field'], $_POST["srchbr_value"], $_POST["srchbr_oper"]);
    }

    /**
     * Resets the searchbar and sets new values
     * @param  array $order[<bar>] = <field>   (bar is probably just '1')
     * @param  array $order_dir[<bar>] = <set>|<unset>
     *               (bar is probably just '1')
     *               if set (to any value), the order is DESCENDING
     */
    function setOrder($order, $order_dir) {
        unset($this->search_row);

        // fill order_row variable
        if (isset($order) AND is_array($order)) {
            $i=1;
            while( list($bar, $fld) = each( $order ) ) {
                $this->order_row[$i++] =
                     array( 'field' => $fld,
                            'dir' => ($order_dir[$bar] ? 'd' : 'a'));
            }
        }
    }

    /**
     * Resets the searchbar and sets new values
     * @param  array $search_field[<bar>] = <field>   (bar indicates row)
     * @param  array $search_value[<bar>] = <search_for_what>
     * @param  array $search_oper[<bar>] = <search_operator>
     */
    function setSearch($search_field, $search_value, $search_oper) {
        unset($this->search_row);

        // fill search_row variable
        if (isset($search_field) AND is_array($search_field)) {
            $i=1;
            while( list($bar, $fld) = each( $search_field ) ) {
                $this->search_row[$i++] =
                     array( 'field' => $fld,
                            'value' => $search_value[$bar],
                            'oper'  => $search_oper[$bar]);
            }
        }
    }

    /**
     * Returns conds[] array to use with QueryIDs() (or Links_QueryIDs(), ...)
     */
    function getConds() {
        if( !isset($this->search_row) OR !is_array($this->search_row) )
            return false;

        $fields = $this->fields;
        reset( $this->search_row );
        while( list( , $c ) = each( $this->search_row ) ) {
            $conds[]=array( 'operator' => $c['oper'],
                            'value' => $c['value'],
                            $c['field'] => 1 );
        }
        return $conds;
    }

    /**
     * Returns sort[] array to use with QueryIDs() (or Links_QueryIDs(), ...)
     */
    function getSort() {
        if( !isset($this->order_row) OR !is_array($this->order_row) )
            return false;

        reset( $this->order_row );
        while( list( , $s ) = each( $this->order_row ) )
            $sort[]=array( $s['field'] => $s['dir'] );
        return $sort;
    }

    /**
     * Prints one search bar (one row)
     * @param int $bar which bar to print (index)
     * @return bool true, if the printed searchrow is not empty
     */
    function print_search_bar($bar) {

        list($val, $fld, $oper) = ( isset($this->search_row[$bar]) AND
                                    is_array($this->search_row[$bar]) ) ?
                                array(safe($this->search_row[$bar]['value']),
                                      $this->search_row[$bar]['field'],
                                      $this->search_row[$bar]['oper']) :
                                array( "", $this->search_fields[0], "");

        if( $bar == 1 ) {   // first bar is described as 'SEARCH' others 'AND'
            $searchimage = "<a href='javascript:document.".$this->form_name.
                           ".submit()'><img src='".
                           $GLOBALS['AA_INSTAL_PATH'] . "images/search.gif' alt='".
                           _m('Search') ."' width='15' height='15' border=0></a>";
            $searchtext = _m('Search');
        } else {
            $searchimage = "<img src='". $GLOBALS['AA_INSTAL_PATH'] .
                           "images/px.gif' alt='-' width='15' height='15' border=0>";
            $searchtext = _m('And');
        }

        # filter
        echo "<tr><td class='search'>&nbsp;$searchimage&nbsp;&nbsp;<b>$searchtext</b></td><td>";
        FrmSelectEasy("srchbr_field[$bar]", $this->search_fields, $fld, 'onchange="ChangeOperators('.$bar.', \'\')"' );
        FrmSelectEasy("srchbr_oper[$bar]", array(' ' => ' '));
        echo "<input type='Text' name='srchbr_value[$bar]' size=20 maxlength=254
              value=\"$val\">&nbsp;"."$searchimage</td></tr>";
        return $val != "";
    }

    /**
     * Access function to searchba operator
     */
    function getSearchBarOperator($bar) {
        return $this->search_row[$bar]['oper'];
    }

    /**
     * Prints one order bar (one row)
     * @param int $bar which bar to print (index)
     */
    function print_order_bar($bar) {

        list($dir, $fld) = ( isset($this->order_row[$bar]) AND
                             is_array($this->order_row[$bar]) ) ?
                                array(safe($this->order_row[$bar]['dir']),
                                      $this->order_row[$bar]['field']) :
                                array( "a", $this->order_fields[0]);

        echo "<tr><td class=search>&nbsp;<a href='javascript:document.".$this->form_name.
                        ".submit()'><img src='".
              $GLOBALS['AA_INSTAL_PATH'] . "images/order.gif' alt='".
              _m('Order'). "' border=0></a>&nbsp;&nbsp;<b>".
              _m('Order'). "</b></td><td class=leftmenuy>";

        FrmSelectEasy("srchbr_order[$bar]", $this->order_fields, $fld,
                      "onchange='submit()'");
        FrmChBoxEasy("srchbr_order_dir[$bar]", $dir=='d',
                     "onchange='submit()'");
        echo _m('Descending'). "</td></tr>";
    }

    /**
     * Prints searchbar (search rows and order rows - based on current settings)
     */
    function printBar() {
        echo '<input type=hidden name=srchbr_akce value="1">
              <table width="100%" border="0" cellspacing="0" cellpadding="0"
              class=leftmenu bgcolor="'. COLOR_TABBG .'">';

        // print searchbars
        $count_sb = 1;
        $empty = false;   // flag - true if the last printed searchrow is empty
        while( ($count_sb <= $this->order_row_count_min) OR
               ($this->add_empty_search_row AND !$empty) ) {
            $empty = !$this->print_search_bar($count_sb++);
        }

        // print searchbars
        $i = 1;
        while( $i <= $this->order_row_count_min )
            $this->print_order_bar($i++);

        echo '</table>
              <script language="JavaScript" type="text/javascript"> <!--
                function ChangeOperators( bar, selectedVal ) {
                    var idx=document.'.$this->form_name.'.elements[\'srchbr_field[\'+bar+\']\'].selectedIndex;
                    var type = field_types.charAt(idx);
                    // clear selectbox
                    for( i=(document.'.$this->form_name.'.elements[\'srchbr_oper[\'+bar+\']\'].options.length-1); i>=0; i--){
                      document.'.$this->form_name.'.elements[\'srchbr_oper[\'+bar+\']\'].options[i] = null
                    }
                    idx = -1;         // overused variable idx
                    // fill selectbox from the right slice
                    for( i=0; i<operator_names[type].length ; i++) {
                      document.'.$this->form_name.'.elements[\'srchbr_oper[\'+bar+\']\'].options[i] = new Option(operator_names[type][i], operator_values[type][i]);
                      if( operator_values[type][i] == selectedVal )
                          idx = i;
                    }
                    if( idx != -1 )
                        document.'.$this->form_name.'.elements[\'srchbr_oper[\'+bar+\']\'].selectedIndex = idx;
                }
                var operator_names  = new Array();
                var operator_values = new Array();
                // text
                operator_names[0]  = new Array(" '._m('contains').' "," '._m('begins with').' ", " '._m('is').' ");
                operator_values[0] = new Array("LIKE"                ,"RLIKE"                  , "=");
                // numeric
                operator_names[1]  = new Array(" = "," < "," > ", " <> ");
                operator_values[1] = new Array("="  ,"<"  ,">"  , "<>");
                // date
                operator_names[2]  = new Array(" < (12/24/2002) "," > (12/24/2002) ");
                operator_values[2] = new Array("d:<"             ,"d:>");
                var field_types    = "';

        // print string like "120021020010" which defines field type (charAt())
        $oper_translate = array( 'text' => 0, 'numeric' => 1, 'date' => 2);
        if ( isset($this->search_operators) AND is_array($this->search_operators) ) {
            reset($this->search_operators);
            while ( list(,$v) = each($this->search_operators) ) {
                echo $oper_translate[$v];
            }
        }
        echo "\";\n";
        for( $i=1; $i<$count_sb; $i++ ) {
            echo "   ChangeOperators($i,'".$this->getSearchBarOperator($i)."');\n";
        }
        echo '// -->
            </script>';
    }
}


?>
