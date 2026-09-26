/** AA Javascripts library usable on the public pages, just like:
 *
 *  <script src="https://actionapps.org/apc-aa/javascript/jquery.min.js"></script>
 *  <script src="https://actionapps.org/apc-aa/javascript/aajslib-jquery.php"></script>
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

"use strict";

INCLUDE_AAJSLIB_COMMON


/** replaces jQuery $xx.load(url) - used as AA_Load(xx,url)
 * @param {HTMLElement|string} sel
 * @param {string} url
 * @param {object} options fetch parameters like {method: "POST", headers: {"X-Requested-With": "XMLHttpRequest"}}
*/
function AA_Load(sel, url, options = {}) {
    return fetch(url,options)
        .then(response => response.text())
        .then(html => {
            const el = (sel instanceof HTMLElement) ? sel : AA_$(sel);
            el.innerHTML = html;
            AA_$$('script', el).forEach(oldjs => {   // to run the script inside
                const newjs = document.createElement("script");
                [...oldjs.attributes].forEach(at => newjs.setAttribute(at.name, at.value));
                newjs.text = oldjs.text;
                oldjs.parentNode.replaceChild(newjs, oldjs);
            });
        });
}



function jqescape(s) {
    return CSS.escape(s);
}

function jqid(s) {
    // escape all special characters (like [])
    return '#' + CSS.escape(s);
}

// switch current item in gallery
function AA_GalleryGoto(photo_div, viewid, sitemid, galeryid, thumb_id) {
    $('div.switcher .active').removeClass('active');
    if ($(jqid(thumb_id))) {
        $(jqid(thumb_id)).addClass('active');
       // $(jqid(thumb_id)).parentNode.scrollTop = $(jqid(thumb_id)).offsetTop - $(jqid(thumb_id)).parentNode.offsetTop - 50;
    }
    $(jqid(photo_div)).load(AA_GetConf('path') + 'view.php?vid=' + viewid + '&cmd[' + viewid + ']=x-' + viewid + '-' + sitemid + '&convertto=utf-8&als[GALERYID]=' + galeryid);
}


/** like AA_Ajax('send_#SITEM_ID', '/apc-aa/view.php?vid=70&cmd[70]=x-70-{id..............}&nocache=3', '', function() { $('#notice_#SITEM_ID').text('emails sent&#58;');} ); */
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

/** selector_update is optional and is good for updating table rows, where we want to show/hide tr, but update td */
function AA_HtmlAjaxToggleCss(link_id, link_text_1, link_text_2, selector_hide, url, selector_update) {
    var link = jqid(link_id);
    var swap;
    selector_update = selector_update ? selector_update : selector_hide;
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
        if (!$(link).attr("data-aa-oldval")?.trim().length) {
            $(selector_hide).hide('fast');
        }
        $(link).html(link_text_1);
        $(link).toggleClass('is-on');
    }
}

