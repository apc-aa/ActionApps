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

// switch current item in gallery
function AA_GalleryGoto(photo_div, viewid, sitemid, galeryid, thumb_id) {
    $(photo_div).show();
    AA_Ajax(photo_div, AA_Config.AA_INSTAL_PATH + 'view.php3?vid=' + viewid + '&cmd[' + viewid + ']=x-' + viewid + '-' + sitemid + '&convertto=utf-8&als[GALERYID]=' + galeryid);
    $$('div.switcher img.active').invoke('removeClassName', 'active');
    if ($(thumb_id)) {
        $(thumb_id).addClassName('active');
        $(thumb_id).parentNode.scrollTop = $(thumb_id).offsetTop - $(thumb_id).parentNode.offsetTop - 50;
    }
}

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

function AA_HtmlToggleCss(link_id, link_text_1, link_text_2, selector) {
    if ( $(link_id).hasClassName('is-on')) {
        $$(selector).invoke('hide');
        $(link_id).update(link_text_1);
        $(link_id).toggleClassName('is-on');
    } else {
        $$(selector).invoke('show');
        $(link_id).update(link_text_2);
        $(link_id).toggleClassName('is-on');
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

/** selector_update is optional and is good for updating table rows, where we want to show/hide tr, but update td */
function AA_HtmlAjaxToggleCss(link_id, link_text_1, link_text_2, selector_hide, url, selector_update) {
    if ( $(link_id).hasClassName('is-on')) {
        $$(selector_hide).invoke('hide');
        $(link_id).update(link_text_1);
        $(link_id).toggleClassName('is-on');
    } else {
        $$(selector_hide).invoke('show');
        $(link_id).toggleClassName('is-on');
        // not loaded from remote url, yet?
        if ( !$(link_id).hasClassName('aa-loaded')) {
            $(link_id).addClassName('aa-loaded');
            AA_AjaxCss(selector_update ? selector_update : selector_hide, url);
        }
        $(link_id).update(link_text_2);
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
    var sess = (AA_Config.SESS_NAME != '') ? AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID : 'AA_CP_Session=' + GetCookie('AA_Sess');
    new Ajax.Updater(div_id, AA_Config.AA_INSTAL_PATH + 'central/responder.php?' + sess + '&command='+ method, {parameters: params});
}

function AA_Response(method, resp_params, ok_func, err_func) {
    var sess  = (AA_Config.SESS_NAME != '') ? AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID : 'AA_CP_Session=' + GetCookie('AA_Sess');
    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'central/responder.php?' + sess + '&command='+ method, {
         parameters: resp_params,
         onSuccess: function(transport) {
             if( transport.responseText.substring(0,5) == 'Error' ) {
                 err_func(transport.responseText);
             } else {
                 ok_func(transport.responseText);
             }
         }
    });
}

// function displayInput(valdivid, item_id, fid) {
//     // already editing ?
//     switch ($(valdivid).readAttribute('data-aa-edited')) {
//        case '1': return;
//        case '2': $(valdivid).setAttribute("data-aa-edited", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
//                  return;
//     }
//
//     // store current content
//     $(valdivid).setAttribute("data-aa-oldval", $(valdivid).innerHTML);
//
//     var alias_name = $(valdivid).readAttribute('data-aa-alias');
//
//     $(valdivid).update(AA_Config.loader);
//     new Ajax.Request( AA_Config.AA_INSTAL_PATH + 'misc/proposefieldchange.php', {
//         parameters: { field_id:   fid,
//                       item_id:    item_id,
//                       alias_name: alias_name,
//                       aaaction:   'DISPLAYINPUT'
//                      },
//         onSuccess: function(transport) {
//             $(valdivid).update(transport.responseText);  // new value
//             $(valdivid).setAttribute('data-aa-edited', '1');
//         }
//     });
// }

function displayInput(valdivid, item_id, fid) {
    // already editing ?
    switch ($(valdivid).readAttribute('data-aa-edited')) {
       case '1': return;
       case '2': $(valdivid).setAttribute("data-aa-edited", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
                 return;
    }

    // store current content
    $(valdivid).setAttribute("data-aa-oldval", $(valdivid).innerHTML);

    $(valdivid).update(AA_Config.loader);
    AA_Response('Get_Widget', { field_id: fid, item_id: item_id }, function(responseText) {
            $(valdivid).update(responseText);  // new value
            $(valdivid).setAttribute('data-aa-edited', '1');
        }
    );
}

/** return back old value - CANCEL pressed on AJAX widget */
function DisplayInputBack(input_id) {
    var valdivid = 'ajaxv_'+input_id
    $(valdivid).update($(valdivid).readAttribute('data-aa-oldval'));
    $(valdivid).setAttribute('data-aa-edited', '2');
}

function AA_Ajax(div, url, param) {
    $(div).update(AA_Config.loader);
    new Ajax.Updater(div, url, param);
}

function AA_AjaxCss(selector, url, param) {
    $$(selector).invoke('update', AA_Config.loader);
    new Ajax.Request(url, {
        onSuccess: function(transport) {
            $$(selector).invoke('update', transport.responseText);  // new value
        }
    });
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

/** Send the form by AJAX and on success refreshes the content of page
 *  @param id        - form id
 *  @param refresh   - id of the html element, which you want to refresh. 
 *                   - Such element must have data-aa-url attributes
 *  @param ok_html   - function to call after the page update
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function SendAjaxForm(id, refresh, ok_func) {
    $(id).insert(AA_Config.loader);
    $(id).request({encoding:   'windows-1250',
                   onComplete: function(transport){
                       if (typeof refresh != "undefined") {
                           AA_Refresh(refresh,false,ok_func);
                       } else {
                           new Insertion.After($(id).up('div'), new Element('div').update(transport.responseText));
                           // close form and display add icon
                           AA_AjaxInsert($(id).up('div').previous(), '');
                       }
                   }});
}

/** Send the form by AJAX and on success displays the ok_html text
 *  @param id        - form id
 *  @param loader_id - id of the html element, where you want to display the loader gif
 *                   - the button itself could be used here (not the form!)
 *  @param ok_html   - what text (html) should be displayed after the success
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function AA_SendForm(id, refresh, ok_func) {
    $(id).insert(AA_Config.loader);
    $(id).request({onComplete: function(transport){
                       if (typeof refresh != "undefined") {
                           AA_Refresh(refresh,false,ok_func);
                       }
                   }});
}

/** Sends the form and replaces the form with the response
 *  Polls ussage - @see: http://actionapps.org/en/Polls_Module#AJAX_Polls_Design
 */
function AA_AjaxSendForm(form_id, url) {
    var filler_url = url || 'modules/polls/poll.php3';  // by default it is used for Polls
    if (filler_url.charAt(0)!='/') {
        filler_url = AA_Config.AA_INSTAL_PATH + filler_url;   // AA link
    }
    var code       = Form.serialize(form_id);
    $(form_id).insert(AA_Config.loader);

    new Ajax.Request(filler_url, {
        parameters: code,
        onSuccess: function(transport) {
            var res = transport.responseText;
            if (res.charAt(0) == '{') {
                var items = res.evalJSON(true);  // maybe we can remove "true"
                for (var i in items) {
                    res = items[i];
                    break;
                }
            }
            $(form_id).update(res);
        }
    });
}

function AA_Refresh(id,inside,ok_func) {
    $(id).update(AA_Config.loader);
    new Ajax.Request($(id).readAttribute('data-aa-url'), {
        onSuccess: function(transport) {
            if (inside) {
                $(id).update(transport.responseText);
            } else {
                $(id).replace(transport.responseText);
            }
            if (typeof ok_func != "undefined") {
                ok_func();
            }
        }
    });
}

/** Send the form by AJAX and on success displays the ok_html text
 *  @param id        - form id
 *  @param loader_id - id of the html element, where you want to display the loader gif
 *                   - the button itself could be used here (not the form!)
 *  @param ok_html   - what text (html) should be displayed after the success
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function AA_AjaxSendAddForm(id) {
    var code = Form.serialize(id);
    var sb   = $(id).up('div').previous('a').identify().substring(1);
    $(id).insert(AA_Config.loader);

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'filler.php3', {
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

/** This function replaces the older one - proposeChange
 *  The main change is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetAjax(id) {
    var valdivid   = 'ajaxv_' + id;
    var code = Form.serialize(valdivid);
    var sess  = (AA_Config.SESS_NAME != '') ? AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID : 'AA_CP_Session=' + GetCookie('AA_Sess');
    var alias_name = $(valdivid).readAttribute('data-aa-alias');
    $(valdivid).insert(AA_Config.loader);

    code += '&' + sess + '&inline=1&ret_code_enc='+alias_name;

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'filler.php3', {
        parameters: code,
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            AA_ReloadAjaxResponse(id, transport.responseText)
        }
    });
}

/** Closes the ajax for after file upload
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_ReloadAjaxResponse(id, responseText) {
    var valdivid   = 'ajaxv_' + id;
    var items = responseText.evalJSON(true);  // maybe we can remove "true"
    var res;
    for (var i in items) {
        res = items[i];
        $(valdivid).update(res.length>0 ? res : '--');
        break;
    }
    $(valdivid).setAttribute("data-aa-edited", "0");
    var succes_function = $(valdivid).getAttribute('data-aa-onsuccess');
    if (succes_function) {
        eval(succes_function);
    }
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetLive(id) {
    AA_StateChange(id, 'updating');

    var valdivid   = 'widget-' + id;
    var sess  = (AA_Config.SESS_NAME != '') ? AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID : 'AA_CP_Session=' + GetCookie('AA_Sess');

    var code = Form.serialize(valdivid);
    code += '&' + sess + '&inline=1';  // do not send us whole page as result

    new Ajax.Request(AA_Config.AA_INSTAL_PATH + 'filler.php3', {
        parameters: code,
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            AA_StateChange(id, 'normal');
        }
    });
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_StateChange(id, state) {
    var outstyle = {};
    var icoimg   = '';
    var elems    = $$('*[id ^="'+id+'"]');

    switch (state) {
    case 'dirty':
        elems.invoke('removeClassName', 'normal');
        elems.invoke('removeClassName', 'updating');
        outstyle = {'outlineColor': '#E4B600', 'outlineWidth': '1px', 'outlineStyle': 'solid'};
        icoimg   = 'images/save.png';
        break;
    case 'updating':
        elems.invoke('removeClassName', 'dirty');
        elems.invoke('removeClassName', 'normal');
        outstyle = {'outlineColor': '#E4B600', 'outlineWidth': '1px', 'outlineStyle': 'dashed'};
        icoimg   = 'images/loader.gif';
        break;
    case 'normal':
    default:
        elems.invoke('removeClassName', 'dirty');
        elems.invoke('removeClassName', 'updating');
        outstyle = {'outline': 'none'};
        icoimg   = 'images/px.gix';
        break;
    }
    elems.invoke('addClassName', state);
    $$('select[id ^="'+id+'"]').invoke('setStyle', outstyle);
    $$('input[id ^="'+id+'"]').invoke('setStyle', outstyle);
    $$('textarea[id ^="'+id+'"]').invoke('setStyle', outstyle);
    $$('img.'+id+'ico').each(function(img) {img.setAttribute('src', AA_Config.AA_INSTAL_PATH+icoimg); });
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

function proposeChange(combi_id, item_id, fid, change) {
    var valdivid   = 'ajaxv_'+combi_id;
    var alias_name = $(valdivid).readAttribute('data-aa-alias');
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
                $('ajaxch_'+combi_id).update($('ajaxch_'+combi_id).innerHTML + '<span class="ajax_change">Navrhovan� zm�na: ' + transport.responseText +'</span><br>');
            }
            $(valdivid).setAttribute("data-aa-edited", "0");
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


/** rotates the element - hide/show .rot-hide, add/remove class "active" for .rot-active
 *  called as:
 * <div id="mydiv">
 *   <span class="rot-hide">A</span>
 *   <span class="rot-hide">B</span>
 *   <span class="rot-hide">C</span>
 * </div>
 * <script>
 *   AA_Rotator('mydiv', 2000, 3);
 * </script>
 */
function AA_Rotator(id, interval, max) {
    // Check to see if the rotators-set  has been initialized
    if ( typeof AA_Rotator.rotators == 'undefined' ) {
        AA_Rotator.rotators = {};
    }

    if ( typeof AA_Rotator.rotators[id] == 'undefined' ) {
        AA_Rotator.rotators[id]       = {"index": 0, "max": max };
        AA_Rotator.rotators[id].timer = setInterval(function () {AA_Rotator(id)},interval);
    }

    $$('#' + id + ' .rot-hide').invoke('hide');
    $$('#' + id + ' .rot-hide:nth-child('+(AA_Rotator.rotators[id].index+1)+')').invoke('show');

    $$('#' + id + ' .rot-active').invoke('removeClassName', 'active');
    $$('#' + id + ' .rot-active:nth-child('+(AA_Rotator.rotators[id].index+1)+')').invoke('addClassName', 'active');

    AA_Rotator.rotators[id].index = (AA_Rotator.rotators[id].index+1)% AA_Rotator.rotators[id].max;
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

/**
 * Event.simulate(@element, eventName[, options]) -> Element
 *
 * - @element: element to fire event on
 * - eventName: name of event to fire (only MouseEvents and HTMLEvents interfaces are supported)
 * - options: optional object to fine-tune event properties - pointerX, pointerY, ctrlKey, etc.
 *
 *    $('foo').simulate('click'); // => fires "click" event on an element with id=foo
 *
 **/
(function(){

  var eventMatchers = {
    'HTMLEvents': /^(?:load|unload|abort|error|select|change|submit|reset|focus|blur|resize|scroll)$/,
    'MouseEvents': /^(?:click|mouse(?:down|up|over|move|out))$/
  }
  var defaultOptions = {
    pointerX: 0,
    pointerY: 0,
    button: 0,
    ctrlKey: false,
    altKey: false,
    shiftKey: false,
    metaKey: false,
    bubbles: true,
    cancelable: true
  }

  Event.simulate = function(element, eventName) {
    var options = Object.extend(defaultOptions, arguments[2] || { });
    var oEvent, eventType = null;

    element = $(element);

    for (var name in eventMatchers) {
      if (eventMatchers[name].test(eventName)) { eventType = name; break; }
    }

    if (!eventType)
      throw new SyntaxError('Only HTMLEvents and MouseEvents interfaces are supported');

    if (document.createEvent) {
      oEvent = document.createEvent(eventType);
      if (eventType == 'HTMLEvents') {
        oEvent.initEvent(eventName, options.bubbles, options.cancelable);
      }
      else {
        oEvent.initMouseEvent(eventName, options.bubbles, options.cancelable, document.defaultView,
          options.button, options.pointerX, options.pointerY, options.pointerX, options.pointerY,
          options.ctrlKey, options.altKey, options.shiftKey, options.metaKey, options.button, element);
      }
      element.dispatchEvent(oEvent);
    }
    else {
      options.clientX = options.pointerX;
      options.clientY = options.pointerY;
      oEvent = Object.extend(document.createEventObject(), options);
      element.fireEvent('on' + eventName, oEvent);
    }
    return element;
  }

  Element.addMethods({ simulate: Event.simulate });
})()
