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
 * @author    Honza Malik <honza.malik@ecn.cz>, Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
/* The arrays INPUT_TYPES and FIELD_FUNCTIONS and all constants used in them
    were generated from another array structure by a program - see param_wizard_generate.php3
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

// require_once AA_BASE_PATH."modules/alerts/reader_field_ids.php3";

$INSERT_TYPES["name"] = _m("Insert Function");
$INSERT_TYPES["items"]["qte"] =
array ("name" => _m("Text = don't modify"),
       "desc" => _m("Does not modify the value."));
$INSERT_TYPES["items"]["dte"] =
array ("name" => _m("Date = don't modify"),
       "desc" => _m("Does not modify the value (just like Text), but it is better to separate it for future ussage."));
$INSERT_TYPES["items"]["num"] =
array ("name" => _m("Number = don't modify"),
       "desc" => _m("Does not modify the value (just like Text), but it is better to separate it for future ussage."));
$INSERT_TYPES["items"]["boo"] =
array ("name" => _m("Boolean = store 0 or 1"));
$INSERT_TYPES["items"]["fil"] =
array ("name" => _m("File = uploaded file"),
       "desc" => _m("Stores the uploaded file and a link to it, parameters only apply if type is image/something."),
       "params"=>array(
        array("name"=>_m("Mime types accepted"),
              "desc"=>_m("Only files of matching mime types will be accepted"),
              "type"=>"STR",
              "example"=>"image/*"),
        array("name"=>_m("Maximum image width"),
              "type"=>"INT",
              "example"=>400),
        array("name"=>_m("Maximum image height"),
              "desc"=>_m("The image will be resampled to be within these limits, while retaining aspect ratio."),
              "type"=>"INT",
              "example"=>650),
        array("name"=>_m("Other fields"),
              "desc"=>_m("List of other fields to receive this image, separated by ##"),
              "type"=>"STRID",
              "example"=>"image..........2"),
        array("name"=>_m("Upload policy"),
              "desc"=>_m("new | overwrite | backup<br>This parameter controls what to do if uploaded file alredy exists:
                       <br>new - AA creates new filename (by adding _x postfix) and store it with this new name (default)
                       <br>overwrite - the old file of the same name is overwritten
                       <br>backup - the old file is copied to new (non-existing) file and current file is stored with current name.
                       <br>In all cases the filename is escaped, so any non-word characters will be replaced by an underscore."),
              "type"=>"STRID",
              "example"=>"new"),
        array("name"=>_m("Exact dimensions"),
              "desc"=>_m("If set to 1 the image will be downsized exactly to the specified dimensions (and croped if needed).
                       Default is 0 or empty: Maintain aspect ratio while resizing the image."),
              "type"=>"INT",
              "example"=>"1")
       ));
$INSERT_TYPES["items"]["uid"] =
array ("name" => _m("User ID = always store current user ID"),
       "desc" => "Inserts the identity of the current user, no matter what the user sets.");
$INSERT_TYPES["items"]["log"] =
array ("name" => _m("Login name"));
$INSERT_TYPES["items"]["ids"] =
array ("name" => _m("Item IDs"));
$INSERT_TYPES["items"]["now"] =
array ("name" => _m("Now = always store current time"),
       "desc" => _m("Inserts the current time, no matter what the user sets."));
$INSERT_TYPES["items"]["pwd"] =
array ("name" => _m("Password and Change Password"),
       "desc" => _m("Stores value from a 'Password and Change Password' field type. First prooves the new password matches the retyped new password, and if so, encrypts the new password and stores it."),
       "params"=>array(
                        array("name"=>_m("Copy of password"),
                              "desc"=>_m("field_id, where you want to store the copy of the password. Some times usefull if you want to allow people to get hir/her old password. It is strongly recommended to not store it in plaintext and encrypt it. See second parameter."),
                              "type"=>"STRID",
                              "example"=>"password.......1"
                             ),
                        array("name"=>_m("Encrypt phrase"),
                              "desc"=>_m("If you specify the 'Copy of password' field, it is recommended to ecrypt the password in the database. Fill there any string. If you then need the password, you cen decrypt it from the field by using the same phrase - {decrypt:{password.......1}:Some Phrase}"),
                              "type"=>"STR",
                              "example"=>"Some Phrase"
                             )
                       )
       );
$INSERT_TYPES["items"]["com"] =
array ("name" => _m("Computed field"),
"desc" => _m("The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here"),
       "params"=>array(
                        array("name"=>_m("Code for unaliasing (INSERT+UPDATE)"),
                              "desc"=>_m("There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"),
                              "type"=>"STR",
                              "example"=>"({publish_date....}) {headline........}"
                             )
                       )
       );
$INSERT_TYPES["items"]["co2"] =
array ("name" => _m("Computed field for INSERT/UPDATE"),
"desc" => _m("The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here"),
       "params"=>array(
                        array("name"=>_m("Code for unaliasing (INSERT)"),
                              "desc"=>_m("There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"),
                              "type"=>"STR",
                              "example"=>"({publish_date....}) {headline........}"
                             ),
                        array("name"=>_m("Code for unaliasing (UPDATE)"),
                            "desc"=>_m("The same as above, but just for UPDATE operation. If unfilled, the value of the field stays unchanged"),
                              "type"=>"STR",
                              "example"=>""
                             ),
                        array("name"=>_m("Multivalue delimiter"),
                            "desc"=>_m("Character or string, which will split the computed string into multiple values (the same field)"),
                              "type"=>"STR",
                              "example"=>"|"
                             ),
                        array("name"=>_m("Recompute every"),
                            "desc"=> 'minute|hour|day|week|month '._m("The field will be recomputed every minute, hour, ... (using UPDATE code above). The times are not exact - the minute means something 1-10 minutes. If not filled, the field will be recomputed only on insert/update of the item."),
                              "type"=>"STR",
                              "example"=>""
                             )
                       )
       );

// --------------------------------------------------------------------------------

$DEFAULT_VALUE_TYPES["name"] = _m("Default Value Type");
$DEFAULT_VALUE_TYPES["items"]["txt"] =
array ("name" => _m("Text from 'Parameter'"),
       "params" => array (array ("name" => _m("Text"))));
$DEFAULT_VALUE_TYPES["items"]["dte"] =
array ("name" => _m("Date + 'Parameter' days"),
       "params" => array (array ("name" => _m("Number of days"))));
$DEFAULT_VALUE_TYPES["items"]["uid"] =
array ("name" => _m("User ID"));
$DEFAULT_VALUE_TYPES["items"]["log"] =
array ("name" => _m("Login name"));
$DEFAULT_VALUE_TYPES["items"]["now"] =
array ("name" => _m("Now, i.e. current date"));
$DEFAULT_VALUE_TYPES["items"]["variable"] =
array ("name" => _m("Variable"),
       "desc" => _m("A dangerous function. Do not use."));
$DEFAULT_VALUE_TYPES["items"]["rnd"] =
array ("name" => _m("Random string"),
       "desc" => _m("Random alphanumeric [A-Z0-9] string."),
       "params"=>array(
        array("name"=>_m("String length"),
              "type"=>"INT",
              "example"=>4),
        array("name"=>_m("Field to check"),
              "desc"=>_m("If you need a unique code, you must send the field ID,
                  the function will then look into this field to ensure uniqueness."),
              "type"=>"STRID",
              "example"=>"undefined......."),
        array("name"=>_m("Slice only"),
              "desc"=>_m("Do you want to check for uniqueness this slice only
                  or all slices?"),
              "type"=>"BOOL",
              "example"=>1)
       ));

// --------------------------------------------------------------------------------

$VALIDATE_TYPES["name"] = _m("Input Validate Type");
$VALIDATE_TYPES["items"]["text"] = array (
    "name"=>_m("No validation"));
$VALIDATE_TYPES["items"]["url"] = array (
    "name"=>_m("URL"));
$VALIDATE_TYPES["items"]["e-mail"] = array (
    "name"=>_m("E-mail"));
$VALIDATE_TYPES["items"]["number"] = array (
    "name"=>_m("Number = integer number"),
    "params"=>array (
        array("name"=>_m("Min"),
              "desc"=>_m("Alloved minimum value"),
              "type"=>"INT",
              "example"=>1),
        array("name"=>_m("Max"),
              "desc"=>_m("Alloved maximum value"),
              "type"=>"INT",
              "example"=>12),
        array("name"=>_m("Step"),
              "type"=>"INT",
              "example"=>1)
     )
    );
$VALIDATE_TYPES["items"]["id"] = array (
    "name"=>_m("Id = 1-32 hexadecimal digits [0-9a-f]"));
$VALIDATE_TYPES["items"]["date"] = array (
    "name"=>_m("Date = store as date"));
$VALIDATE_TYPES["items"]["bool"] = array (
    "name"=>_m("Bool = store as bool"));
$VALIDATE_TYPES["items"]["user"] = array (
    "name"=>_m("User = does nothing ???"));
$VALIDATE_TYPES["items"]["unique"] = array (
    "name"=>_m("Unique = proove uniqueness"),
    "desc"=>_m("Validates only if the value is not yet used. Useful e.g.
        for emails or user names."),
    "params"=>array (
        array("name"=>_m("Field ID"),
              "desc"=>_m("Field in which to look for matching values."),
              "type"=>"STRID",
              "example"=>"undefined......."),
        array("name"=>_m("Scope"),
              "desc"=>_m("<b>1</b> = This slice only.
                <b>2</b> = All slices.<br>
                <b>0</b> = Username, special: Checks uniqueness in reader management
                slices and in the permission system. Always uses field ID %1",
                array(FIELDID_USERNAME)),
              "type"=>"INT",
              "example"=>1)
     ));
$VALIDATE_TYPES["items"]["regexp"] = array (
    "name"=>_m("Regular Expression"),
    "desc"=>_m("Validation based on Regular Expressions"),
    "params"=>array (
        array("name"=>_m("Regular Expressions"),
              "desc"=>_m('use something like: /^[0-9]*$/ - see "Regular Expressions" manual.'),
              "type"=>"STR",
              "example"=>'/^[a-zA-Z ]{0,10}$/'),
        array("name"=>_m("Error text"),
              "desc"=>_m("error message"),
              "type"=>"STR",
              "example"=>_m("Wrong value"))
     ));
$VALIDATE_TYPES["items"]["e-unique"] = array (
    "name"=>_m("Unique e-mail"),
    "desc"=>_m("Combines the e-mail and unique validations. Validates only if the value is a valid email address and not yet used."),
    "params"=>array (
        array("name"=>_m("Field ID"),
              "desc"=>_m("Field in which to look for matching values."),
              "type"=>"STRID",
              "example"=>"undefined......."),
        array("name"=>_m("Slice only"),
              "desc"=>_m("Do you want to check for uniqueness this slice only
                  or all slices?"),
              "type"=>"BOOL",
              "example"=>1)
     ));
$VALIDATE_TYPES["items"]["pwd"] = array (
    "name"=>_m("Password and Change Password"),
    "desc"=>_m("Validates the passwords do not differ when changing password.
        <i>The validation is provided only by JavaScript and not by ValidateInput()
        because the insert
        function does the validation again before inserting the new password.</i>"));

// --------------------------------------------------------------------------------

/** It is important the input types are 3 letter acronyms, because
*   this is used e.g. in admin/se_constant.php3, function propagateChanges(). */

