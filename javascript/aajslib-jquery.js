/** AA Javascripts library usable on the public pages, just like:
 *  <script src="https://actionapps.org/apc-aa/javascript/jquery.min.js"></script>
 *  <script src="https://actionapps.org/apc-aa/javascript/aajslib-jquery.php3"></script>
 *  (replace "https://actionapps.org/apc-aa" with your server and aa path
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Honza Malik <honza.malik@ecn.cz>
 *  @copyright Econnect, Honza Malik, December 2006
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

// polyfills - will be removed when the incompatible browsers will not be supported by its creator
if (window.NodeList && !NodeList.prototype.forEach) {
  NodeList.prototype.forEach = Array.prototype.forEach;
}

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

// switch current item in gallery
function AA_GalleryGoto(photo_div, viewid, sitemid, galeryid, thumb_id) {
    $('div.switcher .active').removeClass('active');
    if ($(jqid(thumb_id))) {
        $(jqid(thumb_id)).addClass('active');
       // $(jqid(thumb_id)).parentNode.scrollTop = $(jqid(thumb_id)).offsetTop - $(jqid(thumb_id)).parentNode.offsetTop - 50;
    }
    $(jqid(photo_div)).load(AA_GetConf('path') + 'view.php3?vid=' + viewid + '&cmd[' + viewid + ']=x-' + viewid + '-' + sitemid + '&convertto=utf-8&als[GALERYID]=' + galeryid);
}

// now AA specific functions
function AA_HtmlToggle(link_id, link_text_1, div_id_1, link_text_2, div_id_2, persist_id) {
    if ( $(jqid(div_id_1)).is(':visible') ) {
        $(jqid(div_id_1)).hide('fast');
        $(jqid(div_id_2)).show('fast');
        $(jqid(link_id)).html(link_text_2);
        $(jqid(link_id)).addClass("is-on");
        if (persist_id) {
            localStorage[persist_id] = '2';
        }
    } else {
        $(jqid(div_id_2)).hide('fast');
        $(jqid(div_id_1)).show('fast');
        $(jqid(link_id)).html(link_text_1);
        $(jqid(link_id)).removeClass("is-on");
        if (persist_id) {
            localStorage[persist_id] = '1';
        }
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
    var di = $(selector).css('display');
    $(selector).hide('fast');
    $(selector).html(AA_GetConf('loader'));
    $(selector).show('fast');
    $(selector).css(di);
    $(selector).load(url, param, onload);
}

function AA_InsertHtml(into_id, code) {
   $(jqid(into_id)).append(code);
}

/** selector_update is optional and is good for updating table rows, where we want to show/hide tr, but update td */
function AA_HtmlAjaxToggleCss(link_id, link_text_1, link_text_2, selector_hide, url, selector_update) {
    var link = jqid(link_id);
    var selector_update = selector_update ? selector_update : selector_hide;
    var swap;
    if ( !$(link).hasClass('is-on')) {
        $(selector_hide).show('fast');
        $(link).toggleClass('is-on');
        // not loaded from remote url, yet?
        if ( !$(link).hasClass('aa-loaded')) {
            $(link).addClass('aa-loaded');
            $(link).attr("data-aa-oldval", $(selector_update).html());
            AA_AjaxCss(selector_update, url);
        } else {
            swap = $(selector_update).html(); $(selector_update).html($(link).attr("data-aa-oldval")); $(link).attr("data-aa-oldval", swap);
        }
        $(link).html(link_text_2);
    } else {
        swap = $(selector_update).html(); $(selector_update).html($(link).attr("data-aa-oldval")); $(link).attr("data-aa-oldval", swap);
        if (!$(link).attr("data-aa-oldval").trim().length) {
            $(selector_hide).hide('fast');
        }
        $(link).html(link_text_1);
        $(link).toggleClass('is-on');
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
 *     FrmSelectEasy('from_slice', $slice_array, $from_slice, 'onchange="DisplayAaResponse(\'fieldselection\', \'Get_Fields\', {slice_id:this.value})"');
 *     echo '<div id="fieldselection"></div>';
 **/
function DisplayAaResponse(div_id, method, params) {
    AA_AjaxCss(jqid(div_id), AA_GetConf('path') + 'central/responder.php?command='+ method, params);
}

function AA_Response(method, resp_params, ok_func, err_func) {
    $.post(AA_GetConf('path') + 'central/responder.php?command='+ method, resp_params, function(data) {
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
    $(form_id).append(AA_GetConf('loader'));

    var code   = $(form_id + ' *').serialize();

    $.post( url, code).always( function() {
        if (typeof refresh != "undefined") {
            AA_Refresh(refresh,false,ok_func);
       }
    })
}

/** Sends the form and replaces the form with the response
 *  Polls ussage - @see: https://actionapps.org/en/Polls_Module#AJAX_Polls_Design
 */
function AA_AjaxSendForm(form_id, url) {
    var filler_url = url || 'modules/polls/poll.php3';  // by default it is used for Polls
    if (filler_url.charAt(0)!='/') {
        filler_url = AA_GetConf('path') + filler_url;   // AA link
    }

    var valdiv = jqid(form_id);
    var code   = $(valdiv + ' *').serialize();
    $(valdiv).append(AA_GetConf('loader'));

    $.post(filler_url, code, function(data) {
        $(valdiv).attr("data-aa-edited", "0");
        var res = data;
        if (typeof data === 'object') {  // $.post is parsed if JSON is returned
            for (var i in data) {
                res = data[i];
                break;
            }
        }
        $(valdiv).html(res);
    });
}


function displayInput(valdivid, item_id, fid) {
    var valdiv = jqid(valdivid);

    // already editing ?
    switch ($(valdiv).attr('data-aa-edited')) {
       case '1': return;
       //case '2': $(valdiv).attr("data-aa-edited", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
       //          return;
    }
    $(valdiv).attr("data-aa-oldval", $(valdiv).html());

    var width = $(valdivid).width();
    $(valdiv).html(AA_GetConf('loader'));

    AA_Response('Get_Widget', { field_id: fid, item_id: item_id }, function(data) {
            var valdiv = jqid(valdivid);
            $(valdiv).attr('data-aa-edited', '1');
            if (width>200) {
                data = data.replace('class="ajax_widget aa-ajax-open"', 'class="ajax_widget aa-ajax-open" style="width:'+width+'px;"');
            }
            $(valdiv).html(data);
            var aa_input = $(valdiv).find('select,textarea,input').first();
            $(aa_input).focus();  // select the input field (<select> or <input>)
            if ((aa_input).is("textarea")) {
                // do not react on enter in textarea
                $(aa_input).keydown( function(event) {
                    switch (event.which) {
                    case 27: $(valdiv).find('input.cancel-button').click(); break; // Esc
                    }
                });
            } else {
                $(aa_input).keydown( function(event) {
                    switch (event.which) {
                    case 13: $(valdiv).find('input.save-button').click();   break; // Enter
                    case 27: $(valdiv).find('input.cancel-button').click(); break; // Esc
                    case 9:  // Tab
                         // we must grab the next input right now - after save-button click we have no current div
                         var next_input = $('div.ajax_container').eq($('div.ajax_container').index($(this).parents('div.ajax_container'))+1);
                         $(valdiv).find('input.save-button').click();
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
    // $(valdiv).attr('data-aa-edited', '2');      // no longer needed  state 2- we use stopPropagation() in widget Ajax
    $(valdiv).attr('data-aa-edited', '0');
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
    //$(valdiv).append(AA_GetConf('loader'));

    var alias_name = $(valdiv).attr('data-aa-alias');

    code += '&inline=1&ret_code_enc='+alias_name;

    $.post(AA_GetConf('path') + 'filler.php3', code, function(data) {
        AA_ReloadAjaxResponse(base_id, data);
    });
}


/** Closes the ajax for after file upload
 *  The main change is, that now we use standard AA input names:
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

    $.post(AA_GetConf('path') + 'filler.php3', code, function(data) {
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
    fnc = function(t,v,q) { AA_AjaxCss(t, AA_GetConf('path') + 'view.php3?vid=' + viewparam.replace('AA_LS_QUERY', q));}
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
                AA_LoadCss(AA_GetConf('path') + 'css/aa-system.css');
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
    $('img.'+id+'ico').attr('src', AA_GetConf('path')+icoimg);
}

/** If condition, call callback directly. Otherwise - load js script from url and execute callcack after the script is loaded */
function AA_LoadJs(condition, callback, url, sync) {
    if (condition && ((condition!='load_once') || (url && document.querySelector('script[src="'+url+'"]')))) {
        if (callback) {
            callback();
        }
    } else {
        ++AA_LoadJs.cnt;
        var script = document.createElement("script")
        script.type = "text/javascript";
        if (sync) {
            script.async = false;
        }

        if (script.readyState) { //IE
            script.onreadystatechange = function () {
                if (script.readyState == "loaded" || script.readyState == "complete") {
                    --AA_LoadJs.cnt;
                    script.onreadystatechange = null;
                    if (callback) {
                        callback();
                    }
                }
            };
        } else { //Others
            script.onload = function () {
                --AA_LoadJs.cnt;
                if (callback) {
                    callback();
                }
            };
        }

        script.src = url;
        document.getElementsByTagName("head")[0].appendChild(script);
    }
}

/** internal AA function for function call after all the scripts are loaded */
function AA_IsLoaded() {
    if (!AA_LoadJs.cnt && AA_IsLoaded.callback) {
        AA_IsLoaded.callback();
        AA_IsLoaded.callback = undefined;       // run it just once
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

    $.post(AA_GetConf('path') + 'filler.php3', params, function(data) {
        AA_Toolbar('');
        window.removeEventListener("beforeunload", AA_WindowUnloadQ);
        AA_Message((Object.keys(data).length == 1) ? 'Zmeny ulozeny' : ('Ulozeno ' + Object.keys(data).length +' zmen'));
    }, 'json');
}

// used in window.addEventListener("beforeunload", AA_WindowUnloadQ)
function AA_WindowUnloadQ(e) {
    e.preventDefault();
    // The text is not displayed in most modern browsers, but the value must be set;
    e.returnValue = "Changes are not saved. Do you really want to exit this page?";
}

function AA_SiteUpdate(item, field, value) {
    var params = {};
    params['aa[u'+item+']['+field+'][0]'] = value;
    AA__doChange(params);
}

/* template not implemented, field and values pairs could be multiple field, value, field, value, ... */
function AA_SiteNewitem(slice, template, field, value) {
    var params = {};
    for (var i = 2, len = arguments.length; i < len; i+=2) {
        if (len > i+1) {
            params['aa[n1_'+slice+']['+arguments[i]+'][0]'] = arguments[i+1];
        }
    }
    AA__doChange(params);
}
function AA_SiteEdit(item) {
    AA__doChange({'aaedit':item}, 'get');
}
function AA_SiteSendForm(form) {
    AA__doChange(new FormData(form));
    return false;
}

function AA__SiteUpdateParts(data) {
    var ckeditors = [];
    var matches   = [];
    var re = /contenteditable=true id="([^"]*)"/g;
    for (var i in data) {
        if (i == 'message') {
            $('article.aa-updating[data-aa-part]').removeClass('aa-updating');
            AA_Message(data[i], 'err');
        } else {
            $("[data-aa-part='"+i+"']").replaceWith(data[i]);
            if (data[i].indexOf("contenteditable=true")) {
                while (matches = re.exec(data[i])) {
                    if (matches[1]) {
                        ckeditors.push(matches[1]);
                    }
                }
            }
        }
    }
    if (ckeditors.length && (typeof CKEDITOR !== "undefined")) {
        for(var i in ckeditors) {
            var editor = CKEDITOR.instances[ckeditors[i]];
            if (editor) {
                editor.destroy(true);
            }
            CKEDITOR.inline(ckeditors[i]);
        }
    }
}

function AA__doChange(params, type) {
    if (type == 'get') {
        $.get(window.location.href, params, AA__SiteUpdateParts);
    } else if (params instanceof FormData) {
        $.ajax({
          url         : window.location.href,
          data        : params,
          cache       : false,
          contentType : false,
          processData : false,
          type        : 'POST',
          success     : AA__SiteUpdateParts
        });
        // $.post(window.location.href, params, AA__SiteUpdateParts);
    } else {
        $.post(window.location.href, params, AA__SiteUpdateParts);
    }
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

function aa_maketags(id, slice_id, field_id, max_values ) {
    var responder = AA_GetConf('path') + 'central/responder.php';
    var conf = {
        templateResult: function (d) { return $("<span>"+d.text+"</span>"); },
        templateSelection: function (d) { return $("<span>"+d.text+"</span>"); },
        createTag: function (params) { return undefined; },
        selectOnClose: true,
        ajax: { url: responder,
            dataType: 'json',
            delay: 250,
            data: function (params) { // page is the one-based page number tracked by Select2
                return {
                    free: "nobody",
                    command: "tags",
                    q: params.term,     //search term
                    p: params.page,     // page number
                    s: slice_id, // slice_id
                    f: field_id  // field_id
                };
            },
            processResults: function (data, params) {
                params.page = params.page || 1; // whether or not there are more results available
                // notice we return the value of more so Select2 knows if more results can be loaded
                return {results: data, pagination: {more: (params.page * 10) < data.count_filtered} }
            }
        }
    }
    if (max_values > 1) {
        conf.maximumSelectionLength =  max_values;
    }
    $('#'+id).select2( conf );
}

function AA_Translate(input_id, text, from, to) {
    var url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl="+from+"&tl="+to+"&dt=t&q="+ encodeURIComponent(text);
    $.ajax({
            url: url,
            method: "GET",
            success: function(data) {
                var ret='';
                var textarr = data[0];
                if (textarr) {
                    for ( var i=0; i < textarr.length; i++ ) {
                        ret += textarr[i][0];
                    }
                }
                $(jqid(input_id)).val(ret);  
            }
        });
}
