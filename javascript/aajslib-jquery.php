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


?>

// usage: $(div_id_2).update(AA_Config.loader);
var AA_Config = {
  AA_INSTAL_PATH: '<?php echo AA_INSTAL_PATH; ?>',
  loader:         '<img src="<?php echo AA_INSTAL_PATH; ?>images/loader.gif" border="0" width="16" height="16">',
  icon_new:       '<img src="<?php echo AA_INSTAL_PATH; ?>images/icon_new.gif" border="0" width="17" height="17">',
  icon_close:     '<img src="<?php echo AA_INSTAL_PATH; ?>images/icon_close.gif" border="0" width="17" height="17">'
}


// switch current item in gallery
function AA_GalleryGoto(photo_div, viewid, sitemid, galeryid, thumb_id) {
    $('div.switcher .active').removeClass('active');
    if ($(jqid(thumb_id))) {
        $(jqid(thumb_id)).addClass('active');
       // $(jqid(thumb_id)).parentNode.scrollTop = $(jqid(thumb_id)).offsetTop - $(jqid(thumb_id)).parentNode.offsetTop - 50;
    }
    $(jqid(photo_div)).load(AA_Config.AA_INSTAL_PATH + 'view.php3?vid=' + viewid + '&cmd[' + viewid + ']=x-' + viewid + '-' + sitemid + '&convertto=utf-8&als[GALERYID]=' + galeryid);
}

// now AA specific functions
function AA_HtmlToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2) {
    if ( $(jqid(div_id_1)).is(':visible') ) {
        $(jqid(div_id_1)).hide('fast');
        $(jqid(div_id_2)).show('fast');
        $(jqid(link_id)).html(link_text_2);
        $(jqid(link_id)).addClass("is-on");
    } else {
        $(jqid(div_id_2)).hide('fast');
        $(jqid(div_id_1)).show('fast');
        $(jqid(link_id)).html(link_text_1);
        $(jqid(link_id)).removeClass("is-on");
    }
}

function AA_HtmlToggleCss(link_id, link_text_1, link_text_2, selector) {
    var link = jqid(link_id);
    if ( $(link).hasClass('is-on')) {
        $(selector).hide('fast');
        $(link).html(link_text_1);
        $(link).toggleClass('is-on');
    } else {
        $(selector).show('fast');
        $(link).toggleClass('is-on');
        $(link).html(link_text_2);
    }
}

function AA_Ajax(div_id, url, param, onload) {
    AA_AjaxCss(jqid(div_id), url, param, onload);
}

function AA_AjaxCss(selector, url, param, onload) {
    $(selector).hide('fast');
    $(selector).html(AA_Config.loader);
    $(selector).show('fast');
    $(selector).load(url, param, onload);
}

function AA_InsertHtml(into_id, code) {
   $(jqid(into_id)).append(code);
}

/** selector_update is optional and is good for updating table rows, where we want to show/hide tr, but update td */
function AA_HtmlAjaxToggleCss(link_id, link_text_1, link_text_2, selector_hide, url, selector_update) {
    var link = jqid(link_id);
    if ( $(link).hasClass('is-on')) {
        $(selector_hide).hide('fast');
        $(link).html(link_text_1);
        $(link).toggleClass('is-on');
    } else {
        $(selector_hide).show('fast');
        $(link).toggleClass('is-on');
        // not loaded from remote url, yet?
        if ( !$(link).hasClass('aa-loaded')) {
            $(link).addClass('aa-loaded');
            AA_AjaxCss(selector_update ? selector_update : selector_hide, url);
        }
        $(link).html(link_text_2);
    }
}

function AA_HtmlAjaxToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2, url) {
    if ( $(jqid(div_id_1)).is(':visible') ) {
        $(jqid(div_id_1)).hide('fast');
        $(jqid(div_id_2)).show('fast');
        // not loaded from remote url, yet?
        if ( $(jqid(div_id_2)).attr('data-aa-loaded') != '1') {
            $(jqid(div_id_2)).attr('data-aa-loaded', '1');
            AA_Ajax(div_id_2, url);
        }
        $(jqid(link_id)).html(link_text_2);
    } else {
        $(jqid(div_id_2)).hide('fast');
        $(jqid(div_id_1)).show('fast');
        $(jqid(link_id)).html(link_text_1);
    }
}

/** calls AA responder with permissions of current user and displays returned
 *  html code into div_id
 *  Usage:
 *     FrmSelectEasy('from_slice', $slice_array, $from_slice, 'onchange="DisplayAaResponse(\'fieldselection\', \'Get_Fields\', {slice_id:this.options[this.selectedIndex].value})"');
 *     echo '<div id="fieldselection"></div>';
 **/
