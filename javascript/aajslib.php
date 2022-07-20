<?php
/** @deprecated - use aajslib-legacy.min.js directly or even better aajslib-jquery.min.js with jQuery
 *  This file is used also to generate aajslib-legacy.js (manualy through http://.../apc-aa/javascript/aajslib.php )
 *  which is then compressed to aajslib-legacy.min.js by UglifyJS
 *
 *  AA Javascripts library usable on the public pages, just like:
 *  <script src="https://actionapps.org/apc-aa/javascript/aajslib.php"></script>
 *  (replace "https://actionapps.org/apc-aa" with your server and aa path
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
https://www.apc.org/

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
require_once __DIR__."/../include/config.php3";

// headers copied from include/extsess.php3 file
$allowcache_expire = 24*3600; // 1 day
$exp_gmt           = gmdate("D, d M Y H:i:s", time() + $allowcache_expire) . " GMT";
$mod_gmt           = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
header('Expires: '       . $exp_gmt);
header('Last-Modified: ' . $mod_gmt);
header('Cache-Control: public');
header('Cache-Control: max-age=' . $allowcache_expire);
header('Content-Type: application/javascript');

$dir = __DIR__. '/prototype/';

// next lines are copied from prototype/HEADER and prototype/prototype.js files

?>
/*  Prototype JavaScript framework
 *  (c) 2005, 2006 Sam Stephenson <sam@conio.net>
 *
 *  Prototype is freely distributable under the terms of an MIT-style license.
 *  For details, see the Prototype web site: http://prototype.conio.net/
 *
/*--------------------------------------------------------------------------*/

if (typeof window.AA_GetConf === 'undefined') {
    window.AA_GetConf = function(prop) {
        if (typeof AA_GetConf.aa_install_path == 'undefined') {
            var src = document.querySelector('script[src*="javascript/aajslib"]').getAttribute('src');
            AA_GetConf.aa_install_path = src.replace(/javascript\/aajslib.*$/, '');
        }
        switch (prop) {
            case 'path':
                return AA_GetConf.aa_install_path;
            case 'loader':
                return '<img src="' + AA_GetConf.aa_install_path + 'images/loader.gif" border=0 width=16 height=16>';
        }
    }
}

var AA_Config = {
    get AA_INSTAL_PATH() { return AA_GetConf("path") },
    get loader()         { return AA_GetConf("loader") },
    get icon_new()       { return AA_GetConf("loader").replace("loader.gif", "icon_new.gif") },
    get icon_close()     { return AA_GetConf("loader").replace("loader.gif", "icon_close.gif") }
}

<?php
readfile($dir. 'prototype.js'    ); echo "\n";      // make sure there is new line after each file, in order we do not mix lats and first line of the files
readfile($dir. 'prototip.js'     ); echo "\n";      // make sure there is new line after each file, in order we do not mix lats and first line of the files
readfile($dir. 'control.tabs.js' );
?>

// polyfills - will be removed when the incompatible browsers will not be supported by its creator
if (window.NodeList && !NodeList.prototype.forEach) {
    NodeList.prototype.forEach = Array.prototype.forEach;
}

// switch current item in gallery
function AA_GalleryGoto(photo_div, viewid, sitemid, galeryid, thumb_id) {
    $(photo_div).show();
    AA_Ajax(photo_div, AA_GetConf('path') + 'view.php3?vid=' + viewid + '&cmd[' + viewid + ']=x-' + viewid + '-' + sitemid + '&convertto=utf-8&als[GALERYID]=' + galeryid);
    $$('div.switcher img.active').invoke('removeClassName', 'active');
    if ($(thumb_id)) {
        $(thumb_id).addClassName('active');
        $(thumb_id).parentNode.scrollTop = $(thumb_id).offsetTop - $(thumb_id).parentNode.offsetTop - 50;
    }
}

