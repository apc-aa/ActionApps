<?php

use AA\Util\LastErrTrait;

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

class SQL_Parser_Constants {
    // token types - number representation
    public const TOKEN_TYPE_UNKNOWN           = 0;
    public const TOKEN_TYPE_STRING            = 1;
    public const TOKEN_TYPE_OPERATOR_AND      = 2;
    public const TOKEN_TYPE_OPERATOR_OR       = 3;
    public const TOKEN_TYPE_OPERATOR_NOT      = 4;
    public const TOKEN_TYPE_LEFT_PARENTHESIS  = 5;
    public const TOKEN_TYPE_RIGHT_PARENTHESIS = 6;
    public const TOKEN_TYPE_EMPTY_TOKEN       = 7;
    public const TOKEN_TYPE_EOF               = 8;

    // token types - text representation
    public const SYNTAX_LEFT_PAR  = "(";
    public const SYNTAX_RIGHT_PAR = ")";
    public const SYNTAX_AND       = "and";
    public const SYNTAX_OR        = "or";
    public const SYNTAX_NOT       = "not";
    public const SYNTAX_STRING    = "string";
    public const SYNTAX_EOF       = "EOF";

    /** translates number representation token types to textual representation */
    public const READABLE = [
        self::TOKEN_TYPE_LEFT_PARENTHESIS  => self::SYNTAX_LEFT_PAR,
        self::TOKEN_TYPE_RIGHT_PARENTHESIS => self::SYNTAX_RIGHT_PAR,
        self::TOKEN_TYPE_OPERATOR_AND      => self::SYNTAX_AND,
        self::TOKEN_TYPE_OPERATOR_OR       => self::SYNTAX_OR,
        self::TOKEN_TYPE_OPERATOR_NOT      => self::SYNTAX_NOT,
        self::TOKEN_TYPE_STRING            => self::SYNTAX_STRING,
        self::TOKEN_TYPE_EOF               => self::SYNTAX_EOF,
    ];
}


/*
$N = ["S", "E", "Eap", "T", "Tap", "F", "G"];
$T = ["string", "and", "or", "not", "(", ")"];
$P = ["S"   => [["E", "lambda"]],
      "E"   => [["T", "Eap", "lambda"]],
      "Eap" => [["or", "T", "Eap", "lambda"], ["lambda"]],
      "T"   => [["F", "Tap", "lambda"]],
      "Tap" => [["and", "F", "Tap", "lambda"], ["lambda"]],
      "F"   => [["not", "G", "lambda"], ["G", "lambda"]],
      "G"   => [["(", "E", ")", "lambda"], ["string", "lambda"]]
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
*/

// -----------------------------------------------------------------------------
//  Functions for Lexical analysis
// -----------------------------------------------------------------------------

class SQL_Lex {

    public const APOS              = "'";
    public const WHITE             = " \t";
    public const LEFT_PARENTHESES  = "({[";
    public const RIGHT_PARENTHESES = ")]}";
    public const OPERATOR          = "+-";
    public const SPECIAL           = "+-\"' \t(){}[]";
    public const QUOT              = "\"";

    // return status codes
    public const E_NO_ENDING_QUOTAPOS = 16;
    public const S_OK                 = 17;
    public const S_IMPLICIT           = 18;
    public const S_NO_MORE_TOKENS     = 19;

    /** resolveOperator function
     * @param $op
     * @return array
     */
    public static function resolveOperator($op): array {
        switch ($op) {
            case '+':
                return ["type" => SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_AND, "value" => "and"];
            case '-':
                return ["type" => SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT, "value" => "not"];
        }
        return ["type" => SQL_Parser_Constants::TOKEN_TYPE_UNKNOWN, "value" => "strange operator"];
    }

    /** contains SQL special characters? */
    public static function isSpecial($text): bool {
        return (strpos(self::SPECIAL, $text) !== false);
    }

