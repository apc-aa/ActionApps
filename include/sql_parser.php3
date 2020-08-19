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
// -----------------------------------------------------------------------------
//  Constants
// -----------------------------------------------------------------------------

$syntax_error = "";

// -------------- Lexical analysis ---------------------------------------------
define("WHITE", " \t");
define("LEFT_PARENTHESES", "({[");
define("RIGHT_PARENTHESES", ")]}");
define("OPERATOR", "+-");
define("SPECIAL", "+-\"' \t(){}[]");
define("QUOT", "\"");
define("APOS", "'");

// return status codes
define("E_NO_ENDING_QUOTAPOS", 16);
define("S_OK", 17);
define("S_IMPLICIT", 18);
define("S_NO_MORE_TOKENS", 19);

// -------------- Syntax analysis ---------------------------------------------

// token types - number representation
define("TOKEN_TYPE_UNKNOWN", 0);
define("TOKEN_TYPE_STRING", 1);
define("TOKEN_TYPE_OPERATOR_AND", 2);
define("TOKEN_TYPE_OPERATOR_OR", 3);
define("TOKEN_TYPE_OPERATOR_NOT", 4);
define("TOKEN_TYPE_LEFT_PARENTHESIS", 5);
define("TOKEN_TYPE_RIGHT_PARENTHESIS", 6);
define("TOKEN_TYPE_EMPTY_TOKEN", 7);
define("TOKEN_TYPE_EOF", 8);

// token types - text representation
define("SYNTAX_LEFT_PAR", "(");
define("SYNTAX_RIGHT_PAR", ")");
define("SYNTAX_AND", "and");
define("SYNTAX_OR", "or");
define("SYNTAX_NOT", "not");
define("SYNTAX_STRING", "string");
define("SYNTAX_EOF", "EOF");

// translates number representation token types to textual reprasentation
$readable = [
    TOKEN_TYPE_LEFT_PARENTHESIS  => SYNTAX_LEFT_PAR,
                  TOKEN_TYPE_RIGHT_PARENTHESIS => SYNTAX_RIGHT_PAR,
                  TOKEN_TYPE_OPERATOR_AND       => SYNTAX_AND,
                  TOKEN_TYPE_OPERATOR_OR       => SYNTAX_OR,
                  TOKEN_TYPE_OPERATOR_NOT       => SYNTAX_NOT,
                  TOKEN_TYPE_STRING               => SYNTAX_STRING,
                  TOKEN_TYPE_EOF               => SYNTAX_EOF
];

// operator LIKE, RLIKE a LLIKE
$rl = [
        "operator" => ["LIKE" => "LIKE", "RLIKE" => "LIKE", "LLIKE" => "LIKE", "XLIKE" => "LIKE", "=" => "="],
        "pre"  => ["LIKE" => "%", "LLIKE" => "%", "RLIKE" => "",  "XLIKE" => "",  "=" => ""],
        "post" => ["LIKE" => "%", "LLIKE" => "",  "RLIKE" => "%", "XLIKE" => "",  "=" => ""]
];


$N = ["S", "E", "Eap", "T", "Tap", "F", "G"];
$T = ["string", "and", "or", "not", "(", ")"];
$P = [
        "S"   => [
                    ["E", "lambda"]
        ],
        "E"   => [
                    ["T", "Eap", "lambda"]
        ],
        "Eap" => [
                    ["or", "T", "Eap", "lambda"],
                     ["lambda"]
        ],
        "T"   => [
                    ["F", "Tap", "lambda"]
        ],
        "Tap" => [
                    ["and", "F", "Tap", "lambda"],
                     ["lambda"]
        ],
        "F"      => [
                    ["not", "G", "lambda"],
                    ["G", "lambda"]
        ],
        "G"   => [
                    ["(", "E", ")", "lambda"],
                     ["string", "lambda"]
        ]
];

$First = [
            "string" => ["string"],
            "and"    => ["and"],
            "or"     => ["or"],
            "not"     => ["not"],
            "("         => ["("],
            ")"         => [")"],
            "S"         => ["string", "(", "not"],
            "E"         => ["string", "(", "not"],
            "Eap"     => ["lambda", "or"],
            "T"         => ["string", "(", "not"],
            "Tap"     => ["lambda", "and"],
            "F"         => ["string", "(", "not"],
            "G"         => ["string", "("]
];


