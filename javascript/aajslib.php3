<?php
/** AA Javascripts library usable on the public pages, just like:
 *  <script type="text/javascript" src="http://actionapps.org/apc-aa/javascript/aajslib.php3"></script>
 *  (replace "http://actionapps.org/apc-aa" with your server and aa path
 *
 *  It includes the scripts, which are based on great prototype.js library
 *  (see http://prototype.conio.net/)
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Honza Malik <honza.malik@ecn.cz>
 *  @copyright Econnect, Honza Malik, December 2006
 *
 */
/*
Copyright (C) 2002 Association for Progressive Communications
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

// include config in order we can define AA_Config variables for javascript
require_once "../include/config.php3";

// headers copied from include/extsess.php3 file
$allowcache_expire = 24*3600; // 1 day
$exp_gmt           = gmdate("D, d M Y H:i:s", time() + $allowcache_expire) . " GMT";
$mod_gmt           = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
header('Expires: '       . $exp_gmt);
header('Last-Modified: ' . $mod_gmt);
header('Cache-Control: public');
header('Cache-Control: max-age=' . $allowcache_expire);
header('Content-Type: application/x-javascript');

$dir = dirname(__FILE__). '/prototype/';

// next lines are copied from prototype/HEADER and prototype/prototype.js files

?>
/*  Prototype JavaScript framework
 *  (c) 2005, 2006 Sam Stephenson <sam@conio.net>
 *
 *  Prototype is freely distributable under the terms of an MIT-style license.
 *  For details, see the Prototype web site: http://prototype.conio.net/
 *
/*--------------------------------------------------------------------------*/

// usage: $(div_id_2).update(AA_Config.loader);
var AA_Config = {
  AA_INSTAL_PATH: '<?php echo AA_INSTAL_PATH; ?>',
  SESS_NAME:      '<?php echo isset($_GET['sess_name']) ? $_GET['sess_name'] : ''; ?>',
  SESS_ID:        '<?php echo isset($_GET['sess_id'])   ? $_GET['sess_id']   : ''; ?>',
  loader:         '<img src="<?php echo AA_INSTAL_PATH; ?>images/loader.gif" border="0" width="16" height="16">',
  icon_new:       '<img src="<?php echo AA_INSTAL_PATH; ?>images/icon_new.gif" border="0" width="17" height="17">',
  icon_close:     '<img src="<?php echo AA_INSTAL_PATH; ?>images/icon_close.gif" border="0" width="17" height="17">'
}

<?php
readfile($dir. 'prototype.js'    ); echo "\n";      // make sure there is new line after each file, in order we do not mix lats and first line of the files
readfile($dir. 'prototip.js'     ); echo "\n";      // make sure there is new line after each file, in order we do not mix lats and first line of the files
readfile($dir. 'control.tabs.js' );
?>


// now AA specific functions
function AA_HtmlToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2) {
    if ( $(div_id_1).visible() ) {
        $(div_id_1).hide();
        $(div_id_2).show();
        $(link_id).update(link_text_2);
    } else {
        $(div_id_2).hide();
        $(div_id_1).show();
        $(link_id).update(link_text_1);
    }
}

function AA_HtmlAjaxToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2, url) {
    if ( $(div_id_1).visible() ) {
        $(div_id_1).hide();
        $(div_id_2).show();
        // not loaded from remote url, yet?
        if ( $(div_id_2).readAttribute('aa_loaded') != '1') {
            $(div_id_2).setAttribute('aa_loaded', '1');
            AA_Ajax(div_id_2, url);
        }
        $(link_id).update(link_text_2);
    } else {
        $(div_id_2).hide();
        $(div_id_1).show();
        $(link_id).update(link_text_1);
    }
}

/** calls AA responder with permissions of current user and displays returned
 *  html code into div_id
 *  Usage:
 *     FrmSelectEasy('from_slice', $slice_array, $from_slice, 'onchange="DisplayAaResponse(\'fieldselection\', \'Get_Fields\', {slice_id:this.options[this.selectedIndex].value})"');
 *     echo '<div id="fieldselection"></div>';
 **/