    ///** isLetter function
    // * @param $c
    // */
    //function isLetter($c) {
    //    return ($c >= "A" && $c <= "Z")
    //        || ($c >= "a" && $c <= "z")
    //        || (ord ($c) >= 128);
    //}
    /** tillTheEndingQuotApos function
     * @param $input
     * @param $i
     * @param $length
     * @param $ending
     * @return array
     */
    public static function tillTheEndingQuotApos($input, $i, $length, $ending): array {
        $tok = "";
        while ($input[$i] != $ending) {
            $tok .= $input[$i++];
            if ($i >= $length) {
                return ["status" => self::E_NO_ENDING_QUOTAPOS, "value" => "", "i" => $i];
            }
        }
        return ["status" => self::S_OK, "value" => $tok, "i" => $i + 1];    // i+1  =>  skips string terminator
    }

    /** tillTheFirstSpecial function
     * @param $input
     * @param $i
     * @param $length
     * @return array
     */
    public static function tillTheFirstSpecial($input, $i, $length): array {
        $tok  = "";
        $last = $length - 1;
        while (($i < $length) and
            (
                !SQL_Lex::isSpecial($input[$i])
                or // don't break on apostrophe and minus sign in the middle of words
                (($input[$i] == '-') and (($i and !SQL_Lex::isSpecial($input[$i - 1])) or ($i == $last)))
                or
                (($input[$i] == "'") and ($i and ($i != $last) and !SQL_Lex::isSpecial($input[$i - 1] . $input[$i + 1])))
            )) {
            // (false !== strpos("'-", $input[$i])) && ($i != 0) && ($i != $length - 1) && !isSpecial($input[$i-1].$input[$i+1])))) {
            $tok .= $input[$i++];
        }
        return ["status" => self::S_OK, "value" => $tok, "i" => $i];
    }

    /** getToken function
     * @param $input
     * @param $i
     * @param $length
     * @return array
     */
    public static function getToken($input, $i, $length): array {
        $tok = [];
        // eat whitespaces
        $i += strspn($input, self::WHITE, $i, $length);
        if ($i >= $length) {
            // no more tokens
            return [
                "status" => self::S_NO_MORE_TOKENS,
                "value"  => "",
                "i"      => $i,
                "type"   => SQL_Parser_Constants::TOKEN_TYPE_EMPTY_TOKEN,
            ];
        }
        if (($input[$i] == self::QUOT) || ($input[$i] == self::APOS)) {
            $tok         = SQL_Lex::tillTheEndingQuotApos($input, $i + 1, $length, $input[$i]);
            $tok["type"] = ($tok["status"] == self::S_OK) ? SQL_Parser_Constants::TOKEN_TYPE_STRING : SQL_Parser_Constants::TOKEN_TYPE_UNKNOWN; // error handling
        } elseif (strpos(self::SPECIAL, $input[$i]) !== false) {
            if (strpos(self::OPERATOR, $input[$i]) !== false) { // includes '+' and '-' (one character operators)
                $tok["status"] = self::S_OK;
                $val           = SQL_Lex::resolveOperator($input[$i]);
                $tok['value']  = $val['value'];    // 'and' (for +) and 'not' (for -)
                $tok["i"]      = $i + 1;
                $tok["type"]   = $val["type"];
            } elseif (strpos(self::LEFT_PARENTHESES, $input[$i]) !== false) { // isLeftParenthesis - includes '(', '[' a '{'
                $tok["status"] = self::S_OK;
                $tok['value']  = "(";    // all parenthesis are equal
                $tok["i"]      = $i + 1;
                $tok["type"]   = SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS;
            } elseif (strpos(self::RIGHT_PARENTHESES, $input[$i]) !== false) { // isRightParenthesis - includes ')', ']' a '}'
                $tok["status"] = self::S_OK;
                $tok['value']  = ")";    // all parenthesis are equal
                $tok["i"]      = $i + 1;
                $tok["type"]   = SQL_Parser_Constants::TOKEN_TYPE_RIGHT_PARENTHESIS;
            }
            $i++;
        } else {
            $tok         = SQL_Lex::tillTheFirstSpecial($input, $i, $length);
            $tok["type"] = SQL_Parser_Constants::TOKEN_TYPE_UNKNOWN;
        }
        return $tok;
    }

