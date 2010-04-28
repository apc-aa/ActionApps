<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/
require_once AA_INC_PATH ."statestore.php3";
require_once AA_INC_PATH."sql_parser.php3";
require_once AA_INC_PATH."zids.php3";
require_once AA_INC_PATH."pagecache.php3";


class AA_Operators {

    /// Static ///
    /** getEnum function
     *
     */
    function getEnum() {
        return array('enum', array('LIKE'    => _m('contains'),
                                   'RLIKE'   => _m('begins with'),
                                   'LLIKE'   => _m('LLIKE'),
                                   'XLIKE'   => _m('XLIKE'),
                                   'BETWEEN' => _m('BETWEEN'),
                                   'ISNULL'  => _m('not set'),
                                   'NOTNULL' => _m('is set'),
                                   '=='      => _m('=='),         // exact match - no SQL parsing
                                   '='       => _m('='),
                                   '<'       => _m('<'),
                                   '>'       => _m('>'),
                                   '<>'      => _m('<>'),
                                   '!='      => _m('<>'),
                                   '<='      => _m('<>'),
                                   '>='      => _m('<>'),
                                   'd:<'     => _m('d:<'),
                                   'd:>'     => _m('d:>'),
                                   'd:<='    => _m('d:<='),
                                   'd:>='    => _m('d:>='),
                                   'd:='     => _m('d:='),
                                   'd:!='    => _m('d:!='),
                                   'd:<>'    => _m('d:<>'),
                                   'e:<'     => _m('e:<'),
                                   'e:>'     => _m('e:>'),
                                   'e:<='    => _m('e:<='),
                                   'e:>='    => _m('e:>='),
                                   'e:='     => _m('e:='),
                                   'e:!='    => _m('e:!='),
                                   'e:<>'    => _m('e:<>'),
                                   'm:<'     => _m('m:<'),
                                   'm:>'     => _m('m:>'),
                                   'm:<='    => _m('m:<='),
                                   'm:>='    => _m('m:>='),
                                   'm:='     => _m('m:='),
                                   'm:!='    => _m('m:!='),
                                   'm:<>'    => _m('m:<>'),
                                   '-:<'     => _m('-:<'),
                                   '-:>'     => _m('-:>'),
                                   '-:<='    => _m('-:<='),
                                   '-:>='    => _m('-:>='),
                                   '-:='     => _m('-:='),
                                   '-:!='    => _m('-:!='),
                                   '-:<>'    => _m('-:<>'),
                                   ));
    }
    /** getJsDefinition function
     *
     */
    function getJsDefinition() {
        return '
            var operator_names  = new Array();
            var operator_values = new Array();
            // text
            operator_names[0]  = new Array(" '._m('contains').' "," '._m('begins with').' ", " '._m('is').' ", " '._m('not set').' ", " '._m('is set').' ");
            operator_values[0] = new Array(       "LIKE"         ,       "RLIKE"           ,        "=",              "ISNULL",              "NOTNULL");
            // numeric
            operator_names[1]  = new Array(" = "," < "," > ", " <> ", " '._m('not set').' ", " '._m('is set').' ");
            operator_values[1] = new Array( "=" , "<" , ">" ,  "<>",          "ISNULL",             "NOTNULL");
            // date
            operator_names[2]  = new Array(" < "," > ", " '._m('not set').' ", " '._m('is set').' ");
            operator_values[2] = new Array("d:<","d:>",         "ISNULL",             "NOTNULL");
            // constants
            operator_names[3]  = new Array(" '._m('contains').' "," '._m('begins with').' ", " '._m('is').' ", " '._m('not set').' ", " '._m('is set').' ", " '._m("select ...").' ");
            operator_values[3] = new Array(       "LIKE"         ,       "RLIKE"           ,        "="      ,         "ISNULL"     ,        "NOTNULL",            "select");
            ';
    }
}

class AA_Condition extends AA_Object {

    var $fields;    // (array)
    var $operator;
    var $value;


    /// Static ///

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    function getClassProperties()  {
        return array (                   //  id            name         type      multi  persistent validator, required, help,                                         morehelp, example
            /** Array of compared fields */
            'fields'   => new AA_Property( 'fields',   _m("Fields"),   'text',  true,  true, 'field' ),
            /** Condition operator like 'LIKE', 'RLIKE', '=', '<>', '<', '>' ... */
            'operator' => new AA_Property( 'operator', _m("Operator"), 'text',  false, true, AA_Operators::getEnum() ),
             /** Compared value */
            'value'    => new AA_Property( 'value',    _m("Value"),    'text',  false, true, 'text' )
            );
    }

    // required - class name (just for PHPLib sessions)
    var $classname        = "AA_Condition";
    var $persistent_slots = array('fields', 'operator', 'value');

    /** AA_Condition function
     *   The parameters are optional, because we are storing AA_Condition
     *  to the session (with AA_Search_Row) and phplib session management uses
     *  constructors with none parameters
     * @param $fields
     * @param $operator
     * @param $value
     */
    function AA_Condition($fields=null, $operator=null, $value=null) {
        $this->fields    = (array)$fields;
        $this->operator  = $operator;
        $this->value     = $value;
    }

    /** getFields function
     *  Access function to condition field
     */
    function getFields() {
        return $this->fields;
    }

    /** getOperator function
     * Access function to condition operator
     */
    function getOperator() {
        return $this->operator;
    }

    /** getValue function
     *  Access function to condition value
     */
    function getValue() {
        return $this->value;
    }

    /** getArray function
     *  @return clasic $conds array - array('operator'  => ..,
     *                                      'value'     => ..,
     *                                      <field_1>   => 1
     *                                      [,<field_n> => 1])
     *  Mainly for backward compatibility with old - array approach
     */
    function getArray() {
        $ret['value']    = $this->value;
        $ret['operator'] = $this->operator;
        foreach ($this->fields as $cond_field) {
            $ret[$cond_field] = 1;
        }
        return $ret;
    }

    /** getAsString function
     * @param $condition_number
     */
    function getAsString($condition_number=0) {
        $ret = array();
        foreach ($this->fields as $cond_field) {
            $ret[] = "conds[$condition_number][$cond_field]=1";
        }
        $ret[] = "conds[$condition_number][operator]=". $this->operator;
        $ret[] = "conds[$condition_number][value]=".    $this->value;
        return join('&', $ret);
    }

    /** matches function
     * @param $itemcontent
     */
    function matches(&$itemcontent) {
        foreach ($this->fields as $field) {
            foreach ((array)$itemcontent->getValues($field) as $val) {
                if ( $this->_compare($val['value']) ) {
                    // any match is sufficient
                    return true;
                }
            }
        }
        return false;
    }

    /** _compare function
     *  Postfiltering, when the item (field value) is already loaded
     * @param $field_value
     */
    function _compare($field_value) {
        switch( $this->operator ) {
            case 'LIKE':
            case 'XLIKE':   return (strpos($field_value, $this->value) === false) ? false : true;
            case 'RLIKE':   return (strpos($field_value, $this->value) === 0)     ? true  : false;
            case 'LLIKE':   return  strpos($field_value, $this->value) == (strlen($field_value)-strlen($this->value));
            case '=':       return  $field_value == $this->value;
            case 'BETWEEN':
                $arr = explode( ",", $this->value );
                return (((int)$field_value >= (int)$arr[0]) AND ((int)$field_value <= (int)$arr[1]));
            case 'ISNULL':  return ($field_value == '');
            case 'NOTNULL': return ($field_value <> '');
            case '==' :                                           // exact match - no SQL parsing
            case '='  :     return $field_value == $this->value;
            case '<>' :     return $field_value != $this->value;
            case '!=' :     return $field_value != $this->value;
            case '<=' :     return $field_value <= $this->value;
            case '<'  :     return $field_value <  $this->value;
            case '>=' :     return $field_value >= $this->value;
            case '>'  :     return $field_value >  $this->value;
//          case '<=>':  //MySQL know this operator, but we do not use it in AA
        }
        return false;
    }
}


/** Stores one sorting order
 *  The order is stored in the array like array('category........' => d)
 *  It is also possible to specify "group limit" (maximum number of items
 *  of each group. In such case the array looks like:
 *     array( 'limit' => 4, 'category........' => d )
 */
class AA_Sortorder {
    var $sort;

    /** AA_Sortorder function  */
    function AA_Sortorder($sort) {
        $this->clear();
        $this->sort = $sort;
    }

    /** clear function */
    function clear() {
        $this->sort = array();
    }

    /** getArray function
     *  @return clasic $sort array - array('field'  => a|d [,limit => <group_no>])
     *
     *  Mainly for backward compatibility with old - array approach
     */
    function getArray() {
        return $this->sort;
    }
}

class AA_Set extends AA_Object {
    /** array of slice_ids (unpacked_ids) */
    var $slices;