function DisplayAaResponse(div_id, method, params) {
    $(div_id).update(AA_Config.loader);
    new Ajax.Updater(div_id, AA_Config.AA_INSTAL_PATH + 'central/responder.php?' + AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID + '&command='+ method, {parameters: params});
}


function AA_Ajax(div, url, param) {
    $(div).update(AA_Config.loader);
    new Ajax.Updater(div, url, param);
}


function AA_AjaxInsert(a_obj, form_url) {
    var new_div_id = $(a_obj).identify() + '_ins';
    if ( $(new_div_id) == null ) {
        var new_div  = new Element('div', { 'id': new_div_id});
        $(a_obj).update(AA_Config.icon_close);
        new Insertion.After(a_obj, new_div);
        AA_Ajax(new_div, form_url);
    } else {
        $(a_obj).update(AA_Config.icon_new);
        $(new_div_id).remove();
    }
}

/** Send the form by AJAX and on success displays the ok_html text
 *  @param id        - form id
 *  @param loader_id - id of the html element, where you want to display the loader gif
 *                   - the button itself could be used here (not the form!)
 *  @param ok_html   - what text (html) should be displayed after the success
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function SendAjaxForm(id) {
    $(id).insert(AA_Config.loader);
    $(id).request({encoding:   'windows-1250',
                   onComplete: function(transport){
                       new Insertion.After($(id).up('div'), new Element('div').update(transport.responseText));
                       // close form and display add icon
                       AA_AjaxInsert($(id).up('div').previous(), '');
                   }});
}

/** Send the form by AJAX and on success displays the ok_html text
 *  @param id        - form id
 *  @param loader_id - id of the html element, where you want to display the loader gif
 *                   - the button itself could be used here (not the form!)
 *  @param ok_html   - what text (html) should be displayed after the success
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function AA_AjaxSendObject(id) {
    var code = Form.serialize(id);
    var sb   = $(id).up('div').previous('a').identify().substring(1);
    $(id).insert(AA_Config.loader);

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + '/filler.php3', {
        parameters: code,
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            var items = $H(transport.responseText.evalJSON(true));  // maybe we can remove "true"

            items.each(function(pair) {
                sb_SetValue( $(sb), 'new', pair.value, pair.key);
            });

            //new Insertion.After($(id).up('div'), new Element('div').update(transport.responseText));
            // close form and display add icon
            AA_AjaxInsert($(id).up('div').previous(), '');
        }
    });
}

/** Deprecated
 *  For backward compatibility only. Use $(element).update('text') from
 *  aajslib.php instead.
 */
function SetContent(id,txt) {
    // function replaces html code of a an HTML element (identified by id)
    // by another code
    $(id).update(txt);
}


