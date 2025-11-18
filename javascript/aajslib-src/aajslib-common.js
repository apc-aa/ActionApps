/** Common part of aajslib-* library - the one in vanilla JS
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Honza Malik <honza.malik@ecn.cz>
 *  @copyright Ecn Studio, Honza Malik, 2023-07-12
 */

// polyfills - will be removed when the incompatible browsers are not supported by its creator
if (window.NodeList && !NodeList.prototype.forEach) { NodeList.prototype.forEach = Array.prototype.forEach; }
/** shortcut to parent.querySelector() */
if (typeof AA_$ !== 'function')  { var AA_$ = (s, el = document) => el.querySelector(s); }
/** shortcut to parent.querySelectorAll() */
if (typeof AA_$$ !== 'function') { var AA_$$ = (s, el = document) => Array.from(el.querySelectorAll(s)); }
if (typeof AA_$N !== 'function') { var AA_$N = (s, el = document) => el.getElementsByName(s)?.[0] ?? null; }
if (typeof AA_$V !== 'function') { var AA_$V = (s, el = document) => {
    if (!(el = typeof s === 'string' ? (AA_$(s, el) || AA_$N(s, el)) : s)) return null;
    if (el.type === 'checkbox') return el.checked;
    if (el.type === 'radio') return AA_$( `[name="${el.name}"]:checked`, el.form || document )?.value ?? null;
    if (el.multiple) return [...el.selectedOptions].map(o => o.value);
    return el.value ?? null;
};}

if (typeof window.AA_GetConf === 'undefined') {
    window.AA_GetConf = function(prop) {
        if (typeof AA_GetConf.aa_install_path === 'undefined') {
            var src = document.querySelector('script[src*="javascript/aajslib"]').getAttribute('src');
            AA_GetConf.aa_install_path = src.replace(/javascript\/aajslib.*$/, '');
        }
        switch (prop) {
            case 'path':
                return AA_GetConf.aa_install_path;
            case 'loader':
                return '<img src="' + AA_GetConf.aa_install_path + 'images/loader.gif" width=16 height=16 class=aa-loader>';
            case 'lang':
                return document.documentElement.lang.substring(0,2);
        }
    }
}

/** rewrite of AA_AjaxInsert, which uses prototype.js to vanilla JS */
function AA_AjaxInsert(a_obj, form_url) {
    var new_div_id = a_obj.id + '_ins';
    if ( document.getElementById(new_div_id) == null ) {
        var new_div  = document.createElement('div');
        new_div.id = new_div_id;
        a_obj.innerHTML =  '<img src="' + AA_GetConf('path') + 'images/icon_close.gif" width=16 height=16>';
        a_obj.parentNode.insertBefore(new_div, a_obj.nextSibling);
        AA_Ajax(new_div_id, form_url);
    } else {
        a_obj.innerHTML =  '<img src="' + AA_GetConf('path') + 'images/icon_new.gif" width=16 height=16>';
        document.getElementById(new_div_id).remove();
    }
}

/** calls AA responder with permissions of current user and displays returned
 *  html code into div_id
 *  Usage:
 *     FrmSelectEasy('from_slice', $slice_array, $from_slice, 'onchange="DisplayAaResponse(\'fieldselection\', \'Get_Fields\', {slice_id:this.value})"');
 *     echo '<div id="fieldselection"></div>';
*/
function DisplayAaResponse(div_id, method, params) {
    AA_AjaxCss('#'+CSS.escape(div_id), AA_GetConf('path') + 'central/responder.php?command='+ method, params);
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
    if (text.charAt(0)==='/') {
        AA__systemDiv('aa-message-box', attrs, '<div id="aa-message-box-in"></div>');
        AA_Ajax('aa-message-box-in', text);
    } else {
        AA__systemDiv('aa-message-box', attrs, text.length ? '<div id="aa-message-box-in">'+text+'</div>' : '');
    }
    if (type !== 'text') {
        setTimeout(function() { $('#aa-message-box').hide(); }, 3000);
    }
}

function AA_Toolbar(text) {
    AA__systemDiv('aa-bottom-toolbar', {'id': 'aa-bottom-toolbar'}, text);
}