    /** array of AA_Condition */
    var $conds;

    /** array of AA_Sortorder objects */
    var $sort;

    /** bitfield representing the bins - like Holding Bin, Approved, Trash, ...
     *  AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH
     */
    var $bins;


    /** AA_Set function
     * @param $slices array or one slice id, where to search
     * @param $conds  array or conds url string
     * @param $sort   array or sort  url string
     */
    function AA_Set($slices=null, $conds=null, $sort=null, $bins=AA_BIN_ACTIVE) {
        $this->clear();
        if ( !is_null($conds) ) {
            if (is_object($conds)) {
                $this->addCondition($conds);
            } elseif (is_array($conds)) {
                $this->addCondsFromArray($conds, 'LIKE');
            } else {
                $this->addCondsFromString($conds);
            }
        }
        if ( !is_null($sort) ) {
            if (is_object($sort)) {
                $this->addSortorder($sort);
            } elseif (is_array($sort)) {
                $this->addSortFromArray($sort);
            } else {
                $this->addSortFromString($sort);
            }
        }
        if ( !is_null($slices) ) {
            if (is_array($slices)) {
                $this->slices = $slices;
            } elseif (is_string($slices)) {
                $this->slices = array($slices);
            }
        }
        $this->bins = $bins;
    }

    /** New main function to get item ids from database based on conditions...
     *  Should replace QueryZIDs() in future
     *  @param $restrict_zids - zids
     */
    function query($restrict_zids=false) {
        return QueryZIDs($this->getModules(), $this->getConds(), $this->getSort(), $this->getBins(), 0, $restrict_zids);
    }

    /** clear function
     *
     */
    function clear() {
        $this->conds  = array();
        $this->sort   = array();
        $this->slices = array();
        $this->bins   = AA_BIN_ACTIVE;
    }

    /** set the bins - like Holding Bin, Approved, Trash, ...
     *  @param $bins bitfield
     *  AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH
     */
    function setBins($bins) {
        $this->bins = $bins;
    }

    /** addCondition function
     * @param $condition
     */
    function addCondition($condition) {
        if ( $condition ) {
            $this->conds[] = $condition;
        }
    }

    /** addSortorder function
     * @param $sortorder
     */
    function addSortorder($sortorder) {
        if ( $sortorder ) {
            $this->sort[] = $sortorder;
        }
    }

    /** addCondsFromString function
     *  Creates conditions from d-<fields>-<operator>-<value>-<fields>-<op....
     *  string ie:   d-headline........,category.......1-RLIKE-Bio
     * @param $string
     * @param $defaultCondsOperator
     */
    function addCondsFromString($string, $defaultCondsOperator='RLIKE') {
        if (substr($string, 0, 2)== 'd-') {
            $this->_parseViewConds($string);
        }
        $this->_parseCondsString($string, $defaultCondsOperator);
    }

    /** _parseCondsString function
     *  Returns $conds[] array, which is created from conds[] 'url' string
     *  like conds[0][category........]=first&conds[1][switch.........1]=1
     * @param $conds_string
     * @param $defaultCondsOperator
     */
    function _parseCondsString($conds_string, $defaultCondsOperator) {
        if (empty($conds_string)) {
            return;
        }
        $conds = false;
        parse_str($conds_string, $aa_query_arr);
        // we also need PHP to think a['key'] is the same as a[key], that's why we
        // call NormalizeArrayIndex()
        $aa_query_arr = NormalizeArrayIndex(magic_strip($aa_query_arr));
        $this->addCondsFromArray($aa_query_arr['conds'], $defaultCondsOperator);
    }

    /** addCondsFromArray function
     * @param $conds
     * @param $defaultCondsOperator
     */
    function addCondsFromArray($conds, $defaultCondsOperator='RLIKE') {
        if (!is_array($conds)) {
            return;
        }

        // joined two older functions:
        //    ParseMultiSelectConds($conds);
        //    ParseEasyConds($conds, $defaultCondsOperator);

        // First take care about 'valuejoin'

        // Parses the conds from a multiple select box: e.g.
        //  conds[1][value][0] = 'apple'
        //  conds[1][value][1] = 'cherry'
        //  conds[1][valuejoin] = 'AND'
        //      => creates two conds: conds[7] and conds[8] for example,
        //         fill conds[7][value] = 'apple', conds[8][value] = 'cherry'
        //
        //  with conds[1][valuejoin] = 'OR'
        //      => only changes conds[1][value] to '"apple" OR "cherry"'
        //  (c) Jakub, May 2002
        foreach ($conds as $icond => $cond) {
            if (is_array($cond['value'])) {
                // make phrases from all the all the values
                array_walk($cond['value'], 'PutInQuotes');
                if ($cond['valuejoin'] == 'AND') {
                    foreach ($cond['value'] as $val) {
                        $newcond = $cond;
                        unset($newcond['valuejoin']);
                        $conds[] = $newcond;
                    }
                    unset($conds[$icond]);
                } else {    // default is using valuejoin as OR
                    // the phrases are already in quotes
                    unset($conds[$icond]['valuejoin']);
                    $conds[$icond]['value'] = join(' OR ', $cond['value']);
                }
            }
        }

        // the 'valuejoin is now removed - transformed into miltiple conditions

        // Now we convert the easy conds to extended syntax

        /**
         * Transforms simplified version of conditions to the extended syntax
         * for example conds[0][headline........]='Hi' transforms into
         * conds[0][headline........]=1,conds[0]['value']='Hi',conds[0][operator]=LIKE
         *
         * It also replaces all united field conds
         *    like conds[0][headline........,abstract........]='Hi'
         * with its equivalents:
         *     conds[0][headline........]=1,conds[0][abstract........]=1,
         *     conds[0]['value']='Hi',conds[0][operator]=LIKE
         * (number of united field conds is unlimited and you can use it in simplified
         *  condition syntax as well as in extended condition syntax)
         *
         * @param array $conds input/output - transformed conditions
         * @param array $defaultCondsOperator - could be scalar (default), but also
         *              array: field_id => array('operator'=>'LIKE')
         */
        // Check the syntax and remove conds with wrong syntax (like conds[xx]=yy)
        // and replace easy conds with extended syntax conds
        foreach ($conds as $k => $cond) {
            if ( !is_array($cond) ) {
                unset($conds[$k]);
                continue;             // bad condition - ignore
            }
            if ( !isset($cond['value']) && (count($cond) == 1) ) {
                $conds[$k]['value'] = reset($cond);
            }
            if ( !isset($cond['operator']) ) {
                if ( is_array($defaultCondsOperator) ) {
                    if ( is_array($defaultCondsOperator[key($cond)] )) {
                        $conds[$k]['operator'] = get_if($defaultCondsOperator[key($cond)]['operator'], 'LIKE');
                    } else {
                        $conds[$k]['operator'] = 'LIKE';
                    }
                } else {
                    $conds[$k]['operator'] = $defaultCondsOperator;
                }
            }
            if (!($cond['operator'] == 'ISNULL') AND !($cond['operator'] == 'NOTNULL')) {
                // The value could be empty for ISNULL or NOTNULL operators
                if (!isset($conds[$k]['value']) OR ($conds[$k]['value']=="")) {
                    // For other operators we should remove all conditions without value
                    unset ($conds[$k]);
                }
            }
        }

        // and now replace all united conds (like conds[0][headline........,abstract........]=1)
        // with its equivalents
        foreach ($conds as $k => $cond) {
            foreach ( $cond as $field => $val ) {
                if ( strpos( $field, ',') !== false ) {
                    unset($conds[$k][$field]);
                    foreach ( explode(',',$field) as $separate_field ) {
                        $conds[$k][$separate_field] = $val;
                    }
                }
            }
        }

        // Finally create the the conds array
        foreach ($conds as $k => $cond) {
            $operator      = $cond['operator'];
            $value         = $cond['value'];
            unset($cond['operator']);
            unset($cond['value']);
            $field_arr      = array_keys($cond);
            $this->conds[] = new AA_Condition($field_arr, $operator, $value);
        }
    }

    /** addSortFromString function
     *  Transforms 'publish_date....-' like sort definition (used in prifiles, ...)
     *  to $arr['publish_date....'] = 'd' as used in sort[] array
     *  It is also possible to specify "group limit" by the number at the begin
     *  of the string (like 4category........-), which means that we want maximum
     *  4 items of each category. In such case we returned something like:
     *  array( 'limit' => 4, 'category........' => d )
     * @param $sort
     */
    function addSortFromString( $sort ) {
        $ret = array();
        if ($sort) {
            // is defined group limit?
            if (($limit_len = strspn($sort,'0123456789')) > 0) {
                $ret['limit'] = (int)substr($sort,0,$limit_len);
                $sort  = substr($sort,$limit_len);        // rest of the string
            }
            switch ( substr($sort,-1) ) {    // last character
                case '-':  $ret[substr($sort,0,-1)] = 'd'; break;
                case '+':  $ret[substr($sort,0,-1)] = 'a'; break;
                default:   $ret[$sort]              = 'a';
            }
        }
        if ( count($ret) > 0 ) {
            $this->sort[] = new AA_Sortorder($ret);
        }
    }