$INPUT_TYPES["name"] = _m("Input Type");
$INPUT_TYPES["items"]["hco"] =
array("name"=>_m("Hierarchical constants"),
    "desc"=>_m("A view with level boxes allows to choose constants."),
    "params"=>array(
        array("name"=>_m("Level count"),
        "desc"=>_m("Count of level boxes"),
        "type"=>"INT",
        "example"=>"3"),
        array("name"=>_m("Box width"),
        "desc"=>_m("Width in characters"),
        "type"=>"INT",
        "example"=>"60"),
        array("name"=>_m("Size of target"),
        "desc"=>_m("Lines in the target select box"),
        "type"=>"INT",
        "example"=>"5"),
        array("name"=>_m("Horizontal"),
        "desc"=>_m("Show levels horizontally"),
        "type"=>"BOOL",
        "example"=>"1"),
        array("name"=>_m("First selectable"),
        "desc"=>_m("First level which will have a Select button"),
        "type"=>"INT",
        "example"=>"0"),
        array("name"=>_m("Level names"),
        "desc"=>_m("Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."),
        "type"=>"TEXT",
        "example"=>_m("Top level~Second level~Keyword"))
 //       array("name"=>_m("slice field"),
 //       "desc"=>_m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
 //       "type"=>"STRID",
 //       "example"=>_m("category........")),
 //       array("name"=>_m("Filtering conditions"),
 //       "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
 //       "type"=>"STR",
 //       "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
 //       array("name"=>_m("Sort by"),
 //       "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
 //       "type"=>"STR",
 //       "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
 //       array("name"=>_m("Slice password"),
 //       "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
 //       "type"=>"STR",
 //       "example"=>""),
 //       array("name"=>_m("Relation field"),
 //       "desc"=>_m("(for slices only) Field id of the field which defines the relations in the slice. relation........ is default"),
 //       "type"=>"STR",
 //       "example"=>"relation........")
        ));

$INPUT_TYPES["items"]["txt"]=
array("name"=>_m("Text Area"),
    "desc"=>_m("Text area with 60 columns"),
    "params"=>array(
        array("name"=>_m("row count"),
        "desc"=>"",
        "type"=>"INT",
        "example"=>"20")));