    /** preProcess function
     * @param array $toks
     * @return array
     */
    public static function preProcess(array $toks): array {
        foreach ($toks as $val) {
            // UNKNOWN will be set for strings not delimeted by apostrofs,
            // we can't say: "it is string" (see "and", ...)
            if ($val["type"] == SQL_Parser_Constants::TOKEN_TYPE_UNKNOWN) {
                switch (strtolower($val['value'])) {
                    case 'and':
                        $val['value'] = "and";
                        $val["type"]  = SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_AND;
                        break;
                    case 'or':
                        $val['value'] = "or";
                        $val["type"]  = SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_OR;
                        break;
                    case 'not':
                        $val['value'] = "not";
                        $val["type"]  = SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT;
                        break;
                    default:
                        $val["type"] = SQL_Parser_Constants::TOKEN_TYPE_STRING;
                }
            }
            //        elseif ( $val["type"] == TOKEN_TYPE_OPERATOR ) {
            //            if ( $val['value'] == "+" ) $val['value'] = "and";
            //            elseif ( $val['value'] == "-" ) $val['value'] = "not";
            //        }
            $newtoks[] = $val;
        }
        if (isset($newtoks) and is_array($newtoks)) {
            reset($newtoks);
            while ($t1 = current($newtoks)) {
                $newtoks2[] = $t1;
                $t2         = next($newtoks);
                if ($t1["type"] == SQL_Parser_Constants::TOKEN_TYPE_STRING) {
                    if (($t2) && (($t2["type"] == SQL_Parser_Constants::TOKEN_TYPE_STRING) || ($t2["type"] == SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS) || ($t2["type"] == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT))) {
                        $newtoks2[] = [
                            "status" => self::S_IMPLICIT,
                            "value"  => "and",
                            "i"      => $t1["i"],
                            "type"   => SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_AND,
                        ];
                    }
                }
            }
        }
        $newtoks2[] = [
            "status" => self::S_IMPLICIT,
            "value"  => "end of input",
            "i"      => 65536,
            "type"   => SQL_Parser_Constants::TOKEN_TYPE_EOF,
        ];
        return $newtoks2;
    }

    /** lex function
     * @param $input
     * @return array
     */
    public static function lex($input): array {
        $res    = [];
        $length = strlen($input);
        $i      = 0;
        do {
            $r = SQL_Lex::getToken($input, $i, $length);
            if ($r["status"] == self::S_OK) {
                $res[] = $r;
            }
            $i = $r["i"];
        } while ($r["type"] != SQL_Parser_Constants::TOKEN_TYPE_EMPTY_TOKEN);
        return SQL_Lex::preProcess($res);
    }


}


// -----------------------------------------------------------------------------
//  Functions for Syntax analysis
// -----------------------------------------------------------------------------

class TokenList {
    protected $tList;
    protected $index;

    /** TokenList function
     * @param $t
     */
    function __construct($t) {
        $this->tList = $t;
        $this->index = 0;
    }

    /** lookAhead function  */
    function lookAhead() {
        return $this->tList[$this->index]["type"];
    }

    /** match function
     * @param $symbol
     * @return string
     */
    function match($symbol): string {
        if (($la = $this->lookAhead()) == $symbol) {
            $this->index++;
            return '';
        }
        // error - match something, what is not in order
        return "Syntax error at position " . $this->getTokenBegin() . ". Found " . Syntax::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . Syntax::B(SQL_Parser_Constants::READABLE[$symbol]) . ".<BR>\n";
    }

    /** getStringValue function
     *
     */
    function getStringValue(): string {
        return ($this->tList[$this->index]["type"] == SQL_Parser_Constants::TOKEN_TYPE_STRING) ? quote($this->tList[$this->index]['value']) : '';
    }

    /** getTokenBegin function
     *
     */
    function getTokenBegin() {
        $t = $this->tList[$this->index];
        return $t["i"] - strlen($t['value']);
    }
}    // class TokenList


/**
 * Class for syntax analysis contains variable tList, which is list of token,
 * which comes from lexical analysis.
 */
class Syntax {
    use LastErrTrait;

    protected $tList;
    protected $column;
    protected $operator;
    protected $pre;
    protected $post;

