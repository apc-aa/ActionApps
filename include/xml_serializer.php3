<?php
/**
 *
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
 * @package   UserInput
 * @version   $Id$
 * @author    Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// A class for converting PHP structures to XML and back
//
// Author and Maintainer: Mitra mitra@mitra.biz
//
// It is intended - and you are welcome - to extend this to be more
// comprehensive.  It is a requirement that the routines here are inverses
// of each other, i.e.


/** xml_serialize function
 *  Convert a PHP array to an XML structure
 *   A logical extension of this would be to make it nest
 *   if $a is set, then output is of form <k $a>....</k>,
 *   otherwise it is of form <t tname="k">.....</t>
 *   note that it can be set to ""
 * @param $k
 * @param $v
 * @param $i
 * @param $ii
 * @param $a
 */
function xml_serialize($k,&$v,$i,$ii,$a=null) {
    global $debug;
    $start = (isset($a) ? "$k $a" : "t tname=\"$k\"");
    $end = (isset($a) ? "$k" : "t");
    if (is_null($v)) {
        return "$i<$start />";
    }
    elseif (is_string($v) || is_integer($v) || is_real($v) || is_bool($v)) {
        //if ($debug) print "STRING";
        // if contains stuff other than printable ascii and CRLF then hex it
        if (preg_match('/[\000-\011\013-\014\016-\037\200-\377]/',$v)) {
            return "$i<$start coding=\"bin2hex\">".bin2hex($v)."</$end>";
        } else {
            return "$i<$start>".htmlspecialchars($v)."</$end>";
        }
    }
    elseif (is_array($v)) {
        reset($v);
        while (list($k1,$v1) = each($v)) {
            $o .= xml_serialize($k1,$v1,$i.$ii,$ii);
        }
        return "$i<$start>$o$i</$end>";
    } elseif (is_object($v)) {
        //if ($debug) print "OBJECT";
        if (is_callable(array($v,"xml_serialize"))) {
            return $v->xml_serialize($k,$i,$ii,$a);
        } else {
            print("ERROR: Can't serialize an Object");
        }
    } else {
        print("What is $k, if it isn't String, array or object=");
        print_r($v);
    }
}

// A class to unserialize from XML to a PHP structure,
// Note this matches the xml_serializer above,
class xml_unserializer {
    var $stack;          // stack of items above oen being worked on
    var $chardata;          // Current string of CDATA
    var $top;        // Element we are working on
    var $coding;   // set to bin2hex when coded id
    var $debug;          // set to true to debug this
    var $parser;
    var $namestack;
    var $codingstack;
    var $name;      // Name of the current array
    /** xml_unserializer function
     *
     */
    function xml_unserializer() {
        $this->stack       = array();
        $this->namestack   = array();
        $this->codingstack = array();
        $this->chardata    = "";
        $this->top         = null;
        $this->coding      = "";
        $this->debug       = false;
        $this->parser      = xml_parser_create();
        xml_set_object($this->parser, $this);
        xml_set_element_handler($this->parser, "startElement", "endElement");
        xml_set_character_data_handler($this->parser,"charD");
    }
    /** parse function
     * @param $xml_data
     */
    function parse($xml_data) {
        if (!xml_parse($this->parser, $xml_data, true)) {
            return sprintf("XML parse error: %s at line %d",
                xml_error_string(xml_get_error_code($this->parser)),
                xml_get_current_line_number($this->parser));
                xml_parser_free($this->parser);
        }
        return $this->top;
    }
    /** startElement function
     * @param $parser
     * @param $name
     * @param $attrs
     */
    function startElement($parser, $name, $attrs) {
        global $debugimport;
        if ($this->debug) {
            huhl("\n:start='".$name."'",$attrs,$this);
        }
        array_push($this->stack,$this->top);
        array_push($this->namestack,$this->name);
        array_push($this->codingstack,$this->coding);
        if ($attrs["CODING"]) {
            $this->coding = $attrs["CODING"];
            unset($attrs["CODING"]);
        } else {
            $this->coding = "";
        }
        if (($name == "T" || is_callable($name . "_xml_unserialize"))
             && isset($attrs["TNAME"])) {
            $this->name = $attrs["TNAME"];
            unset($attrs["TNAME"]);
        } else {
            $this->name = $name;
        }
        if (isset($attrs) && count($attrs)) {
            $this->top = $attrs;
        }
        else {
            $this->top = null;
        }
        $this->chardata = "";
//        if ($debugimport) huhl("START:$name: els=",$this->stack,"Top=",$this->top);
    }