$INPUT_TYPES["items"]["edt"]=
array("name"=>_m("Rich Edit Area"),
    "desc"=>_m("Rich edit text area. This operates the same way as Text Area in browsers which don't support the Microsoft TriEdit library. In IE 5.0 and higher and in Netscape 4.76 and higher (after installing the necessary features) it uses the TriEdit to provide an incredibly powerful HTML editor.<br><br>\nAnother possibility is to use the <b>iframe</b> version which should work in IE on Windows and Mac (set the 3rd parameter to \"iframe\").<br><br>\nThe code for this editor is taken from the Wysiwyg open project (http://www.unica.edu/uicfreesoft/) and changed to fullfill our needs. See http://www.unica.edu/uicfreesoft/wysiwyg_web_edit/Readme_english.txt on details how to prepare Netscape.<br><br>\nThe javascript code needed to provide the editor is saved in two HTML files, so that the user doesn't have to load it every time she reloads the Itemedit web page."),
    "params"=>array(
        array("name"=>_m("row count"),
        "desc"=>"",
        "type"=>"INT",
        "example"=>"10"),
        array("name"=>_m("column count"),
        "desc"=>"",
        "type"=>"INT",
        "example"=>"70"),
        array("name"=>_m("type"),
        "desc"=>_m("type: class (default) / iframe"),
        "type"=>"TEXT",
        "example"=>_m("class"))));
$INPUT_TYPES["items"]["fld"]=
array("name"=>_m("Text Field"),
    "desc"=>_m("A text field."),
    "params"=>array(
        array("name"=>_m("max characters"),
        "desc"=>_m("max count of characters entered (maxlength parameter)"),
        "type"=>"INT",
        "example"=>"254"),
        array("name"=>_m("width"),
        "desc"=>_m("width of the field in characters (size parameter)"),
        "type"=>"INT",
        "example"=>"30")));