/** This code comes from: http://www.devpro.it/JSL/JSLOpenSource.js */
/** We used it for encodeURIComponent implementation for older browsers
// (C) Andrea Giammarchi - JSL 1.4b
/* not sure, why this was included, but probably it is nice code
function $JSL(){
    this.charCodeAt=function(str){return $JSL.$charCodeAt(str.charCodeAt(0))};
    this.$charCodeAt=function(i){
        var str=i.toString(16).toUpperCase();
        return str.length<2?"0"+str:str;
    };
    this.encodeURI=function(str){return str.replace(/"/g,"%22").replace(/\\/g,"%5C")};
    this.$encodeURI=function(str){return $JSL.$charCodeAt(str)};
    this.$encodeURIComponent=function(a,b){
        var i=b.charCodeAt(0),str=[];
        if(i<128)		str.push(i);
        else if(i<2048)		str.push(0xC0+(i>>6),0x80+(i&0x3F));
        else if(i<65536)	str.push(0xE0+(i>>12),0x80+(i>>6&0x3F),0x80+(i&0x3F));
        else			str.push(0xF0+(i>>18),0x80+(i>>12&0x3F),0x80+(i>>6&0x3F),0x80+(i&0x3F));
        return "%"+str.map($JSL.$encodeURI).join("%");
    };
};$JSL=new $JSL();
if(typeof(encodeURI)==="undefined"){function encodeURI(str){
    var elm=/([\x00-\x20]|[\x25|\x3C|\x3E|\x5B|\x5D|\x5E|\x60|\x7F]|[\x7B-\x7D]|[\x80-\uFFFF])/g;
    return $JSL.encodeURI(str.toString().replace(elm,$JSL.$encodeURIComponent));
}};
if(typeof(encodeURIComponent)==="undefined"){function encodeURIComponent(str){
    var elm=/([\x23|\x24|\x26|\x2B|\x2C|\x2F|\x3A|\x3B|\x3D|\x3F|\x40])/g;
    return $JSL.encodeURI(encodeURI(str).replace(elm,function(a,b){return "%"+$JSL.charCodeAt(b)}));
}};
*/

/*
function writeProposal(divid, item_id, fid, text) {
    var divtag = document.getElementById(divid);
    var divcontent = divtag.innerHTML;
    SetContent(divid, text);
    convertToForm(divtag, item_id, fid);
    proposeChange(divid, item_id, fid);
    //SetContent(divid, divcontent);
}
*/

/*
function convertToForm(divtag, item_id, fid) {
    var divcontent = divtag.innerHTML;
    if ((divcontent.substring(0,6) == '<input') ||
        (divcontent.substring(0,6) == '<INPUT') ||
    (divcontent.substring(0,9) == '<textarea') ||
    (divcontent.substring(0,9) == '<TEXTAREA')) {
        // already converted to form
        return;
    }
    var divid = divtag.getAttribute('id');
    var contentdiv = divtag.innerHTML;
    var formhtml;
    var perms = (typeof do_change == 'undefined') ? 1 : do_change;

    if ( (contentdiv.length >= 60) || (fid=='edit_note......1')) {
        formhtml = '<textarea cols="80" rows="8" id="i' + divid + '">'+ contentdiv + '</textarea>';
    } else {
        formhtml = '<input type="text" size="80" id="i' + divid + '" value="' + contentdiv +'">';
    }
    formhtml += ' <input type="hidden" id="h' + divid + '" value="' + divtag.innerHTML +'">';
    formhtml += ' <input type="button" value="ULOŽIT ZMÌNU" onclick="proposeChange(\''+divid+'\', \''+item_id+'\', \''+fid+'\', \''+perms+'\')">';
    formhtml += ' <input type="button" value="storno" onclick="SetContent(\''+divid+'\', document.getElementById(\'h'+divid+'\').value)">';
    SetContent(divtag.getAttribute('id'), formhtml);
}

*/

function proposeChange(combi_id, item_id, fid, change) {
    var valdivid   = 'ajaxv_'+combi_id;
    var alias_name = $(valdivid).readAttribute('aaalias');
    if ( typeof do_change == 'undefined') {
        do_change = 1;
    }

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        parameters: { field_id:   fid,
                      item_id:    item_id,
                      alias_name: alias_name,
                      content:    $F('ajaxi_'+combi_id),     // encodeURIComponent(document.getElementById('ajaxi_'+combi_id).value)
                      do_change:  do_change
                     },
        onSuccess: function(transport) {
            if ( change ) {
                $('ajaxv_'+combi_id).update(transport.responseText);  // new value
                $('ajaxch_'+combi_id).update('');
            } else {
                $('ajaxv_'+combi_id).update( $('ajaxh_'+combi_id).value);  // restore old content
                $('ajaxch_'+combi_id).update($('ajaxch_'+combi_id).innerHTML + '<span class="ajax_change">Navrhovaná zmìna: ' + transport.responseText +'</span><br>');
            }
            $(valdivid).setAttribute("aaedit", "0");
        }
    });
}