/** If condition, call callback directly. Otherwise - load js script from url and execute callback after the script is loaded */
function AA_LoadJs(condition, callback, url, sync) {
    if (condition && ((condition!=='load_once') || (url && document.querySelector('script[src="'+url+'"]')))) {
        if (callback) {
            callback();
        }
    } else {
        var script = document.createElement("script")
        script.type = "text/javascript";
        if (sync) {
            script.async = false;
        }

        if (script.readyState) { //IE
            script.onreadystatechange = function () {
                if (script.readyState === "loaded" || script.readyState === "complete") {
                    script.onreadystatechange = null;
                    if (callback) {
                        callback();
                    }
                }
            };
        } else { //Others
            script.onload = function () {
                if (callback) {
                    callback();
                }
            };
        }

        script.src = url;
        document.getElementsByTagName("head")[0].append(script);
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

async function AA_InnerHtmlWithScripts(el, html) {
    el.innerHTML = html;
    const scripts = el.querySelectorAll('script');
    for (const s of scripts) {
        const ns = document.createElement('script');
        [...s.attributes].forEach(a => ns.setAttribute(a.name, a.value));

        if (s.src) {
            // External script — load sequentially (blocking style)
            document.head.appendChild(ns);
            await new Promise((resolve, reject) => {
                ns.onload = resolve;
                ns.onerror = reject;
            });
        } else {
            // Inline script — execute immediately
            ns.textContent = s.textContent;
            s.parentNode.replaceChild(ns, s);
        }
    }
}

function AA_LoadCss(url) {
    var link = document.querySelector('link[href="'+url+'"]');
    if (url && !link) {
        link  = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = url;
        document.getElementsByTagName('head')[0].append(link);
    }
    return link;
}
/* function to check the computed value of a style element */
function AA_GetStyle(id, name) {
    return window.getComputedStyle(document.getElementById(id))?.getPropertyValue(name) ?? null;
}

// used in window.addEventListener("beforeunload", AA_WindowUnloadQ)
function AA_WindowUnloadQ(e) {
    e.preventDefault();
    // The text is not displayed in most modern browsers, but the value must be set;
    e.returnValue = "Changes are not saved. Do you really want to exit this page?";
}

function AA_SiteUpdate(item, field, value, action) {
    if (['u','i','r'].indexOf(action) === -1) {
        action = 'u';
    }
    var params = {};
    params['aa['+action+item+']['+field+'][0]'] = value;
    AA__doChange(params);
}

/* template not implemented, field and values pairs could be multiple field, value, field, value, ... */
function AA_SiteNewitem(slice, template, field, value) {
    let params = {};
    for (var i = 2, len = arguments.length; i < len; i+=2) {
        if (len > i+1) {
            params['aa[n1_'+slice+']['+arguments[i]+'][0]'] = arguments[i+1];
        }
    }
    AA__doChange(params);
}
function AA_SiteEdit(item, cmdvar) {
    AA__doChange({[cmdvar]:item,'aaedit':cmdvar}, 'get');
}
function AA_SiteSendForm(form) {
    AA__doChange(new FormData(form));
    return false;
}

function AA__SiteUpdateParts(data) {
    // CKEditors (and CodeMirrors, ..) are now loaded automatically - no need to do any extra work here HM 2021-09-21
    for (let i in data) {
        if (i === 'message') {
            document.querySelectorAll('article.aa-updating[data-aa-part]').forEach(function(elem) {
                elem.classList.remove('aa-updating');
            });
            AA_Message(data[i], 'err');
        } else {
            document.querySelectorAll("[data-aa-part='" + i + "']").forEach(function(elem) {
                if (elem.getAttribute('data-aa-part-update') === 'add') {
                    elem.innerHTML += data[i];  // or innerHTML? (as was in prototype version)
                } else {
                    elem.outerHTML = data[i];
                }
            });
        }
    }
}

/* older - jQyery version of the function
function AA__doChange(params, type) {
    if (type === 'get') {
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
    } else {
        $.post(window.location.href, params, AA__SiteUpdateParts);
    }
}
*/
function AA__doChange(params, type) {
    const headers = {'X-Requested-With': 'xmlhttprequest'};
    const base_url = window.location.href.split('#')[0];
    if (type === 'get') {
        const url = base_url + '?' + new URLSearchParams(params).toString();
        fetch(url, { method: 'GET', credentials: 'same-origin', headers: headers })
            .then(response => response.json())
            .then(data => AA__SiteUpdateParts(data))
            .catch(error => console.error('Error:', error));
    } else if (params instanceof FormData) {
        fetch(base_url, { method: 'POST', body: params, credentials: 'same-origin', headers: headers })
            .then(response => response.json())
            .then(data => AA__SiteUpdateParts(data))
            .catch(error => console.error('Error:', error));
    } else {
        const formData = new URLSearchParams();
        for (let key in params) {
            formData.append(key, params[key]);
        }
        fetch(base_url, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString(), credentials: 'same-origin' })
            .then(response => response.json())
            .then(data => AA__SiteUpdateParts(data))
            .catch(error => console.error('Error:', error));
    }
}

function AA_Translate(text, from, to) {
    const url = `https://translate.googleapis.com/translate_a/single?client=gtx&sl=${from}&tl=${to}&dt=t&q=${encodeURIComponent(text)}`;
    return fetch(url).then(response => response.json()).then(data => {
        return data[0]?.map(item => item[0]).join('') || '';
    })
}


/* Cookies */
function SetCookie(name, value, plustime) {
    var coo = encodeURIComponent(name) + "=" + encodeURIComponent(value);
    plustime = (typeof plustime === "undefined") ? (1000 * 60 * 60 * 24) : plustime;   // a day
    var expires = new Date();
    expires.setTime(expires.getTime() + plustime);
    coo += "; expires=" + expires.toGMTString() + "; path=/";
    coo += (window.location.protocol === 'https:') ? ';secure;samesite=lax' : '';
    document.cookie = coo;
    // + ((expires == null) ? "" : ("; expires=" + expires.toGMTString()))
    // + ((path == null)    ? "" : ("; path=" + path))
    // + ((domain == null)  ? "" : ("; domain=" + domain))
    // + ((secure == true)  ? "; secure" : "");
}

function getCookieVal(offset) {
    var endstr = document.cookie.indexOf(";", offset);
    if (endstr === -1) {
        endstr = document.cookie.length;
    }
    return decodeURIComponent(document.cookie.substring(offset, endstr));
}

function GetCookie(name) {
    var arg  = name + "=";
    var alen = arg.length;
    var clen = document.cookie.length;
    var i    = 0;
    while (i < clen) {
        var j = i + alen;
        if (document.cookie.substring(i, j) === arg) {
            return getCookieVal(j);
        }
        i = document.cookie.indexOf(" ", i) + 1;
        if (i === 0) break;
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
    if ( GetCookie(name) !== val ) {
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

function displayInput(valdivid, item_id, fid, widget_type, widget_properties, context) {
    // already editing ?
    var el = document.getElementById(valdivid);
    switch (el.getAttribute('data-aa-edited')) {
        case '1': return;
        //case '2': document.getElementById(valdivid).setAttribute("data-aa-edited", "0");  // the state 2 is needed for Firefox 3.0 - Storno not works
        //          return;
    }

    el.setAttribute("data-aa-oldval", el.innerHTML);
    var width = el.offsetWidth;
    el.innerHTML = AA_GetConf('loader');
    var lang = AA_GetConf('lang');
    AA_Response('Get_Widget', { "field_id": fid, "item_id": item_id, "widget_type": widget_type, "widget_properties": widget_properties, lang: lang, context: context}, function(responseText) {
        // var style = 'position:absolute; left:0px; top:0px; width:'+width+'px; z-index:1000;';
        if (width>200) {
            responseText = responseText.replace('class="ajax_widget aa-widget aa-ajax-open"', 'class="ajax_widget aa-widget aa-ajax-open" style="width:'+width+'px;"');
        }
        AA_InnerHtmlWithScripts(el, responseText);
        el.setAttribute("data-aa-edited", "1");
        var firstInput = el.querySelector('input, select, textarea');
        if (firstInput != null) {
            firstInput.focus();
            if (firstInput.tagName.toLowerCase()==="textarea") {
                // add keydown event handler on firstElement
                firstInput.addEventListener('keydown', event => {
                    switch (event.key) {
                        case "Escape": el.querySelector('input.cancel-button')?.click(); break; // Esc
                    }
                });
            } else {
                firstInput.addEventListener('keydown', event => {
                    switch (event.key) {
                        case "Escape": el.querySelector('input.cancel-button')?.click(); break;
                        case "Enter":  el.querySelector('input.save-button')?.click(); break;
                        case "Tab":
                            const current = event.target.closest('div.ajax_container');
                            if (current) {
                                const containers = [...document.querySelectorAll('div.ajax_container')];
                                el.querySelector('input.save-button')?.click();
                                containers[containers.indexOf(current) + 1]?.click();
                            }
                            break;
                    }
                });

            }
        }
    });
}
