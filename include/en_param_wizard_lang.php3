<?php
//$Id$
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

define("L_PARAM_WIZARD_TITLE","Wizard");
define("L_PARAM_WIZARD_CHOOSE","Choose another ");
define("L_PARAM_WIZARD_PARAMS","Available parameters: ");
define("L_PARAM_WIZARD_CLOSE","Close the wizard");
define("L_PARAM_WIZARD_READ","Reread params");
define("L_PARAM_WIZARD_WRITE","Write params");
define("L_PARAM_WIZARD_EXAMPLE","Example params");
define("L_PARAM_WIZARD_NO_PARAMS","This %s has no parameters.");
define("L_PARAM_WIZARD_TYPE_INT","integer number");
define("L_PARAM_WIZARD_TYPE_STR","any text");
define("L_PARAM_WIZARD_TYPE_STRID","field id");
define("L_PARAM_WIZARD_TYPE_BOOL","boolean: 0=false,1=true");
define("L_PARAM_WIZARD_CHOOSE_EXAMPLE","Have a look at these examples of parameters sets:");
define("L_PARAM_WIZARD_NOT_FOUND","This is an undocumented %s. We don't recommend to use it.");
define("L_PARAM_WIZARD_SHOW_EXAMPLE","Show");
define("L_PARAM_WIZARD_INPUT_USING_CONSTANTS","<br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)");

/* The arrays INPUT_TYPES and FIELD_FUNCTIONS and all constants used in them
	were generated from another array structure by a program - see param_wizard_generate.php3
*/