$INPUT_TYPES["items"]["mfl"]=
array("name"=>_m("Multiple Text Field"),
    "desc"=>_m("Text field input type which allows you to enter more than one (multiple) values into one field (just like if you select multiple values from Multiple Selectbox). The new values are filled by popup box."),
    "params"=>array(
        array("name"=>_m("Show Actions"),
        "desc"=>_m("Which action buttons to show:
                       <br>M - Move (up and down)
                       <br>D - Delete value,
                       <br>A - Add new value
                       <br>C - Change the value
                       <br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."),
        "type"=>"STR",
        "example"=>"MDAC"),
        array("name"=>_m("Row count"),
        "desc"=>_m("Number of rows (values) displayed at once"),
        "type"=>"INT",
        "example"=>"6")
        ));

$INPUT_TYPES["items"]["sel"]=
array("name"=>_m("Select Box"),
    "desc"=>_m("A selectbox field with a values list.<br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice, usually with the f_v alias function)"),
    "params"=>array(
        array("name"=>_m("slice field"),
        "desc"=>_m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
        "type"=>"STRID",
        "example"=>_m("category........")),
        array("name"=>_m("use name"),
        "desc"=>_m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"),
        "type"=>"BOOL",
        "example"=>"0"),
        array("name"=>_m("Show items from bins"),
        "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
                   "Active bin - '%1'<br>".
                   "Pending bin - '%2'<br>".
                   "Expired bin - '%3'<br>".
                   "Holding bin - '%4'<br>".
                   "Trash bin - '%5'<br>".
                   "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
                   "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
        "type" => "INT",
        "example" => "3"),
        array("name"=>_m("Filtering conditions"),
        "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
        "type"=>"STR",
        "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
        array("name"=>_m("Sort by"),
        "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
        "type"=>"STR",
        "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
        array("name"=>_m("Slice password"),
        "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
        "type"=>"STR",
        "example"=>"")
        ));
$INPUT_TYPES["items"]["pre"]=
array("name"=>_m("Text Field with Presets"),
    "desc"=>_m("Text field with values names list. When you choose a name from the list, the appropriate value is printed in the text field"),
    "params"=>array(
        array("name"=>_m("max characters"),
        "desc"=>_m("max count of characteres entered in the text field (maxlength parameter)"),
        "type"=>"INT",
        "example"=>"254"),
        array("name"=>_m("width"),
        "desc"=>_m("width of the text field in characters (size parameter)"),
        "type"=>"INT",
        "example"=>"20"),
        array("name"=>_m("slice field"),
        "desc"=>_m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
        "type"=>"STRID",
        "example"=>_m("category........")),
        array("name"=>_m("use name"),
        "desc"=>_m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"),
        "type"=>"BOOL",
        "example"=>"0"),
        array("name"=>_m("adding"),
        "desc"=>_m("adding the selected items to input field comma separated"),
        "type"=>"BOOL",
        "example"=>"0"),
        array("name"=>_m("secondfield"),
        "desc"=>_m("field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"),
        "type"=>"STRID",
        "example"=>_m("source_href.....")),
        array("name"=>_m("add2constant"),
        "desc"=>_m("if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"),
        "type"=>"BOOL",
        "example"=>"0"),
        array("name"=>_m("Show items from bins"),
        "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
                   "Active bin - '%1'<br>".
                   "Pending bin - '%2'<br>".
                   "Expired bin - '%3'<br>".
                   "Holding bin - '%4'<br>".
                   "Trash bin - '%5'<br>".
                   "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
                   "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
        "type" => "INT",
        "example" => "3"),
        array("name"=>_m("Filtering conditions"),
        "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
        "type"=>"STR",
        "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
        array("name"=>_m("Sort by"),
        "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
        "type"=>"STR",
        "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d")
        ));
$INPUT_TYPES["items"]["tpr"]=
array("name"=>_m("Text Area with Presets"),
    "desc"=>_m("Text area with values names list. When you choose a name from the list, the appropriate value is printed in the text area"),
    "params"=>array(
        array("name"=>_m("rows"),
        "desc"=>_m("Textarea rows"),
        "type"=>"INT",
        "example"=>"4"),
        array("name"=>_m("cols"),
        "desc"=>_m("Text area columns"),
        "type"=>"INT",
        "example"=>"60")));
$INPUT_TYPES["items"]["rio"]=
array("name"=>_m("Radio Button"),
    "desc"=>_m("Radio button group - the user may choose one value of the list. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"),
    "params"=>array (
        array("name"=>_m("Columns"),
        "desc"=>_m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."),
        "type"=>"INT",
        "example"=>"3"),
        array("name"=>_m("Move right"),
        "desc"=>_m("Should the function move right or down to the next value?"),
        "type"=>"BOOL",
        "example"=>"1"),
        array("name"=>_m("Slice field"),
        "desc"=>_m("Field (or format string) that will be displayed as radiobuton's option (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
        "type"=>"STRID",
        "example"=>_m("category........")),
        array("name"=>_m("Show items from bins"),
        "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
                   "Active bin - '%1'<br>".
                   "Pending bin - '%2'<br>".
                   "Expired bin - '%3'<br>".
                   "Holding bin - '%4'<br>".
                   "Trash bin - '%5'<br>".
                   "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
                   "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
        "type" => "INT",
        "example" => "3"),
        array("name"=>_m("Filtering conditions"),
        "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
        "type"=>"STR",
        "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
        array("name"=>_m("Sort by"),
        "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
        "type"=>"STR",
        "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
        array("name"=>_m("Slice password"),
        "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
        "type"=>"STR",
        "example"=>"")
        ));
$INPUT_TYPES["items"]["dte"]=
array("name"=>_m("Date"),
    "desc"=>_m("you can choose an interval from which the year will be offered"),
    "params"=>array(
        array("name"=>_m("Starting Year"),
        "desc"=>_m("The (relative) start of the year interval"),
        "type"=>"INT",
        "example"=>"1"),
        array("name"=>_m("Ending Year"),
        "desc"=>_m("The (relative) end of the year interval"),
        "type"=>"INT",
        "example"=>"10"),
        array("name"=>_m("Relative"),
        "desc"=>_m("If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."),
        "type"=>"BOOL",
        "example"=>"1"),
        array("name"=>_m("Show time"),
        "desc"=>_m("show the time box? (1 means Yes, undefined means No)"),
        "type"=>"BOOL",
        "example"=>"1")));
$INPUT_TYPES["items"]["chb"]=
array("name"=>_m("Checkbox"),
    "desc"=>_m("The field value will be represented by a checkbox."));
$INPUT_TYPES["items"]["mch"]=
array("name"=>_m("Multiple Checkboxes"),
    "desc"=>_m("Multiple choice checkbox group. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"),
    "params"=>array (
    array("name"=>_m("Columns"),
    "desc"=>_m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."),
    "type"=>"INT",
    "example"=>"3"),
    array("name"=>_m("Move right"),
    "desc"=>_m("Should the function move right or down to the next value?"),
    "type"=>"BOOL",
    "example"=>"1"),
    array("name"=>_m("Slice field"),
    "desc"=>_m("Field (or format string) that will be displayed as checbox options (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
    "type"=>"STRID",
    "example"=>_m("category........")),
    array("name"=>_m("Show items from bins"),
    "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
               "Active bin - '%1'<br>".
               "Pending bin - '%2'<br>".
               "Expired bin - '%3'<br>".
               "Holding bin - '%4'<br>".
               "Trash bin - '%5'<br>".
               "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
               "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
    "type" => "INT",
    "example" => "3"),
    array("name"=>_m("Filtering conditions"),
    "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
    "type"=>"STR",
    "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
    array("name"=>_m("Sort by"),
    "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
    "type"=>"STR",
    "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
    array("name"=>_m("Slice password"),
    "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
    "type"=>"STR",
    "example"=>"")
    ));

$INPUT_TYPES["items"]["mse"]=
array("name"=>_m("Multiple Selectbox"),
    "desc"=>_m("Multiple choice select box. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"),
    "params"=>array(
    array("name"=>_m("Row count"),
    "desc"=>"",
    "type"=>"INT",
    "example"=>"5"),
    array("name"=>_m("Slice field"),
    "desc"=>_m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
    "type"=>"STRID",
    "example"=>_m("category........")),
    array("name"=>_m("Show items from bins"),
    "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
               "Active bin - '%1'<br>".
               "Pending bin - '%2'<br>".
               "Expired bin - '%3'<br>".
               "Holding bin - '%4'<br>".
               "Trash bin - '%5'<br>".
               "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
               "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
    "type" => "INT",
    "example" => "3"),
    array("name"=>_m("Filtering conditions"),
    "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
    "type"=>"STR",
    "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
    array("name"=>_m("Sort by"),
    "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
    "type"=>"STR",
    "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
    array("name"=>_m("Slice password"),
    "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
    "type"=>"STR",
    "example"=>"")
    ));

$INPUT_TYPES["items"]["fil"]=
array("name"=>_m("File"),
    "desc"=>_m("File upload - a text field with the file find button"),
    "params"=>array(
        array("name"=>_m("Allowed file types"),
        "desc"=>"",
        "type"=>"STR",
        "example"=>_m("image/*")),
        array("name"=>_m("Label"),
        "desc"=>_m("To be printed before the file upload field"),
        "type"=>"STR",
        "example"=>_m("File: ")),
        array("name"=>_m("Hint"),
        "desc"=>_m("appears beneath the file upload field"),
        "type"=>"STR",
        "example"=>_m("You can select a file ...")),
        array("name"=>_m("Display URL"),
        "desc"=>_m("Show URL field? 0 - show, 1 - show if not empty, 2 - do not show"),
        "type"=>"INT",
        "example"=> '0')
        ));
$INPUT_TYPES["items"]["iso"]=
array("name"=>_m("Related Item Window"),
    "desc"=>_m("List of items connected with the active one - by using the buttons Add and Delete you show a window, where you can search in the items list"),
    "params"=>array(
        array("name"=>_m("Row count"),
        "desc"=>_m("Row count in the list"),
        "type"=>"INT",
        "example"=>"15"),
        array("name"=>_m("Buttons to show"),
        "desc"=>_m("Defines, which buttons to show in item selection:
                       <br>A - Add
                       <br>M - add Mutual
                       <br>B - Backward
                       <br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."),
        "type"=>"STR",
        "example"=>_m("AMB")),
        array("name"=>_m("Admin design"),
        "desc"=>_m("If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."),
        "type"=>"BOOL",
        "example"=>"0"),
        array("name"=>_m("Tag Prefix"),
        "desc"=>_m("Selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."),
        "type"=>"STR",
        "example"=>_m("AMB")),
        array("name"=>_m("Show Actions"),
        "desc"=>_m("Which action buttons to show:
                       <br>M - Move (up and down)
                       <br>D - Delete relation,
                       <br>R - add Relation to existing item
                       <br>N - insert new item in related slice and make it related
                       <br>E - Edit related item
                       <br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."),
        "type"=>"STR",
        "example"=>"MDR"),
        array("name"=>_m("Show headlines from selected bins"),
        "desc"=>_m("To show headlines in related window from selected bins.<br>".
                   "Use this values for bins:<br>".
                   "Active bin - '%1'<br>".
                   "Pending bin - '%2'<br>".
                   "Expired bin - '%3'<br>".
                   "Holding bin - '%4'<br>".
                   "Trash bin - '%5'<br>".
                   "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
                   "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
        "type" => "INT",
        "example" => "3"),
        array("name"=>_m("Filtering conditions - unchangeable"),
        "desc"=>_m("Conditions for filtering items in related items window. This conds user can't change."),
        "type"=>"STR",
        "example"=>""),
        array("name"=>_m("Filtering conditions - changeable"),
        "desc"=>_m("Conditions for filtering items in related items window. This conds user can change."),
        "type"=>"STR",
        "example"=>""),
        array("name"=>_m("Slice field"),
        "desc"=>_m("field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - {publish_date....})."),
        "type"=>"STRID",
        "example"=>_m("publish_date...."))
        ));

$INPUT_TYPES["items"]["wi2"]=
array("name"=>_m("Two Windows"),
  "desc"=>_m("Two Windows. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"),
  "params"=>array(
    array("name"=>_m("Row count"),
    "desc"=>"",
    "type"=>"INT",
    "example"=>"5"),
    array("name"=>_m("Title of \"Offer\" selectbox"),
    "desc"=>"",
    "type"=>"STR",
    "example"=>_m("Our offer")),
    array("name"=>_m("Title of \"Selected\" selectbox"),
    "desc"=>"",
    "type"=>"STR",
    "example"=>_m("Selected")),
    array("name"=>_m("Slice field"),
    "desc"=>_m("field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
    "type"=>"STRID",
    "example"=>_m("category........")),
    array("name"=>_m("Show items from bins"),
    "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
               "Active bin - '%1'<br>".
               "Pending bin - '%2'<br>".
               "Expired bin - '%3'<br>".
               "Holding bin - '%4'<br>".
               "Trash bin - '%5'<br>".
               "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
               "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
    "type" => "INT",
    "example" => "3"),
    array("name"=>_m("Filtering conditions"),
    "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
    "type"=>"STR",
    "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
    array("name"=>_m("Sort by"),
    "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
    "type"=>"STR",
    "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
    array("name"=>_m("Add Form"),
    "desc"=>_m("(for slices only) ID of the form for adding items into related slice"),
    "type"=>"STR",
    "example"=>"8c3da97ab7a505a923f123121ad4945a"),
    array("name"=>_m("Slice password"),
    "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
    "type"=>"STR",
    "example"=>"")
    ));

$INPUT_TYPES["items"]["tag"]=
array("name"=>_m("Tags"),
  "desc"=>_m("Two Windows. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"),
  "params"=>array(
    array("name"=>_m("Slice field"),
    "desc"=>_m("field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"),
    "type"=>"STRID",
    "example"=>_m("category........")),
    array("name"=>_m("Show items from bins"),
    "desc"=>_m("(for slices only) To show items from selected bins, use following values:<br>".
               "Active bin - '%1'<br>".
               "Pending bin - '%2'<br>".
               "Expired bin - '%3'<br>".
               "Holding bin - '%4'<br>".
               "Trash bin - '%5'<br>".
               "Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value ".
               "for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13", array( AA_BIN_ACTIVE, AA_BIN_PENDING, AA_BIN_EXPIRED, AA_BIN_HOLDING, AA_BIN_TRASH)),
    "type" => "INT",
    "example" => "3"),
    array("name"=>_m("Filtering conditions"),
    "desc"=>_m("(for slices only) Conditions for filtering items in selection. Use conds[] array."),
    "type"=>"STR",
    "example"=>"conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
    array("name"=>_m("Sort by"),
    "desc"=>_m("(for slices only) Sort the items in specified order. Use sort[] array"),
    "type"=>"STR",
    "example"=>"sort[0][headline........]=a&sort[1][publish_date....]=d"),
    array("name"=>_m("Add Form"),
    "desc"=>_m("(for slices only) ID of the form for adding items into related slice"),
    "type"=>"STR",
    "example"=>"8c3da97ab7a505a923f123121ad4945a"),
    array("name"=>_m("Slice password"),
    "desc"=>_m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"),
    "type"=>"STR",
    "example"=>"")
    ));

$INPUT_TYPES["items"]["hid"]=
    array("name"=>_m("Hidden field"),
    "desc"=>_m("The field value will be shown as &lt;input type='hidden'. You will probably set this filed by javascript trigger used on any other field."));

$INPUT_TYPES["items"]["inf"]=
    array("name"=>_m("Info text - output"),
    "desc"=>_m("Just output field - prints the content. Used for computed fields, for example."));

$INPUT_TYPES["items"]["pwd"]=
    array("name"=>_m("Password and Change Password"),
          "desc"=>_m("Password input boxes allowing to send password (for password-protected items)
        and to change password (including the \"Retype password\" box).<br><br>
        When a user fills new password, it is checked against the retyped password,
        MD5-encrypted so that nobody may learn it and stored in the database.<br><br>
        If the field is not Required, shows a 'Delete Password' checkbox."),
          "params"=>array(
    array("name"=>_m("Field size"),
          "desc"=>_m("Size of the three fields"),
          "type"=>"INT",
          "example"=>"60"),
    array("name"=>_m("Label for Change Password"),
          "desc"=>_m("Replaces the default 'Change Password'"),
          "type"=>"STR",
          "example"=>_m("Change your password")),
    array("name"=>_m("Label for Retype New Password"),
          "desc"=>_m("Replaces the default \"Retype New Password\""),
          "type"=>"STR",
          "example"=>_m("Retype the new password")),
    array("name"=>_m("Label for Delete Password"),
          "desc"=>_m("Replaces the default \"Delete Password\""),
          "type"=>"STR",
          "example"=>_m("Delete password (set to empty)")),
    array("name"=>_m("Help for Change Password"),
          "desc"=>_m("Help text under the Change Password box (default: no text)"),
          "type"=>"STR",
          "example"=>_m("To change password, enter the new password here and below")),
    array("name"=>_m("Help for Retype New Password"),
          "desc"=>_m("Help text under the Retype New Password box (default: no text)"),
          "type"=>"STR",
          "example"=>_m("Retype the new password exactly the same as you entered into "
            ."\"Change Password\".")),
   ));

$INPUT_TYPES["items"]["nul"]=
array("name"=>_m("Do not show"),
    "desc"=>_m("This option hides the input field"));

//BEGIN// Local URL Picker | Omar/Jaime | 11-06-2005
$INPUT_TYPES["items"]["lup"]=
array("name"=>_m("Local URL Pick"),
    "desc"=>_m("You can use this window to browse your local web site and pick the URL that you want to use."),
    "params"=>array(
        array("name"=>_m("URL"),
        "desc"=>"The URL of your local web server from where you want to start browsing for a particular URL.",
        "type"=>"STR",
        "example"=>_m("http#://www.ecn.cz/articles/solar.shtml"))));
//END// Local URL Picker | Omar/Jaime | 11-06-2005

// --------------------------------------------------------------------------------

$FIELD_FUNCTIONS = array ("name"=>_m("Function"),
"hint"=>_m("How the formatting in the text on this page is used:<br><i>the field</i> in italics stands for the field edited in the \"configure Fields\" window,<br><b>parameter name</b> in bold stands for a parameter on this screen."),
"items"=>array(
////alejo
"f_z"=>array("name"=>_m("file information"),
   "desc"=>_m("prints <i>the field</i> as file information, its size or type"),
   "params"=>array(
       array("name"=>_m("information"),
       "desc"=>_m("specifies returned information: <br> - <i>size</i> - returns size of file in kB o MB<br> - <i>type</i> - returns type of file)"),
       "type"=>"STR",
       "example"=>"size"))),
"f_0"=>array("name"=>_m("null function"),
    "desc"=>_m("prints nothing")),
"f_a"=>array("name"=>_m("abstract"),
    "desc"=>_m("prints abstract (if exists) or the beginning of the <b>fulltext</b>"),
    "params"=>array(
        array("name"=>_m("length"),
        "desc"=>_m("max number of characters grabbed from the <b>fulltext</b> field"),
        "type"=>"INT",
        "example"=>"80"),
        array("name"=>_m("fulltext"),
        "desc"=>_m("field id of fulltext field (like full_text.......), from which the text is grabbed. If empty, the text is grabbed from <i>the field</i> itself."),
        "type"=>"STRID",
        "example"=>_m("full_text.......")),
        array("name"=>_m("paragraph"),
        "desc"=>_m("take first paragraph (text until \<BR\> or \<P\> or \</P\> or at least '.' (dot)) if shorter then <b>length</b>"),
        "type"=>"BOOL",
        "example"=>"1"))),
"f_b"=>array("name"=>_m("extended fulltext link"),
    "desc"=>_m("Prints some <b>text</b> (or field content) with a link to the fulltext. A more general version of the f_f function. This function doesn't use <i>the field</i>."),
    "params"=>array(
        array("name"=>_m("link only"),
        "desc"=>_m("field id (like 'link_only.......') where switch between external and internal item is stored.  (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."),
        "type"=>"STRID",
        "example"=>_m("link_only.......")),
        array("name"=>_m("url_field"),
        "desc"=>_m("field id if field, where external URL is stored (like hl_href.........)"),
        "type"=>"STRID",
        "example"=>_m("hl_href.........")),
        array("name"=>_m("redirect"),
        "desc"=>_m("The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."),
        "type"=>"STR",
        "example"=>_m("http#://www.ecn.cz/articles/solar.shtml")),
        array("name"=>_m("text"),
        "desc"=>_m("The text to be surrounded by the link. If this parameter is a field id, the field's content is used, else it is used verbatim"),
        "type"=>"STR",
        "example"=>""),
        array("name"=>_m("condition field"),
        "desc"=>_m("when the specified field hasn't any content, no link is printed, but only the <b>text</b>"),
        "type"=>"STRID",
        "example"=>_m("full_text.......")),
        array("name"=>_m("tag addition"),
        "desc"=>_m("additional text to the \"\<a\>\" tag"),
        "type"=>"STR",
        "example"=>_m("target=_blank")),
        array("name"=>_m("no session id"),
        "desc"=>_m("If 1, the session id (AA_SL_Session=...) is not added to url"),
        "type"=>"BOOL",
        "example"=>"1"))),
"f_c"=>array("name"=>_m("condition"),
    "desc"=>_m("This is a very powerful function. It may be used as a better replace of some previous functions. If <b>cond_field</b> = <b>condition</b>, prints <b>begin</b> <i>field</i> <b>end</b>, else prints <b>else</b>. If <b>cond_field</b> is not specified, <i>the field</i> is used. Condition may be reversed (negated) by the \"!\" character at the beginning of it."),
    "params"=>array(
        array("name"=>_m("condition"),
        "desc"=>_m("you may use \"!\" to reverse (negate) the condition"),
        "type"=>"STR",
        "example"=>"1"),
        array("name"=>_m("begin"),
        "desc"=>_m("text to print before <i>field</i>, if condition is true"),
        "type"=>"STR",
        "example"=>_m("Yes")),
        array("name"=>_m("end"),
        "desc"=>_m("text to print after <i>field</i>, if condition is true"),
        "type"=>"STR",
        "example"=>""),
        array("name"=>_m("else"),
        "desc"=>_m("text to print when condition is not satisfied"),
        "type"=>"STR",
        "example"=>_m("No")),
        array("name"=>_m("cond_field"),
        "desc"=>_m("field to compare with the <b>condition</b> - if not filled, <i>field</i> is used"),
        "type"=>"STRID",
        "example"=>""),
        array("name"=>_m("skip_the_field"),
        "desc"=>_m("if set to 1, skip <i>the field</i> (print only <b>begin end</b> or <b>else</b>)"),
        "type"=>"STRID",
        "example"=>"1")),
    "examples"=>array(
        array("desc"=>_m("This example is usable e.g. for Highlight field - it shows Yes or No depending on the field content"),
        "params"=>_m("1:Yes::No::1")),
        array("desc"=>_m("When e-mail field is filled, prints something like \"Email: email@apc.org\", when it is empty, prints nothing"),
        "params"=>_m("!:Email#:&nbsp;")),
        array("desc"=>_m("Print image height attribute, if <i>the field</i> is filled, nothing otherwise."),
        "params"=>_m("!:height=")))),
"f_d"=>array("name"=>_m("date"),
    "desc"=>_m("prints date in a user defined format"),
    "params"=>array(
        array("name"=>_m("format"),
        "desc"=>_m("PHP-like format - see <a href=\"http://php.net/manual/en/function.date.php\" target=_blank>PHP manual</a>"),
        "type"=>"STR",
        "example"=>_m("m-d-Y")))),
"f_e"=>array("name"=>_m("edit item"),
    "desc"=>_m("_#EDITITEM used on admin page index.php3 for itemedit url"),
    "params"=>array(
        array("name"=>_m("type"),
        "desc"=>_m("disc - for editing a discussion<br>itemcount - to output an item count<br>safe - for safe html<br>slice_info - select a field from the slice info<br>edit - URL to edit the item<br>add - URL to add a new item"),
        "type"=>"STR",
        "example"=>_m("edit")),
        array("name"=>_m("return url"),
        "desc"=>_m("Return url being called from, usually leave blank and allow default"),
        "type"=>"STR",
        "example"=>_m("/mysite.shtml")))),
"f_f"=>array("name"=>_m("fulltext link"),
    "desc"=>_m("Prints the URL name inside a link to the fulltext - enables using external items. To be used immediately after \"\<a href=\""),
    "params"=>array(
        array("name"=>_m("link only"),
        "desc"=>_m("field id (like 'link_only.......') where switch between external and internal item is stored. Usually this field is represented as checkbox. If the checkbox is checked, <i>the field</i> is printed, if unchecked, link to fulltext is printed (it depends on <b>redirect</b> parameter, too)."),
        "type"=>"STRID",
        "example"=>_m("link_only.......")),
        array("name"=>_m("redirect"),
        "desc"=>_m("The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."),
        "type"=>"STR",
        "example"=>_m("http#://www.ecn.cz/articles/solar.shtml")),
        array("name"=>_m("no session id"),
        "desc"=>_m("If 1, the session id (AA_SL_Session=...) is not added to url"),
        "type"=>"BOOL",
        "example"=>"1"))),
"f_g"=>array("name"=>_m("image height"),
    "desc"=>_m("An old-style function. Prints <i>the field</i> as image height value (\<img height=...\>) or erases the height tag. To be used immediately after \"height=\".The f_c function provides a better way of doing this with parameters \":height=\". ")),
"f_h"=>array("name"=>_m("print HTML multiple"),
    "desc"=>_m("prints <i>the field</i> content depending on the html flag (escape html special characters or just print)"),
    "params"=>array(
        array("name"=>_m("delimiter"),
        "desc"=>_m("if specified, a field with multiple values is displayed with the values delimited by it"),
        "type"=>"STR",
        "example"=>";"))),
"f_i"=>array("name"=>_m("image src"),
    "desc"=>_m("prints <i>the field</i> as image source (\<img src=...\>) - NO_PICTURE for none. The same could be done by the f_c function with parameters :::NO_PICTURE. ")),
"i_s"=>array("name"=>_m("image size"),
    "desc"=>_m("prints <i>the field</i> as image size (height='xxx' width='yyy') (or other image information) or empty string if cant work out, does not special case URLs from uploads directory, might do later! "),
    "params"=>array(
        array("name"=>_m("information"),
        "desc"=>_m("specifies returned information: <br> - <i>html</i> - (default) - returns image size as HTML atributes (height='xxx' width='yyy')<br> - <i>width</i> - returns width of image in pixels<br> - <i>height</i> - returns height of image in pixels<br> - <i>imgtype</i> - returns flag indicating the type of the image: 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM<br> - <i>mime</i> - returns mimetype of the image (like 'image/gif', 'application/x-shockwave-flash', ...)"),
        "type"=>"STR",
        "example"=>"html"))),

"f_y"=>array("name"=>_m("expanded string"),
    "desc"=>_m("expands the string in the parameter"),
    "params"=>array(
        array("name"=>_m("string to expand"),
        "desc"=>_m("if specified then this string is expanded, if not specified then expands the contents of the field"),
        "type"=>"STR",
        "example"=>"{switch({href_url.........}).+:{href_url.........}}"))),
"f_j"=>array("name"=>_m("substring with case change"),
    "desc"=>_m("prints a part of <i>the field</i>"),
    "params"=>array(
        array("name"=>_m("start"),
        "desc"=>_m("position of substring start (0=first, 1=second, -1=last,-2=two from end)"),
        "type"=>"INT",
        "example"=>"0"),
        array("name"=>_m("count"),
        "desc"=>_m("count of characters (0=until the end)"),
        "type"=>"INT",
        "example"=>""),
        array("name"=>_m("case"),
        "desc"=>_m("upper - convert to UPPERCASE, lower - convert to lowercase, first - convert to First Upper; default is don't change"),
        "type"=>"STR",
        "example"=>"upper"),
        array("name"=>_m("add string"),
        "desc"=>_m("if string is shorted, <i>add string</i> is appended to the string (probably something like [...])"),
        "type"=>"STR",
        "example"=>"..."))),
"f_k"=>array("name" => _m("Auto Update Checkbox")),  //auto update check box
"f_l"=>array("name"=>_m("linked field"),
    "desc"=>_m("prints <i>the field</i> as a link if the <b>link URL</b> is not NULL, otherwise prints just <i>the field</i>"),
    "params"=>array(
        array("name"=>_m("link URL"),
        "desc"=>"",
        "type"=>"STRID",
        "example"=>_m("source_href.....")))),
"f_m"=>array("name"=>_m("e-mail or link"),
    "desc"=>_m("mailto link - prints: <br>\"<b>begin</b>\<a href=\"(mailto:)<i>the field</i>\" <b>tag adition</b>\><b>field/text</b>\</a\>. If <i>the field</i> is not filled, prints <b>else_field/text</b>."),
    "params"=>array(
        array("name"=>_m("begin"),
        "desc"=>_m("text before the link"),
        "type"=>"STR",
        "example"=>_m("e-mail")),
        array("name"=>_m("field/text"),
        "desc"=>_m("if this parameter is a field id, the field's content is used, else it is used verbatim"),
        "type"=>"STR",
        "example"=>""),
        array("name"=>_m("else_field/text"),
        "desc"=>_m("if <i>the field</i> is empty, only this text (or field content) is printed"),
        "type"=>"STR",
        "example"=>""),
        array("name"=>_m("linktype"),
        "desc"=>_m("mailto / href (default is mailto) - it is possible to use f_m function for links, too - just type 'href' as this parameter"),
        "type"=>"STR",
        "example"=>_m("href")),
        array("name"=>_m("tag addition"),
        "desc"=>_m("additional text to the \"\<a\>\" tag"),
        "type"=>"STR",
        "example"=>_m("target=_blank")),
        array("name"=>_m("hide email"),
        "desc"=>_m("if 1 then hide email from spam robots. Default is 0."),
        "type"=>"BOOL",
        "example"=>""))),
"f_o"=>array("name"=>_m("'New' sign"),
    "desc"=>_m("prints 'New' or 'Old' or any other text in <b>newer text</b> or <b>older text</b> depending on <b>time</b>. Time is specified in minutes from current time."),
    "params"=>array(
        array("name"=>_m("time"),
        "desc"=>_m("Time in minutes from current time."),
        "type"=>"INT",
        "example"=>_m("1440")),
        array("name"=>_m("newer text"),
        "desc"=>_m("Text to be printed, if the date in <i>the filed</i> is newer than <i>current_time</i> - <b>time</b>."),
        "type"=>"STR",
        "example"=>_m("NEW")),
        array("name"=>_m("older text"),
        "desc"=>_m("Text to be printed, if the date in <i>the filed</i> is older than <i>current_time</i> - <b>time</b>"),
        "type"=>"STR",
        "example"=>_m("")))),
"f_n"=>array("name"=>_m("id"),
    "desc"=>_m("prints unpacked id (use it, if you watn to show 'item id' or 'slice id')")),
"f_q"=>array("name"=>_m("text (blurb) from another slice"),
    "desc"=>_m("prints 'blurb' (piece of text) from another slice, based on a simple condition.<br>If <i>the field</i> (or the field specifield by <b>stringToMatch</b>) in current slice matches the content of <b>fieldToMatch</b> in <b>blurbSliceId</b>, it returns the content of <b>fieldToReturn</b> in <b>blurbSliceId</b>."),
    "params"=>array(
        array("name"=>_m("stringToMatch"),
        "desc"=>_m("By default it is <i>the field</i>.  It can be formatted either as the id of a field (headline........) OR as static text."),
        "type"=>"STR",
        "example"=>_m("category........")),
        array("name"=>_m("blurbSliceId"),
        "desc"=>_m("unpacked slice id of the slice where the blurb text is stored"),
        "type"=>"STR",
        "example"=>_m("41415f436f72655f4669656c64732e2e")),
        array("name"=>_m("fieldToMatch"),
        "desc"=>_m("field id of the field in <b>blurbSliceId</b> where to search for <b>stringToMatch</b>"),
        "type"=>"STR",
        "example"=>_m("headline........")),
        array("name"=>_m("fieldToReturn"),
        "desc"=>_m("field id of the field in <b>blurbSliceId</b> where the blurb text is stored (what to print)"),
        "type"=>"STR",
        "example"=>_m("full_text.......")))),
"f_r"=>array("name"=>_m("RSS tag"),
    "desc"=>_m("serves for internal purposes of the predefined RSS aliases (e.g. _#RSS_TITL). Adds the RSS 0.91 compliant tags.")),
"f_s"=>array("name"=>_m("default"),
    "desc"=>_m("prints <i>the field</i> or a default value if <i>the field</i> is empty. The same could be done by the f_c function with parameters :::<b>default</b>."),
    "params"=>array(
        array("name"=>_m("default"),
        "desc"=>_m("default value"),
        "type"=>"STR",
        "example"=>_m("javascript: window.alert('No source url specified')")))),
"f_t"=>array("name"=>_m("print field"),
    "desc"=>_m("prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too (in its basic variant)"),
    "params"=>array(
        array("name"=>_m("unalias string"),
        "desc"=>_m("if the <i>unalias string</i> is defined, then the function ignores <i>the field</i> and it rather prints the <i>unalias string</i>. You can of course use any aliases (or fields like {headline.........}) in this string"),
        "type"=>"STR",
        "example"=>_m("<img src={img_src.........1} _#IMG_WDTH _#IMG_HGHT>")),
        array("name"=>_m("output modify"),
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
        "example"=>_m("")))),
"f_x"=>array("name"=>_m("transformation"),
    "desc"=>_m("Allows to transform the field value to another value.<br>Usage: <b>content_1</b>:<b>return_value_1</b>:<b>content_1</b>:<b>return_value_1</b>:<b>default</b><br>If the content <i>the field</i> is equal to <b>content_1</b> the <b>return_value_1</b> is returned. If the content <i>the field</i> is equal to <b>content_2</b> the <b>return_value_2</b> is returned. If <i>the field is not equal to any <b>content_x</b>, <b>default</b> is returned</i>."),
    "params"=>array(
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("content"),
        "desc"=>_m("string for comparison with <i>the field</i> for following return value"),
        "type"=>"STR",
        "example"=>"E"),
        array("name"=>_m("return value"),
        "desc"=>_m("string to return if previous content matches - You can use field_id too"),
        "type"=>"STR",
        "example"=>_m("Environment")),
        array("name"=>_m("default"),
        "desc"=>_m("if no content matches, use this string as return value"),
        "type"=>"STR",
        "example"=>L_PARAM_WIZARD_FUNC_F_X_PAR2_EXAMPLE))),
"f_u"=>array("name"=>_m("user function"),
    "desc"=>_m("calls a user defined function (see How to create new aliases in <a href='http://apc-aa.sourceforge.net/faq/#aliases'>FAQ</a>)"),
    "params"=>array(
        array("name"=>_m("function"),
        "desc"=>_m("name of the function in the include/usr_aliasfnc.php3 file"),
        "type"=>"STR",
        "example"=>_m("usr_start_end_date_cz")),
        array("name"=>_m("parameter"),
        "desc"=>_m("a parameter passed to the function"),
        "type"=>"STR",
        "example"=>"1"))),
"f_v"=>array("name"=>_m("view"),
    "desc"=>_m("allows to manipulate the views. This is a complicated and powerful function, described in <a href=\"../doc/FAQ.html#viewparam\" target=_blank>FAQ</a>, which allows to display any view in place of the alias. It can be used for 'related stories' table or for dislaying content of related slice."),
    "params"=>array(
        array("name"=>_m("complex parameter"),
        "desc"=>_m("this parameter is the same as we use in view.php3 url parameter - see the FAQ"),
        "type"=>"STR",
        "example"=>_m("vid=4&amp;cmd[23]=v-25")))),
"f_w"=>array("name"=>_m("image width"),
    "desc"=>_m("An old-style function. Prints <i>the field</i> as image width value (\<img width=...\>) or erases the width tag. To be used immediately after \"width=\".The f_c function provides a better way of doing this with parameters \":width=\". "))));


// --------------------------------------------------------------------------------

$TRANS_ACTIONS["name"] = _m("Transformation action");
$TRANS_ACTIONS["items"]["store"] = array (
    "name"=>_m("Store"),
    "desc"=>_m("Simply store a value from the input field")
    );

$TRANS_ACTIONS["items"]["removestring"] = array (
    "name"=>_m("Remove string"),
    "desc"=>_m("Remove all occurences of a string from the input field.") ,
    "params"=>array(
        array("name"=>_m("string parameter"),
        "desc"=>_m("Removed string"),
        "type"=>"STR"))
    );
$TRANS_ACTIONS["items"]["formatdate"] = array (
    "name"=>_m("Format date"),
    "desc"=>_m("Parse the date in the input field expected to be in English date format. In case of error, the transformation fails")
    );

$TRANS_ACTIONS["items"]["web"] = array (
    "name"=>_m("Add http prefix"),
    "desc"=>_m("Adds 'http://' prefix to the field if not beginning with 'http://' and not empty.")
    );

$TRANS_ACTIONS["items"]['value'] = array (
    "name"=>_m("Store parameter"),
    "desc"=>_m("Store parameter instead of the input field") ,
    "params"=>array(
        array("name"=>_m("string parameter"),
              "type"=>"STR")));

$TRANS_ACTIONS["items"]["string2id"] = array (
    "name"=>_m("Store as long id"),
    "desc"=>_m("Creates long id from the string. The string is combined with the parameter!! or with slice_id (if the parameter is not provided. From the same string (and the same parameter) we create allways the same id."),
    "params"=>array(
        array("name"=>_m("string to add"),
              "desc"=>_m("this parameter will be added to the string before conversion (the reason is to aviod empty strings and also in order we do not generate allways the same id for common strings (in different imports). If this param is not specified, slice_id is used istead."),
              "example"=>"This string is up to you (any length)",
              "type"=>"STR"))
    );

$TRANS_ACTIONS["items"]["storeparsemulti"] = array (
    "name"=>_m("Split input field by string"),
    "desc"=>_m("Split input field by string parameter and store the result as multi-value.") ,
    "params"=>array(
        array("name"=>_m("string parameter"),
              "desc"=>_m("string which separates the values of the input field"),
              "type"=>"STR",
              "example"=>":"))
    );
$TRANS_ACTIONS["items"]["default"] = array (
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
    );
?>