    function addSortFromArray($sort) {
        if ($sort and is_array($sort)) {
            foreach ($sort as $s) {
                $this->sort[] = new AA_Sortorder($s);
            }
        }
    }

    /** _parseViewConds function
     *  Creates conditions from d-<fields>-<operator>-<value>-<fields>-<op....
     *  @param $string ie:   d-headline........,category.......1-RLIKE-Bio
     */
    function _parseViewConds($string) {
        $commands = new AA_View_Commands($string);
        $command  = $commands->get('d');
        if (!$command) {
            return false;
        }
        return $this->addFromCommand($command);
    }
    /** addFromCommand function
     * @param $command
     */
    function addFromCommand($command) {
        if ($command->getCommand() != 'd') {
            return false;
        }
        $i=0;
        $command_params = $command->getParameterArray();
        while ( $command_params[$i] ) {
             if ( AA_Set::check($command_params[$i], $command_params[$i+2]) ) {
                 $field_arr = explode(',',$command_params[$i]);
                 $cond_str  = $command_params[$i+2];
                 // well stripsplashes if bad - we never want the slashed text
                 // here, but we do not know, if the command is not from url
                 // so we will rather stripslash the string in most cases
                 // However - if the string starts with ", then it is never
                 // slashed, for sure
                 // @todo remove stripslashes for AA3.0 - Honza
                 if ( substr($cond_str,0,1) !='"') {
                     $cond_str = stripslashes($cond_str);
                 }
                 $this->conds[] = new AA_Condition($field_arr, $command_params[$i+1], $cond_str);
             }
             $i += 3;
         }
         return true;
    }

    /** getConds function
     *  retruns $conds[] array - mainly for backward compatibility
     */
    function getConds() {
        $ret = array();
        foreach ( $this->conds as $condition ) {
            $ret[] = $condition->getArray();
        }
        return $ret;
    }

    /** getSort function
     *  retruns $sort[] array - mainly for backward compatibility
     */
    function getSort() {
        $ret = array();
        foreach ( $this->sort as $sortorder ) {
            $ret[] = $sortorder->getArray();
        }
        return $ret;
    }

    /** getModules function
     *  retruns $modules array - mainly for backward compatibility
     */
    function getModules() {
        return $this->slices;
    }

    /** @return bins bitfield - AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH */
    function getBins() {
        return $this->bins;
    }

    /** getCondsAsString function
     *  @return $conds[] array - mainly for backward compatibility
     */
    function getCondsAsString() {
        $ret   = '';
        $delim = '';
        foreach ( $this->conds as $k => $condition ) {
            $ret  .= $delim . $condition->getAsString($k);
            $delim = '&';
        }
        return $ret;
    }

    /** check function
     *  static - Checks if the condition is in right format - is valid
     * @param $field
     * @param $value
     */
    function check($field, $value) {
        return ($field && ($value != 'AAnoCONDITION'));
    }

    /** matches function
     *  Postfilter - checks if the item matches the conditions
     *  In this case we already have an item loaded from database
     *  (which is new). We are trying to have the same syntax as classical
     *  $conds[] applayed to database selection.
     * @param $itemcontent
     *  @todo allow to compare not only fields, but also aliases
     */
    function matches(&$itemcontent) {
        foreach ( $this->conds as $condition ) {
            if ( !$condition->matches($itemcontent) ) {
                // we must met all the conditions criteria
                return false;
            }
        }
        return true;
    }

    /// Static ///

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                //  id            name         type          multi  persistent validator, required, help,                                         morehelp, example
            /** Array of AA_Condition */
            'conds'  => new AA_Property( 'conds',  _m("Conditions"), 'AA_Condition',  true, true ),
            /** array of AA_Sortorder */
            'sort'   => new AA_Property( 'sort',   _m("Sort"),       'AA_Sortorder',  true, true ),
            /** array of slice_ids */
            'slices' => new AA_Property( 'slices', _m("Slices"),     'id',            true, true )
            );
    }

}

/** getSortFromUrl function
 *  Returns sort[] array used by QueryZids functions
 *  $sort - sort definition in various formats:
 *     1)   sort = headline........-
 *     2)   sort[0] = headline........-
 *     3)   sort[0][headline........]=d
 *  or with group limits (limited number of items displayed in each group)
 *     1)   sort = 5headline........-
 *     2)   sort[0] = 5headline........-
 *     3)   sort[0][headline........]=d&sort[0][limit]=5
 */
function getSortFromUrl( $sort ) {
    $ret_sort = array();
    $set      = new AA_Set;
    if ( isset($sort) ) {
        if ( !is_array($sort) ) {
            $set->addSortFromString($sort);
            $ret_sort = $set->getSort();
        } else {
            ksort( $sort, SORT_NUMERIC); // it is not sorted and the order is important
            foreach ( $sort as $k => $srt) {
                if ($srt) {
                    if ( is_array($srt) ) {
                        $tmp = array();
                        if ( key($srt) == 'limit') {
                            next($srt);
                        }
                        $tmp[key($srt)] = (strtolower(current($srt)) == "d" ? 'd' : 'a');
                        if ($srt['limit']) {
                            $tmp['limit'] = $srt['limit'];
                        }
                        $ret_sort[] = $tmp;
                    } else {
                        $set->addSortFromString($srt);
                        $ret_sort = array_merge($ret_sort, $set->getSort());
                    }
                }
            }
        }
    }
    return $ret_sort;
}

/** GetWhereExp function
 * @param $field
 * @param $operator
 * @param $querystring
 */
function GetWhereExp( $field, $operator, $querystring ) {
    if ( $GLOBALS['debug'] ) {
        echo "<br>GetWhereExp( $field, $operator, $querystring )";
    }

    if ($operator == '==') {
        return " ($field = '$querystring') ";            // exact match - no SQL parsing, no stripslashes (we added this because SQL Syntax parser in AA have problems with packed ids)
    }

    // query string could be slashed - sometimes :-(
    // However - if the string starts with ", then it is never
    // slashed, for sure
    // @todo remove stripslashes for AA3.0 - Honza
    //    if ( substr($querystring,0,1) != '"') {
    //        $querystring = stripslashes( $querystring );
    //    }

    $querystring = stripslashes( $querystring );

    // search operator for functions (some operators can be in function:operator
    // fomat - the function is called to $querystring (good for date transform ...)
    if ( $pos = strpos($operator,":") ) {  // not ==
        $func = substr($operator,0,$pos);
        $operator = substr($operator,$pos+1);

        switch( $func ) {
            case 'd': // english style datum (like '12/31/2001' or '10 September 2000')
                $querystring = strtotime($querystring);
                break;
            case 'e': // european datum style (like 24. 12. 2001)
                if ( !ereg("^ *([0-9]{1,2}) *\. *([0-9]{1,2}) *\. *([0-9]{4}) *$", $querystring, $part)) {
                    if ( !ereg("^ *([[0-9]]{1,2}) *\. *([0-9]{1,2}) *\. *([0-9]{2}) *$", $querystring, $part)) {
                        if ( !ereg("^ *([[0-9]]{1,2}) *\. *([0-9]{1,2}) *$", $querystring, $part)) {
                            $querystring = time();
                            break;
                        }
                    }
                }
                if ( ($operator == "<=") or ($operator == ">") ) {
                    // end of day used for some operators
                    $querystring = mktime(23,59,59,$part[2],$part[1],$part[3]);
                } else {
                    $querystring = mktime(0,0,0,$part[2],$part[1],$part[3]);
                }
                break;
            case 'm':
            case '-':
                $querystring = time() - $querystring;
                break;
        }
    }

    $querystring =  (string)$querystring;   // to be able to do string operations

    switch( $operator ) {
        case 'LIKE':
        case 'RLIKE':
        case 'LLIKE':
        case 'XLIKE':
            // @todo - I do not like this part of code, which means, that you
            // can use '%' as well as '*'
            // I think we should escape all the % and _ and allow it only for
            // say XLIKE
            $querystring = str_replace('*', '%', trim($querystring));
            $querystring = str_replace('?', '_', $querystring);
            // continue!
        case '=':
            // it is not possible to use wildcard characers with '='
            $syntax      = new Syntax($field, $operator, lex( trim($querystring) ) );
            $ret         = $syntax->S();
            if ( $ret == "_SYNTAX_ERROR" ) {
                if ( $GLOBALS['debug'] ) {
                    echo "<br>Query syntax error: ". $GLOBALS['syntax_error'];
                }
                return "1=1";
            }
            return ( $ret ? " ($ret) " : "1=1" );
        case 'BETWEEN':
            $arr = explode( ",", $querystring );
            return  " (($field >= $arr[0]) AND ($field <= $arr[1])) ";
        case 'ISNULL':   return " (($field IS NULL) OR ($field='')) ";
        case 'NOTNULL':  return " (($field IS NOT NULL) AND ($field<>'')) ";
//      case '<=>':  //MySQL know this operator, but we do not use it in AA
        case '<>' :
        case '!=' :
        case '<=' :
        case '<'  :
        case '>=' :
        case '>'  :
            $str = ( ($querystring[0] == '"') OR ($querystring[0] == "'") ) ? substr( $querystring, 1, -1 ) : $querystring ;
            return " ($field $operator '$str') ";
    }
}