// -----------------------------------------------------------------------------
//  Functions for Lexical analisys
// -----------------------------------------------------------------------------

/** resolveOperator function
 * @param $op
 * @return array
 */
function resolveOperator($op) {
    switch ($op) {
    case '+': return ["type"=>TOKEN_TYPE_OPERATOR_AND, "value"=>"and"];
    case '-': return ["type"=>TOKEN_TYPE_OPERATOR_NOT, "value"=>"not"];
    }
    return ["type"=>TOKEN_TYPE_UNKNOWN, "value"=>"strange operator"];
}

///** isLetter function
// * @param $c
// */
//function isLetter($c) {
//    return ($c >= "A" && $c <= "Z")
//        || ($c >= "a" && $c <= "z")
//        || (ord ($c) >= 128);
//}

/** contains SQL special characters? */
function isSpecial($text) {
    return (strpos(SPECIAL, $text) !== false);
}


// there if $input[$i-1]==QUOT (or APOS) on begin
// $ending should be QUOT or APOS
/** tillTheEndingQuotApos function
 * @param $input
 * @param $i
 * @param $length
 * @param $ending
 * @return array
 */
function tillTheEndingQuotApos($input, $i, $length, $ending) {
    $tok="";
    while ($input[$i] != $ending) {
        $tok .= $input[$i++];
        if ($i >= $length) {
            return ["status"=>E_NO_ENDING_QUOTAPOS, "value"=>"", "i"=>$i];
        }
    }
    return ["status"=>S_OK, "value"=>$tok, "i"=>$i+1];    // i+1  =>  skips string terminator
}

/** tillTheFirstSpecial function
 * @param $input
 * @param $i
 * @param $length
 * @return array
 */
function tillTheFirstSpecial($input, $i, $length) {
    $tok="";
    $last = $length-1;
    while ( ($i < $length) AND
        (
            !isSpecial($input[$i])
            OR  // don't break on apostrophe and minus sign in the middle of words
                ( ($input[$i] == '-') AND (($i AND !isSpecial($input[$i-1])) OR ($i == $last)))
            OR
                ( ($input[$i] == "'") AND ($i AND ($i != $last) AND !isSpecial($input[$i-1].$input[$i+1])))
        )) {
                // (false !== strpos("'-", $input[$i])) && ($i != 0) && ($i != $length - 1) && !isSpecial($input[$i-1].$input[$i+1])))) {
        $tok .= $input[$i++];
    }
    return ["status"=>S_OK, "value"=>$tok, "i"=>$i];
}

/** getToken function
 * @param $input
 * @param $i
 * @param $length
 * @return array
 */
function getToken($input, $i, $length) {
    // eat whitespaces
    $i += strspn($input, WHITE, $i, $length);
    if ($i >= $length) {
        // no more tokens
        return ["status"=>S_NO_MORE_TOKENS, "value"=>"", "i"=>$i, "type"=>TOKEN_TYPE_EMPTY_TOKEN];
    }
    if (($input[$i]==QUOT) || ($input[$i]==APOS)) {
        $tok = tillTheEndingQuotApos($input, $i+1, $length, $input[$i]);
        $tok["type"] = ($tok["status"]==S_OK) ? TOKEN_TYPE_STRING : TOKEN_TYPE_UNKNOWN; // error handling
    }
    elseif ( strpos(SPECIAL, $input[$i]) !== false) {
        if ( strpos(OPERATOR, $input[$i]) !== false ) { // includes '+' and '-' (one character operators)
            $tok["status"] = S_OK;
            $val = resolveOperator($input[$i]);
            $tok['value']  = $val['value'];    // 'and' (for +) and 'not' (for -)
            $tok["i"]      = $i+1;
            $tok["type"]   = $val["type"];
        }
        elseif ( strpos(LEFT_PARENTHESES, $input[$i]) !== false ) { // isLeftParenthesis - includes '(', '[' a '{'
            $tok["status"] = S_OK;
            $tok['value']  = "(";    // all parenthesis are equal
            $tok["i"]       = $i+1;
            $tok["type"]   = TOKEN_TYPE_LEFT_PARENTHESIS;
        }
        elseif ( strpos(RIGHT_PARENTHESES, $input[$i]) !== false ) { // isRightParenthesis - includes ')', ']' a '}'
            $tok["status"] = S_OK;
            $tok['value']  = ")";    // all parenthesis are equal
            $tok["i"]       = $i+1;
            $tok["type"]   = TOKEN_TYPE_RIGHT_PARENTHESIS;
        }
        $i++;
    }
    else {
        $tok = tillTheFirstSpecial($input, $i, $length);
        $tok["type"] = TOKEN_TYPE_UNKNOWN;
    }
    return $tok;
}

