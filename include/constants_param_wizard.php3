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
 * @author    Honza Malik <honza.malik@ecn.cz>, Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

/* These arrays serve to the parameter wizard. You can describe each item and its parameters.
    You can write some more described examples as well.

You can use HTML tags in the description text. If you want < or > to be printed verbatim,
use the escape character \ - the wizard will translate the characters. Remember you have to write
\\ in the PHP strings (e.g. "... writes some text like \\<a href=...\\>").

    Each array has this structure:
        "name"=>describes the items contained in the array (used on various places in the wizard)
        "hint"=>a common text displayed at the bottom of the wizard
        "items"=>array of items
    Each item has this structure:
        "name"=>a brief name
        "desc"=>a thoroughfull description
        "params"=>array of parameters
        "examples"=>array of examples
    Each param has this structure:
        "name"=>a brief name
        "desc"=>a thoroughfull description
        "type"=>see the param wizard constants for a description of available types
        "example"=>an example value
    Each example has this structure:
        "desc"=>a thoroughfull description
        "params"=>the params in the internal format (divided by :)
*/

// require_once __DIR__."/../modules/alerts/reader_field_ids.php3";


// $INSERT_TYPES[...] - now uses class approach with AA\Util\Paramwizard
// $DEFAULT_VALUE_TYPES[...] - now uses class approach with AA\Util\Paramwizard