/** grabs Item_id from aa variable in AA form */
//function GetItemIdFromId4Form(input_id) {
//    // aa[i<item_id>][<field_id>][]
//    var parsed = input_id.split("]");
//    return parsed[0].substring(4);
//}
//
///** Grabs Field id from aa variable in AA form */
//function GetFieldIdFromId4Form(input_id) {
//    // aa[i<item_id>][<field_id>][]
//    var parsed = input_id.split("]");
//    var dirty_field_id = parsed[1].substring(1);
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('__', '..');
//    dirty_field_id = dirty_field_id.replace('._', '..');
//    return dirty_field_id;
//}

function AcceptChange(change_id, divid) {
   new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        parameters: { change_id:  change_id },
        onSuccess: function(transport) {
            $(divid).update(transport.responseText);  // new value
            $('zmena_cmds'+divid).update('');
            $('zmena'+divid).update('');
        }
    });
}

function CancelChanges(item_id, fid, divid) {
   new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        parameters: { cancel_changes: 1,
                      field_id:       fid,
                      item_id:        item_id
                    },
        onSuccess: function(transport) {
            $(divid).update(transport.responseText);  // new value
            $('zmena_cmds'+divid).update('');
            $('zmena'+divid).update('');
        }
    });
}


function isArray(obj) {
   return (obj.constructor.toString().indexOf("Array") != -1);
}

function displayInput(valdivid, item_id, fid) {
    // already editing ?
    switch ($(valdivid).readAttribute('aaedit')) {
       case '1': return;
       case '2': $(valdivid).setAttribute("aaedit", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
                 return;
    }
    var alias_name = $(valdivid).readAttribute('aaalias');

    $(valdivid).update(AA_Config.loader);
    new Ajax.Request( AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        parameters: { field_id:   fid,
                      item_id:    item_id,
                      alias_name: alias_name,
                      aaaction:   'DISPLAYINPUT'
                     },
        onSuccess: function(transport) {
            $(valdivid).update(transport.responseText);  // new value
            $(valdivid).setAttribute('aaedit', '1');
        }
    });
}


function _getInputContent(input_id) {
    var content    = Array();
    var i          = 0;
    var add_empty  = false;

    if ( $(input_id+'[]') != null ) {
        if ( (typeof($F(input_id+'[]')) == 'undefined') || ($F(input_id+'[]')==null)) { // unchecked checkbox is undefined
            content.push('0');
        } else if (isArray($F(input_id+'[]'))) {
            content = content.concat($F(input_id+'[]'));
        } else {
            content.push($F(input_id+'[]'));
        }
    }

    while ( $(input_id+'['+ i +']') != null) {
        if ((typeof($F(input_id+'['+ i +']')) == 'undefined') || ($F(input_id+'['+ i +']')==null) ) { // unchecked checkbox is undefined
            add_empty = true;
        } else if (isArray($F(input_id+'['+ i +']'))) {
            content = content.concat($F(input_id+'['+ i +']'));
        } else {
            content.push($F(input_id+'['+ i +']'));
        }
        i++;
    }
    if ( add_empty && content.count < 1 ) {
        content.push('');  // it is different from push('0') above, because single chbox is 1|0, but multi is value..value|''
    }
    return content;
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function DoChange(input_id) {
    var valdivid   = 'ajaxv_'+input_id;
    var alias_name = $(valdivid).readAttribute('aaalias');
    var content    = _getInputContent(input_id);

    $(valdivid).update(AA_Config.loader);
    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        parameters: { input_id:   input_id,
                      alias_name: alias_name,
                      aaaction:   'DOCHANGE',
                      'content[]':    content    // encodeURIComponent(document.getElementById('ajaxi_'+combi_id).value)
                     },
        onSuccess: function(transport) {
            $('ajaxv_'+input_id).update(transport.responseText);  // new value
            $('ajaxch_'+input_id).update('');
            $(valdivid).setAttribute("aaedit", "0");
        }
    });
}

