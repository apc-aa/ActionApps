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
    // templates call AA_AjaxInsert(this, ...) on an <a> with no id - prototype.js's
    // identify() used to auto-assign one, the vanilla rewrite must do it explicitly
    if (!a_obj.id) {
        a_obj.id = 'aa_el_' + AA_NewId();
    }
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

/** Serializes the named form controls inside `root` into a query string.
 *  FormData is no help here - AA serializes controls sitting in a <div>/<fieldset>,
 *  not in a <form>. Skips what jQuery's .serialize() skips: unnamed and disabled
 *  controls, buttons, file inputs and unchecked boxes.
 *  @param {HTMLElement} root
 *  @returns {string} e.g. "aa%5Bn1%5D%5Bheadline%5D=Hi&slice_id=1234"
 */
function AA__Serialize(root) {
    const params = new URLSearchParams();
    // browsers send CRLF for newlines on a real form submit - do the same, or textarea
    // content would reach the server one byte per line shorter than it used to
    const add = (name, value) => params.append(name, String(value).replace(/\r?\n/g, '\r\n'));
    AA_$$('input, select, textarea', root).forEach(el => {
        if (!el.name || el.matches(':disabled') || ['submit', 'button', 'image', 'reset', 'file'].includes(el.type)) {
            return;
        }
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
            return;
        }
        if (el.tagName === 'SELECT') {
            // as jQuery: only enabled selected options, nothing for a select with none
            [...el.selectedOptions].filter(opt => !opt.matches(':disabled')).forEach(opt => add(el.name, opt.value));
            return;
        }
        add(el.name, el.value);
    });
    return params.toString();
}

/** Sends the "add new item" form opened by AA_AjaxInsert and puts the new item
 *  into the selectbox the form belongs to, then closes the form.
 *  The opening <a id="a<selectbox_id>"> sits right before the div AA_AjaxInsert
 *  created, so it is both the way back to the selectbox and the thing to click closed.
 *  @param {string} id  id of the fieldset holding the form (see AA_Form::getAjaxHtml)
 */