    /** endElement function
     *  End Element, two choices, either data, or attributes.
     * @param $parser
     * @param $name
     */
    function endElement($parser,$name) {
        if ($this->debug) {
            huhl("\n:end='".$name."'",$this);
        }
        switch ($this->coding) {
          case "bin2hex": $this->chardata = pack_id($this->chardata); break;
          case "serialize":
          case "serializezip":
                $c = base64_decode($this->chardata);
                if ($this->coding == "serializegzip") {
                    $c = gzuncompress($c);
                }
                $this->top = unserialize($c);
                $this->chardata = "";
                break;
          default:
    //       $this->chardata = preg_replace("/^\s+/",'',$this->chardata);
    // Strip trailing whitespace, note this occurs in parent stack
            $this->chardata = preg_replace("/\s+$/",'',$this->chardata);
        }
        $n = $this->name;
        if (isset($this->top)) { // Already seen children or attrs
            $t = $this->top;
            $this->top = array_pop($this->stack);
            $this->name = array_pop($this->namestack);
            $this->coding= array_pop($this->codingstack);
            if ($this->chardata) {
                $t["CHARDATA"] = $this->chardata;
                $this->chardata = "";
            }
            if (is_callable($name."_xml_unserialize")) {
                $f=$name."_xml_unserialize";
                $this->top[$n] = $f($n,$t);
                //print("Unparsing object $name as name $n");
            } elseif ($n == $name) {
                // Cant assume its a structure export, import as array
                $this->top[$n][] = $t;
                //print("Unserialized stuff from somewhere $n []");
            } else {  // This came from a xml_sequencer
                $this->top[$n] = $t;
                //print("Unserialized xmlserialized array $n");
            }
        } else {  // Just chardata
            $this->top = array_pop($this->stack);
            $this->name = array_pop($this->namestack);
            if ($n == $name) { // Raw XML, cant guarrantee
                $this->top[$n][] = $this->chardata;
            } else { // Came from structure export, name from attribute
                $this->top[$n] = $this->chardata; // possibly empty
            }
            $this->chardata = ""; $this->coding = "";
        }
        if ($this->debug)  huhl("END:$name: els=",$this->stack,"Top=",$this->top);
    }
    /** charD function
     * @param $parser
     * @param $data
     */
    function charD($parser, $data) {
        if ($this->debug) {
            huhl("\n:charD='".$data."'");
        }
        $this->chardata .= $data;
        if ($this->debug) {
            huhl("\n:charD now='".$this->chardata."'");
        }
    }
}
/** xml_unserialize function
 * @param $xml_data
 */
function xml_unserialize($xml_data) {
    $x = new xml_unserializer();
    return $x->parse($xml_data);
}
/** test_xmlserializer function
 * Testing
 */
function test_xmlserializer() {
    require_once "config.php3";
    require_once "AA_INC_PATH"."zids.php3";
    $aa = new zids("12345678901234567890123456789012");
    $a = $aa->packedids();
    print_r($a);
    //$a = array(" one");
    $serial_a = xml_serialize("OVERALL",$a,"\n","    ");
    print("SERIAL");print_r($serial_a);
    $x = new xml_unserializer();
    $y = $x->parse($serial_a);
    print "RESULT="; print_r($y);
}

//test_xmlserializer();

?>