// -------------------------------------------------------------------------------------------

/** ProoveFieldNames function
 * show info about non-existing fields in all given slices
 * @param $slices
 * @param $conds
 */
function ProoveFieldNames($slices, $conds) {

    if (!(isset($slices) AND is_array($slices) AND isset($conds) AND is_array($conds))) {
        return;
    }

    global $CONDS_NOT_FIELD_NAMES;
    if (!is_array($slices) || !is_array($conds)) {
        return;
    }
    $db = new DB_AA;
    foreach ($slices as $slice_id) {
        $db->query("SELECT * FROM field WHERE slice_id='".q_pack_id($slice_id)."'");
        while ($db->next_record()) {
            $slicefields[$db->f("id")] = 1;
        }
        foreach ($conds as $cond) {
            if (!(isset($cond) AND is_array($cond))) {
                continue;
            }
            foreach ($cond as $key => $foo) {
                if (!$CONDS_NOT_FIELD_NAMES[$key] && !isset($slicefields[$key])) {
                    echo "Field <b>$key</b> does not exist in slice <b>$slice_id</b> (".q_pack_id($slice_id).").<br>";
                }
            }
        }
    }
}

// -------------------------------------------------------------------------------------------

/** PutInQuotes function
 *  Puts the expression in the quotes, so it becames phrase
 *  Example:   I say: "Hay..."    ->    "I say: \"Hay\"..."
 * @param $text
 * @param $key
 */
function PutInQuotes(&$text, $key) {
    $text = '"'. str_replace('"', '\"', $text). '"';
}

/* parses the conds from a multiple select box: e.g.
    conds[1][value][0] = 'apple'
    conds[1][value][1] = 'cherry'
    conds[1][valuejoin] = 'AND'

    => creates two conds: conds[7] and conds[8] for example,
        fill conds[7][value] = 'apple', conds[8][value] = 'cherry'

    with conds[1][valuejoin] = 'OR' only changes conds[1][value] to '"apple" OR "cherry"'
    (c) Jakub, May 2002
*/
/** ParseMultiSelectConds function
 * @param $conds
 */
function ParseMultiSelectConds(&$conds) {
    if (!is_array($conds)) {
        return;
    }
    foreach ($conds as $icond => $cond) {
        if (is_array($cond['value'])) {
            // make phrases from all the all the values
            array_walk($cond['value'], 'PutInQuotes');
            if ($cond['valuejoin'] == 'AND') {
                foreach ($cond['value'] as $val) {
                    $newcond = $cond;
                    unset($newcond['valuejoin']);
                    $conds[] = $newcond;
                }
                unset($conds[$icond]);
            } else {    // default is using valuejoin as OR
                // the phrases are already in quotes
                unset($conds[$icond]['valuejoin']);
                $conds[$icond]['value'] = join(' OR ', $cond['value']);
            }
        }
    }
}

/** ParseEasyConds function
 * Transforms simplified version of conditions to the extended syntax
 * for example conds[0][headline........]='Hi' transforms into
 * conds[0][headline........]=1,conds[0]['value']='Hi',conds[0][operator]=LIKE
 *
 * It also replaces all united field conds
 *    like conds[0][headline........,abstract........]='Hi'
 * with its equivalents:
 *     conds[0][headline........]=1,conds[0][abstract........]=1,
 *     conds[0]['value']='Hi',conds[0][operator]=LIKE
 * (number of united field conds is unlimited and you can use it in simplified
 *  condition syntax as well as in extended condition syntax)
 *
 * @param array $conds input/output - transformed conditions
 * @param array $defaultCondsOperator - could be scalar (default), but also
 *              array: field_id => array('operator'=>'LIKE')
 */
function ParseEasyConds(&$conds, $defaultCondsOperator = "LIKE") {
    if (is_array($conds)) {
        // In first step we remove conds with wrong syntax (like conds[xx]=yy)
        // and replace easy conds with extended syntax conds
        foreach ($conds as $k => $cond) {
            if ( !is_array($cond) ) {
                unset($conds[$k]);
                continue;             // bad condition - ignore
            }
            if ( !isset($cond['value']) && (count($cond) == 1) ) {
                $conds[$k]['value'] = reset($cond);
            }
            if ( !isset($cond['operator']) ) {
                if ( is_array($defaultCondsOperator) ) {
                    if ( is_array($defaultCondsOperator[key($cond)] )) {
                        $conds[$k]['operator'] = get_if($defaultCondsOperator[key($cond)]['operator'], 'LIKE');
                    } else {
                        $conds[$k]['operator'] = 'LIKE';
                    }
                } else {
                    $conds[$k]['operator'] = $defaultCondsOperator;
                }
            }
            if (!($cond['operator'] == 'ISNULL') AND !($cond['operator'] == 'NOTNULL')) {
                // The value could be empty for ISNULL or NOTNULL operators
                if (!isset($conds[$k]['value']) OR ($conds[$k]['value']=="")) {
                    // For other operators we should remove all conditions without value
                    unset ($conds[$k]);
                }
            }
        }
        // and now replace all united conds (like conds[0][headline........,abstract........]=1)
        // with its equivalents
        foreach ($conds as $k => $cond) {
            foreach ( $cond as $field => $val ) {
                if ( strpos( $field, ',') !== false ) {
                    unset($conds[$k][$field]);
                    foreach ( explode(',',$field) as $separate_field ) {
                        $conds[$k][$separate_field] = $val;
                    }
                }
            }
        }
    }
}

/** String2Conds function
 *  Returns $conds[] array, which is created from conds[] 'url' string
 *  like conds[0][category........]=first&conds[1][switch.........1]=1
 * @param $conds_string
 */
function String2Conds( $conds_string ) {
    $conds = false;
    if (isset($conds_string)) {
        parse_str($conds_string, $aa_query_arr);
        // we also need PHP to think a['key'] is the same as a[key], that's why we
        // call NormalizeArrayIndex()
        $aa_query_arr = NormalizeArrayIndex(magic_strip($aa_query_arr));
        $conds        = $aa_query_arr['conds'];
        ParseMultiSelectConds($conds);
        ParseEasyConds($conds,'RLIKE');
    }
    return $conds;
}

/** String2Sort function
 *  Returns $sort[] array, which is created from sort[] 'url' string
 *  like sort[0][headline........]=a&sort[2][publish_date....]=d
 * @param $sort_string
 */
function String2Sort( $sort_string ) {
    $sort = false;
    if (isset($sort_string)) {
        parse_str($sort_string, $aa_query_arr);
        // we also need PHP to think a['key'] is the same as a[key], that's why we
        // call NormalizeArrayIndex()
        $aa_query_arr = NormalizeArrayIndex(magic_strip($aa_query_arr));
        $sort = $aa_query_arr['sort'];
    }
    return $sort;
}

/** MakeSQLConditions function
 *  Creates array of SQL conditions based on $conds and fields $add
 * @param $fields_arr
 * @param $conds
 * @param $defaultCondsOperator
 * @param function $join_tables           - if some table is needed to join,
 *                                          this function adds it to the array
 * @param function $additional_field_cond - aditional condition function
 * @param function $additional_field_cond - aditional condition function parameter
 * @param $add_param
 */
function MakeSQLConditions($fields_arr, $conds, $defaultCondsOperator, &$join_tables, $additional_field_cond=false, $add_param=false) {

    ParseMultiSelectConds($conds);
    ParseEasyConds($conds, $defaultCondsOperator);

    if ( $GLOBALS['debug'] ) {
        huhl( "<br>Conds after ParseEasyConds():", $conds, "<br>--");
    }

    if ( isset($conds) AND is_array($conds)) {
        foreach ($conds as $cond) {
            if ( isset($cond) AND is_array($cond) ) {
                unset($onecond);                    // clear
                foreach ( $cond as $fid => $v ) {
                    $finfo = $fields_arr[$fid];
                    if ( isset($finfo) AND is_array($finfo) ) {
                        if ( $additional_field_cond ) {
                            if ( !$additional_field_cond( $finfo, $v, $add_param ) ) {
                                continue;
                            }
                        }
                        $onecond[] = GetWhereExp( $finfo['field'],
                                            $cond['operator'], $cond['value'] );
                        if ( $finfo['table'] ) {
                            $join_tables[$finfo['table']] = true;
                        }
                    }
                }  // between conditions inside one cond is OR
                if     ( count($onecond) == 1 ) {
                    $ret[] = $onecond[0];
                } elseif ( count($onecond) >  1 ) {
                    $ret[] = '( '. join( ' OR ',$onecond ) . ')';
                }
            }
        }
    }
    return ( isset($ret) AND is_array($ret) ) ?
                       ' AND ( '. join(' AND ', $ret ) .') ' : '';
}