function DisplayAaResponse(div_id, method, params) {
    AA_AjaxCss(jqid(div_id), AA_Config.AA_INSTAL_PATH + 'central/responder.php?command='+ method, {parameters: params});
}

function AA_Response(method, resp_params, ok_func, err_func) {
    $.post(AA_Config.AA_INSTAL_PATH + 'central/responder.php?command='+ method, resp_params, function(data) {
        if ( data.substring(0,5) == 'Error' ) {
            if (typeof err_func != "undefined") {
                err_func(data);
            }
        } else {
            if (typeof ok_func != "undefined") {
                ok_func(data);
            }
         }
    });
}

function AA_Refresh(id,inside,ok_func) {
    AA_Ajax(id, $(jqid(id)).attr('data-aa-url'));
}

/** Send the form by AJAX and on success displays the ok_html text
 *  @param id        - form id
 *  @param refresh   - id of the html element, which you want to refresh.
 *                   - Such element must have data-aa-url attributes
 *  @param ok_html   - function to call after the page update
 *  Note, that the form action atribute must be RELATIVE (not with 'http://...')
 */
function AA_SendForm(id, refresh, ok_func) {
    var form_id = jqid(id);
    // browser supports HTML5 validation
    if (typeof $(form_id)[0].checkValidity == 'function') {
        if (!$(form_id)[0].checkValidity()) {
            // $(form_id)[0].submit();
            // AA_StateChange(base_id, 'invalid');
            return;
        }
    }

    var url = $(form_id).attr('action');
    $(form_id).append(AA_Config.loader);

    var code   = $(form_id + ' *').serialize();

    $.post( url, code).always( function() {
        if (typeof refresh != "undefined") {
            AA_Refresh(refresh,false,ok_func);
       }
    })
}

/** Sends the form and replaces the form with the response
 *  Polls ussage - @see: http://actionapps.org/en/Polls_Module#AJAX_Polls_Design
 */
function AA_AjaxSendForm(form_id, url) {
    var filler_url = url || 'modules/polls/poll.php3';  // by default it is used for Polls
    if (filler_url.charAt(0)!='/') {
        filler_url = AA_Config.AA_INSTAL_PATH + filler_url;   // AA link
    }

    var valdiv = jqid(form_id);
    var code   = $(valdiv + ' *').serialize();
    $(valdiv).append(AA_Config.loader);

    $.post(filler_url, code, function(data) {
        $(valdiv).attr("data-aa-edited", "0");
        $(valdiv).html(data);
    });
}