function AA_AjaxSendAddForm(id) {
    const form = document.getElementById(id);
    let   opener = form.parentElement.closest('div')?.previousElementSibling;
    while (opener && opener.tagName !== 'A') {
        opener = opener.previousElementSibling;
    }
    if (!opener) {
        return;
    }
    const selectbox = document.getElementById(opener.id.substring(1));   // <a> id is 'a' + selectbox id
    const code      = AA__Serialize(form);

    form.insertAdjacentHTML('beforeend', AA_GetConf('loader'));

    fetch(AA_GetConf('path') + 'filler.php', {
        method:      'POST',
        headers:     {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body:        code,
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            for (const key in data) {
                sb_SetValue(selectbox, 'new', data[key], key);   // key is the item id, value its label
            }
            AA_AjaxInsert(opener, '');   // the _ins div exists, so this closes the form
        })
        .catch(error => console.error('Error:', error));
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
/** Loads an external script once.
 *  @param condition truthy = treat as already loaded; 'load_once' = look for the same src in the DOM
 *  @param callback  run when loaded - AA_IsLoaded counts down to fire the queued run-code
 *  @param url       script src
 *  @param sync      true = script.async = false, keeping insertion order (CodeMirror 5 addons need it)
 *  @param opts      optional {type: 'module', integrity: 'sha256-...'}
 */
function AA_LoadJs(condition, callback, url, sync, opts) {
    if (condition && ((condition!=='load_once') || (url && document.querySelector('script[src="'+url+'"]')))) {
        if (callback) {
            callback();
        }
        return;
    }
    const options = opts || {};
    const script  = document.createElement('script');
    // type=module also fires onload after the whole module graph has executed,
    // so the AA_IsLoaded countdown keeps working for ESM libraries.
    script.type = (options.type === 'module') ? 'module' : 'text/javascript';
    if (sync) {
        script.async = false;
    }
    if (options.integrity) {
        script.integrity   = options.integrity;
        script.crossOrigin = 'anonymous';
    }
    script.onload = function () {
        if (callback) {
            callback();
        }
    };
    // Without this a script that 404s or fails its SRI check would stall the
    // AA_IsLoaded countdown for good and the queued run-code would never fire.
    script.onerror = function () {
        console.error('AA_LoadJs: failed to load ' + url);
        if (callback) {
            callback();
        }
    };
    script.src = url;
    document.head.append(script);
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

/** Live widgets whose last save would be lost on leaving the page: sent without
 *  keepalive (no room left for it) or failed. */
if (!window.AA__LiveUnsaved) {
    window.AA__LiveUnsaved = new Set();
}
/** number of the newest save per widget - only its answer may set the widget's state */
if (!window.AA__LiveSeq) {
    window.AA__LiveSeq = new Map();
}
/** keepalive bytes on their way - browsers give all keepalive requests of a page 64 kB together */
if (typeof window.AA__LiveKeepalive !== 'number') {
    window.AA__LiveKeepalive = 0;
}

/** Asks before leaving a page with an unsaved live edit. Registered on first use:
 *  Firefox keeps no page with a beforeunload listener in its back/forward cache. */
function AA_LiveGuard() {
    if (window.AA__LiveGuarded) {
        return;
    }
    window.AA__LiveGuarded = true;
    window.addEventListener('beforeunload', e => {
        // an editor keeps the last edit until focus leaves it - send it now
        AA_$$('aa-code, aa-richtext').forEach(el => el.flush?.());
        // a widget an AJAX refresh replaced has nothing left to lose
        if ([...window.AA__LiveUnsaved].some(id => document.getElementById('widget-' + id))) {
            AA_WindowUnloadQ(e);
        }
    });
}

/** Saves one live widget - all controls in #widget-<base_id> - through filler.php.
 *  keepalive lets the save finish when the click that ended the edit leaves the page.
 *  @param {string}   base_id
 *  @param {Element}  liveinput  the control that changed (validated first)
 *  @param {Function} fnc        called after a successful save
 */
function AA_SendWidgetLive(base_id, liveinput, fnc) {
    AA_LiveGuard();
    AA_StateChange(base_id, 'updating');
    if (typeof liveinput.checkValidity === 'function' && !liveinput.checkValidity()) {
        AA_StateChange(base_id, 'invalid');
        return;
    }
    const unsaved = window.AA__LiveUnsaved;
    const mine    = (window.AA__LiveSeq.get(base_id) ?? 0) + 1;
    window.AA__LiveSeq.set(base_id, mine);
    const body    = AA__Serialize(document.getElementById('widget-' + base_id)) + '&inline=1';
    const bytes   = new Blob([body]).size;
    // decided before sending: a rejected keepalive request is not sent again - while the
    // page is being left it may still arrive, and a second copy would save twice
    const keepalive = (window.AA__LiveKeepalive + bytes) <= 60000;
    if (keepalive) {
        window.AA__LiveKeepalive += bytes;
    } else {
        unsaved.add(base_id);
    }
    fetch(AA_GetConf('path') + 'filler.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
        body,
        keepalive,
    }).then(response => response.ok, () => false).then(ok => {
        if (keepalive) {
            window.AA__LiveKeepalive -= bytes;
        }
        if (window.AA__LiveSeq.get(base_id) !== mine) {
            return;                 // a newer save of this widget is on its way
        }
        if (!ok) {
            unsaved.add(base_id);
            AA_StateChange(base_id, 'dirty');
            return;
        }
        unsaved.delete(base_id);
        AA_StateChange(base_id, 'normal');
        if (typeof fnc === 'function') {
            fnc();                  // after the state: an error in it is its own, not a failed save
        }
    });
}

/** Saves every changed {editable} field of the page in one request (widgets/aa-editable.js). */
function AA_SaveEditors() {
    const params = new URLSearchParams({ inline: '1' });
    const saved  = [];
    AA_$$('[contenteditable][data-aa-field]').forEach((el) => {
        if (!el.aaEditor?.isDirty()) {
            return;
        }
        const field = `aa[u${el.dataset.aaId}][${el.dataset.aaField}]`;
        params.append(`${field}[${el.dataset.aaIndex ?? 0}]`, el.aaEditor.getContent());
        params.append(`${field}[flag]`, '1');
        saved.push(el.aaEditor);
    });
    fetch(AA_GetConf('path') + 'filler.php', { method: 'POST', body: params, headers: { Accept: 'application/json' } })
        .then(response => response.json())
        .then((data) => {
            saved.forEach(editor => editor.markSaved());
            AA_Toolbar('');
            window.removeEventListener('beforeunload', AA_WindowUnloadQ);
            const n = Object.keys(data).length;
            AA_Message((n === 1) ? 'Zmeny ulozeny' : ('Ulozeno ' + n + ' zmen'));
        });
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

/** Translate `text` from one language to another.
 *
 *  Tries the service straight from the page first, so the load and the rate limit
 *  stay spread across users' own IPs. Falls back to AA's endpoint, which is the
 *  only way to survive a content blocker on one machine, to see the real HTTP
 *  status (error responses carry no CORS header, so here they are opaque) and to
 *  log that it happened.
 *
 *  Rejects rather than resolving with an empty string - callers write the result
 *  straight into a field and would wipe it.
 *
 *  @param {string} text  text to translate
 *  @param {string} from  source language code, e.g. 'cs'
 *  @param {string} to    target language code, e.g. 'uk'
 *  @returns {Promise<string>} the translated text
 */
async function AA_Translate(text, from, to) {
    if (typeof text !== 'string' || text.trim() === '') {
        throw new Error('Nothing to translate');
    }
    const direct = await AA__TranslateDirect(text, from, to);
    return direct !== null ? direct : AA__TranslateViaAa(text, from, to);
}

/** Direct call to the translation service. Returns null on any failure - never
 *  throws - so AA_Translate() can just try the server instead. POST, not GET:
 *  over GET the text hits a ~16 kB URL limit.
 *  @returns {Promise<string|null>} */
async function AA__TranslateDirect(text, from, to) {
    const url = 'https://translate.googleapis.com/translate_a/single?client=gtx&dt=t'
              + '&sl=' + encodeURIComponent(from) + '&tl=' + encodeURIComponent(to);
    try {
        const response = await fetch(url, {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body:    new URLSearchParams({q: text}).toString(),
            signal:  AbortSignal.timeout(15000)
        });
        if (!response.ok) {
            return null;
        }
        const data = await response.json();
        const out = (data?.[0] ?? []).map(seg => seg?.[0]).filter(v => typeof v === 'string').join('');
        return out === '' ? null : out;
    } catch {
        // Blocked, throttled or offline - all indistinguishable here, and all
        // mean the same thing: let the server try.
        return null;
    }
}

/** Translate through AA's own endpoint (api/translate.php). Throws with the
 *  server's message, which unlike the direct call knows what really went wrong.
 *  @returns {Promise<string>} */
async function AA__TranslateViaAa(text, from, to) {
    const response = await fetch(AA_GetConf('path') + 'api/translate.php', {
        method:      'POST',
        headers:     {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body:        new URLSearchParams({text, from, to}).toString(),
        credentials: 'same-origin',
        // Without it a stalled connection leaves the button spinning forever.
        signal:      AbortSignal.timeout(120000)
    });
    const data = await response.json().catch(() => ({}));   // error pages are not always JSON
    if (!response.ok || typeof data.text !== 'string' || data.text === '') {
        throw new Error(data.error || ('Translation failed (HTTP ' + response.status + ')'));
    }
    return data.text;
}

/** Runs one "translate from X" button: reads the source, translates it, writes
 *  the target, and reports a failure instead of leaving the button dead.
 *
 *  Source and target are callbacks because every widget keeps its value elsewhere
 *  (input value, CKEditor, CodeMirror) and that object may not exist yet.
 *
 *  @param {function(): string} getSource      reads the source text
 *  @param {function(string): void} setTarget  writes the translated text
 *  @param {string} from   source language code
 *  @param {string} to     target language code
 *  @param {Element} [btn] the clicked button, shown busy while the call runs
 */
async function AA_TranslateInto(getSource, setTarget, from, to, btn) {
    if (btn?.classList.contains('aa-updating')) {
        return;    // already translating - ignore the double click
    }
    btn?.classList.add('aa-updating');
    try {
        // Both callbacks sit inside the try: a missing editor throws there.
        setTarget(await AA_Translate(getSource(), from, to));
    } catch (err) {
        AA_Message('Translation failed: ' + (err?.message || err), 'err');
    } finally {
        btn?.classList.remove('aa-updating');
    }
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

function displayInput(valdivid, item_id, fid, widget_type, widget_properties, context, sig) {
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
    AA_Response('Get_Widget', { "field_id": fid, "item_id": item_id, "widget_type": widget_type, "widget_properties": widget_properties, lang: lang, context: context, sig: sig || ''}, function(responseText) {
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