/** MakeSQLOrderBy function
 *  Creates array of SQL ORDER BY expresions based on $sort and fields array
 * @param $fields_arr
 * @param $sort
 *  @param function $join_tables           - if some table is needed to join,
 *                                           this function adds it to the array
 *  @param function $additional_field_cond - aditional condition function
 *  @param function $additional_field_cond - aditional condition function parameter
 * @param $add_param
 */
function MakeSQLOrderBy($fields_arr, $sort, &$join_tables, $additional_field_cond=false, $add_param=false) {
    if ( isset($sort) AND is_array($sort)) {
        foreach ( $sort as $srt ) {
            if ( isset($srt) AND is_array($srt) ) {
                // random order
                // This operatin is quite slow in MySQL, so if you need just
                // one random item (for banner, ...), you should rather use
                // set[]=random-1 parameter for view.php3


                // This code is not tested, but should work, so if you need it, just enable it and you will see
                // I wrote it for item randomization, but then I find out that this function is not used for normal items in the slice
                // Honza 2008-09-01
                // if (key($srt) == 'random') {
                //     $ret[] = 'RAND()';
                //     continue;
                // }
                $finfo = $fields_arr[key($srt)];
                if ( $finfo AND is_array($finfo)) {
                    if ( $additional_field_cond ) {
                        if ( !$additional_field_cond( $finfo, current($srt), $add_param ) ) {
                            continue;
                        }
                    }
                    $ret[] = $finfo['field'] . (stristr(current( $srt ), 'd') ? " DESC" : "");
                    if ( $finfo['table'] ) {
                        $join_tables[$finfo['table']] = true;
                    }
               }
            }
        }
    }
    return ( isset($ret) AND is_array($ret) ) ?
                           ' ORDER BY '. join(' , ', $ret ) : '';
}

/** GetZidsFromSQL function - get zids from database
 * @param string $SQL              - SQL query
 * @param string $col              - column in database containing id
 * @param $zid_type
 * @param bool   $empty_result_condition - have we return empty set?
 * @param zids   $sort_zids        - used for sorting zids to right order
 *                                 - if specified, return zids are sorted
 *                                   in the same order as in $sort_zids
 * @param arrray $group_limit      - array('field' => <grouping_column>,
 *                                         'limit' => <number>)
 *                                   Limits the number of returned ids from each
 *                                   group. Group is defined by 'field'. Used
 *                                   for displaying only firs <number> of items
 *                                   from each group. Also good, if you want to
 *                                   list just the group names which is used in
 *                                   selected items (then set the number to 1)
 * @return zids from SQL query;
 */
function GetZidsFromSQL( $SQL, $col, $zid_type='s', $empty_result_condition=false, $sort_zids=null, $group_limit=null ) {
    global $QueryIDsCount, $debug;
    $db = getDB();

    $arr = array();                  // result ids array
    if (!$empty_result_condition) {
        $db->tquery($SQL);
        if ( $debug ) {
            huhl("GetZidsFromSQL: SQL", $SQL);
        }

        if (!$group_limit) {
            while ($db->next_record()) {
                $arr[] = $db->f($col);
            }
        } else {                     // we have to remove the ids above the limit for group
            $groups  = array();                 // array where we count the number of items in each group
            $glimit  =  $group_limit['limit'];  // shortcut - just for possible speedup
            $gfield  =  $group_limit['field'];  // shortcut - just for possible speedup
            while ($db->next_record()) {
                if (++$groups[$db->f($gfield)] <= $glimit) {
                    $arr[] = $db->f($col);
                }
            }
        }
    }
    $zids = new zids($arr, $zid_type);

    $QueryIDsCount = count($arr);

    if ( is_object($sort_zids) ) {
        $zids->sort_and_restrict_as_in($sort_zids);
    }

    freeDB($db);
    return $zids;
}


// -------------------------------------------------------------------------------------------