/** preProcess function
 * @param $toks
 * @return array
 */
function preProcess($toks) {
    if ( isset($toks) AND is_array($toks) ) {
        foreach ( $toks as $ind => $val) {

            // UNKNOWN will be set for strings not delimeted by apostrofs,
            // we can't say: "it is string" (see "and", ...)
            if ( $val["type"] == TOKEN_TYPE_UNKNOWN ) {
                switch (strtolower($val['value'])) {
                case 'and':
                    $val['value'] = "and";
                    $val["type"]  = TOKEN_TYPE_OPERATOR_AND;
                    break;
                case 'or':
                    $val['value'] = "or";
                    $val["type"]  = TOKEN_TYPE_OPERATOR_OR;
                    break;
                case 'not':
                    $val['value'] = "not";
                    $val["type"]  = TOKEN_TYPE_OPERATOR_NOT;
                    break;
                default:
                    $val["type"] = TOKEN_TYPE_STRING;
                }
            }
            //        elseif ( $val["type"] == TOKEN_TYPE_OPERATOR ) {
            //            if ( $val['value'] == "+" ) $val['value'] = "and";
            //            elseif ( $val['value'] == "-" ) $val['value'] = "not";
            //        }
            $newtoks[] = $val;
        }
    }
    if ( isset($newtoks) AND is_array($newtoks) ) {
        reset($newtoks);
        while ( $t1 = current($newtoks) ) {
            $newtoks2[] = $t1;
            $t2 = next($newtoks);
            if ($t1["type"]==TOKEN_TYPE_STRING) {
                if ( ($t2) && (($t2["type"]==TOKEN_TYPE_STRING) || ($t2["type"]==TOKEN_TYPE_LEFT_PARENTHESIS) || ($t2["type"]==TOKEN_TYPE_OPERATOR_NOT)))
                    $newtoks2[] = ["status"=>S_IMPLICIT, "value"=>"and", "i"=>$t1["i"], "type"=>TOKEN_TYPE_OPERATOR_AND];
            }
        }
    }
    $newtoks2[] = ["status"=>S_IMPLICIT, "value"=>"end of input", "i"=>65536, "type"=>TOKEN_TYPE_EOF];
    return $newtoks2;
}

/** lex function
 * @param $input
 * @return array
 */
function lex($input) {
    $length = strlen($input);
    $i = 0;
    do {
        $r = getToken($input, $i, $length);
        if ($r["status"] == S_OK) $res[] = $r;
        $i = $r["i"];
    } while ($r["type"] != TOKEN_TYPE_EMPTY_TOKEN);
    $res = preProcess($res);
    return $res;
}


// -----------------------------------------------------------------------------
//  Functions for Syntax analisys
// -----------------------------------------------------------------------------

class TokenList {
    var $tList;
    var $index;
    /** TokenList function
     * @param $t
     */
    function __construct($t) {
        $this->tList = $t;
        $this->index = 0;
    }
    /** lookAhead function
     *
     */
    function lookAhead() {
        return $this->tList[$this->index]["type"];
    }

    /** match function
     * @param $symbol
     * @return string
     */
    function match($symbol) {
        global $readable;
        if ( ($la=$this->lookAhead()) == $symbol ) {
            $this->index++;
        } else { // error - match something, what is not in order
            return "Syntax error at position ".$this->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B($readable[$symbol]).".<BR>\n";
        }
    }
    /** getStringValue function
     *
     */
    function getStringValue() {
        if ( $this->tList[$this->index]["type"] == TOKEN_TYPE_STRING ) {
            return quote($this->tList[$this->index]['value']);
        } else {
            return "";
        }
    }
    /** getTokenBegin function
     *
     */
    function getTokenBegin() {
        $t = $this->tList[$this->index];
        return $t["i"] - strlen($t['value']);
    }
}    // class TokenList