/** updates database for given iten and field by Ajax
 */
function DoChangeLive(input_id) {

    $$('*[id ^="'+input_id+'"]').invoke('addClassName', 'updating');
    var content    = _getInputContent(input_id);

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
        parameters: { input_id:   input_id,
                      alias_name: '',
                      aaaction:   'DOCHANGE',
                      'content[]':    content    // encodeURIComponent(document.getElementById('ajaxi_'+combi_id).value)
                     },
        onSuccess: function(transport) {
            $$('*[id ^="'+input_id+'"]').invoke('removeClassName', 'updating');
        }
    });
}


/* Cookies */

function SetCookie(name, value) {
   var expires = new Date();
   expires.setTime (expires.getTime() + (1000 * 60 * 60 * 24 * 1)); // a day
   document.cookie = name + "=" + escape(value) +
                      "; expires=" + expires.toGMTString() +
                      "; path=/";
    // + ((expires == null) ? "" : ("; expires=" + expires.toGMTString()))
    // + ((path == null)    ? "" : ("; path=" + path))
    // + ((domain == null)  ? "" : ("; domain=" + domain))
    // + ((secure == true)  ? "; secure" : "");
}

function getCookieVal(offset) {
    var endstr = document.cookie.indexOf(";", offset);
    if (endstr == -1)
        endstr = document.cookie.length;
    return unescape(document.cookie.substring(offset, endstr));
}

function GetCookie(name) {
    var arg = name + "=";
    var alen = arg.length;
    var clen = document.cookie.length;
    var i = 0;
    while (i < clen) {
        var j = i + alen;
        if (document.cookie.substring(i, j) == arg)
        return getCookieVal(j);
        i = document.cookie.indexOf(" ", i) + 1;
        if (i == 0) break;
    }
    return null;
}

function DeleteCookie(name) {
    var exp = new Date();
    exp.setTime (exp.getTime() - 1);
    var cval = GetCookie (name);
    document.cookie = name + "=" + cval + "; expires=" + exp.toGMTString() + "; path=/";
}

function ToggleCookie(name,val) {
    if ( GetCookie(name) != val )
        SetCookie(name,val);
    else
        DeleteCookie(name);
}

/* ----------------------------------------------------------
prototypeUtils.js from http://jehiah.com/
Licensed under Creative Commons.
version 1.0 December 20 2005

Contains:
+ Form.Element.setValue()
+ unpackToForm()

*/

/* Form.Element.setValue("fieldname/id","valueToSet") */
Form.Element.setValue = function(element,newValue) {
    element_id = element;
    element = $(element);
    if (!element){element = document.getElementsByName(element_id)[0];}
    if (!element){return false;}
    var method = element.tagName.toLowerCase();
    var parameter = Form.Element.SetSerializers[method](element,newValue);
}

Form.Element.SetSerializers = {
  input: function(element,newValue) {
    switch (element.type.toLowerCase()) {
      case 'submit':
      case 'hidden':
      case 'password':
      case 'text':
        return Form.Element.SetSerializers.textarea(element,newValue);
      case 'checkbox':
      case 'radio':
        return Form.Element.SetSerializers.inputSelector(element,newValue);
    }
    return false;
  },

  inputSelector: function(element,newValue) {
    fields = document.getElementsByName(element.name);
    for (var i=0;i<fields.length;i++){
      if (fields[i].value == newValue){
        fields[i].checked = true;
      }
    }
  },

  textarea: function(element,newValue) {
    element.value = newValue;
  },

  select: function(element,newValue) {
    var value = '', opt, index = element.selectedIndex;
    for (var i=0;i< element.options.length;i++){
      if (element.options[i].value == newValue){
        element.selectedIndex = i;
        return true;
      }
    }
  }
}

function unpackToForm(data){
   for (i in data){
     Form.Element.setValue(i,data[i].toString());
   }
}