/** QueryZIDs function - @deprecated - use $aa_set->query() instead
*  Finds item IDs for items to be shown in a slice / view
*
*   @param array  $slices array of slices in which to look for items
*                         could be false. if you specify restrict_zids
*   @param array  $conds    search conditions (see FAQ)
*   @param array  $sort     sort fields (see FAQ)
*   @param string $type
*       sets status, pub_date and expiry_date according to specified type:
*       ACTIVE | EXPIRED | PENDING | HOLDING | TRASH | ALL.
*       If you want to specify it in conds, set to ALL.
*
*   @param bool   $neverAllItems
*       if no conds[] apply (all are wrong formatted or empty),
*       should the function generate an empty set?
*       Otherwise all items from given slices are returned.
*
*   @param array  $restrict_zids
*       ids are packed but not quoted in $restrict_ids or short.
*       Use it if you want to choose only from a set of items
*       (used by E-mail Alerts and related item view
*       (for sorting and eliminating of expired items)).
*
*   @param string $defaultCondsOperator
*       replaces the default "LIKE" for conditions with no operator set
*
*   @param bool   $use_cache should be the cache searched for the result? -- no longer used used
*
*   @return A zids object with a list of the ids that match the query.
*
*   @global  bool $debug (in) many debug messages
*   @global  bool $debugfields (in) useful mainly for multiple slices mode -- views info about field_ids
*               used in conds[] but not existing in some of the slices
*   @global  int $QueryIDsCount (out) is set to the count of IDs returned
*
*   Parameter format example:
*   <pre>
*   conds[0][fulltext........] = 1;   // returns id of items where word 'Prague'
*   conds[0][abstract........] = 1;   // is in fulltext, absract or keywords
*   conds[0][keywords........] = 1;
*   conds[0][operator] = "=";
*   conds[0][value] = "Prague";
*   conds[1][source..........] = 1;   // and source field of that item is
*   conds[1][operator] = "=";         // 'Econnect'
*   conds[1][value] = "Econnect";
*   sort[0][category........]='a';    // order items by category ascending
*   sort[1][publish_date....]='d';    // and publish_date descending (secondary)
*   sort[0][category........]='1';    // order items by category priority - ascending
*   sort[0][category........]='9';    // order items by category priority - descending
*   sort[0]=random;                   // order items in random order (it is quite database intensive,
*                                     // so if you want to diplay just one random item, use set[]=random-1
*                                     // view parameter instead)
*   </pre>
*/
function QueryZIDs($slices, $conds="", $sort="", $type="ACTIVE", $neverAllItems=0, $restrict_zids=false, $defaultCondsOperator = "LIKE", $use_cache=false ) {

    // select * from item, content as c1, content as c2 where item.id=c1.item_id AND item.id=c2.item_id AND       c1.field_id IN ('fulltext........', 'abstract..........') AND c2.field_id = 'keywords........' AND c1.text like '%eufonie%' AND c2.text like '%eufonie%' AND item.highlight = '1';

    global $debug;                 // displays debug messages
    global $CONDS_NOT_FIELD_NAMES; // list of special conds[] indexes (defined in constants.php3)
    global $QueryIDsCount;

    if (!is_array($slices)) {
        $slices = empty($slices) ? array() : (array)$slices;
    }

    if ( $debug ) {
        huhl("QueryZIDs - start:<br>Conds=",$conds,"<br>Sort=",$sort, "<br>Slices=",join('-',$slices));
    }

    if (is_object($restrict_zids) AND ($restrict_zids->count() == 0)) {
        return new zids(); // restrict_zids defined but empty - no result
    }

    if ($GLOBALS['debugfields'] OR $debug) {
        if (!empty($slices)) {
            ProoveFieldNames($slices, $conds);
        }
    }

    ParseMultiSelectConds($conds);
    ParseEasyConds($conds, $defaultCondsOperator);

    // we need fields just in case we use sort or conds. Not necessary for
    // restrict_zids queries, where we often do not have slice id
    if ( (is_array($conds) AND (count($conds)>0)) OR (is_array($sort) AND (count($sort)>0)) ) {
        if ( empty($slices) ) {
            if ( is_object($restrict_zids) ) {
                $sid = $restrict_zids->getFirstSlice();
                if ( $sid ) {
                    $slices[] = $sid;
                }
            }
        }
        if ( empty($slices) ) {
            return new zids();
        }

        // get the fields for the first slice (used as template and we expect that
        // all slices in the query has the same structure
        $slice  = AA_Slices::getSlice(reset($slices));   // @todo convert whole $slices to AA_Slices
        // access the fields through slice - it is better for caching of values

        $fields = $slice->getFields();
    }

    if ( $debug ) {
        huhl("QueryZIDs: Conds=",$conds,"Sort=",$sort, "Slices=",join('-',$slices));
    }

    // parse conditions ----------------------------------
    if ( is_array($conds)) {
        $tbl_count=0;

        foreach ($conds as $cond) {

            // fill arrays according to this condition
            $field_count = 0;
            $cond_flds   = '';
            foreach ( $cond as $fid => $v ) {
                // search in all content table fields (new in AA v2.8)
                switch ( strtolower($fid) ) {
                    case 'all_fields':          $cond_flds = 'all_fields';
                                                $store     = 'text';
                                                continue;
                    case 'all_fields_numeric':  $cond_flds = 'all_fields';
                                                $store     = 'number';
                                                continue;
                }
                if ( $CONDS_NOT_FIELD_NAMES[$fid] ) {
                    continue;      // it is not field_id parameters - skip it for now
                }

                // Remote fields
                // It is possible to write conditions also using fields from
                // remote slice (which is related to this one)
                // Syntax is:
                //   <remote_field>@<remote_slice_id>/<relation_field>@<this_slice_id>
                //   headline........@2a652342562728238937365353322372/relation.......1@eba428a3736353783289287499a99c8e
                //   - search in headline field of all related items
                //     (relation.......1 field pointed to related items)
                if ( strpos('@',$fid) !== false ) {

                }

                $field = $fields->getField($fid);

                if ( is_null($field) OR $v=="") {
                    if ($debug) echo "Skipping $fid in conds[]: not known.<br>"; {
                        continue;            // bad field_id or not defined condition - skip
                    }
                }

                if ( $field->storageTable() == 'item' ) {   // field is stored in table 'item'
                    // Long ID in conds should be specified as unpacked, but in db it is packed
                    $cur_cond = GetWhereExp( 'item.'. $field->storageColumn(), $cond['operator'], $cond['value'] );
                    switch ($fid) {
                    case 'id..............':
                    case 'slice_id........':
                        // replace unpaced ids with the packed ones
                        $cur_cond =  preg_replace("/([0-9a-f]{32})/ie", "q_pack_id('\\1')", $cur_cond);
                        break;
                    case 'expiry_date.....';
                        $ignore_expiry_date = true;
                        break;
                    }
                    $select_conds[] = $cur_cond;
                } else {
                    $cond_flds .= ( ($field_count++>0) ? ',' : "" ). "'$fid'";
                    // will not work with one condition for text and number fields
                    $store      = $field->storageColumn();
                }
            }
            if ( $cond_flds != '' ) {
                $tbl = 'c'.$tbl_count++;
                // fill arrays to be able construct select command
                $select_conds[] = GetWhereExp( "$tbl.$store", $cond['operator'], $cond['value'] );

                if ( strpos($cond_flds, 'all_fields')!== false ) {  // we are searching all fields in content table
                    $select_tabs[] = "LEFT JOIN content as $tbl
                                      ON ($tbl.item_id=item.id)";
                } elseif ($field_count>1) {
                    $select_tabs[] = "LEFT JOIN content as $tbl
                                      ON ($tbl.item_id=item.id
                                      AND ($tbl.field_id IN ($cond_flds) OR $tbl.field_id is NULL))";
                } else {
                    $select_tabs[] = "LEFT JOIN content as $tbl
                                      ON ($tbl.item_id=item.id
                                      AND ($tbl.field_id=$cond_flds OR $tbl.field_id is NULL))";
                    // mark this field as sortable (store without apostrofs)
                    $sortable[ str_replace( "'", "", $cond_flds) ] = $tbl;
                }
            }
        }
    }

    if ( $debug ) {
        huhl("QueryZIDs: Cafretconds");
    }


    if ( !is_array($sort) OR count($sort)<1 ) {
        $select_order =  is_object($restrict_zids) ? '' : 'item.publish_date DESC';   // default item order
    } else {
        $delim='';
        foreach ($sort as  $sort_no => $srt) {
            if (key($srt)=='limit') {
                next($srt);       // skip the 'limit' record in the array
            }

            $fid   = key($srt);

            // random sorting by following url parameters:
            //    sort[0]=random
            //    sort[0]=category........&sort[1]=random
            //    /apc-aa/view.php3?vid=13&cmd[13]=c-1-1&set[13]=sort-random
            // This operatin is quite slow in MySQL, so if you need just
            // one random item (for banner, ...), you should rather use
            // set[]=random-1 parameter for view.php3
            if ( $fid == 'random' ) {
                $select_order .= $delim  . ' RAND()';
                $delim         = ',';

                // break! - we do not want to create expressions like
                //    ORDER BY RAND(),item.publish_date DESC
                // bacause it makes no sense
                // (on the other hand the following expressions are perfectly OK:
                //    ORDER BY s0, RAND()
                break;
            }

            $field = $fields->getField($fid);
            if ( is_null($field) ) { // bad field_id - skip
                if ($debug) {
                    echo "Skipping sort[x][$fid], don't know $fid.<br>";
                }
                continue;
            }

            if ( $field->storageTable() == 'item' ) {   // field is stored in table 'item'
                $fieldId          = 'item.' . $field->storageColumn();
                $select_order    .= $delim  . $fieldId;
                if ( stristr(current( $srt ), 'd')) {
                    $select_order .= ' DESC';
                }
                $delim         = ',';
            } else {
                if ( !$sortable[ $fid ] ) {           // this field is not joined, yet
                    $tbl = 'c'.$tbl_count++;
                    // fill arrays to be able construct select command
                    $select_tabs[] = "LEFT JOIN content as $tbl
                                      ON ($tbl.item_id=item.id
                                      AND ($tbl.field_id='$fid' OR $tbl.field_id is NULL))";
                    // mark this field as sortable (store without apostrofs)
                    $sortable[$fid] = $tbl;
                }

                // join constant table if we want to sort by priority
                $direction = current( $srt );
                if ( stristr($direction,'1') OR stristr($direction,'9') ) { // sort by priority
                    if ( !($constgroup = $field->getConstantGroup() )) {
                        // no constant group defined - can't assign priority
                        continue;
                    }

                    $tbl = 'o'.$tbl_count++;
                    // fill arrays to be able construct select command
                    $select_tabs[] = "LEFT JOIN constant as $tbl
                                      ON ($tbl.value=". $sortable[$fid] .".text
                                      AND ($tbl.group_id='$constgroup'
                                      OR $tbl.group_id is NULL))";
                    // mark this field as sortable (store without apostrofs)

                    // fill arrays according to this sort specification
                    $fieldId          = $tbl. ".pri";
                    $select_order    .= $delim . $fieldId;
                    if ( stristr($direction,'9') ) {
                        $select_order  .= " DESC";
                    }
                } else {                                                   // sort by value
                    $store = $field->storageColumn();
                    // fill arrays according to this sort specification
                    $fieldId          = $sortable[$fid]. ".$store";
                    $select_order    .= $delim . $fieldId;
                    if ( stristr(current( $srt ), 'd')) {
                        $select_order  .= " DESC";
                    }
                }
                $delim = ',';
            }
            if ($srt['limit']) {
                // select_distinct added in order we can group by multiple value fields
                // (items are shown more times)
                $select_distinct .= ", $fieldId as s$sort_no";
                $select_limit_field = array('field' => "s$sort_no", 'limit' => $srt['limit']);
            }
        }
    }

    // parse group by parameter ----------------------------
    // .. removed 2/27/2005 Honza (was never used)
    // ---

    if ( $debug ) {
        huhl("QueryZIDs:slice_id=",join('-',$slices),"  select_tabs=",$select_tabs, "  select_conds=",$select_conds,"  select_order=",$select_order );
    }

    // construct query --------------------------
    $SQL = "SELECT DISTINCT item.id as itemid $select_distinct FROM item ";
    if ( isset($select_tabs) AND is_array($select_tabs)) {
        $SQL .= ' '. implode (' ', $select_tabs);
    }

    $SQL .= ' WHERE ';                                         // slice ----------

    if ( !empty($slices) ) {
        $slices_SQL = join(",", array_map( "qq_pack_id", $slices));
        $SQL .= 'item.slice_id' . ((count($slices) == 1) ? " = $slices_SQL AND " :
                                                           " IN ($slices_SQL) AND ");
    }

    if (is_object($restrict_zids)) {
        $SQL .= " ".$restrict_zids->sqlin() ." AND ";
    } else {
        // slice(s) or $restrict_zids MUST be specified (in order we can get answer in limited time)
        if (!$slices_SQL) {
            return new zids();
        }
    }

    $SQL .= CreateBinCondition($type, 'item', $ignore_expiry_date);

    if ( isset($select_conds) AND is_array($select_conds)) {     // conditions -----
        $SQL .= " AND (" . implode (") AND (", $select_conds) .") ";
    }

    if ( $select_order ) {                                // order ----------
        $SQL .= " ORDER BY $select_order";
    }

    // add comment to the SQL command (for debug purposes)
    $SQL .= " -- AA slice: ". join('-', $slices);
    if ($GLOBALS['slice_info']) {
        $SQL .= ", slice_name: ". $GLOBALS['slice_info']['name'];
    }
    if ($GLOBALS['vid']) {
        $SQL .= ", vid: ".        $GLOBALS['vid'];
    }
    if ($GLOBALS['view_info']) {
        $SQL .= ", view_name: ".  $GLOBALS['view_info']['name'];
    }

    if ( $debug ) {
        huhl("QueryZIDs: SQL: $SQL");
    }

    // if neverAllItems is set, return empty set if no conds[] are used
    $ret = GetZidsFromSQL( $SQL, 'itemid', 'p', !is_array($select_conds) && $neverAllItems,
                           // last parameter is used for sorting zids to right order
                           // - if no order specified and restrict_zids are specified,
                           // return zids in unchanged order
                           (is_object($restrict_zids) AND !$select_order) ? $restrict_zids : null, $select_limit_field);

    if ( $debug ) {
        huhl("QueryZIDs: result:", $ret);
    }
    return $ret;
}