function AA_Response(method, resp_params, ok_func, err_func) {
    $.post(AA_GetConf('path') + 'central/responder.php?command='+ method, resp_params, function(data) {
        if ( data.substring(0,5) === 'Error' ) {
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


/** Refreshes the id using the data-aa-url attribute.
 *  If that attribute is not present, finds the first up in the DOM
 *  @param {HTMLElement|string} id  element or its id - AA_Refresh(this) is a documented
 *                                  idiom of {ajax:...:<onsuccess>}, see AA_Stringexpand_Ajax
 *  @param {boolean} inside         accepted for compatibility - the content is always
 *                                  replaced inside the element (aa-jslib@1 replaced the
 *                                  whole element when falsy)
 *  @param {function} ok_func       called after the content is refreshed
 */
function AA_Refresh(id, inside, ok_func) {
    const el     = (id instanceof HTMLElement) ? id : AA_$(jqid(id));
    // closest() also matches the element itself, not just ancestors - covers both cases
    const target = el?.closest('[data-aa-url]');
    if (!target) {
        return;
    }
    AA_AjaxCss(target, target.getAttribute('data-aa-url'), '', ok_func);
}

/** Send the form by AJAX and on success displays the ok_html text
 *  @param id        - form id
 *  @param refresh   - id of the html element, which you want to refresh.
 *                   - Such element must have data-aa-url attributes
 *  @param ok_func   - function to call after the page update
 *  Note, that the form action attribute must be RELATIVE (not with 'http://...')
 */
function AA_SendForm(id, refresh, ok_func) {
    var form_id = jqid(id);
    // browser supports HTML5 validation
    if (typeof $(form_id)[0].checkValidity === 'function') {
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
 *  Polls usage - @see: https://actionapps.org/en/Polls_Module#AJAX_Polls_Design
 */
function AA_AjaxSendForm(form_id, url) {
    var filler_url = url || 'modules/polls/poll.php';  // by default it is used for Polls
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

/** return back old value - CANCEL pressed on AJAX widget */
function DisplayInputBack(input_id) {
    var valdiv   = jqid('ajaxv_'+input_id);
    $(valdiv).html( $(valdiv).attr('data-aa-oldval') );
    // $(valdiv).attr('data-aa-edited', '2');      // no longer needed  state 2- we use stopPropagation() in widget Ajax
    $(valdiv).attr('data-aa-edited', '0');
}

/* AA_AjaxSendAddForm() moved to aajslib-common.js (vanilla, shared by all variants) */


/** This function replaces the older one - proposeChange
 *  The main change is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
function AA_SendWidgetAjax(base_id) {
    var valdiv = jqid('ajaxv_'+base_id);
    var inputs = $(valdiv + ' :input');

    if (typeof inputs[0].checkValidity === 'function') {
        for(var i = 0; i < inputs.length; i++) {
            if (!inputs[i].checkValidity()) {
                AA_StateChange(base_id, 'invalid');
                return;
            }
        }

    }

    // CodeMirror writes into its textarea on form submit - and we never submit, we serialize.
    // CodWidget saves on every change, MflWidget does not, so flush them all first.
    AA_$$(valdiv + ' .CodeMirror').forEach(el => el.CodeMirror?.save());

    var code   = $(valdiv + ' *').serialize();
    AA_StateChange(base_id, 'updating');
    //$(valdiv).append(AA_GetConf('loader'));

    var alias_name = $(valdiv).attr('data-aa-alias');

    code += '&inline=1&ret_code_enc='+alias_name;

    $.post(AA_GetConf('path') + 'filler.php', code, function(data) {
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
        if (i === 'report') {
            continue;
        }
        res = items[i];
        $(valdiv).html(res.length>0 ? res : '--');
        break;
    }
    $(valdiv).attr("data-aa-edited", "0");
    var succes_function = $(valdiv).attr('data-aa-onsuccess');
    if (succes_function) {
        // eval() is the documented contract of {ajax:...:<onsuccess>} - the code comes from the
        // slice template (admin-authored), not from user input. Called with `this` set to the
        // value div, so the documented AA_Refresh(this) idiom resolves.
        (function() { eval(succes_function); }).call(AA_$(valdiv));
    }
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
    let val = $(jqid(id)+ ' input.itemsearch')[0].value;
    if (!val.length && deflt.length) {
        val=deflt;
    }
    let fnc = function(t,v,q) { AA_AjaxCss(t, AA_GetConf('path') + 'view.php?vid=' + viewparam.replace('AA_LS_QUERY', q));}
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

/** used with conjunction with livesearch for arrows navigation */
function AA__Keynavigate(e) {
    if (e.key === 'ArrowDown') {
        $("a:focus").closest('li').next().find('a.move').focus();
    }
    if (e.key === 'ArrowUp') {
        $(".move:focus").closest('li').prev().find('a.move').focus();
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
            if (window.getComputedStyle(document.getElementById('aa-bottom-toolbar'))?.getPropertyValue('position')!=='fixed') {
                AA_LoadCss(AA_GetConf('path') + 'css/aa-system.css');
            }
        }
        box.show();
    }
}

/** This function replaces the older one - proposeChange
 *  The main chane is, that now we use standard AA input names:
 *   aa[i<item_id>][<field_id>][]
 */
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


function AA_SaveEditors() {
    var params = {'inline':1};

    for(var i in CKEDITOR.instances) {   // for each
        if (CKEDITOR.instances[i].checkDirty()) {
            params['aa[u'+$(jqid(i)).attr('data-aa-id')+']['+$(jqid(i)).attr('data-aa-field')+']['+$(jqid(i)).attr('data-aa-index')+']'] = CKEDITOR.instances[i].getData();
            params['aa[u'+$(jqid(i)).attr('data-aa-id')+']['+$(jqid(i)).attr('data-aa-field')+'][flag]'] = 1;
        }
    }

    $.post(AA_GetConf('path') + 'filler.php', params, function(data) {
        AA_Toolbar('');
        window.removeEventListener("beforeunload", AA_WindowUnloadQ);
        AA_Message((Object.keys(data).length === 1) ? 'Zmeny ulozeny' : ('Ulozeno ' + Object.keys(data).length +' zmen'));
    }, 'json');
}



function aa_maketags(id, slice_id, field_id, max_values, item_id ) {
    var responder = AA_GetConf('path') + 'central/responder.php';
    var conf = {
        templateResult: function (d) { return $("<span>"+d.text+"</span>"); },
        templateSelection: function (d) { return $("<span>"+d.text+"</span>"); },
        createTag: function (params) { return undefined; },
        language: AA_GetConf('lang'),
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
                    f: field_id, // field_id
                    i: item_id   // item_id (if possible)
                 };
            },
            processResults: function (data, params) {
                params.page = params.page || 1; // whether there are more results available
                // notice we return the value of more so Select2 knows if more results can be loaded
                return {results: data, pagination: {more: (params.page * 10) < data.count_filtered} }
            }
        }
    }
    if (max_values > 0) {
        conf.maximumSelectionLength =  max_values;
    }
    $('#'+id).select2( conf );
}