function displayInput(valdivid, item_id, fid) {
    var valdiv = jqid(valdivid);

    // already editing ?
    switch ($(valdiv).attr('data-aa-edited')) {
       case '1': return;
       case '2': $(valdiv).attr("data-aa-edited", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
                 return;
    }
    $(valdiv).attr("data-aa-oldval", $(valdiv).html());
    $(valdiv).html(AA_Config.loader);

    AA_Response('Get_Widget', { field_id: fid, item_id: item_id }, function(data) {
            var valdiv = jqid(valdivid);
            $(valdiv).attr('data-aa-edited', '1');
            $(valdiv).html(data);
            var aa_input = $(valdiv).children('select,textarea,input').first();
            $(aa_input).focus();  // select the input field (<select> or <input>)
            if ((aa_input).is("textarea")) {
                // do not react on enter in textarea
                $(aa_input).keydown( function(event) {
                    switch (event.which) {
                    case 27: $(this).nextAll('input.cancel-button').click(); break; // Esc
                    }
                });
            } else {
                $(aa_input).keydown( function(event) {
                    switch (event.which) {
                    case 13: $(this).nextAll('input.save-button').click();   break; // Enter
                    case 27: $(this).nextAll('input.cancel-button').click(); break; // Esc
//                  case 9:  $(this).nextAll('input.save-button').click(); $(this).closest('div.ajax_container').nextAll('div.ajax_container').click(); break; // Tab
                    case 9:  // Tab
                         // we must grab the next input right now - after save-button click we have no current div
                         var next_input = $('div.ajax_container').eq($('div.ajax_container').index($(this).parents('div.ajax_container'))+1);
                         $(this).nextAll('input.save-button').click();
                         $(next_input).click();
                         break;
                    }
                });
            }
        }
    );
}

/** return back old value - CANCEL pressed on AJAX widget */
function DisplayInputBack(input_id) {
    var valdiv   = jqid('ajaxv_'+input_id);
    $(valdiv).html( $(valdiv).attr('data-aa-oldval') );
    $(valdiv).attr('data-aa-edited', '2');
}



function jqescape(s) {
    // escape all special characters (like [])
    return s.replace(/([^a-zA-Z0-9_-])/g, '\\$1')
}

function jqid(s) {
    // escape all special characters (like [])
    return '#' + jqescape(s);
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetAjax(base_id) {
    var valdiv = jqid('ajaxv_'+base_id);
    var inputs = $(valdiv + ' :input');

    if (typeof inputs[0].checkValidity == 'function') {
        for(var i = 0; i < inputs.length; i++) {
            if (!inputs[i].checkValidity()) {
                AA_StateChange(base_id, 'invalid');
                return;
            }
        }

    }

    var code   = $(valdiv + ' *').serialize();
    AA_StateChange(base_id, 'updating');
    //$(valdiv).append(AA_Config.loader);

    var alias_name = $(valdiv).attr('data-aa-alias');

    code += '&inline=1&ret_code_enc='+alias_name;

    $.post(AA_Config.AA_INSTAL_PATH + 'filler.php3', code, function(data) {
        AA_ReloadAjaxResponse(base_id, data);
    });
}


/** Closes the ajax for after file upload
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_ReloadAjaxResponse(id, responseText) {
    var valdiv = jqid('ajaxv_'+id);

    var items  = (typeof responseText === 'string') ? jQuery.parseJSON(responseText) : responseText;
    var res;
    for (var i in items) {
        res = items[i];
        $(valdiv).html(res.length>0 ? res : '--');
        break;
    }
    $(valdiv).attr("data-aa-edited", "0");
    var succes_function = $(valdiv).attr('data-aa-onsuccess');
    if (succes_function) {
        eval(succes_function);
    }
}


/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetLive(base_id, liveinput, fnc) {
    AA_StateChange(base_id, 'updating');
    var valdivid   = jqid('widget-' + base_id);

    // browser supports HTML5 validation
    if (typeof liveinput.checkValidity == 'function') {
        if (!liveinput.checkValidity()) {
            AA_StateChange(base_id, 'invalid');
            return;
        }
    }

    var code = $(valdivid + ' *').serialize();

    code += '&inline=1';  // do not send us whole page as result

    $.post(AA_Config.AA_INSTAL_PATH + 'filler.php3', code, function(data) {
        AA_StateChange(base_id, 'normal');
        if (typeof fnc == 'function') {
            fnc();
        }
    });
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
        AA_Rotator.rotators[id]       = {"index": 0, "max": max, "speed": speed, "effect":effect };
        AA_Rotator.rotators[id].timer = setInterval(function () {AA_Rotator(id)},interval);
        $(jqid(id)).hover(function(ev) {
            clearInterval(AA_Rotator.rotators[id].timer);
        }, function(ev){
            AA_Rotator.rotators[id].timer = setInterval(function () {AA_Rotator(id)},interval);
        });
    }

    if (AA_Rotator.rotators[id].effect == 'fade') {
        $(jqid(id)+ ' .rot-hide').stop( true, true ).fadeOut(AA_Rotator.rotators[id].speed);
        $(jqid(id)+ ' .rot-hide:nth-child('+(AA_Rotator.rotators[id].index+1)+')').fadeIn(AA_Rotator.rotators[id].speed);
    } else {
        $(jqid(id)+ ' .rot-hide').stop( true, true ).hide(AA_Rotator.rotators[id].speed);
        $(jqid(id)+ ' .rot-hide:nth-child('+(AA_Rotator.rotators[id].index+1)+')').show(AA_Rotator.rotators[id].speed);
    }

    $(jqid(id)+ ' .rot-active').removeClass('active');
    $(jqid(id)+ ' .rot-active:nth-child('+(AA_Rotator.rotators[id].index+1)+')').addClass('active');

    AA_Rotator.rotators[id].index = (AA_Rotator.rotators[id].index+1)% AA_Rotator.rotators[id].max;
}

/** used by {livesearch:...}
 *  internal - could be changed
 */
function AA__liveSearch(id, viewparam, deflt) {
    val    = $(jqid(id)+ ' input.itemsearch')[0].value;
    if (!val.length && deflt.length) {
        val=deflt;
    }
    fnc = function(t,v,q) { AA_AjaxCss(t, AA_Config.AA_INSTAL_PATH + 'view.php3?vid=' + viewparam.replace('AA_LS_QUERY', q));}
    if (AA__liveSearch.timer) {
       if (AA__liveSearch.searchval != val) {
           clearTimeout(AA__liveSearch.timer);
           AA__liveSearch.searchval = val;
           AA__liveSearch.timer     = setTimeout(fnc,200, $(jqid(id)+ ' .itemgroup')[0], viewparam, val);
       }
    } else {
       AA__liveSearch.timer = setTimeout(fnc,200, $(jqid(id)+ ' .itemgroup')[0], viewparam, val);
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
      case 'ok':   attrs['class'] = 'aa-ok';   break;
      case 'info': attrs['class'] = 'aa-info'; break;
      default:     attrs['class'] = 'aa-text';
                   type = 'text';
    }
    if (text.charAt(0)=='/') {
        AA__systemDiv('aa-message-box', attrs, '<div id="aa-message-box-in"></div>');
        AA_Ajax('aa-message-box-in', text);
    } else {
        AA__systemDiv('aa-message-box', attrs, text.length ? '<div id="aa-message-box-in">'+text+'</div>' : '');
    }
    if (type != 'text') {
        setTimeout(function() { $('aa-message-box').hide(); }, 5000);
    }
}

function AA__systemDiv(id, attrs, text) {
    var box = $(jqid(id));
    if (!box.length) {
        $('<div/>',attrs).appendTo('body');
        box = $(jqid(id));
    }
    box.html(text);
    if (!text) {
        box.hide();
    } else {
        if (!$('#aa-bottom-toolbar').length) {
            AA_Toolbar(''); // we need toolbar defined, we need it to test styles
            if (AA_GetStyle('aa-bottom-toolbar', 'position')!='fixed') {
                AA_LoadCss(AA_Config.AA_INSTAL_PATH + 'css/aa-system.css');
            }
        }
        box.show();
    }
}

function AA_Toolbar(text) {
    AA__systemDiv('aa-bottom-toolbar', {'id': 'aa-bottom-toolbar'}, text);
}

/** indicator of changed / updating data */
function AA_StateChange(id, state) {
    var outstyle = {};
    var icoimg   = '';

    switch (state) {
    case 'dirty':
        outstyle = {'outline-color': '#E4B600', 'outline-width': '1px', 'outline-style': 'solid'};
        icoimg   = 'images/save.png';
        break;
    case 'updating':
        outstyle = {'outline-color': '#E4B600', 'outline-width': '1px', 'outline-style': 'dashed'};
        icoimg   = 'images/loader.gif';
        break;
    case 'invalid':
        outstyle = {'outline': 'none'};
        icoimg   = 'images/warn.png';
        break;
    case 'normal':
    default:
        outstyle = {'outline': 'none'};
        icoimg   = 'images/px.gif';
        break;
    }
    $('*[id ^="'+id+'"]').removeClass('updating normal dirty invalid').addClass(state);
    $('select[id ^="'+id+'"]').css(outstyle);
    $('input[id ^="'+id+'"]').css(outstyle);
    $('textarea[id ^="'+id+'"]').css(outstyle);
    $('img.'+id+'ico').attr('src', AA_Config.AA_INSTAL_PATH+icoimg);
}

function AA_LoadJs(condition, callback, url) {
    if (condition) {
        if (callback) {
            callback();
        }
    } else {
        var script = document.createElement("script")
        script.type = "text/javascript";

        if (callback) {
            if (script.readyState) { //IE
                script.onreadystatechange = function () {
                    if (script.readyState == "loaded" || script.readyState == "complete") {
                        script.onreadystatechange = null;
                        callback();
                    }
                };
            } else { //Others
                script.onload = function () {
                    callback();
                };
            }
        }

        script.src = url;
        document.getElementsByTagName("head")[0].appendChild(script);
    }
}

function AA_LoadCss(url) {
   var link  = document.createElement('link');
   link.type = 'text/css';
   link.rel  = 'stylesheet';
   link.href = url;
   document.getElementsByTagName('head')[0].appendChild(link);
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
            params['aa[u'+$(jqid(i)).attr('data-aa-id')+']['+$(jqid(i)).attr('data-aa-field')+']['+$(jqid(i)).attr('data-aa-index')+']'] = CKEDITOR.instances[i].getData();
            params['aa[u'+$(jqid(i)).attr('data-aa-id')+']['+$(jqid(i)).attr('data-aa-field')+'][flag]'] = 1;
        }
    }

    $.post(AA_Config.AA_INSTAL_PATH + 'filler.php3', params, function(data) {
        AA_Toolbar('');
        AA_Message((Object.keys(data).length == 1) ? 'Zmeny ulozeny' : ('Ulozeno ' + Object.keys(data).length +' zmen'));
    }, 'json');
}

/* Cookies */
function SetCookie(name, value, plustime) {
   plustime = (typeof plustime === "undefined") ? (1000 * 60 * 60 * 24) : plustime;   // a day
   var expires = new Date();
   expires.setTime(expires.getTime() + plustime);
   document.cookie = name + "=" + escape(value) + "; expires=" + expires.toGMTString() + "; path=/";
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
    var arg  = name + "=";
    var alen = arg.length;
    var clen = document.cookie.length;
    var i    = 0;
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

function NewId() {
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