/*
 * Class for syntax analysis contains variableu tList, which is list of token,
 * which comes from lexical analysis.
 */
class Syntax {
    var $tList;
    var $column;
    var $operator;
    var $pre;
    var $post;
    /** Syntax function
     * @param $column
     * @param $operator
     * @param $t
     */
    function __construct($column, $operator, $t) {
        global $rl;
        $operator = strtoupper($operator);
        $this->column = $column;
        $this->operator = $rl["operator"][$operator];
        if ($this->operator == "") {
            $operator = $this->operator = "LIKE";    // unknown operator
        }
        $this->tList = new TokenList($t);
        $this->pre  = $rl["pre"][$operator];
        $this->post = $rl["post"][$operator];
    }

/*
 *    Gramar rules.
 *  If there are more rules for one noterminal, we should chose the right one.
 *  It is suitable to look for 1 token - gramar is LL(1).
 */
    /** S function
     *
     */
    function S() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( ($la == TOKEN_TYPE_LEFT_PARENTHESIS) ||
             ($la == TOKEN_TYPE_STRING) ||
             ($la == TOKEN_TYPE_OPERATOR_NOT) )        // S -> E
        {
//            echo("S->E<br>\n");//debug
            if ( ($val = $this->E()) == "_SYNTAX_ERROR" ) {
                return $val;
            }
            if ( ($err = $this->tList->match(TOKEN_TYPE_EOF) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
//            echo("<b>Input string is OK.</b><br>\n");
        }
        else {
      $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_LEFT_PAR).", ".B(SYNTAX_STRING)." or ".B(SYNTAX_NOT).".<BR>\n";
      return "_SYNTAX_ERROR";
      }
        return $val;
    }
    /** E function
     *
     */
    function E() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( ($la == TOKEN_TYPE_LEFT_PARENTHESIS) ||
             ($la == TOKEN_TYPE_STRING) ||
             ($la == TOKEN_TYPE_OPERATOR_NOT) )        // E -> TE'
        {
//            echo("E->TEap<BR>\n");//debug
            if ( ($val = $this->T()) == "_SYNTAX_ERROR" ) {
                    return $val;
            }
            if ( ($foo = $this->Eap()) == "_SYNTAX_ERROR" ) {
                    return $foo;
            }
            $val .= $foo;
        }
        else {
      $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_LEFT_PAR).", ".B(SYNTAX_STRING)." or ".B(SYNTAX_NOT).".<BR>\n";
      return "_SYNTAX_ERROR";
        }
        return $val;
    }
    /** Eap function
     *
     */
    function Eap() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( $la == TOKEN_TYPE_OPERATOR_OR ) {    // E' -> orTE'
//            echo("Eap->orTEap<BR>\n");//debug
            if ( ($err = $this->tList->match(TOKEN_TYPE_OPERATOR_OR) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
            if ( ($val = $this->T()) == "_SYNTAX_ERROR" ) {
                return $val;
            }
            if ( ($foo = $this->Eap()) == "_SYNTAX_ERROR" ) {
                return $foo;
            }
            $val = " or " . $val . $foo;
        }
        elseif ( ($la == TOKEN_TYPE_RIGHT_PARENTHESIS) || ($la == TOKEN_TYPE_EOF) ) { // E' -> lambda
//            echo("Eap-><BR>\n");//debug
            $val = "";
        }
        else {
      $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_OR).", ".B(SYNTAX_LEFT_PAR)." or ".B(SYNTAX_EOF).".<BR>\n";
      return "_SYNTAX_ERROR";
        }
        return $val;
    }
    /** T function
     *
     */
    function T() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( ($la == TOKEN_TYPE_LEFT_PARENTHESIS) ||
             ($la == TOKEN_TYPE_STRING) ||
             ($la == TOKEN_TYPE_OPERATOR_NOT) )         // T -> FT'
        {
//            echo("T->FTap<BR>\n");//debug
            if ( ($val = $this->F()) == "_SYNTAX_ERROR" ) {
                return $val;
            }
            if ( ($foo = $this->Tap()) == "_SYNTAX_ERROR" ) {
                return $foo;
            }
            $val .= $foo;
        }
        else {
      $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_LEFT_PAR).", ".B(SYNTAX_STRING)." or ".B(SYNTAX_NOT).".<BR>\n";
      return "_SYNTAX_ERROR";
        }
        return $val;
    }
    /** Tap function
     *
     */
    function Tap() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( $la == TOKEN_TYPE_OPERATOR_AND ) {    // T' -> andFT'
//            echo("Tap->andFTap<BR>\n");//debug
            if ( ($err = $this->tList->match(TOKEN_TYPE_OPERATOR_AND) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
            if ( ($val = $this->F()) == "_SYNTAX_ERROR" ) {
                return $val;
            }
            if ( ($foo = $this->Tap()) == "_SYNTAX_ERROR" ) {
                return $foo;
            }
            $val = " and " . $val . $foo;
        }
        elseif ( ($la == TOKEN_TYPE_OPERATOR_OR) ||
                  ($la == TOKEN_TYPE_RIGHT_PARENTHESIS) ||
                  ($la == TOKEN_TYPE_EOF) ) {                // T' -> lambda
//            echo("Tap-><br>\n");//debug
            $val = "";
        }
        else {
            $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_AND).", ".B(SYNTAX_OR).", ".B(SYNTAX_RIGHT_PAR)." or ".SYNTAX_EOF.".<BR>\n";
            return "_SYNTAX_ERROR";
        }
        return $val;
    }
    /** F function
     *
     */
    function F() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( $la == TOKEN_TYPE_OPERATOR_NOT ) {    // F -> not G
//            echo("F->notG<BR>\n");//debug
            if ( ($err = $this->tList->match(TOKEN_TYPE_OPERATOR_NOT) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
            if ( ($foo = $this->G()) == "_SYNTAX_ERROR" ) {
                return $foo;
            }
            $val = "not ($foo)";
        }
        elseif ( ($la == TOKEN_TYPE_LEFT_PARENTHESIS) || ($la == TOKEN_TYPE_STRING) ) { // F -> G
//            echo("F->G<BR>\n");//debug
            if ( ($val = $this->G()) == "_SYNTAX_ERROR" ) {
                return $val;
            }
        }
        else {
            $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_LEFT_PAR).", ".B(SYNTAX_STRING)." or ".B(SYNTAX_NOT).".<BR>\n";
            return "_SYNTAX_ERROR";
        }
        return $val;
    }
    /** G function
     *
     */
    function G() {
        global $readable;
        $la = $this->tList->lookAhead();
        if ( $la == TOKEN_TYPE_LEFT_PARENTHESIS ) { // G -> (E)
//            echo("G->(E)<BR>\n");//debug
            if ( ($err = $this->tList->match(TOKEN_TYPE_LEFT_PARENTHESIS) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
            $val = SYNTAX_LEFT_PAR;
            if ( ($foo = $this->E()) == "_SYNTAX_ERROR" ) {
                return $foo;
            }
            $val .= $foo;
            if ( ($err = $this->tList->match(TOKEN_TYPE_RIGHT_PARENTHESIS) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
            $val .= SYNTAX_RIGHT_PAR;
        }
        elseif ( $la == TOKEN_TYPE_STRING ) {    // G -> string
//            echo("G->string<BR>\n");//debug
            $val = $this->column . " " . $this->operator . " '" . $this->pre . $this->tList->getStringValue() . $this->post . "'";
            if ( ($err = $this->tList->match(TOKEN_TYPE_STRING) )) {
                $GLOBALS["syntax_error"]=$err;
                return "_SYNTAX_ERROR";
            }
        }
        else {
            $GLOBALS["syntax_error"]="Syntax error at position ".$this->tList->getTokenBegin().". Found ".B($readable[$la])." while expecting ".B(SYNTAX_LEFT_PAR)." or ".B(SYNTAX_STRING).".<BR>\n";
            return "_SYNTAX_ERROR";
        }
        return $val;
    }
} // class Syntax


/** B function
 *
 */
function B($text) {
    return "<b>".$text."</b>";
}

