<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
*/

use AA\IO\DB\DB_AA;

/** Page_HTML_Begin function
 * @param $title
 */
function Page_HTML_Begin($title="") {
    HtmlPageBegin();
    echo '
      <title>'.$title.'</title>
    </head>
    <body>';
}

/** Page_HTML_End function
 *  print closing HTML tags for page
 */
function Page_HTML_End(){
    echo '
    </body>
    </html>';
}

/**
 * @param $url
 * @param $sli_id
 * @deprecated - used only on not encapsulated slice.php which nobody uses
 */
function pCatSelector($url, $sli_id){

    // was GetCategories() - strange to search for packed slice id in gropup_id - HM 2024
    $cats = DB_AA::select(['pvalue'=>'name'], 'SELECT LOWER(HEX(`value`)) AS pvalue, `name` FROM `constant`', [['group_id',$sli_id,'l']]);

    if ($cats) {
        $selected = $_GET['cat_id'];
        echo "<form action=\"$url\" method=\"get\">";
        // not encapsulated - need to send slice_id
          echo "<input type=hidden name=slice_id value=\"$sli_id\">";
          echo "<input type=hidden name=encap value=false>";
        echo _m("Select Category ") . "<select name=\"cat_id\">";
        $seloption=(($selected=="")?"selected":"");
        echo "<option value=all $seloption>"._m("All categories").'</option>';
        foreach ($cats as $id => $name) {
            $seloption=(($selected==$id)?"selected":"");
            echo "<option value=\"$id\" $seloption>".safe($name)."</option>";
        }
        echo "<input type=\"hidden\" name=\"scr_".$scr_name."_Go\" value=\"1\">";
        echo "<input type=\"submit\" name=\"Go\" value=\"Go\">";
        echo "</select>";
        echo "</form>";
    }
}

/** ExitPage function
 *
 */
function ExitPage($msg='') {
    global $encap, $r_packed_state_vars, $r_state_vars;
    if ($msg) {
        echo $msg;
    }
    if (!$encap) {
        Page_HTML_End();
    }
    $r_packed_state_vars = serialize($r_state_vars);
    page_close();
    exit;
}

/** SotreVariables function
 *
 */
function StoreVariables( array $vars ) {
    $state_vars = [];
    foreach ($vars as $v) {
        $state_vars[$v] = $GLOBALS[$v];
    }
    return $state_vars;
}

/** PutSearchLog function
 *
 */
function PutSearchLog($searchlog, $found_count=0) {
    $httpquery = $_SERVER['QUERY_STRING_UNESCAPED'].$_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'];
    $httpquery = DeBackslash($httpquery);
    $httpquery = str_replace("'", "\\'", $httpquery);
    [$usec, $sec] = explode(" ",microtime());
    $slice_time = 1000 * ((float)$usec + (float)$sec - $GLOBALS['slice_starttime']);
    $user = $_SERVER['PHP_AUTH_USER'];
    DB_AA::sql("INSERT INTO searchlog (date,query,user,found_count,search_time,additional1) VALUES (".time().",'$httpquery','$user',$found_count,$slice_time,'$searchlog')");
}