$VALIDATE_TYPES["name"] = _m("Input Validate Type");
$VALIDATE_TYPES["items"]["text"] = [
    "name"=>_m("No validation")
];
$VALIDATE_TYPES["items"]["url"] = [
    "name"=>_m("URL")
];
$VALIDATE_TYPES["items"]["e-mail"] = [
    "name"=>_m("E-mail")
];
$VALIDATE_TYPES["items"]["number"] = [
    "name"=>_m("Number = positive integer number"),
    "params"=> [
        [
            "name"=>_m("Min"),
            "desc"=>_m("Alloved minimum value"),
            "type"=>"INT",
            "example"=>1
        ],
        [
            "name"=>_m("Max"),
            "desc"=>_m("Alloved maximum value"),
            "type"=>"INT",
            "example"=>12
        ],
        [
            "name"=>_m("Step"),
            "type"=>"INT",
            "example"=>1
        ]
    ]
];
$VALIDATE_TYPES["items"]["signed"] = [
    "name"=>_m("Number = (signed) integer number"),
    "params"=> [
        [
            "name"=>_m("Min"),
            "desc"=>_m("Alloved minimum value"),
            "type"=>"INT",
            "example"=>1
        ],
        [
            "name"=>_m("Max"),
            "desc"=>_m("Alloved maximum value"),
            "type"=>"INT",
            "example"=>12
        ],
        [
            "name"=>_m("Step"),
            "type"=>"INT",
            "example"=>1
        ]
    ]
];
$VALIDATE_TYPES["items"]["id"] = [
    "name"=>_m("Id = 1-32 hexadecimal digits [0-9a-f]")
];
$VALIDATE_TYPES["items"]["date"] = [
    "name"=>_m("Date = store as date")
];
$VALIDATE_TYPES["items"]["bool"] = [
    "name"=>_m("Bool = store as bool")
];
$VALIDATE_TYPES["items"]["user"] = [
    "name"=>_m("User = does nothing ???")
];
$VALIDATE_TYPES["items"]["unique"] = [
    "name"=>_m("Unique = proove uniqueness"),
    "desc"=>_m("Validates only if the value is not yet used. Useful e.g.
        for emails or user names."),
    "params"=> [
        [
            "name"=>_m("Field ID"),
              "desc"=>_m("Field in which to look for matching values (default is current field)."),
              "type"=>"STRID",
              "example"=>"undefined......."
        ],
        [
            "name"=>_m("Scope"),
              "desc"=>_m("<b>1</b> = This slice only.
                <b>2</b> = All slices.<br>
                <b>0</b> = (default) Username, special: Checks uniqueness in reader management
                slices and in the permission system. Always uses field ID %1",
                [FIELDID_USERNAME]),
              "type"=>"INT",
              "example"=>1
        ]
    ]
];
$VALIDATE_TYPES["items"]["regexp"] = [
    "name"=>_m("Regular Expression"),
    "desc"=>_m("Validation based on Regular Expressions"),
    "params"=> [
        [
            "name"=>_m("Regular Expressions"),
              "desc"=>_m('use something like: /^[0-9]*$/ - see "Regular Expressions" manual.'),
              "type"=>"STR",
              "example"=>'/^[a-zA-Z ]{0,10}$/'
        ],
        [
            "name"=>_m("Error text"),
              "desc"=>_m("error message"),
              "type"=>"STR",
              "example"=>_m("Wrong value")
        ]
    ]
];
$VALIDATE_TYPES["items"]["e-unique"] = [
    "name"=>_m("Unique e-mail"),
    "desc"=>_m("Combines the e-mail and unique validations. Validates only if the value is a valid email address and not yet used."),
    "params"=> [
        [
            "name"=>_m("Field ID"),
              "desc"=>_m("Field in which to look for matching values (default is current field)."),
              "type"=>"STRID",
              "example"=>"undefined......."
        ],
        [
            "name"=>_m("Slice only"),
              "desc"=>_m("Do you want to check for uniqueness this slice only
                  or all slices?"),
              "type"=>"BOOL",
              "example"=>1
        ]
    ]
];
$VALIDATE_TYPES["items"]["pwd"] = [
    "name"=>_m("Password and Change Password"),
    "desc"=>_m("Validates the passwords do not differ when changing password.
        <i>The validation is provided only by JavaScript and not by ValidateInput()
        because the insert
        function does the validation again before inserting the new password.</i>")
];

// --------------------------------------------------------------------------------

/** It is important the input types are 3 letter acronyms, because
*   this is used e.g. in admin/se_constant.php3, function propagateChanges(). */

// $INPUT_TYPES[...] - now uses class approach with AA\Util\Paramwizard


$FIELD_FUNCTIONS = [
    "name"=>_m("Function"),
"hint"=>_m("How the formatting in the text on this page is used:<br><i>the field</i> in italics stands for the field edited in the \"configure Fields\" window,<br><b>parameter name</b> in bold stands for a parameter on this screen."),
"items"=> [
////alejo
"f_z"=> [
    "name"=>_m("file information"),
   "desc"=>_m("prints <i>the field</i> as file information, its size or type"),
   "params"=> [
       [
           "name"=>_m("information"),
       "desc"=>_m("specifies returned information: <br> - <i>size</i> - returns size of file in kB o MB<br> - <i>type</i> - returns type of file)"),
       "type"=>"STR",
       "example"=>"size"
       ]
   ]
],
"f__"=> [
    "name"=>_m("just code"),
    "desc"=>_m("prints the code in parameter - _#this could be used - easier than f_t (no aditional parameter)")
],
"f_0"=> [
    "name"=>_m("null function"),
    "desc"=>_m("prints nothing")
],
"f_1"=> [
    "name"=>_m("first value"),
    "desc"=>_m("prints first value, even if the field is multivalue")
],
"f_2"=> [
    "name"=>_m("translated"),
    "desc"=>_m("prints translated field based on currently selected language")
],
"f_a"=> [
    "name"=>_m("abstract"),
    "desc"=>_m("prints abstract (if exists) or the beginning of the <b>fulltext</b>"),
    "params"=> [
        [
            "name"=>_m("length"),
        "desc"=>_m("max number of characters grabbed from the <b>fulltext</b> field"),
        "type"=>"INT",
        "example"=>"80"
        ],
        [
            "name"=>_m("fulltext"),
        "desc"=>_m("field id of fulltext field (like full_text.......), from which the text is grabbed. If empty, the text is grabbed from <i>the field</i> itself."),
        "type"=>"STRID",
        "example"=>_m("full_text.......")
        ],
        [
            "name"=>_m("paragraph"),
        "desc"=>_m("take first paragraph (text until \<BR\> or \<P\> or \</P\> or at least '.' (dot)) if shorter then <b>length</b>"),
        "type"=>"BOOL",
        "example"=>"1"
        ]
    ]
],
"f_b"=> [
    "name"=>_m("extended fulltext link"),
    "desc"=>_m("Prints some <b>text</b> (or field content) with a link to the fulltext. A more general version of the f_f function. This function doesn't use <i>the field</i>."),
    "params"=> [
        [
            "name"=>_m("link only"),
        "desc"=>_m("field id (like 'link_only.......') where switch between external and internal item is stored.  (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."),
        "type"=>"STRID",
        "example"=>_m("link_only.......")
        ],
        [
            "name"=>_m("url_field"),
        "desc"=>_m("field id if field, where external URL is stored (like hl_href.........)"),
        "type"=>"STRID",
        "example"=>_m("hl_href.........")
        ],
        [
            "name"=>_m("redirect"),
        "desc"=>_m("The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."),
        "type"=>"STR",
        "example"=>_m("http#://www.ecn.cz/articles/solar.shtml")
        ],
        [
            "name"=>_m("text"),
        "desc"=>_m("The text to be surrounded by the link. If this parameter is a field id, the field's content is used, else it is used verbatim"),
        "type"=>"STR",
        "example"=>""
        ],
        [
            "name"=>_m("condition field"),
        "desc"=>_m("when the specified field hasn't any content, no link is printed, but only the <b>text</b>"),
        "type"=>"STRID",
        "example"=>_m("full_text.......")
        ],
        [
            "name"=>_m("tag addition"),
        "desc"=>_m("additional text to the \"\<a\>\" tag"),
        "type"=>"STR",
        "example"=>_m("target=_blank")
        ],
        [
            "name"=>_m("no session id"),
        "desc"=>_m("If 1, the session id (AA_Session=...) is not added to url"),
        "type"=>"BOOL",
        "example"=>"1"
        ]
    ]
],
"f_c"=> [
    "name"=>_m("condition"),
    "desc"=>_m("This is a very powerful function. It may be used as a better replace of some previous functions. If <b>cond_field</b> = <b>condition</b>, prints <b>begin</b> <i>field</i> <b>end</b>, else prints <b>else</b>. If <b>cond_field</b> is not specified, <i>the field</i> is used. Condition may be reversed (negated) by the \"!\" character at the beginning of it."),
    "params"=> [
        [
            "name"=>_m("condition"),
        "desc"=>_m("you may use \"!\" to reverse (negate) the condition"),
        "type"=>"STR",
        "example"=>"1"
        ],
        [
            "name"=>_m("begin"),
        "desc"=>_m("text to print before <i>field</i>, if condition is true"),
        "type"=>"STR",
        "example"=>_m("Yes")
        ],
        [
            "name"=>_m("end"),
        "desc"=>_m("text to print after <i>field</i>, if condition is true"),
        "type"=>"STR",
        "example"=>""
        ],
        [
            "name"=>_m("else"),
        "desc"=>_m("text to print when condition is not satisfied"),
        "type"=>"STR",
        "example"=>_m("No")
        ],
        [
            "name"=>_m("cond_field"),
        "desc"=>_m("field to compare with the <b>condition</b> - if not filled, <i>field</i> is used"),
        "type"=>"STRID",
        "example"=>""
        ],
        [
            "name"=>_m("skip_the_field"),
        "desc"=>_m("if set to 1, skip <i>the field</i> (print only <b>begin end</b> or <b>else</b>)"),
        "type"=>"STRID",
        "example"=>"1"
        ]
    ],
    "examples"=> [
        [
            "desc"=>_m("This example is usable e.g. for Highlight field - it shows Yes or No depending on the field content"),
        "params"=>_m("1:Yes::No::1")
        ],
        [
            "desc"=>_m("When e-mail field is filled, prints something like \"Email: email@apc.org\", when it is empty, prints nothing"),
        "params"=>_m("!:Email#:&nbsp;")
        ],
        [
            "desc"=>_m("Print image height attribute, if <i>the field</i> is filled, nothing otherwise."),
        "params"=>_m("!:height=")
        ]
    ]
],
"f_d"=> [
    "name"=>_m("date"),
    "desc"=>_m("prints date in a user defined format"),
    "params"=> [
        [
            "name"=>_m("format"),
        "desc"=>_m("PHP-like format - see <a href=\"http://php.net/manual/en/function.date.php\" target=_blank>PHP manual</a>"),
        "type"=>"STR",
        "example"=>_m("m-d-Y")
        ]
    ]
],
"f_e"=> [
    "name"=>_m("edit item"),
    "desc"=>_m("_#EDITITEM used on admin page index.php3 for itemedit url"),
    "params"=> [
        [
            "name"=>_m("type"),
        "desc"=>_m("disc - for editing a discussion<br>itemcount - to output an item count<br>safe - for safe html<br>slice_info - select a field from the slice info<br>edit - URL to edit the item<br>add - URL to add a new item"),
        "type"=>"STR",
        "example"=>_m("edit")
        ],
        [
            "name"=>_m("return url"),
        "desc"=>_m("Return url being called from, usually leave blank and allow default"),
        "type"=>"STR",
        "example"=>_m("/mysite.shtml")
        ]
    ]
],
"f_f"=> [
    "name"=>_m("fulltext link"),
    "desc"=>_m("Prints the URL name inside a link to the fulltext - enables using external items. To be used immediately after \"\<a href=\""),
    "params"=> [
        [
            "name"=>_m("link only"),
        "desc"=>_m("field id (like 'link_only.......') where switch between external and internal item is stored. Usually this field is represented as checkbox. If the checkbox is checked, <i>the field</i> is printed, if unchecked, link to fulltext is printed (it depends on <b>redirect</b> parameter, too)."),
        "type"=>"STRID",
        "example"=>_m("link_only.......")
        ],
        [
            "name"=>_m("redirect"),
        "desc"=>_m("The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."),
        "type"=>"STR",
        "example"=>_m("http#://www.ecn.cz/articles/solar.shtml")
        ],
        [
            "name"=>_m("no session id"),
        "desc"=>_m("If 1, the session id (AA_Session=...) is not added to url"),
        "type"=>"BOOL",
        "example"=>"1"
        ]
    ]
],
"f_g"=> [
    "name"=>_m("image height"),
    "desc"=>_m("An old-style function. Prints <i>the field</i> as image height value (\<img height=...\>) or erases the height tag. To be used immediately after \"height=\".The f_c function provides a better way of doing this with parameters \":height=\". ")
],
"f_h"=> [
    "name"=>_m("print HTML multiple"),
    "desc"=>_m("prints <i>the field</i> content depending on the html flag (escape html special characters or just print)"),
    "params"=> [
        [
            "name"=>_m("delimiter"),
        "desc"=>_m("if specified, a field with multiple values is displayed with the values delimited by it"),
        "type"=>"STR",
        "example"=>";"
        ]
    ]
],
"f_i"=> [
    "name"=>_m("image src"),
    "desc"=>_m("prints <i>the field</i> as image source (\<img src=...\>) - NO_PICTURE for none. The same could be done by the f_c function with parameters :::NO_PICTURE. ")
],
"i_s"=> [
    "name"=>_m("image size"),
    "desc"=>_m("prints <i>the field</i> as image size (height='xxx' width='yyy') (or other image information) or empty string if cant work out, does not special case URLs from uploads directory, might do later! "),
    "params"=> [
        [
            "name"=>_m("information"),
        "desc"=>_m("specifies returned information: <br> - <i>html</i> - (default) - returns image size as HTML atributes (height='xxx' width='yyy')<br> - <i>width</i> - returns width of image in pixels<br> - <i>height</i> - returns height of image in pixels<br> - <i>imgtype</i> - returns flag indicating the type of the image: 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM<br> - <i>mime</i> - returns mimetype of the image (like 'image/gif', 'application/x-shockwave-flash', ...)"),
        "type"=>"STR",
        "example"=>"html"
        ]
    ]
],
"f_j"=> [
    "name"=>_m("substring with case change"),
    "desc"=>_m("prints a part of <i>the field</i>"),
    "params"=> [
        [
            "name"=>_m("start"),
        "desc"=>_m("position of substring start (0=first, 1=second, -1=last,-2=two from end)"),
        "type"=>"INT",
        "example"=>"0"
        ],
        [
            "name"=>_m("count"),
        "desc"=>_m("count of characters (0=until the end)"),
        "type"=>"INT",
        "example"=>""
        ],
        [
            "name"=>_m("case"),
        "desc"=>_m("upper - convert to UPPERCASE, lower - convert to lowercase, first - convert to First Upper; default is don't change"),
        "type"=>"STR",
        "example"=>"upper"
        ],
        [
            "name"=>_m("add string"),
        "desc"=>_m("if string is shorted, <i>add string</i> is appended to the string (probably something like [...])"),
        "type"=>"STR",
        "example"=>"..."
        ]
    ]
],
"f_k"=> ["name" => _m("Auto Update Checkbox")],  //auto update check box
"f_l"=> [
    "name"=>_m("linked field"),
    "desc"=>_m("prints <i>the field</i> as a link if the <b>link URL</b> is not NULL, otherwise prints just <i>the field</i>"),
    "params"=> [
        [
            "name"=>_m("link URL"),
        "desc"=>"",
        "type"=>"STRID",
        "example"=>_m("source_href.....")
        ]
    ]
],
"f_m"=> [
    "name"=>_m("e-mail or link"),
    "desc"=>_m("mailto link - prints: <br>\"<b>begin</b>\<a href=\"(mailto:)<i>the field</i>\" <b>tag adition</b>\><b>field/text</b>\</a\>. If <i>the field</i> is not filled, prints <b>else_field/text</b>."),
    "params"=> [
        [
            "name"=>_m("begin"),
        "desc"=>_m("text before the link"),
        "type"=>"STR",
        "example"=>_m("e-mail")
        ],
        [
            "name"=>_m("field/text"),
        "desc"=>_m("if this parameter is a field id, the field's content is used, else it is used verbatim"),
        "type"=>"STR",
        "example"=>""
        ],
        [
            "name"=>_m("else_field/text"),
        "desc"=>_m("if <i>the field</i> is empty, only this text (or field content) is printed"),
        "type"=>"STR",
        "example"=>""
        ],
        [
            "name"=>_m("linktype"),
        "desc"=>_m("mailto / href (default is mailto) - it is possible to use f_m function for links, too - just type 'href' as this parameter"),
        "type"=>"STR",
        "example"=>_m("href")
        ],
        [
            "name"=>_m("tag addition"),
        "desc"=>_m("additional text to the \"\<a\>\" tag"),
        "type"=>"STR",
        "example"=>_m("target=_blank")
        ],
        [
            "name"=>_m("hide email"),
        "desc"=>_m("if 1 then hide email from spam robots. Default is 0."),
        "type"=>"BOOL",
        "example"=>""
        ]
    ]
],
"f_n"=> [
    "name"=>_m("id"),
    "desc"=>_m("prints unpacked id (use it, if you watn to show 'item id' or 'slice id')")
],
"f_o"=> [
    "name"=>_m("'New' sign"),
    "desc"=>_m("prints 'New' or 'Old' or any other text in <b>newer text</b> or <b>older text</b> depending on <b>time</b>. Time is specified in minutes from current time."),
    "params"=> [
        [
            "name"=>_m("time"),
        "desc"=>_m("Time in minutes from current time."),
        "type"=>"INT",
        "example"=>_m("1440")
        ],
        [
            "name"=>_m("newer text"),
        "desc"=>_m("Text to be printed, if the date in <i>the filed</i> is newer than <i>current_time</i> - <b>time</b>."),
        "type"=>"STR",
        "example"=>_m("NEW")
        ],
        [
            "name"=>_m("older text"),
        "desc"=>_m("Text to be printed, if the date in <i>the filed</i> is older than <i>current_time</i> - <b>time</b>"),
        "type"=>"STR",
        "example"=>_m("")
        ]
    ]
],
"f_q"=> [
    "name"=>_m("text (blurb) from another slice"),
    "desc"=>_m("prints 'blurb' (piece of text) from another slice, based on a simple condition.<br>If <i>the field</i> (or the field specifield by <b>stringToMatch</b>) in current slice matches the content of <b>fieldToMatch</b> in <b>blurbSliceId</b>, it returns the content of <b>fieldToReturn</b> in <b>blurbSliceId</b>."),
    "params"=> [
        [
            "name"=>_m("stringToMatch"),
        "desc"=>_m("By default it is <i>the field</i>.  It can be formatted either as the id of a field (headline........) OR as static text."),
        "type"=>"STR",
        "example"=>_m("category........")
        ],
        [
            "name"=>_m("blurbSliceId"),
        "desc"=>_m("unpacked slice id of the slice where the blurb text is stored"),
        "type"=>"STR",
        "example"=>_m("41415f436f72655f4669656c64732e2e")
        ],
        [
            "name"=>_m("fieldToMatch"),
        "desc"=>_m("field id of the field in <b>blurbSliceId</b> where to search for <b>stringToMatch</b>"),
        "type"=>"STR",
        "example"=>_m("headline........")
        ],
        [
            "name"=>_m("fieldToReturn"),
        "desc"=>_m("field id of the field in <b>blurbSliceId</b> where the blurb text is stored (what to print)"),
        "type"=>"STR",
        "example"=>_m("full_text.......")
        ]
    ]
],
"f_r"=> [
    "name"=>_m("RSS tag"),
    "desc"=>_m("serves for internal purposes of the predefined RSS aliases (e.g. _#RSS_TITL). Adds the RSS 0.91 compliant tags.")
],
"f_s"=> [
    "name"=>_m("default"),
    "desc"=>_m("prints <i>the field</i> or a default value if <i>the field</i> is empty. The same could be done by the f_c function with parameters :::<b>default</b>."),
    "params"=> [
        [
            "name"=>_m("default"),
        "desc"=>_m("default value"),
        "type"=>"STR",
        "example"=>_m("javascript: window.alert('No source url specified')")
        ]
    ]
],
"f_t"=> [
    "name"=>_m("print field"),
    "desc"=>_m("prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too (in its basic variant)"),
    "params"=> [
        [
            "name"=>_m("unalias string"),
        "desc"=>_m("if the <i>unalias string</i> is defined, then the function ignores <i>the field</i> and it rather prints the <i>unalias string</i>. You can of course use any aliases (or fields like {headline.........}) in this string"),
        "type"=>"STR",
        "example"=>_m("<img src={img_src.........1} _#IMG_WDTH _#IMG_HGHT>")
        ],
        [
            "name"=>_m("output modify"),
        "desc"=>_m("You can use some output modifications:<br>
                   &nbsp; - [<i>empty</i>] - no modification<br>
                   &nbsp; - <i>csv</i>  - prints the field for CSV file (Comma Separated Values) export<br>
                   &nbsp; - <i>urlencode</i> - URL-encodes string (see <a href=\"http://php.net/urlencode\">urlencode<a> PHP function)<br>
                   &nbsp; - <i>safe</i> - converts special characters to HTML entities (see <a href=\"http://php.net/htmlspecialchars\">htmlspecialchars<a> PHP function)<br>
                   &nbsp; - <i>javascript</i> - escape ' (replace ' with \\')<br>
                   &nbsp; - <i>striptags</i>  - strip HTML and PHP tags from the string<br>
                   &nbsp; - <i>asis</i>  - prints field content 'as is' - it do not add &lt;br&gt; at line ends even if field is marked as 'Plain text'. 'asis' parameter is good for Item Manager's 'Modify content...' feature, for example<br>
                   "),
        "type"=>"STR",
        "example"=>_m("")
        ]
    ]
],
"f_u"=> [
    "name"=>_m("user function"),
    "desc"=>_m("calls a user defined function (see How to create new aliases in <a href='http://apc-aa.sourceforge.net/faq/#aliases'>FAQ</a>)"),
    "params"=> [
        [
            "name"=>_m("function"),
            "desc"=>_m("name of the function in the include/usr_aliasfnc.php3 file"),
            "type"=>"STR",
            "example"=>_m("usr_start_end_date_cz")
        ],
        [
            "name"=>_m("parameter"),
            "desc"=>_m("a parameter passed to the function"),
            "type"=>"STR",
            "example"=>"1"
        ]
    ]
],
"f_v"=> [
    "name"=>_m("view"),
    "desc"=>_m("allows to manipulate the views. This is a complicated and powerful function, described in <a href=\"../doc/FAQ.html#viewparam\" target=_blank>FAQ</a>, which allows to display any view in place of the alias. It can be used for 'related stories' table or for dislaying content of related slice."),
    "params"=> [
        [
            "name"=>_m("complex parameter"),
            "desc"=>_m("this parameter is the same as we use in view.php3 url parameter - see the FAQ"),
            "type"=>"STR",
            "example"=>_m("vid=4&amp;cmd[23]=v-25")
        ]
    ]
],
"f_w"=> [
    "name"=>_m("image width"),
    "desc"=>_m("An old-style function. Prints <i>the field</i> as image width value (\<img width=...\>) or erases the width tag. To be used immediately after \"width=\".The f_c function provides a better way of doing this with parameters \":width=\". ")
],
"f_x"=> [
    "name"=>_m("transformation"),
    "desc"=>_m("Allows to transform the field value to another value.<br>Usage: <b>content_1</b>:<b>return_value_1</b>:<b>content_1</b>:<b>return_value_1</b>:<b>default</b><br>If the content <i>the field</i> is equal to <b>content_1</b> the <b>return_value_1</b> is returned. If the content <i>the field</i> is equal to <b>content_2</b> the <b>return_value_2</b> is returned. If <i>the field is not equal to any <b>content_x</b>, <b>default</b> is returned</i>."),
    "params"=> [
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"
        ],
        [
            "name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")
        ],
        [
            "name"=>_m("default"),
        "desc"=>_m("if no content matches, use this string as return value"),
        "type"=>"STR",
        "example"=>_m('Environment')
        ]
    ]
],
"f_y"=> [
    "name"=>_m("expanded string"),
    "desc"=>_m("expands the string in the parameter"),
    "params"=> [
        [
            "name"=>_m("string to expand"),
            "desc"=>_m("if specified then this string is expanded, if not specified then expands the contents of the field"),
            "type"=>"STR",
            "example"=>"{switch({href_url.........}).+:{href_url.........}}"
        ]
    ]
]
]
];


// --------------------------------------------------------------------------------

$TRANS_ACTIONS["name"] = _m("Transformation action");
$TRANS_ACTIONS["items"]["store"] = [
    "name"=>_m("Store"),
    "desc"=>_m("Simply store a value from the input field"),
    "params"=> [
        [
            "name"=>_m("lang (optional)"),
            "desc"=>_m("two letters - cz | en | es | de. If provided the value wil be stored to specified language (the field must use translations)"),
            "example"=>"es",
            "type"=>"STR"
        ]
    ]
];

$TRANS_ACTIONS["items"]["removestring"] = [
    "name"=>_m("Remove string"),
    "desc"=>_m("Remove all occurences of a string from the input field.") ,
    "params"=> [
        [
            "name"=>_m("string parameter"),
        "desc"=>_m("Removed string"),
        "type"=>"STR"
        ]
    ]
];
$TRANS_ACTIONS["items"]["formatdate"] = [
    "name"=>_m("Format date"),
    "desc"=>_m("Parse the date in the input field expected to be in English date format. In case of error, the transformation fails")
];

$TRANS_ACTIONS["items"]["web"] = [
    "name"=>_m("Add http prefix"),
    "desc"=>_m("Adds 'http://' prefix to the field if not beginning with 'http://' and not empty.")
];

$TRANS_ACTIONS["items"]['value'] = [
    "name"=>_m("Store parameter"),
    "desc"=>_m("Store parameter instead of the input field") ,
    "params"=> [
        [
            "name"=>_m("string parameter"),
              "type"=>"STR"
        ]
    ]
];

$TRANS_ACTIONS["items"]["string2id"] = [
    "name"=>_m("Store as long id"),
    "desc"=>_m("Creates long id from the string. The string is combined with the parameter!! or with slice_id (if the parameter is not provided. From the same string (and the same parameter) we create always the same id."),
    "params"=> [
        [
            "name"=>_m("string to add"),
              "desc"=>_m("this parameter will be added to the string before conversion (the reason is to aviod empty strings and also in order we do not generate always the same id for common strings (in different imports). If this param is not specified, slice_id is used istead."),
              "example"=>"This string is up to you (any length)",
              "type"=>"STR"
        ]
    ]
];

$TRANS_ACTIONS["items"]["storeparsemulti"] = [
    "name"=>_m("Split input field by string"),
    "desc"=>_m("Split input field by string parameter and store the result as multi-value.") ,
    "params"=> [
        [
            "name"=>_m("delimiter (+ possible languages)"),
            "desc"=>_m("string delimeter which separates the values of the input field (like \"|\"). Another option is to add also languages separated by \"##\" in the form <delimiter>##<lang1>##<lang2> (\"|##cz##en\" for \"Ahoj|Hi\") - this will fill translated fields"),
            "type"=>"STR",
            "example"=>"|"
        ],
    ]
];
$TRANS_ACTIONS["items"]["default"] = [
    "name"=>_m("Store default value"),
    "desc"=>_m("Store these default values for the following output fields. The other output fields will filled form <i>From</i> field (if specified). Else it is filled by <i>Action parameters</i> string.
    <table>
        <tr><td><b>Output field</b></td><td><b>Value</b></td><td><b>Description</b></td></tr></b>
    <tr><td>Status code</td><td>1</td><td>The item will be stored in Active bin (Hint: set it to 2 for Holding bin)</td></tr>
    <tr><td>Display count</td><td>0</td><td></td></tr>
        <tr><td>Publish date</td><td>Current date</td><td></td></tr>
    <tr><td>Post date</td><td>Current date</td><td></td></tr>
    <tr><td>Last edit</td><td>Current date</td><td></td></tr>
    <tr><td>Expiry date</td><td>Current date + 10 years</td><td></td></tr>
    <tr><td>Posted by</td><td>Active user</td><td></td></tr>
    <tr><td>Edited by</td><td>Active user</td><td></td></tr>
      </table>
    ")
];