/** QueryConstantZIDs function
*  Finds constant ZIDs for constants to be shown in a slice / view
*   @param string $group_id   constant group to search in
*   @param array  $conds      search conditions {see QueryZIDs, FAQ}
*   @param array  $sort       sort fields       {see QueryZIDs, FAQ}
*   @param string $type       not used, yet
*   @param array  $restrict_zids
*       Use it if you want to choose only from a set of constants
*   @param string $defaultCondsOperator
*       replaces the default "RLIKE" for conditions with no operator set
*
*   @return A zids object with a list of the ids that match the query.
*
*   @global  int $QueryIDsCount (out) is set to the count of IDs returned
*
*   Parameter format example - {see QueryZIDs, FAQ}
*   Fields definition - {see include/constants.php3}
*/
function QueryConstantZIDs($group_id, $conds, $sort="", $restrict_zids=false, $defaultCondsOperator = "RLIKE") {
    global $debug;                 // displays debug messages

    // set default sortorder for constants if sortorder is not set
    if ( !isset($sort) OR !is_array($sort) OR count($sort)<1) {
        $sort[] = array( 'const_priority' => 'a');
        $sort[] = array( 'const_name' => 'a');
    }
    // for backward compatibily rename value to const_value ... (used in old views)
    if ( key($sort[0]) == 'value' ) {
        $sort[0] = array('const_value'    => $sort[0]['value']);
    }
    if ( key($sort[0]) == 'name' ) {
        $sort[0] = array('const_name'     => $sort[0]['name']);
    }
    if ( key($sort[0]) == 'pri' ) {
        $sort[0] = array('const_priority' => $sort[0]['pri']);
    }
    // for older database structure, where conds is just 16 characters long
    if ( key($sort[0]) == 'const_descriptio' ) {
        $sort[0] = array('const_description'    => $sort[0]['const_descriptio']);
    }

    if ( $debug ) {
        huhl( "<br>Conds:", $conds, "<br>--<br>Sort:", $sort, "<br>--");
    }

    // parse conditions and sort order ----------------------------------
    $where_sql    = MakeSQLConditions( GetConstantFields(), $conds, $defaultCondsOperator, $foo);
    $order_by_sql = MakeSQLOrderBy(    GetConstantFields(), $sort,  $foo);

    // construct query --------------------------
    $SQL  = "SELECT DISTINCT constant.short_id FROM constant WHERE group_id='$group_id' ";
    $SQL .=  $where_sql . $order_by_sql;

    if (is_object($restrict_zids)) {
        if ($restrict_zids->count() == 0) {
            return new zids(); // restrict_zids defined but empty - no result
        }
        $SQL .= ' AND '.$restrict_zids->sqlin();
    }

    // get result --------------------------
    return GetZidsFromSQL($SQL, 'short_id');
}

// -------------------------------------------------------------------------------------------

/** QueryDiscIDs function
 * Purpose:  Finds discussion items IDs to be shown by the aa/discussion.php3 script
 * @param $slice_id
 * @param $conds
 * @param $sort
 * @param $slices
*/

function QueryDiscIDs($slice_id, $conds, $sort, $slices ) {
  // parameter format example:
  // conds[0][discussion][subject] = 1;   // discussion fields are preceded by [discussion]
  // sort[0][category........]='a';    // order items by category ascending

  if (!$slice_id && !$slices) {
      return;
  }

    $fields = array ("date","subject","author","e_mail","body","state","flag","url_address",
        "url_description", "remote_addr", "free1", "free2");

    global $debug;          // displays debug messages

    $db = new DB_AA;
    if ( $debug ) {
      echo "<br>Conds:<br>";
      huhl($conds);
      echo "<br><br>Sort:<br>";
      huhl($sort);
      echo "<br><br>Slices:<br>";
      huhl($slices);
    }

    // parse conditions ----------------------------------
    if (is_array($conds)) {
        reset($conds);
        $tbl_count=0;
        while ( list( , $cond) = each( $conds )) {
            if ( !is_array($cond) OR !$cond['discussion']
            OR !$cond['operator'] OR ($cond['value']=="")) {
              continue;             // bad condition - ignore
            }

            // fill arrays according to this condition
            reset($cond);
            while ( list( $fid, $vv) = each( $cond ))
                if ( $fid == 'discussion' ) {
                    unset ($select_cond);
                    while ( list ($fid) = each ($vv)) {
                        if ( in_array($fid,$fields) AND $cond['value'] > "" ) {
                            $select_cond[] = GetWhereExp( "discussion.$fid",
                                              $cond['operator'], $cond['value'] );
                        }
                    }
                    if (is_array($select_cond)) {
                        $select_conds[] = join ($select_cond, " OR ");
                    }
                }
        }
    }
/*
  // parse sort order ----------------------------
  if ( !(isset($sort) AND is_array($sort)))
    $select_order = 'item.publish_date DESC';   // default item order
  else {
    reset($sort);
    $delim='';
    while ( list( , $srt) = each( $sort )) {
      $fid = key($srt);
      if ( !$fields[$fid] )  // bad field_id - skip
          continue;

      if ( $fields[$fid]['in_item_tbl'] ) {   // field is stored in table 'item'
        $select_order .= $delim . 'item.' . $fields[$fid]['in_item_tbl'];
        if ( stristr(current( $srt ), 'd'))
          $select_order .= " DESC";
        $delim=',';
      } else {
        if ( !$sortable[ $fid ] ) {           // this field is not joined, yet
          $tbl = 'c'.$tbl_count++;
          // fill arrays to be able construce select command
          $select_tabs[] = "LEFT JOIN content as $tbl
                                   ON ($tbl.item_id=item.id
                                   AND ($tbl.field_id='$fid' OR $tbl.field_id is NULL))";
                        // mark this field as sortable (store without apostrofs)
          $sortable[$fid] = $tbl;
        }

        $store = ($fields[$fid]['text_stored'] ? "text" : "number");
        // fill arrays according to this sort specification
        $select_order .= $delim .$sortable[$fid]. ".$store";
        if ( stristr(current( $srt ), 'd'))
          $select_order .= " DESC";
        $delim=',';
      }
    }
  }
*/

if ( $debug ) {
  echo "<br><br>select_conds:";
  print_r($select_conds);
  echo "<br><br>select_order:";
  print_r($select_order);
}

    // construct query --------------------------
    $SQL = "SELECT discussion.id
            FROM discussion INNER JOIN item ON item.id = discussion.item_id
            WHERE ";

    if ( is_array($slices) AND (count($slices) > 0) ) {
        $slices_SQL = join(",", array_map( "qq_pack_id", $slices));
        $SQL .= ' item.slice_id' . ((count($slices) == 1) ? " = $slices_SQL AND " :
                                                           " IN ($slices_SQL) AND ");
    }
    elseif ( $slice_id ) {
        $SQL .= " item.slice_id = '". q_pack_id($slice_id) ."'";
    }

    if ( isset($select_conds) AND is_array($select_conds)) {    // conditions -----
        $SQL .= " AND (" . implode (") AND (", $select_conds) .") ";
    }

    if ( isset($select_order) ) {                               // order ----------
        $SQL .= " ORDER BY $select_order";
    }

    // get result --------------------------
    $db->tquery($SQL);

    while ( $db->next_record() ) {
        $arr[] = unpack_id($db->f('id'));
    }

    return $arr;
}