define("L_PARAM_WIZARD_INPUT_NAME","Input Type");
define("L_PARAM_WIZARD_INPUT_txt_NAME","Text Area");
define("L_PARAM_WIZARD_INPUT_txt_DESC","Text area with 60 columns");
define("L_PARAM_WIZARD_INPUT_txt_PAR0_NAME","row count");
define("L_PARAM_WIZARD_INPUT_txt_PAR0_DESC","");
define("L_PARAM_WIZARD_INPUT_txt_PAR0_EXAMPLE","20");
define("L_PARAM_WIZARD_INPUT_edt_NAME","Rich Edit Area");
define("L_PARAM_WIZARD_INPUT_edt_DESC","Rich edit text area. This operates the same way as Text Area in browsers which don't support the Microsoft TriEdit library. In IE 5.0 and higher and in Netscape 4.76 and higher (after installing the necessary features) it uses the TriEdit to provide an incredibly powerful HTML editor.<br><br>
Another possibility is to use the <b>iframe</b> version which should work in IE on Windows and Mac (set the 3rd parameter to \"iframe\").<br><br>
The code for this editor is taken from the Wysiwyg open project (http://www.unica.edu/uicfreesoft/) and changed to fullfill our needs. See http://www.unica.edu/uicfreesoft/wysiwyg_web_edit/Readme_english.txt on details how to prepare Netscape.<br><br>
The javascript code needed to provide the editor is saved in two HTML files, so that the user doesn't have to load it every time she reloads the Itemedit web page.");
define("L_PARAM_WIZARD_INPUT_edt_PAR0_NAME","row count");
define("L_PARAM_WIZARD_INPUT_edt_PAR0_DESC","");
define("L_PARAM_WIZARD_INPUT_edt_PAR0_EXAMPLE","10");
define("L_PARAM_WIZARD_INPUT_edt_PAR1_NAME","column count");
define("L_PARAM_WIZARD_INPUT_edt_PAR1_DESC","");
define("L_PARAM_WIZARD_INPUT_edt_PAR1_EXAMPLE","70");
define("L_PARAM_WIZARD_INPUT_edt_PAR2_NAME","type");
define("L_PARAM_WIZARD_INPUT_edt_PAR2_DESC","type: class (default) / iframe");
define("L_PARAM_WIZARD_INPUT_edt_PAR2_EXAMPLE","class");

define("L_PARAM_WIZARD_INPUT_fld_NAME","Text Field");
define("L_PARAM_WIZARD_INPUT_fld_DESC","A text field.");
define("L_PARAM_WIZARD_INPUT_fld_PAR0_NAME","max characters");
define("L_PARAM_WIZARD_INPUT_fld_PAR0_DESC","max count of characters entered (maxlength parameter)");
define("L_PARAM_WIZARD_INPUT_fld_PAR0_EXAMPLE","254");
define("L_PARAM_WIZARD_INPUT_fld_PAR1_NAME","width");
define("L_PARAM_WIZARD_INPUT_fld_PAR1_DESC","width of the field in characters (size parameter)");
define("L_PARAM_WIZARD_INPUT_fld_PAR1_EXAMPLE","30");

define("L_PARAM_WIZARD_INPUT_sel_NAME","Select Box");
define("L_PARAM_WIZARD_INPUT_sel_DESC","A selectbox field with a values list." . L_PARAM_WIZARD_INPUT_USING_CONSTANTS);
define("L_PARAM_WIZARD_INPUT_sel_PAR0_NAME","slice field");
define("L_PARAM_WIZARD_INPUT_sel_PAR0_DESC","field will be displayed in select box. if not specified, in select box are displayed headlines. (only for constants input type: slice)");
define("L_PARAM_WIZARD_INPUT_sel_PAR0_EXAMPLE","category........");
define("L_PARAM_WIZARD_INPUT_sel_PAR1_NAME","use name");
define("L_PARAM_WIZARD_INPUT_sel_PAR1_DESC","if set (=1), then the name of selected constant is used, insted of the value. Default is 0");
define("L_PARAM_WIZARD_INPUT_sel_PAR1_EXAMPLE","0");
define("L_PARAM_WIZARD_INPUT_pre_NAME","Text Field with Presets");
define("L_PARAM_WIZARD_INPUT_pre_DESC","Text field with values names list. When you choose a name from the list, the appropriate value is printed in the text field");
define("L_PARAM_WIZARD_INPUT_pre_PAR0_NAME","max characters");
define("L_PARAM_WIZARD_INPUT_pre_PAR0_DESC","max count of characteres entered in the text field (maxlength parameter)");
define("L_PARAM_WIZARD_INPUT_pre_PAR0_EXAMPLE","254");
define("L_PARAM_WIZARD_INPUT_pre_PAR1_NAME","width");
define("L_PARAM_WIZARD_INPUT_pre_PAR1_DESC","width of the text field in characters (size parameter)");
define("L_PARAM_WIZARD_INPUT_pre_PAR1_EXAMPLE","20");
define("L_PARAM_WIZARD_INPUT_pre_PAR2_NAME","slice field");
define("L_PARAM_WIZARD_INPUT_pre_PAR2_DESC","field will be displayed in select box. if not specified, in select box are displayed headlines. (only for constants input type: slice)");
define("L_PARAM_WIZARD_INPUT_pre_PAR2_EXAMPLE","category........");
define("L_PARAM_WIZARD_INPUT_pre_PAR3_NAME","use name");
define("L_PARAM_WIZARD_INPUT_pre_PAR3_DESC","if set (=1), then the name of selected constant is used, insted of the value. Default is 0");
define("L_PARAM_WIZARD_INPUT_pre_PAR3_EXAMPLE","0");
define("L_PARAM_WIZARD_INPUT_pre_PAR4_NAME","adding");
define("L_PARAM_WIZARD_INPUT_pre_PAR4_DESC","adding the selected items to input field comma separated");
define("L_PARAM_WIZARD_INPUT_pre_PAR4_EXAMPLE","0");
define("L_PARAM_WIZARD_INPUT_pre_PAR5_NAME","secondfield");
define("L_PARAM_WIZARD_INPUT_pre_PAR5_DESC","field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)");
define("L_PARAM_WIZARD_INPUT_pre_PAR5_EXAMPLE","source_href.....");
define("L_PARAM_WIZARD_INPUT_pre_PAR6_NAME","add2constant");
define("L_PARAM_WIZARD_INPUT_pre_PAR6_DESC","if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)");
define("L_PARAM_WIZARD_INPUT_pre_PAR6_EXAMPLE","0");

define("L_PARAM_WIZARD_INPUT_tpr_NAME","Text Area with Presets");
define("L_PARAM_WIZARD_INPUT_tpr_DESC","Text area with values names list. When you choose a name from the list, the appropriate value is printed in the text area");
define("L_PARAM_WIZARD_INPUT_tpr_PAR0_NAME","rows");
define("L_PARAM_WIZARD_INPUT_tpr_PAR0_DESC","Textarea rows");
define("L_PARAM_WIZARD_INPUT_tpr_PAR0_EXAMPLE","4");
define("L_PARAM_WIZARD_INPUT_tpr_PAR1_NAME","cols");
define("L_PARAM_WIZARD_INPUT_tpr_PAR1_DESC","Text area columns");
define("L_PARAM_WIZARD_INPUT_tpr_PAR1_EXAMPLE","60");

define("L_PARAM_WIZARD_INPUT_rio_NAME","Radio Button");
define("L_PARAM_WIZARD_INPUT_rio_DESC","Radio button group - the user may choose one value of the list. ");
define("L_PARAM_WIZARD_INPUT_dte_NAME","Date");
define("L_PARAM_WIZARD_INPUT_dte_DESC","you can choose an interval from which the year will be offered");
define("L_PARAM_WIZARD_INPUT_dte_PAR0_NAME","Starting Year");
define("L_PARAM_WIZARD_INPUT_dte_PAR0_DESC","The (relative) start of the year interval");
define("L_PARAM_WIZARD_INPUT_dte_PAR0_EXAMPLE","1");
define("L_PARAM_WIZARD_INPUT_dte_PAR1_NAME","Ending Year");
define("L_PARAM_WIZARD_INPUT_dte_PAR1_DESC","The (relative) end of the year interval");
define("L_PARAM_WIZARD_INPUT_dte_PAR1_EXAMPLE","10");
define("L_PARAM_WIZARD_INPUT_dte_PAR2_NAME","Relative");
define("L_PARAM_WIZARD_INPUT_dte_PAR2_DESC","If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute.");
define("L_PARAM_WIZARD_INPUT_dte_PAR2_EXAMPLE","1");
define("L_PARAM_WIZARD_INPUT_dte_PAR3_NAME","Show time");
define("L_PARAM_WIZARD_INPUT_dte_PAR3_DESC","show the time box? (1 means Yes, undefined means No)");
define("L_PARAM_WIZARD_INPUT_dte_PAR3_EXAMPLE","1");
define("L_PARAM_WIZARD_INPUT_chb_NAME","Checkbox");
define("L_PARAM_WIZARD_INPUT_chb_DESC","The field value will be represented by a checkbox.");
define("L_PARAM_WIZARD_INPUT_mch_NAME","Multiple Checkboxes");
define("L_PARAM_WIZARD_INPUT_mch_DESC","Multiple choice checkbox group. " . L_PARAM_WIZARD_INPUT_USING_CONSTANTS);
define("L_PARAM_WIZARD_INPUT_mse_NAME","Multiple Selectbox");
define("L_PARAM_WIZARD_INPUT_mse_DESC","Multiple choice select box. " . L_PARAM_WIZARD_INPUT_USING_CONSTANTS);
define("L_PARAM_WIZARD_INPUT_mse_PAR0_NAME","Row count");
define("L_PARAM_WIZARD_INPUT_mse_PAR0_DESC","");
define("L_PARAM_WIZARD_INPUT_mse_PAR0_EXAMPLE","5");
define("L_PARAM_WIZARD_INPUT_wi2_NAME","Two Windows");
define("L_PARAM_WIZARD_INPUT_wi2_DESC","Two Windows. " . L_PARAM_WIZARD_INPUT_USING_CONSTANTS);
define("L_PARAM_WIZARD_INPUT_wi2_PAR0_NAME","Row count");
define("L_PARAM_WIZARD_INPUT_wi2_PAR0_DESC","");
define("L_PARAM_WIZARD_INPUT_wi2_PAR0_EXAMPLE","5");
define("L_PARAM_WIZARD_INPUT_wi2_PAR1_NAME","Title of \"Offer\" selectbox");
define("L_PARAM_WIZARD_INPUT_wi2_PAR1_DESC","");
define("L_PARAM_WIZARD_INPUT_wi2_PAR1_EXAMPLE","Our offer");
define("L_PARAM_WIZARD_INPUT_wi2_PAR2_NAME","Title of \"Selected\" selectbox");
define("L_PARAM_WIZARD_INPUT_wi2_PAR2_DESC","");
define("L_PARAM_WIZARD_INPUT_wi2_PAR2_EXAMPLE","Selected");

define("L_PARAM_WIZARD_INPUT_fil_NAME","File");
define("L_PARAM_WIZARD_INPUT_fil_DESC","File upload - a text field with the file find button");
define("L_PARAM_WIZARD_INPUT_fil_PAR0_NAME","Allowed file types");
define("L_PARAM_WIZARD_INPUT_fil_PAR0_DESC","");
define("L_PARAM_WIZARD_INPUT_fil_PAR0_EXAMPLE","image/*");
define("L_PARAM_WIZARD_INPUT_fil_PAR1_NAME","Label");
define("L_PARAM_WIZARD_INPUT_fil_PAR1_DESC","To be printed before the text field");
define("L_PARAM_WIZARD_INPUT_fil_PAR1_EXAMPLE","File: ");
define("L_PARAM_WIZARD_INPUT_fil_PAR2_NAME","Hint");
define("L_PARAM_WIZARD_INPUT_fil_PAR2_DESC","");
define("L_PARAM_WIZARD_INPUT_fil_PAR2_EXAMPLE","You can select a file ...");
define("L_PARAM_WIZARD_INPUT_iso_NAME","Related Item Window");
define("L_PARAM_WIZARD_INPUT_iso_DESC","List of items connected with the active one - by using the buttons Add and Delete you show a window, where you can search in the items list");
define("L_PARAM_WIZARD_INPUT_iso_PAR0_NAME","Row count");
define("L_PARAM_WIZARD_INPUT_iso_PAR0_DESC","Row count in the list");
define("L_PARAM_WIZARD_INPUT_iso_PAR0_EXAMPLE","15");
define("L_PARAM_WIZARD_INPUT_iso_PAR1_NAME","Buttons to show");
define("L_PARAM_WIZARD_INPUT_iso_PAR1_DESC","Defines, which buttons to show in item selection:<br>A - 'Add'<br>M - 'Add Mutual<br>B - 'Backward'.<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important.");
define("L_PARAM_WIZARD_INPUT_iso_PAR1_EXAMPLE","AMB");
define("L_PARAM_WIZARD_INPUT_iso_PAR2_NAME","Admin design");
define("L_PARAM_WIZARD_INPUT_iso_PAR2_DESC","If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default).");
define("L_PARAM_WIZARD_INPUT_iso_PAR2_EXAMPLE","0");
define("L_PARAM_WIZARD_INPUT_nul_NAME","Do not show");
define("L_PARAM_WIZARD_INPUT_nul_DESC","This option hides the input field");
define("L_PARAM_WIZARD_INPUT_hco_NAME","Hierarchical constants");
define("L_PARAM_WIZARD_INPUT_hco_DESC","A view with level boxes allows to choose constants.");
define("L_PARAM_WIZARD_INPUT_hco_PAR0_NAME","Level count");
define("L_PARAM_WIZARD_INPUT_hco_PAR0_DESC","Count of level boxes");
define("L_PARAM_WIZARD_INPUT_hco_PAR0_EXAMPLE","3");
define("L_PARAM_WIZARD_INPUT_hco_PAR1_NAME","Box width");
define("L_PARAM_WIZARD_INPUT_hco_PAR1_DESC","Width in characters");
define("L_PARAM_WIZARD_INPUT_hco_PAR1_EXAMPLE","60");
define("L_PARAM_WIZARD_INPUT_hco_PAR2_NAME","Size of target");
define("L_PARAM_WIZARD_INPUT_hco_PAR2_DESC","Lines in the target select box");
define("L_PARAM_WIZARD_INPUT_hco_PAR2_EXAMPLE","5");
define("L_PARAM_WIZARD_INPUT_hco_PAR3_NAME","Horizontal");
define("L_PARAM_WIZARD_INPUT_hco_PAR3_DESC","Show levels horizontally");
define("L_PARAM_WIZARD_INPUT_hco_PAR3_EXAMPLE","1");
define("L_PARAM_WIZARD_INPUT_hco_PAR4_NAME","First selectable");
define("L_PARAM_WIZARD_INPUT_hco_PAR4_DESC","First level which will have a Select button");
define("L_PARAM_WIZARD_INPUT_hco_PAR4_EXAMPLE","0");
define("L_PARAM_WIZARD_INPUT_hco_PAR5_NAME","Level names");
define("L_PARAM_WIZARD_INPUT_hco_PAR5_DESC","Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ...");
define("L_PARAM_WIZARD_INPUT_hco_PAR5_EXAMPLE","Top level~Second level~Keyword");

define("L_PARAM_WIZARD_FUNC_NAME","Function");
define("L_PARAM_WIZARD_FUNC_HINT","How the formatting in the text on this page is used:<br>"
	."<i>the field</i> in italics stands for the field edited in the \"configure Fields\" window,<br>" 
	."<b>parameter name</b> in bold stands for a parameter on this screen.");
define("L_PARAM_WIZARD_FUNC_F_0_NAME","null function");
define("L_PARAM_WIZARD_FUNC_F_0_DESC","prints nothing");
define("L_PARAM_WIZARD_FUNC_F_H_NAME","print HTML multiple");
define("L_PARAM_WIZARD_FUNC_F_H_DESC","prints <i>the field</i> content depending on the html flag (escape html special characters or just print)");
define("L_PARAM_WIZARD_FUNC_F_H_PAR0_NAME","delimiter");
define("L_PARAM_WIZARD_FUNC_F_H_PAR0_DESC","if specified, a field with multiple values is displayed with the values delimited by it");
define("L_PARAM_WIZARD_FUNC_F_H_PAR0_EXAMPLE",";");
define("L_PARAM_WIZARD_FUNC_F_D_NAME","date");
define("L_PARAM_WIZARD_FUNC_F_D_DESC","prints date in a user defined format");
define("L_PARAM_WIZARD_FUNC_F_D_PAR0_NAME","format");
define("L_PARAM_WIZARD_FUNC_F_D_PAR0_DESC","PHP-like format - see <a href=\"http://www.php.cz/manual/en/function.date.php\" target=_blank>PHP manual</a>");
define("L_PARAM_WIZARD_FUNC_F_D_PAR0_EXAMPLE","m-d-Y");
define("L_PARAM_WIZARD_FUNC_F_I_NAME","image src");
define("L_PARAM_WIZARD_FUNC_F_I_DESC","prints <i>the field</i> as image source (\\<img src=...\\>) - NO_PICTURE for none. The same could be done by the f_c function with parameters :::NO_PICTURE. ");
define("L_PARAM_WIZARD_FUNC_F_N_NAME","id");
define("L_PARAM_WIZARD_FUNC_F_N_DESC","prints unpacked id (use it, if you watn to show 'item id' or 'slice id')");
define("L_PARAM_WIZARD_FUNC_F_G_NAME","image height");
define("L_PARAM_WIZARD_FUNC_F_G_DESC","An old-style function. Prints <i>the field</i> as image height value (\\<img height=...\\>) or erases the height tag. To be used immediately after \"height=\".The f_c function provides a better way of doing this with parameters \":height=\". ");
define("L_PARAM_WIZARD_FUNC_F_W_NAME","image width");
define("L_PARAM_WIZARD_FUNC_F_W_DESC","An old-style function. Prints <i>the field</i> as image width value (\\<img width=...\\>) or erases the width tag. To be used immediately after \"width=\".The f_c function provides a better way of doing this with parameters \":width=\". ");
define("L_PARAM_WIZARD_FUNC_F_A_NAME","abstract");
define("L_PARAM_WIZARD_FUNC_F_A_DESC","prints abstract (if exists) or the beginning of the <b>fulltext</b>");
define("L_PARAM_WIZARD_FUNC_F_A_PAR0_NAME","length");
define("L_PARAM_WIZARD_FUNC_F_A_PAR0_DESC","number of characters from the <b>fulltext</b> field");
define("L_PARAM_WIZARD_FUNC_F_A_PAR0_EXAMPLE","80");
define("L_PARAM_WIZARD_FUNC_F_A_PAR1_NAME","fulltext");
define("L_PARAM_WIZARD_FUNC_F_A_PAR1_DESC","field id of fulltext field (like full_text.......)");
define("L_PARAM_WIZARD_FUNC_F_A_PAR1_EXAMPLE","full_text.......");
define("L_PARAM_WIZARD_FUNC_F_A_PAR2_NAME","paragraph");
define("L_PARAM_WIZARD_FUNC_F_A_PAR2_DESC","take first paragraph (text until \\<BR\\> or \\<P\\> or \\</P\\) if shorter then <b>length</b>");
define("L_PARAM_WIZARD_FUNC_F_A_PAR2_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_F_NAME","fulltext link");
define("L_PARAM_WIZARD_FUNC_F_F_DESC","Prints the URL name inside a link to the fulltext - enables using external items. To be used immediately after \"\\<a href=\"");
define("L_PARAM_WIZARD_FUNC_F_F_PAR0_NAME","link only");
define("L_PARAM_WIZARD_FUNC_F_F_PAR0_DESC","field id (like 'link_only.......') where switch between external and internal item is stored. Usually this field is represented as checkbox. If the checkbox is checked, <i>the field</i> is printed, if unchecked, link to fulltext is printed (it depends on <b>redirect</b> parameter, too).");
define("L_PARAM_WIZARD_FUNC_F_F_PAR0_EXAMPLE","link_only.......");
define("L_PARAM_WIZARD_FUNC_F_F_PAR1_NAME","redirect");
define("L_PARAM_WIZARD_FUNC_F_F_PAR1_DESC","The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior).");
define("L_PARAM_WIZARD_FUNC_F_F_PAR1_EXAMPLE","http#://www.ecn.cz/articles/solar.shtml");
define("L_PARAM_WIZARD_FUNC_F_F_PAR2_NAME","no session id");
define("L_PARAM_WIZARD_FUNC_F_F_PAR2_DESC","If 1, the session id (AA_SL_Session=...) is not added to url");
define("L_PARAM_WIZARD_FUNC_F_F_PAR2_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_B_NAME","extended fulltext link");
define("L_PARAM_WIZARD_FUNC_F_B_DESC","Prints some <b>text</b> (or field content) with a link to the fulltext. A more general version of the f_f function. This function doesn't use <i>the field</i>.");
define("L_PARAM_WIZARD_FUNC_F_B_PAR0_NAME","link only");
define("L_PARAM_WIZARD_FUNC_F_B_PAR0_DESC","field id (like 'link_only.......') where switch between external and internal item is stored.  (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior).");
define("L_PARAM_WIZARD_FUNC_F_B_PAR0_EXAMPLE","link_only.......");
define("L_PARAM_WIZARD_FUNC_F_B_PAR1_NAME","url_field");
define("L_PARAM_WIZARD_FUNC_F_B_PAR1_DESC","field id if field, where external URL is stored (like hl_href.........)");
define("L_PARAM_WIZARD_FUNC_F_B_PAR1_EXAMPLE","hl_href.........");
define("L_PARAM_WIZARD_FUNC_F_B_PAR2_NAME","redirect");
define("L_PARAM_WIZARD_FUNC_F_B_PAR2_DESC","The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior).");
define("L_PARAM_WIZARD_FUNC_F_B_PAR2_EXAMPLE","http#://www.ecn.cz/articles/solar.shtml");
define("L_PARAM_WIZARD_FUNC_F_B_PAR3_NAME","text");
define("L_PARAM_WIZARD_FUNC_F_B_PAR3_DESC","The text to be surrounded by the link. If this parameter is a field id, the field's content is used, else it is used verbatim");
define("L_PARAM_WIZARD_FUNC_F_B_PAR3_EXAMPLE","");
define("L_PARAM_WIZARD_FUNC_F_B_PAR4_NAME","condition field");
define("L_PARAM_WIZARD_FUNC_F_B_PAR4_DESC","when the specified field hasn't any content, no link is printed, but only the <b>text</b>");
define("L_PARAM_WIZARD_FUNC_F_B_PAR4_EXAMPLE","full_text.......");
define("L_PARAM_WIZARD_FUNC_F_B_PAR5_NAME","tag addition");
define("L_PARAM_WIZARD_FUNC_F_B_PAR5_DESC","additional text to the \"\\<a\\>\" tag");
define("L_PARAM_WIZARD_FUNC_F_B_PAR5_EXAMPLE","target=_blank");
define("L_PARAM_WIZARD_FUNC_F_B_PAR6_NAME","no session id");
define("L_PARAM_WIZARD_FUNC_F_B_PAR6_DESC","If 1, the session id (AA_SL_Session=...) is not added to url");
define("L_PARAM_WIZARD_FUNC_F_B_PAR6_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_T_NAME","print HTML");
define("L_PARAM_WIZARD_FUNC_F_T_DESC","prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too. Obviously this function is used for fultexts.)");
define("L_PARAM_WIZARD_FUNC_F_T_PAR0_NAME","unalias string");
define("L_PARAM_WIZARD_FUNC_F_T_PAR0_DESC","if the <i>unalias string</i> is defined, then the function ignores <i>the field</i> and it rather prints the <i>unalias string</i>. You can of course use any aliases (or fields like {headline.........}) in this string");
define("L_PARAM_WIZARD_FUNC_F_T_PAR0_EXAMPLE","<img src={img_src.........1} _#IMG_WDTH _#IMG_HGHT>");  
define("L_PARAM_WIZARD_FUNC_F_S_NAME","default");
define("L_PARAM_WIZARD_FUNC_F_S_DESC","prints <i>the field</i> or a default value if <i>the field</i> is empty. The same could be done by the f_c function with parameters :::<b>default</b>.");
define("L_PARAM_WIZARD_FUNC_F_S_PAR0_NAME","default");
define("L_PARAM_WIZARD_FUNC_F_S_PAR0_DESC","default value");
define("L_PARAM_WIZARD_FUNC_F_S_PAR0_EXAMPLE","javascript: window.alert('No source url specified')");
define("L_PARAM_WIZARD_FUNC_F_L_NAME","linked field");
define("L_PARAM_WIZARD_FUNC_F_L_DESC","prints <i>the field</i> as a link if the <b>link URL</b> is not NULL, otherwise prints just <i>the field</i>");
define("L_PARAM_WIZARD_FUNC_F_L_PAR0_NAME","link URL");
define("L_PARAM_WIZARD_FUNC_F_L_PAR0_DESC","");
define("L_PARAM_WIZARD_FUNC_F_L_PAR0_EXAMPLE","source_href.....");
define("L_PARAM_WIZARD_FUNC_F_L_PAR1_NAME","tag addition");
define("L_PARAM_WIZARD_FUNC_F_L_PAR1_DESC","additional text to the \"\\<a\\>\" tag");
define("L_PARAM_WIZARD_FUNC_F_L_PAR1_EXAMPLE","target=_blank");
define("L_PARAM_WIZARD_FUNC_F_E_NAME","edit item");
define("L_PARAM_WIZARD_FUNC_F_E_DESC","_#ITEMEDIT used on admin page index.php3 for itemedit url");
define("L_PARAM_WIZARD_FUNC_F_C_NAME","condition");
define("L_PARAM_WIZARD_FUNC_F_C_DESC","This is a very powerful function. It may be used as a better replace of some previous functions. If <b>cond_field</b> = <b>condition</b>, prints <b>begin</b> <i>field</i> <b>end</b>, else prints <b>else</b>. If <b>cond_field</b> is not specified, <i>the field</i> is used. Condition may be reversed (negated) by the \"!\" character at the beginning of it.");
define("L_PARAM_WIZARD_FUNC_F_C_PAR0_NAME","condition");
define("L_PARAM_WIZARD_FUNC_F_C_PAR0_DESC","you may use \"!\" to reverse (negate) the condition");
define("L_PARAM_WIZARD_FUNC_F_C_PAR0_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_C_PAR1_NAME","begin");
define("L_PARAM_WIZARD_FUNC_F_C_PAR1_DESC","text to print before <i>field</i>, if condition is true");
define("L_PARAM_WIZARD_FUNC_F_C_PAR1_EXAMPLE","Yes");
define("L_PARAM_WIZARD_FUNC_F_C_PAR2_NAME","end");
define("L_PARAM_WIZARD_FUNC_F_C_PAR2_DESC","text to print after <i>field</i>, if condition is true");
define("L_PARAM_WIZARD_FUNC_F_C_PAR2_EXAMPLE","");
define("L_PARAM_WIZARD_FUNC_F_C_PAR3_NAME","else");
define("L_PARAM_WIZARD_FUNC_F_C_PAR3_DESC","text to print when condition is not satisfied");
define("L_PARAM_WIZARD_FUNC_F_C_PAR3_EXAMPLE","No");
define("L_PARAM_WIZARD_FUNC_F_C_PAR4_NAME","cond_field");
define("L_PARAM_WIZARD_FUNC_F_C_PAR4_DESC","field to compare with the <b>condition</b> - if not filled, <i>field</i> is used");
define("L_PARAM_WIZARD_FUNC_F_C_PAR4_EXAMPLE","");
define("L_PARAM_WIZARD_FUNC_F_C_PAR5_NAME","skip_the_field");
define("L_PARAM_WIZARD_FUNC_F_C_PAR5_DESC","if set to 1, skip <i>the field</i> (print only <b>begin end</b> or <b>else</b>)");
define("L_PARAM_WIZARD_FUNC_F_C_PAR5_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_C_EXAMPLE0_DESC","This example is usable e.g. for Highlight field - it shows Yes or No depending on the field content");
define("L_PARAM_WIZARD_FUNC_F_C_EXAMPLE0_PARAMS","1:Yes::No::1");
define("L_PARAM_WIZARD_FUNC_F_C_EXAMPLE1_DESC","When e-mail field is filled, prints something like \"Email: email@apc.org\", when it is empty, prints nothing");
define("L_PARAM_WIZARD_FUNC_F_C_EXAMPLE1_PARAMS","!:Email#:&nbsp;");
define("L_PARAM_WIZARD_FUNC_F_C_EXAMPLE2_DESC","Print image height attribute, if <i>the field</i> is filled, nothing otherwise.");
define("L_PARAM_WIZARD_FUNC_F_C_EXAMPLE2_PARAMS","!:height=");
define("L_PARAM_WIZARD_FUNC_F_U_NAME","user function");
define("L_PARAM_WIZARD_FUNC_F_U_DESC","calls a user defined function (see How to create new aliases in <a href='http://apc-aa.sourceforge.net/faq/#aliases'>FAQ</a>)");
define("L_PARAM_WIZARD_FUNC_F_U_PAR0_NAME","function");
define("L_PARAM_WIZARD_FUNC_F_U_PAR0_DESC","name of the function in the include/usr_aliasfnc.php3 file");
define("L_PARAM_WIZARD_FUNC_F_U_PAR0_EXAMPLE","usr_start_end_date_cz");
define("L_PARAM_WIZARD_FUNC_F_U_PAR1_NAME","parameter");
define("L_PARAM_WIZARD_FUNC_F_U_PAR1_DESC","a parameter passed to the function");
define("L_PARAM_WIZARD_FUNC_F_U_PAR1_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_M_NAME","e-mail or link");
define("L_PARAM_WIZARD_FUNC_F_M_DESC","mailto link - prints: <br>\"<b>begin</b>\\<a href=\"(mailto:)<i>the field</i>\" <b>tag adition</b>\\><b>field/text</b>\\</a\\>. If <i>the field</i> is not filled, prints <b>else_field/text</b>.");
define("L_PARAM_WIZARD_FUNC_F_M_PAR0_NAME","begin");
define("L_PARAM_WIZARD_FUNC_F_M_PAR0_DESC","text before the link");
define("L_PARAM_WIZARD_FUNC_F_M_PAR0_EXAMPLE","e-mail");
define("L_PARAM_WIZARD_FUNC_F_M_PAR1_NAME","field/text");
define("L_PARAM_WIZARD_FUNC_F_M_PAR1_DESC","if this parameter is a field id, the field's content is used, else it is used verbatim");
define("L_PARAM_WIZARD_FUNC_F_M_PAR1_EXAMPLE","");
define("L_PARAM_WIZARD_FUNC_F_M_PAR2_NAME","else_field/text");
define("L_PARAM_WIZARD_FUNC_F_M_PAR2_DESC","if <i>the field</i> is empty, only this text (or field content) is printed");
define("L_PARAM_WIZARD_FUNC_F_M_PAR2_EXAMPLE","");
define("L_PARAM_WIZARD_FUNC_F_M_PAR3_NAME","linktype");
define("L_PARAM_WIZARD_FUNC_F_M_PAR3_DESC","mailto / href (default is mailto) - it is possible to use f_m function for links, too - just type 'href' as this parameter");
define("L_PARAM_WIZARD_FUNC_F_M_PAR3_EXAMPLE","href");
define("L_PARAM_WIZARD_FUNC_F_M_PAR4_NAME","tag addition");
define("L_PARAM_WIZARD_FUNC_F_M_PAR4_DESC","additional text to the \"\\<a\\>\" tag");
define("L_PARAM_WIZARD_FUNC_F_M_PAR4_EXAMPLE","target=_blank");
define("L_PARAM_WIZARD_FUNC_F_R_NAME","RSS tag");
define("L_PARAM_WIZARD_FUNC_F_R_DESC","serves for internal purposes of the predefined RSS aliases (e.g. _#RSS_TITL). Adds the RSS 0.91 compliant tags.");
define("L_PARAM_WIZARD_FUNC_F_V_NAME","view");
define("L_PARAM_WIZARD_FUNC_F_V_DESC","allows to manipulate the views. This is a complicated and powerful function, described in <a href=\"../doc/FAQ.html#viewparam\" target=_blank>FAQ</a>, which allows to display any view in place of the alias. It can be used for 'related stories' table or for dislaying content of related slice.");
define("L_PARAM_WIZARD_FUNC_F_V_PAR0_NAME","complex parameter");
define("L_PARAM_WIZARD_FUNC_F_V_PAR0_DESC","this parameter is the same as we use in view.php3 url parameter - see the FAQ");
define("L_PARAM_WIZARD_FUNC_F_V_PAR0_EXAMPLE","vid=4&amp;cmd[23]=v-25");
define("L_PARAM_WIZARD_FUNC_F_Q_NAME","text (blurb) from another slice");
define("L_PARAM_WIZARD_FUNC_F_Q_DESC","prints 'blurb' (piece of text) from another slice, based on a simple condition.<br>If <i>the field</i> (or the field specifield by <b>stringToMatch</b>) in current slice matches the content of <b>fieldToMatch</b> in <b>blurbSliceId</b>, it returns the content of <b>fieldToReturn</b> in <b>blurbSliceId</b>.");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR0_NAME","stringToMatch");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR0_DESC","By default it is <i>the field</i>.  It can be formatted either as the id of a field (headline........) OR as static text.");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR0_EXAMPLE","category........");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR1_NAME","blurbSliceId");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR1_DESC","unpacked slice id of the slice where the blurb text is stored");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR1_EXAMPLE","41415f436f72655f4669656c64732e2e");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR2_NAME","fieldToMatch");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR2_DESC","field id of the field in <b>blurbSliceId</b> where to search for <b>stringToMatch</b>");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR2_EXAMPLE","headline........");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR3_NAME","fieldToReturn");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR3_DESC","field id of the field in <b>blurbSliceId</b> where the blurb text is stored (what to print)");
define("L_PARAM_WIZARD_FUNC_F_Q_PAR3_EXAMPLE","full_text.......");
define("L_PARAM_WIZARD_FUNC_F_X_NAME","transformation");
define("L_PARAM_WIZARD_FUNC_F_X_DESC","Allows to transform the field value to another value.<br>Usage: <b>content_1</b>:<b>return_value_1</b>:<b>content_1</b>:<b>return_value_1</b>:<b>default</b><br>If the content <i>the field</i> is equal to <b>content_1</b> the <b>return_value_1</b> is returned. If the content <i>the field</i> is equal to <b>content_2</b> the <b>return_value_2</b> is returned. If <i>the field is not equal to any <b>content_x</b>, <b>default</b> is returned</i>.");
define("L_PARAM_WIZARD_FUNC_F_X_PAR0_NAME","content");
define("L_PARAM_WIZARD_FUNC_F_X_PAR0_DESC","string for comparison with <i>the field</i> for following return value");
define("L_PARAM_WIZARD_FUNC_F_X_PAR0_EXAMPLE","E");
define("L_PARAM_WIZARD_FUNC_F_X_PAR1_NAME","return value");
define("L_PARAM_WIZARD_FUNC_F_X_PAR1_DESC","string to return if previous content matches - You can use field_id too");
define("L_PARAM_WIZARD_FUNC_F_X_PAR1_EXAMPLE","Environment");
define("L_PARAM_WIZARD_FUNC_F_X_PAR2_NAME","default");
define("L_PARAM_WIZARD_FUNC_F_X_PAR2_DESC","if no content matches, use this string as return value");
define("L_PARAM_WIZARD_FUNC_F_X_PAR3_EXAMPLE","No such option");

define("L_PARAM_WIZARD_FUNC_F_J_NAME","substring with case change");
define("L_PARAM_WIZARD_FUNC_F_J_DESC","prints a part of <i>the field</i>");
define("L_PARAM_WIZARD_FUNC_F_J_PAR0_NAME","start");
define("L_PARAM_WIZARD_FUNC_F_J_PAR0_DESC","position of substring start (0=first, 1=second, -1=last,-2=two from end)");
define("L_PARAM_WIZARD_FUNC_F_J_PAR0_EXAMPLE","0");
define("L_PARAM_WIZARD_FUNC_F_J_PAR1_NAME","count");
define("L_PARAM_WIZARD_FUNC_F_J_PAR1_DESC","count of characters (0=until the end)");
define("L_PARAM_WIZARD_FUNC_F_J_PAR1_EXAMPLE","1");
define("L_PARAM_WIZARD_FUNC_F_J_PAR2_NAME","case");
define("L_PARAM_WIZARD_FUNC_F_J_PAR2_DESC","upper - convert to UPPERCASE, lower - convert to lowercase, first - convert to First Upper; default is don't change");
define("L_PARAM_WIZARD_FUNC_F_J_PAR2_EXAMPLE","");
?>