// now AA specific functions
function AA_HtmlToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2, persist_id) {
    if ( $(div_id_1).visible() ) {
        $(div_id_1).hide();
        $(div_id_2).show();
        $(link_id).update(link_text_2);
    } else {
        $(div_id_2).hide();
        $(div_id_1).show();
        $(link_id).update(link_text_1);
    }
    if (persist_id) {
       localStorage[persist_id] = ($(div_id_1).visible() ? '1' : '2');
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
            AA_Ajax(div_id_2, url, {evalScripts: true });
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
 *     FrmSelectEasy('from_slice', $slice_array, $from_slice, 'onchange="DisplayAaResponse(\'fieldselection\', \'Get_Fields\', {slice_id:this.value})"');
 *     echo '<div id="fieldselection"></div>';
 **/
function DisplayAaResponse(div_id, method, params) {
    $(div_id).update(AA_GetConf('loader'));
    new Ajax.Updater(div_id, AA_GetConf('path') + 'central/responder.php?command='+ method, {parameters: params});
}

function AA_Response(method, resp_params, ok_func, err_func) {
    new Ajax.Request(AA_GetConf('path') + 'central/responder.php?command='+ method, {
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

function displayInput(valdivid, item_id, fid, widget_type, widget_properties, context) {
    // already editing ?
    switch ($(valdivid).readAttribute('data-aa-edited')) {
       case '1': return;
       //case '2': $(valdivid).setAttribute("data-aa-edited", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
       //          return;
    }

    // store current content
    $(valdivid).setAttribute("data-aa-oldval", $(valdivid).innerHTML);
    var width = $(valdivid).getWidth();

    $(valdivid).update(AA_GetConf('loader'));
    AA_Response('Get_Widget', { "field_id": fid, "item_id": item_id, "widget_type": widget_type, "widget_properties": widget_properties, context: context}, function(responseText) {
            // var style = 'position:absolute;background:#f6f6f6;padding:10px;border:solid 1px #c7c7c7;box-shadow:0 0 8px rgba(0,0,0,0.19);z-index:100;';
            if (width>200) {
                responseText = responseText.replace('class="ajax_widget aa-widget aa-ajax-open"', 'class="ajax_widget aa-widget aa-ajax-open" style="width:'+width+'px;"');
            }
            $(valdivid).update(responseText);
            // $(valdivid).update(responseText.replace('class=ajax_widget', 'class=ajax_widget style="'+style+'"'));  // new value
            $(valdivid).setAttribute('data-aa-edited', '1');
            var firstElement = $(valdivid).select('input','select', 'textarea')[0];
            if(firstElement != null) {
                firstElement.activate();
                $(firstElement).on('keydown', function(event) {
                  switch (event.keyCode) {
                     // done by type submit case 13: $(this).nextAll('input.save-button').click();   break; // Enter
                     case Event.KEY_ESC: $(valdivid).select('input.cancel-button')[0].simulate('click'); break; // Esc
                  }
               });
            }
    });
}

/** return back old value - CANCEL pressed on AJAX widget */
function DisplayInputBack(input_id) {
    var valdivid = 'ajaxv_'+input_id
    $(valdivid).update($(valdivid).readAttribute('data-aa-oldval'));
    // $(valdivid).setAttribute('data-aa-edited', '2');    // no longer needed  state 2 - we use stopPropagation() in widget Ajax
    $(valdivid).setAttribute('data-aa-edited', '0');
}

function AA_Ajax(div, url, param, onload) {
    $(div).update(AA_GetConf('loader'));
    if( onload && typeof onload === "function" ) {
        if (param && typeof param === 'object' ) {
            param['onSuccess'] = onload;
        } else {
            param = {onSuccess: onload};
        }
    }
    new Ajax.Updater(div, url, param);
}

function AA_AjaxCss(selector, url, param) {
    $$(selector).invoke('update', AA_GetConf('loader'));
    new Ajax.Request(url, {
        onSuccess: function(transport) {
            $$(selector).invoke('update', transport.responseText);  // new value
        }
    });
}

function AA_InsertHtml(into_id, code) {
   $(into_id).insert(code);
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
    $(id).insert(AA_GetConf('loader'));
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
 *  @param refresh   - id of the html element, which you want to refresh.
 *                   - Such element must have data-aa-url attributes
 *  @param ok_html   - function to call after the page update
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function AA_SendForm(id, refresh, ok_func) {
    $(id).insert(AA_GetConf('loader'));
    $(id).request({onComplete: function(transport){
                       if (typeof refresh != "undefined") {
                           AA_Refresh(refresh,false,ok_func);
                       }
                   }});
}

/** Sends the form and replaces the form with the response
 *  Polls usage - @see: https://actionapps.org/en/Polls_Module#AJAX_Polls_Design
 */
function AA_AjaxSendForm(form_id, url) {
    var filler_url = url || 'modules/polls/poll.php3';  // by default it is used for Polls
    if (filler_url.charAt(0)!='/') {
        filler_url = AA_GetConf('path') + filler_url;   // AA link
    }
    var code       = Form.serialize(form_id);
    $(form_id).insert(AA_GetConf('loader'));

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

/** Refreshes the id using the data-aa-url attribute.
 *  If that attribute is not present, finds the first up in the DOM
 */
function AA_Refresh(id,inside,ok_func) {
    var refresh_id  = id || this;
    var refresh_url = $(id).readAttribute('data-aa-url');
    if (!refresh_url) {
        refresh_id  = $(id).up('*[data-aa-url]');
        refresh_url = $(refresh_id).readAttribute('data-aa-url');
    }
    $(refresh_id).update(AA_GetConf('loader'));

    new Ajax.Request(refresh_url, {
        onSuccess: function(transport) {
            if (inside) {
                $(refresh_id).update(transport.responseText);
            } else {
                $(refresh_id).replace(transport.responseText);
            }
            if (typeof ok_func != "undefined") {
                ok_func();
            }
        }
    });
}

/*return first element up the DOM tree matching css */
function AA_up(el, css) { return $(el).up(css); }

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
    $(id).insert(AA_GetConf('loader'));

    new Ajax.Request(AA_GetConf('path') + 'filler.php3', {
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
    $(valdivid).querySelectorAll('.CodeMirror').forEach(function(el) { el.CodeMirror.save()});
    var code = Form.serialize(valdivid);
    var alias_name = $(valdivid).readAttribute('data-aa-alias');
    $(valdivid).insert(AA_GetConf('loader'));

    code += '&inline=1&ret_code_enc='+alias_name;

    new Ajax.Request(AA_GetConf('path') + 'filler.php3', {
        parameters: code,
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            AA_ReloadAjaxResponse(id, transport.responseText)
        }
    });
}

/** Closes the ajax for after file upload
 *  The main change is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_ReloadAjaxResponse(id, responseText) {
    var valdivid   = 'ajaxv_' + id;
    var items  = (typeof responseText === 'string') ? responseText.evalJSON(true) : responseText;  // maybe we can remove "true"
    var res;
    for (var i in items) {
        if (i == 'report') {
          continue;
        }
        res = items[i];
        $(valdivid).update(res.length>0 ? res : '--');
        break;
    }
    $(valdivid).setAttribute("data-aa-edited", "0");
    var succes_function = $(valdivid).getAttribute('data-aa-onsuccess');
    if (succes_function) {
        var func = function() { eval(succes_function) };
        // we use call just to make right this object in called function
        func.call($(valdivid));
    }
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetLive(id, liveinput, fnc) {
    AA_StateChange(id, 'updating');

    var valdivid   = 'widget-' + id;

    // browser supports HTML5 validation
    if (typeof liveinput.checkValidity == 'function') {
        if (!liveinput.checkValidity()) {
            AA_StateChange(id, 'invalid');
            return;
        }
    }

    var code = Form.serialize(valdivid);
    code += '&inline=1';  // do not send us whole page as result

    new Ajax.Request(AA_GetConf('path') + 'filler.php3', {
        parameters: code,
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            AA_StateChange(id, 'normal');
            if (typeof fnc == 'function') {
                fnc();
            }
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
        icoimg   = 'images/px.gif';
        break;
    }
    elems.invoke('addClassName', state);
    $$('select[id ^="'+id+'"]').invoke('setStyle', outstyle);
    $$('input[id ^="'+id+'"]').invoke('setStyle', outstyle);
    $$('textarea[id ^="'+id+'"]').invoke('setStyle', outstyle);
    $$('img.'+id+'ico').each(function(img) {img.setAttribute('src', AA_GetConf('path')+icoimg); });
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

    new Ajax.Request(AA_GetConf('path') + 'misc/proposefieldchange.php', {
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
   new Ajax.Request(AA_GetConf('path') + 'misc/proposefieldchange.php', {
        parameters: { change_id:  change_id },
        onSuccess: function(transport) {
            $(divid).update(transport.responseText);  // new value
            $('zmena_cmds'+divid).update('');
            $('zmena'+divid).update('');
        }
    });
}

function CancelChanges(item_id, fid, divid) {
   new Ajax.Request(AA_GetConf('path') + 'misc/proposefieldchange.php', {
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
function AA_Rotator(id, interval, max, speed, effect) {
    if (max<2) { return; }

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

/** used by {livesearch:...}
 *  internal - could be changed
 */
function AA__liveSearch(id, viewparam, deflt) {
    val    = $$('#' +id+ ' input.itemsearch')[0].value;
    if (!val.length && deflt.length) {
        val=deflt;
    }
    fnc = function(t,v,q) { AA_AjaxCss(t, AA_GetConf('path') + 'view.php3?vid=' + viewparam.replace('AA_LS_QUERY', q));}
    if (AA__liveSearch.timer) {
       if (AA__liveSearch.searchval != val) {
           clearTimeout(AA__liveSearch.timer);
           AA__liveSearch.searchval = val;
           AA__liveSearch.timer     = setTimeout(fnc,200, '#' +id+ ' .itemgroup', viewparam, val);
       }
    } else {
       AA__liveSearch.timer = setTimeout(fnc,200, '#' +id+ ' .itemgroup', viewparam, val);
       AA__liveSearch.searchval = val;
    }
}

/* text - string or url (begins with '/')
 * type - err | ok | info | [text]
 */
function AA_Message(text, type) {
    var attrs = {'id': 'aa-message-box', 'onclick': '$(this).hide()'};
    switch(type) {
      case 'err':  attrs['class'] = 'aa-err';  break;
      case 'ok':   attrs['class'] = 'aa-ok'; break;
      case 'info': attrs['class'] = 'aa-info'; break;
      default:     attrs['class'] = 'aa-text';
                   type = 'text';
    }
    if (text.charAt(0)=='/') {
        AA__systemDiv('aa-message-box', attrs, '<div id="aa-message-box-in"></div>');
        AA_Ajax('aa-message-box-in', text);
    } else {
        AA__systemDiv('aa-message-box', attrs, '<div id="aa-message-box-in">'+text+'</div>');
    }
    if (type != 'text') {
        setTimeout(function() { $('aa-message-box').hide(); }, 5000);
    }
}

function AA__systemDiv(id, attrs, text) {
    var box = $(id);
    if (!box) {
        box = new Element('div', attrs);
        document.body.appendChild(box);
    }
    box.update(text);
    if (!text) {
        box.hide();
    } else {
        if (!$('aa-bottom-toolbar')) {
            AA_Toolbar(''); // we need toolbar defined, we need it to test styles
            if (AA_GetStyle('aa-bottom-toolbar', 'position')!='fixed') {
                AA_LoadCss(AA_GetConf('path') + 'css/aa-system.css');
            }
        }
        box.show();
    }
}

function AA_Toolbar(text) {
    AA__systemDiv('aa-bottom-toolbar', {'id': 'aa-bottom-toolbar'}, text);
}

function AA_LoadJs(condition, callback, url, sync) {
    if (condition && ((condition!='load_once') || (url && document.querySelector('script[src="'+url+'"]')))) {
        if (callback) {
            callback();
        }
    } else {
        var script   = document.createElement("script")
        script.type  = "text/javascript";
        if (sync) {
            script.async = false;
        }

        if (script.readyState) { //IE
            script.onreadystatechange = function () {
                if (script.readyState == "loaded" || script.readyState == "complete") {
                    script.onreadystatechange = null;
                    if (callback) {
                        callback()
                    }
                }
            };
        } else { //Others
            script.onload = function () {
                if (callback) {
                    callback()
                }
            };
        }

        script.src = url;
        document.getElementsByTagName("head")[0].appendChild(script);
    }
}

/** internal AA function for function call after all the scripts are loaded */
function AA_IsLoaded() {
    if (AA_IsLoaded.cnt) {
        --AA_IsLoaded.cnt; // count to zero
    }
    if (!AA_IsLoaded.cnt && AA_IsLoaded.callback) {
        AA_IsLoaded.callback();
        AA_IsLoaded.callback = undefined;       // run it just once
    }
}


function AA_LoadCss(url) {
    var link = document.querySelector('link[href="'+url+'"]');
    if (url && !link) {
        link  = document.createElement('link');
        link.type = 'text/css';
        link.rel  = 'stylesheet';
        link.href = url;
        document.getElementsByTagName('head')[0].appendChild(link);
    }
    return link;
}

/* function to check the computed value of a style element */
function AA_GetStyle(id, name) {
    var element = document.getElementById(id);
    return element.currentStyle ? element.currentStyle[name] : window.getComputedStyle ? window.getComputedStyle(element, null).getPropertyValue(name) : null;
}

function AA_SaveEditors() {
  var params = {'inline':1};

  for(var i in CKEDITOR.instances) {   // for each
    if (CKEDITOR.instances[i].checkDirty()) {
        params['aa[u'+$(i).readAttribute('data-aa-id')+']['+$(i).readAttribute('data-aa-field')+'][0]'] = CKEDITOR.instances[i].getData();
        params['aa[u'+$(i).readAttribute('data-aa-id')+']['+$(i).readAttribute('data-aa-field')+'][flag]'] = 1;
    }
  }

  new Ajax.Request(AA_GetConf('path') + 'filler.php3', {
      parameters: params,
      requestHeaders: {Accept: 'application/json'},
      onSuccess: function(transport) {
          AA_Toolbar('');
          window.removeEventListener("beforeunload", AA_WindowUnloadQ);
          AA_Message((Object.keys(transport.responseJSON).length == 1) ? 'Zmeny ulozeny' : ('Ulozeno ' + Object.keys(transport.responseJSON).length +' zmen'));
      }
  });
}

// used in window.addEventListener("beforeunload", AA_WindowUnloadQ)
function AA_WindowUnloadQ(e) {
    e.preventDefault();
    // The text is not displayed in most modern browsers, but the value must be set;
    e.returnValue = "Changes are not saved. Do you really want to exit this page?";
}

function AA_SiteUpdate(item, field, value, action) {
  if (['u','i','r'].indexOf(action) == -1) {
    action = 'u';
  }
  var params = {};
  params['aa['+action+item+']['+field+'][0]'] = value;
  AA__doChange(params);
}
                                                
/* template not implemented, field and values pairs could be multiple field, value, field, value, ... */
function AA_SiteNewitem(slice, template, field, value) {
  var params = {};
  params['aa[n1_'+slice+']['+field+'][0]'] = value;
    AA__doChange(params);
}

function AA__doChange(params) {
  new Ajax.Request(window.location.href, {
      parameters: params,
      requestHeaders: {Accept: 'application/json'},
      onSuccess: function(transport) {
            for (var i in transport.responseJSON) {
                if (i == 'message') {
                    AA_Message(transport.responseJSON[i]);
                } else {
                    $$("[data-aa-part='"+i+"'").invoke('replace', transport.responseJSON[i]);
                }
            }
      }
  });
}

function AA_Translate(input_id, text, from, to) {
    var url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl="+from+"&tl="+to+"&dt=t&q="+ encodeURIComponent(text);

    new Ajax.Request(url, {
        requestHeaders: {Accept: 'application/json'},
        onSuccess: function(transport) {
            var ret='';
            var textarr = transport.responseJSON[0];
            if (textarr) {
                for ( var i=0; i < textarr.length; i++ ) {
                    ret += textarr[i][0];
                }
            }
            $(input_id).setValue(ret);
        }
    });
}

/* Cookies */
function SetCookie(name, value, plustime) {
    var coo = encodeURIComponent(name) + "=" + encodeURIComponent(value);
   plustime = (typeof plustime === "undefined") ? (1000 * 60 * 60 * 24) : plustime;   // a day
   var expires = new Date();
   expires.setTime(expires.getTime() + plustime);
    coo += "; expires=" + expires.toGMTString() + "; path=/";
    coo += (document.location.protocol == 'https:') ? ';secure;samesite=lax' : '';
    document.cookie = coo;
    // + ((expires == null) ? "" : ("; expires=" + expires.toGMTString()))
    // + ((path == null)    ? "" : ("; path=" + path))
    // + ((domain == null)  ? "" : ("; domain=" + domain))
    // + ((secure == true)  ? "; secure" : "");
}

function getCookieVal(offset) {
    var endstr = document.cookie.indexOf(";", offset);
    if (endstr == -1) {
        endstr = document.cookie.length;
    }
    return unescape(document.cookie.substring(offset, endstr));
}

function GetCookie(name) {
    var arg = name + "=";
    var alen = arg.length;
    var clen = document.cookie.length;
    var i = 0;
    while (i < clen) {
        var j = i + alen;
        if (document.cookie.substring(i, j) == arg) {
            return getCookieVal(j);
        }
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
    if ( GetCookie(name) != val ) {
        SetCookie(name,val);
    } else {
        DeleteCookie(name);
    }
}

function AA_NewId() {
    // Private array of chars to use
    var chars = '0123456789abcdefgh'.split('');

    var uuid = [];

    // we do not want to have 0 as the first char in pair
    for (var i = 0; i < 16; i++) {
        uuid[2*i]   = chars[0 | (Math.random()*15+1)];
        uuid[2*i+1] = chars[0 | (Math.random()*16)];
    }
    return uuid.join('');
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