    /** operator LIKE, RLIKE a LLIKE */
    protected const RL = [
        "operator" => ["LIKE" => "LIKE", "LLIKE" => "LIKE", "RLIKE" => "LIKE", "XLIKE" => "LIKE", "=" => "="],
        "pre"      => ["LIKE" => "%",    "LLIKE" => "%",    "RLIKE" => "",     "XLIKE" => "",     "=" => ""],
        "post"     => ["LIKE" => "%",    "LLIKE" => "",     "RLIKE" => "%",    "XLIKE" => "",     "=" => ""],
    ];

    /** Syntax function
     * @param $column
     * @param $operator
     * @param $t
     */
    function __construct($column, $operator, $t) {
        $operator       = strtoupper($operator);
        $this->column   = $column;
        $this->operator = self::RL["operator"][$operator];
        if ($this->operator == "") {
            $operator = $this->operator = "LIKE";    // unknown operator
        }
        $this->tList = new TokenList($t);
        $this->pre   = self::RL["pre"][$operator];
        $this->post  = self::RL["post"][$operator];
    }

    /*
     *    Gramar rules.
     *  If there are more rules for one noterminal, we should choose the right one.
     *  It is suitable to look for 1 token - gramar is LL(1).
     */

    /** S function
     */
    function S(): string {
        $la = $this->tList->lookAhead();
        if (($la == SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS) || ($la == SQL_Parser_Constants::TOKEN_TYPE_STRING) || ($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT)) {      // S -> E
            //     echo("S->E<br>\n");//debug
            if (($val = $this->E()) == "_SYNTAX_ERROR") {
                return $val;
            }
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_EOF))) {
                self::lastErr(1, $err);
                return "_SYNTAX_ERROR";
            }
            // echo("<b>Input string is OK.</b><br>\n");
        } else {
            self::lastErr(2, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_LEFT_PAR) . ", " . self::B(SQL_Parser_Constants::SYNTAX_STRING) . " or " . self::B(SQL_Parser_Constants::SYNTAX_NOT) . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** E function
     */
    function E(): string {
        $la = $this->tList->lookAhead();
        if (($la == SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS) ||
            ($la == SQL_Parser_Constants::TOKEN_TYPE_STRING) ||
            ($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT))        // E -> TE'
        {
            // echo("E->TEap<BR>\n");//debug
            if (($val = $this->T()) == "_SYNTAX_ERROR") {
                return $val;
            }
            if (($foo = $this->Eap()) == "_SYNTAX_ERROR") {
                return $foo;
            }
            $val .= $foo;
        } else {
            self::lastErr(3, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_LEFT_PAR) . ", " . self::B(SQL_Parser_Constants::SYNTAX_STRING) . " or " . self::B(SQL_Parser_Constants::SYNTAX_NOT) . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** Eap function
     */
    function Eap(): string {
        $la = $this->tList->lookAhead();
        if ($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_OR) {    // E' -> orTE'
            // echo("Eap->orTEap<BR>\n");//debug
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_OR))) {
                self::lastErr(4, $err);
                return "_SYNTAX_ERROR";
            }
            if (($val = $this->T()) == "_SYNTAX_ERROR") {
                return $val;
            }
            if (($foo = $this->Eap()) == "_SYNTAX_ERROR") {
                return $foo;
            }
            $val = " or " . $val . $foo;
        } elseif (($la == SQL_Parser_Constants::TOKEN_TYPE_RIGHT_PARENTHESIS) || ($la == SQL_Parser_Constants::TOKEN_TYPE_EOF)) { // E' -> lambda
            // echo("Eap-><BR>\n");//debug
            $val = "";
        } else {
            self::lastErr(5, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_OR) . ", " . self::B(SQL_Parser_Constants::SYNTAX_LEFT_PAR) . " or " . self::B(SQL_Parser_Constants::SYNTAX_EOF) . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** T function
     */
    function T(): string {
        $la = $this->tList->lookAhead();
        if (($la == SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS) || ($la == SQL_Parser_Constants::TOKEN_TYPE_STRING) || ($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT)) {        // T -> FT'
            // echo("T->FTap<BR>\n");//debug
            if (($val = $this->F()) == "_SYNTAX_ERROR") {
                return $val;
            }
            if (($foo = $this->Tap()) == "_SYNTAX_ERROR") {
                return $foo;
            }
            $val .= $foo;
        } else {
            self::lastErr(6, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_LEFT_PAR) . ", " . self::B(SQL_Parser_Constants::SYNTAX_STRING) . " or " . self::B(SQL_Parser_Constants::SYNTAX_NOT) . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** Tap function
     */
    function Tap(): string {
        $la = $this->tList->lookAhead();
        if ($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_AND) {    // T' -> andFT'
            //            echo("Tap->andFTap<BR>\n");//debug
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_AND))) {
                self::lastErr(7, $err);
                return "_SYNTAX_ERROR";
            }
            if (($val = $this->F()) == "_SYNTAX_ERROR") {
                return $val;
            }
            if (($foo = $this->Tap()) == "_SYNTAX_ERROR") {
                return $foo;
            }
            $val = " and " . $val . $foo;
        } elseif (($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_OR) || ($la == SQL_Parser_Constants::TOKEN_TYPE_RIGHT_PARENTHESIS) || ($la == SQL_Parser_Constants::TOKEN_TYPE_EOF)) {                // T' -> lambda
            //            echo("Tap-><br>\n");//debug
            $val = "";
        } else {
            self::lastErr(8, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_AND) . ", " . self::B(SQL_Parser_Constants::SYNTAX_OR) . ", " . self::B(SQL_Parser_Constants::SYNTAX_RIGHT_PAR) . " or " . SQL_Parser_Constants::SYNTAX_EOF . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** F function
     */
    function F(): string {
        $la = $this->tList->lookAhead();
        if ($la == SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT) {    // F -> not G
            // echo("F->notG<BR>\n");//debug
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_OPERATOR_NOT))) {
                self::lastErr(9, $err);
                return "_SYNTAX_ERROR";
            }
            if (($foo = $this->G()) == "_SYNTAX_ERROR") {
                return $foo;
            }
            $val = "not ($foo)";
        } elseif (($la == SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS) || ($la == SQL_Parser_Constants::TOKEN_TYPE_STRING)) { // F -> G
            //            echo("F->G<BR>\n");//debug
            if (($val = $this->G()) == "_SYNTAX_ERROR") {
                return $val;
            }
        } else {
            self::lastErr(10, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_LEFT_PAR) . ", " . self::B(SQL_Parser_Constants::SYNTAX_STRING) . " or " . self::B(SQL_Parser_Constants::SYNTAX_NOT) . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** G function
     */
    function G(): string {
        $la = $this->tList->lookAhead();
        if ($la == SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS) { // G -> (E)
            // echo("G->(E)<BR>\n");//debug
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_LEFT_PARENTHESIS))) {
                self::lastErr(11, $err);
                return "_SYNTAX_ERROR";
            }
            $val = SQL_Parser_Constants::SYNTAX_LEFT_PAR;
            if (($foo = $this->E()) == "_SYNTAX_ERROR") {
                return $foo;
            }
            $val .= $foo;
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_RIGHT_PARENTHESIS))) {
                self::lastErr(12, $err);
                return "_SYNTAX_ERROR";
            }
            $val .= SQL_Parser_Constants::SYNTAX_RIGHT_PAR;
        } elseif ($la == SQL_Parser_Constants::TOKEN_TYPE_STRING) {    // G -> string
            // echo("G->string<BR>\n");//debug
            $val = getDB()->quote_identifier($this->column). $this->operator . " '" . $this->pre . $this->tList->getStringValue() . $this->post . "'";
            if (($err = $this->tList->match(SQL_Parser_Constants::TOKEN_TYPE_STRING))) {
                self::lastErr(13, $err);
                return "_SYNTAX_ERROR";
            }
        } else {
            self::lastErr(14, "Syntax error at position " . $this->tList->getTokenBegin() . ". Found " . self::B(SQL_Parser_Constants::READABLE[$la]) . " while expecting " . self::B(SQL_Parser_Constants::SYNTAX_LEFT_PAR) . " or " . self::B(SQL_Parser_Constants::SYNTAX_STRING) . ".<BR>\n");
            return "_SYNTAX_ERROR";
        }
        return $val;
    }

    /** B function */
    public static function B($text): string {
        return "<b>$text</b>";
    }
} // class Syntax