// ----------- Easy query -------- parse query functions first

/** test_for_closed function
 *  test for closed parentheses
 * @param $search
 */
function test_for_closed($search) {
  $zavorky=0;
  $uvozovky=0;
  for ($i=0; $i< strlen($search); $i++){
    switch ($search[$i]) {
      case "(" : $zavorky++;
                 break;
      case ")" : $zavorky--;
                     break;
      case "\"" :
                  if ($uvozovky==1)
                    $uvozovky--;
                      else
                    $uvozovky++;
                    break;
    }
  }
  $retval = $zavorky+$uvozovky;
  return $retval;
}

/** arrange_query function
 * Prepares query
 *   - replaces + and - sing with AND and NOT
 *   - replaces wildcards * and ?
 * @param $search
 */
function arrange_query($search) {
  // make case insenzitive
  $search = eregi_replace(" AND "," and ", $search);
  $search = eregi_replace(" OR "," or ", $search);
  $search = eregi_replace(" NOT "," not ",$search);
  $retstr = "";
  for ($i=0; $i<strlen($search); $i++) {
    switch($search[$i]) {
      case "\"" : if ($uvozovky == 1) { $uvozovky--; }
                  else { $uvozovky++; }
                  $retstr = $retstr . $search[$i];
                  break;
      case "+" : if ($uvozovky == 0) { $retstr = $retstr . " and "; }
                  else { $retstr = $retstr . $search[$i]; }
                  break;
      case "-" : if ($uvozovky == 0)
                    { $retstr = $retstr . " not "; }
                  else { $retstr = $retstr . $search[$i]; }
                  break;
      case "(" : break;
      case ")" : break;
      case "*" : if ($uvozovky == 0) { $retstr = $retstr . "%"; }
                 else { $retstr = $retstr . "*"; }
                 break;
      case "?" : if ($uvozovky == 0) { $retstr = $retstr . "_"; }
                 else { $retstr = $retstr . "?"; }
                 break;
      default : $retstr = $retstr . $search[$i];
    }
  }
  $retstr = ereg_replace("([[:blank:]]+)"," ", $retstr);
  return $retstr;
}
/** parse_query function
 * @param $search
 * @param $default_op
 */
function parse_query($search, $default_op="and") {
  $terms=array();
  $dummy=$search;
  $strtype=1;

  do {
    if ($dummy[0]=="\"") {
      $strtype=0;
      $dummy=substr($dummy, 1, strlen($dummy));
      $dummy2=substr($dummy, 0, strpos($dummy, "\"")+1);
      $dummy2="\"".$dummy2;
      if (strpos($dummy, "\"")+1 == strlen($dummy)) {
        $dummy2 = "\"". $dummy; $dummy = "";
      } else {
        $dummy=substr($dummy, strpos($dummy,  "\" ")+2, strlen($dummy));
      }
      $dummy2 = ereg_replace("\"","",$dummy2);
// tady to ma nejaky problemy, s tema zavorkama to beha neunosne pomalu
//    } elseif ($dummy[0]=="(") {
//      $dummy=substr($dummy, 1, strlen($dummy));
//      $dummy2="(";
//    } elseif ($dummy[0]==")") {
//      $dummy=substr($dummy, 1, strlen($dummy));
//      $dummy2=")";
    } else {
      $dummy2=substr($dummy, 0, strpos($dummy, " "));
      switch ($dummy2) {
        case "and" :
        case "not" :
        case "or" : $strtype=1;
                      break;
        default : if ($strtype != 1) { $terms[]=$default_op; } else { $strtype = 0; }
      }
      if (strpos($dummy, " ") != false) {
        $dummy=substr($dummy, strpos($dummy, " ")+1, strlen($dummy));
      } else { $dummy2=$dummy; $dummy=""; }
    }
    $terms[]=$dummy2;
  }
  while (strlen($dummy)!=0);
  return $terms;
}

/** build_sql_query function
 * creates SQL query
 * @param $searchitems
 * @param $field
 */
function build_sql_query($searchterms, $field) {
  reset($searchterms);
  $retstr = "";
  $notcls = 0; $typecls=0;
  while (current($searchterms)) {
    switch (current($searchterms)) {
      case "and" : if ($typecls==0) { $retstr = $retstr . " AND "; $typecls=1; }
                 next($searchterms);
                 break;
      case "or" : if ($typecls==0) { $retstr = $retstr . " OR "; $typecls=1; }
                    next($searchterms);
                    break;
      case "not" : if ($typecls==0) {
                    $retstr = $retstr . " AND "; $typecls=1;
                    $notcls = 1;
                  }
                  next($searchterms);
                  break;
      case "(" : $retstr = $retstr . "(";
                 break;
      case ")" : $retstr = $retstr . ")";
                 break;
      default : if ($notcls==1) { $retstr = $retstr . "(". $field . " NOT LIKE '%" . current($searchterms) . "%')"; }
                else { $retstr = $retstr . "(". $field . " LIKE '%" . current($searchterms) . "%')"; }
                $notcls = 0; $typecls=0;
                next($searchterms);
    }
  }
  if ($typecls != 0) { $retstr=""; }
  return  $retstr;
}

/** GetIDs_EasyQuery function
 * @param $fields
 * @param $db
 * @param $p_slice_id
 * @param $srch_fld
 * @param $from
 * @param $to
 * @param $query
 * @param $relevance
 */
function GetIDs_EasyQuery($fields, $db, $p_slice_id, $srch_fld, $from, $to,
                          $query, $relevance=false) {

  $in = "";
  $delim = "";
  $field_no = 0;

  // prepare query
  $search=trim(stripslashes(rawurldecode($query)));
    $query = str_replace("\\", "\\\\", $query);
    $query = str_replace("%", "\%", $query);
    $query = str_replace("_", "\_", $query);
    $query = str_replace("'", "\'", $query);

    if (test_for_closed($search) != 0) {
        return false;
    }
  $search = arrange_query($search);
  $myqueryterms = parse_query($search);

  $sqlstring=build_sql_query($myqueryterms, "text"); // "concat(' ',text)"); // add space to begining for better word matching

    if ( trim($sqlstring) == "" ) {
        $sqlstring = "1=1";
    }

    if ( !isset($srch_fld) OR !is_array($srch_fld) OR !$query ) {
        $field_id_cond = "1=1";               // no fields to search - all rows
    }

  reset($srch_fld);
  while ( list( $fid, $val ) = each($srch_fld) ) {
      if ( !$fields[$fid] ) {     // bad condition - field not exist in this slice
          continue;
      }
    $in .= $delim. "'$fid'";
    $delim=',';
    $field_no++;
  }

  if ( $field_no == 0 ) {
    $field_id_cond = "1=1";  // bad condition - field not exist in this slice
  } else {
    $field_id_cond = "field_id IN ( $in )";
  }

  // from date
  if ( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $from, $part)) {
      $cond = " AND (publish_date >= '". mktime(0,0,0,$part[1],$part[2],$part[3]). "') ";
  } elseif( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $from, $part)) {
      $cond = " AND (publish_date >= '". mktime(0,0,0,$part[1],$part[2],"20".$part[3]). "') ";
  }

  // to date
  if ( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $to, $part)) {
      $cond .= " AND (publish_date <= '". mktime(23,59,59,$part[1],$part[2],$part[3]). "') ";
  } elseif( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $to, $part)) {
      $cond .= " AND (publish_date <= '". mktime(23,59,59,$part[1],$part[2],"20".$part[3]). "') ";
  }

  $distinct = ( $relevance ? "" : "DISTINCT" );

  $SQL = "SELECT $distinct id from item, content WHERE item.id=content.item_id
            AND slice_id='$p_slice_id'
            AND ($field_id_cond)
            AND ($sqlstring)
            AND status_code='1'
            AND expiry_date > '". time() ."'
            $cond
            ORDER BY publish_date DESC";

  $db->query($SQL);

  // search by relevance? (not at all - just count the fields, where the word appears)
  if ( $relevance ) {
    $count=0;
    if ( $db->next_record() ) {      //preset first old id
      $oldid = $db->f(id);
    }

    while ( $db->next_record() ) {
      if ( $oldid != $db->f(id)) {
        $tmp[$count][] = unpack_id($db->f(id));
        $oldid = $db->f(id);
        $count=0;
      }
      else  {
        $count++;
      }
    }
    $tmp[$count][] = unpack_id($oldid);  // last value isn't stored

//print_r($tmp);

    // put the array one after one - first goes the best one
    for ( $i=$field_no; $i > 0; $i-- )
      $ret = array_merge( $tmp[$i], $tmp[$i-1] );
  } else {
    while ( $db->next_record() )
      $ret[] = unpack_id($db->f(id));
  }

  return $ret;
}

?>
