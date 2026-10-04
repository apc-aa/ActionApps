/** shortcut to parent.querySelector() */
if (typeof AA_$ !== 'function')  { var AA_$ = (s, el=document) => el.querySelector(s); }
/** shortcut to parent.querySelectorAll() */
if (typeof AA_$$ !== 'function') { var AA_$$ = (s, el=document) => Array.from(el.querySelectorAll(s)); }
if (typeof AA_$N !== 'function') { var AA_$N = (n, el=document) => el.getElementsByName(n)?.[0] ?? null; }

// Scripts used in Manager.php
// - following global javascript variables should be set before calling
//   AA_Config structure - see javascript/aajslib.php

//  Fills 'akce' hidden field and submits the form or opens popup window
function MarkedActionGo() {
    var ms = document.itemsform.markedaction_select;
    var add_items;

    if( ms.value && (ms.value != 'nothing') ) {
        document.itemsform.akce.value = ms.value
        // markedactionur is global variable defined in Manager.php
        if( markedactionurl[ms.selectedIndex] != null ) {
            var iftarget = document.itemsform.target;
            var ifaction = document.itemsform.action;
            add_items = (markedactionurladd[ms.selectedIndex] == null) ? false :  markedactionurladd[ms.selectedIndex]
            OpenItemWindow(markedactionurl[ms.selectedIndex], add_items);
            document.itemsform.action = ifaction;
            document.itemsform.target = iftarget;
        } else {
            if (!GetItemsArrayString() && !AA_$N('aa_akce_all').value) {
                AA_$('.aa_manager_actions_all').style.display = '';
            } else {
                document.itemsform.submit();
            }
        }
    }
}

//  Fills 'akce' hidden field and submits the form or opens popup window
// @todo get rid of prototype.js in this function, so manager.js do not need it
function MarkedActionSelect(sb) {
    // markedactionsetting is global variable defined in Manager.php
    // which contain serialized state of the AA object, which should be
    // instatnionated in AA and asked for displaying htmlSetting of the action
    if( sb.value && (sb.value != 'nothing') && (markedactionsetting[sb.selectedIndex] != null) ) {
        AA_Ajax('markedactionparams', AA_GetConf('path') + 'admin/index.php?display_params='+markedactionsetting[sb.selectedIndex]);
    } else {
        document.getElementById('markedactionparams').innerHTML = '';
    }
}

function WriteEmailGo() {
  var iftarget = document.itemsform.target;
  var ifaction = document.itemsform.action;
  OpenItemWindow(markedactionurl[6], "");
  document.itemsform.action = ifaction;
  document.itemsform.target = iftarget;
}

function EmptyTrashQuestion(url, question) {
    if (question && confirm(question)) {
        AA_PostForm(url, {DeleteTrash: 1});
    }
}

/** POSTs fields to url by a throwaway form - not by itemsform, which a row button may lack
 *  (AA finder) or sit outside of (the row "..." menu) */
function AA_PostForm(url, fields) {
    // only to this site - the url comes from the page markup
    const target = new URL(url || location.href, location.href);
    if (target.origin !== location.origin || !target.protocol.startsWith('http')) {
        return;
    }
    const form  = document.createElement('form');
    form.method = 'post';
    form.action = target.href;
    form.hidden = true;
    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = name;
        input.value = value;
        form.append(input);
    }
    document.body.append(form);
    form.submit();
}

/* Row buttons of the Manager (AbstractManageraction::getButton()) run their action by POST.
 * The chb key is the row id as the itemsform checkbox has it. An inline onclick may veto.
 * Registered once - a page may load this script twice, and each click would post twice. */
if (!window.AA_ManagerRowActionsOn) {
    window.AA_ManagerRowActionsOn = true;
    document.addEventListener('click', e => {
        const btn = e.target.closest('button[data-aa-akce]');
        if (!btn || e.defaultPrevented) {
            return;
        }
        const d = btn.dataset;
        if (d.aaConfirm && !confirm(d.aaConfirm)) {
            return;
        }
        AA_PostForm(d.aaUrl, {akce: d.aaAkce, [`chb[x${d.aaChb ?? ''}]`]: ''});
    });
}


// Selects/deselect all item checkboxes on the page
function SelectVis() {
    let sel   = 'form[name="itemsform"] input[name^="chb"]';
    let state = !document.querySelector(sel).checked;
    document.querySelectorAll(sel).forEach(el => { el.checked = state; } );
}

function GetItemsArrayString() {
    var len = document.itemsform.elements.length;
    var itemsstring='';
    var delim='';
    for( var i=0; i<len; i++ ) {
        if( (document.itemsform.elements[i].name.substring(0,3) === 'chb') &&
             document.itemsform.elements[i].checked ) { // checkboxes
            itemsstring += delim + 'items' + document.itemsform.elements[i].name.substring(3);
            delim = '&';
        }
    }
    return itemsstring;
}

var useshowpopup;
function OpenUsershowPopup(url) {
    usershowpopup = open('','usershowpopup','scrollbars=1,resizable=1,width=600,height=200');
    document.itform.target='usershowpopup';
    document.itform.action = url;
    document.itform.submit();
}

var itemwindow;

// if add_items == '&' or '?', items[x5443388....] array with selected items
// is added to the url (used for preview, for example). Use false for no add.
function OpenItemWindow(url, add_items) {
    if (url.indexOf("rXn=1") !== -1) {
        let items;
        if( add_items ) {    // defines items string separator ('&' or '?') in url
            items =  GetItemsArrayString();
            if( items ) {
                url += add_items + items
            }
        }
        if( itemwindow != null ) {
            itemwindow.close(); // in order to itemwindow go on top after open
        }
        itemwindow = open(url,'popup','scrollbars')
    } else {
        itemwindow = open('','popup','scrollbars');
        document.itemsform.target='popup';
        document.itemsform.action = url;
        document.itemsform.submit();
    }
}

// used for returning parameters from popup window back to manager class
function ReturnParam(param) {
    window.opener.document.itemsform.elements['akce_param'].value = param;
//    window.opener.document.itemsform.target='';
//    window.opener.document.itemsform.action='';
    window.opener.document.itemsform.submit();
    window.close();
}

// writes searchbar action to hidden field srchbr_akce and submits
// the searchform. srchbr_akce shouldn't be 0
// you can supply question (for user text input) and/or confirmation question
// result srchbr_akce then have the following format: <akce>[:<text>][:y]
function SearchBarAction( formname, srchbr_akce, question, yes_no ) {
    const srchform = document[formname];
    let answer;
    if ( question ) {
        answer = prompt(question);
        srchbr_akce += ':' + answer.replace(/:/g, "#:");
    }
    if ( yes_no ) {
        srchbr_akce += ( confirm(yes_no) ? ':y' : '' );
    }
    srchform.elements['srchbr_akce'].value = srchbr_akce;
    srchform.submit();
    return true;
}

// the same as above SearchBarAction(), but only ask if we have to do action
function SearchBarActionConfirm( formname, srchbr_akce, confirmtxt ) {
    const srchform = document[formname];
    if ( confirm(confirmtxt) ) {
        srchform.elements['srchbr_akce'].value = srchbr_akce;
        srchform.submit();
        return true;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * AA_Dialog — reusable modal dialog built on the native <dialog> element.
 * Renders a small form (text / checkbox fields) with Save + Cancel buttons.
 *
 *   AA_Dialog.show({
 *       title:       'Save filter',
 *       fields:      [ {name:'name',   type:'text',     label:'Name', value:'', required:true, autofocus:true},
 *                      {name:'global', type:'checkbox', label:'Share', checked:false} ],
 *       submitLabel: 'Save',
 *       cancelLabel: 'Cancel',
 *       onSubmit:    values => { ... }   // keyed by field name; text→string, checkbox→bool
 *   });
 *
 * A single <dialog> is created lazily and reused. ESC, backdrop click and
 * Enter behave natively. Required text fields block submit when empty.
 * ───────────────────────────────────────────────────────────────────────── */
const AA_Dialog = {
    _el: null,

    show(opts) {
        const dlg    = AA_Dialog._dialog();
        const fields = opts.fields || [];

        const rows = fields.map((f, i) => {
            const id = 'aa-dlg-f' + i;
            if (f.type === 'checkbox') {
                return `<label class="aa-dialog-check"><input type="checkbox" id="${id}"${f.checked ? ' checked' : ''}> ${AA_Dialog._esc(f.label)}</label>`;
            }
            return `<label class="aa-dialog-row"><span>${AA_Dialog._esc(f.label)}</span>`
                 + `<input type="text" id="${id}" value="${AA_Dialog._esc(f.value ?? '')}"${f.required ? ' required' : ''}${f.autofocus ? ' autofocus' : ''}></label>`;
        }).join('');

        dlg.innerHTML =
            '<form class="aa-dialog-form">'
          +   (opts.title ? `<h2 class="aa-dialog-title">${AA_Dialog._esc(opts.title)}</h2>` : '')
          +   rows
          +   '<div class="aa-dialog-btns">'
          +     `<button type="button" class="btn btn-l btn-secondary" data-aa-cancel>${AA_Dialog._esc(opts.cancelLabel || 'Cancel')}</button>`
          +     `<button type="submit" class="btn btn-l btn-primary">${AA_Dialog._esc(opts.submitLabel || 'OK')}</button>`
          +   '</div>'
          + '</form>';

        const form = dlg.querySelector('form');
        form.querySelector('[data-aa-cancel]').onclick = () => dlg.close();
        form.onsubmit = (e) => {
            e.preventDefault();
            const values = {};
            let invalid  = null;
            fields.forEach((f, i) => {
                const inp = dlg.querySelector('#aa-dlg-f' + i);
                if (f.type === 'checkbox') {
                    values[f.name] = inp.checked;
                } else {
                    values[f.name] = inp.value;
                    if (f.required && !inp.value.trim() && !invalid) { invalid = inp; }
                }
            });
            if (invalid) { invalid.focus(); return; }
            dlg.close();
            if (opts.onSubmit) { opts.onSubmit(values); }
        };

        // optional live hook: called on every keystroke with the current values
        // and a small ui ({setSubmitLabel}) so callers can relabel the submit
        // button (e.g. Save <-> Refine when the typed name matches an existing one)
        if (opts.onInput) {
            const submitBtn = form.querySelector('button[type=submit]');
            const fire = () => {
                const values = {};
                fields.forEach((f, i) => {
                    const inp = dlg.querySelector('#aa-dlg-f' + i);
                    values[f.name] = (f.type === 'checkbox') ? inp.checked : inp.value;
                });
                opts.onInput(values, { setSubmitLabel: (t) => { submitBtn.textContent = t; } });
            };
            form.addEventListener('input', fire);
            fire();   // set the initial label (a prefilled name may already match)
        }

        dlg.showModal();
    },

    _dialog() {
        if (!AA_Dialog._el) {
            AA_Dialog._el = document.createElement('dialog');
            AA_Dialog._el.className = 'aa-dialog';
            document.body.appendChild(AA_Dialog._el);
        }
        return AA_Dialog._el;
    },

    _esc(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
};

/* Saved-filter (bookmark) dialogs — thin wrappers over AA_Dialog. They read
 * their data from the clicked control's data-* attributes (set in PHP, so a
 * bookmark name never enters a JS string and cannot break out of an attribute).
 * Localized labels come from the global AA_BM_L10N object emitted by the page. */
/* The filter most recently applied is remembered per module in localStorage so
 * the Save dialog can prefill its name + scope (within the last hour, same
 * module). window.AA_BM_NAMES (emitted by the page, keyed by bookmark key)
 * supplies the names for that lookup and for the Save<->Refine label toggle. */
const AA_BM_LAST_TTL    = 3600000;        // remember the last-used filter for 1 hour
const AA_BM_KEY_PREFIX  = 'aa_bm_last_';  // one key per module: aa_bm_last_<module_id>

function AA_BM_moduleId() {
    return new URLSearchParams(location.search).get('module_id') || '';
}

/* Drop stale (or corrupt) aa_bm_last_* entries so the store can't accumulate a
 * dead key per module ever visited — keeps it bounded to the last hour's use.
 * Runs on every save (an infrequent, user-initiated action). */
function AA_BM_prune() {
    const now = Date.now();
    for (let i = localStorage.length - 1; i >= 0; i--) {   // backwards: removeItem reindexes
        const k = localStorage.key(i);
        if (!k || !k.startsWith(AA_BM_KEY_PREFIX)) { continue; }
        let stale = true;
        try {
            const v = JSON.parse(localStorage.getItem(k));
            stale = !v || (now - v.ts) >= AA_BM_LAST_TTL;
        } catch (e) { stale = true; }   // unparseable → drop
        if (stale) { localStorage.removeItem(k); }
    }
}

function AA_BM_saveLast(name, global) {
    const mod = AA_BM_moduleId();
    if (!mod || name == null) { return; }
    try {
        AA_BM_prune();   // sweep stale entries first so the store stays bounded
        localStorage.setItem(AA_BM_KEY_PREFIX + mod, JSON.stringify({ name, global: !!global, ts: Date.now() }));
    } catch (e) { /* private mode / quota — prefill is a nicety, not required */ }
}

function AA_BM_readLast() {
    const mod = AA_BM_moduleId();
    if (!mod) { return null; }
    try {
        const v = JSON.parse(localStorage.getItem(AA_BM_KEY_PREFIX + mod));
        return (v && (Date.now() - v.ts) < AA_BM_LAST_TTL) ? v : null;
    } catch (e) { return null; }
}

/* does a (trimmed) name already name a stored filter? drives Save <-> Refine */
function AA_BM_isExistingName(name) {
    const n = String(name ?? '').trim();
    return n !== '' && Object.values(window.AA_BM_NAMES || {}).some(b => b.name.trim() === n);
}

function AA_FilterSaveDialog(btn) {
    const d    = btn.dataset;
    const last = AA_BM_readLast();   // prefill from the last filter used here, if recent
    const fields = [{ name: 'name', type: 'text', label: AA_BM_L10N.name, value: last ? last.name : '', required: true, autofocus: true }];
    if (d.canglobal === '1') {
        fields.push({ name: 'global', type: 'checkbox', label: AA_BM_L10N.share, checked: last ? last.global : false });
    }
    AA_Dialog.show({
        title:       AA_BM_L10N.saveTitle,
        fields,
        submitLabel: AA_BM_L10N.save,
        cancelLabel: AA_BM_L10N.cancel,
        onInput:     (v, ui) => ui.setSubmitLabel(AA_BM_isExistingName(v.name) ? AA_BM_L10N.refine : AA_BM_L10N.save),
        onSubmit:    v => { AA_BM_saveLast(v.name, v.global); AA_FilterBookmarkSubmit(d.form, 'bookmark', null, v.name, v.global); }
    });
}

function AA_FilterRenameDialog(btn) {
    const d = btn.dataset;
    AA_Dialog.show({
        title:       AA_BM_L10N.renameTitle,
        fields:      [{ name: 'name', type: 'text', label: AA_BM_L10N.name, value: d.name, required: true, autofocus: true }],
        submitLabel: AA_BM_L10N.save,
        cancelLabel: AA_BM_L10N.cancel,
        onSubmit:    v => AA_FilterBookmarkSubmit(d.form, 'bookmarkrename', d.key, v.name, false)
    });
}

/* applies the filter chosen in the "Filter:" dropdown (its onchange handler) */
function AA_FilterSelectApply(sel) {
    const key = sel.value;
    if (key === 'none' || key === '') { return; }   // the "Select one..." placeholder
    const b = (window.AA_BM_NAMES || {})[key];
    if (b) { AA_BM_saveLast(b.name, b.global); }
    AA_FilterBookmarkSubmit(sel.form.name, 'bookmarkgo', key, null, false);
}

/* applies a saved filter (clicking its name in the Manage-filters list) */
function AA_FilterApply(el) {
    const b = (window.AA_BM_NAMES || {})[el.dataset.key];
    if (b) { AA_BM_saveLast(b.name, b.global); }
    AA_FilterBookmarkSubmit(el.dataset.form, 'bookmarkgo', el.dataset.key, null, false);
}

/* deletes a saved filter, after confirmation */
function AA_FilterDelete(btn) {
    if (confirm(AA_BM_L10N.delConfirm)) {
        AA_FilterBookmarkSubmit(btn.dataset.form, 'bookmarkdelete', btn.dataset.key, null, false);
    }
}

/* points srchbr_bookmark at the target row, builds srchbr_akce (mirroring
 * SearchBarAction's ':' / '#:' encoding) and submits the search form -
 * by POST when it changes the stored filters - the server refuses those by GET.
 * The POST goes to the bare page (module_id rides in the form): a query left
 * from a link (?GoBookmark=) would override the filter just stored. */
function AA_FilterBookmarkSubmit(formName, akce, key, name, global) {
    const f = document[formName];
    let action = akce;
    if (name != null) { action += ':' + String(name).replace(/:/g, '#:'); }
    if (global)       { action += ':y'; }
    if (akce !== 'bookmarkgo') {
        const fields = Object.fromEntries(new FormData(f));
        fields.srchbr_akce = action;
        if (key != null && key !== '') {
            fields.srchbr_bookmark = key;
        }
        AA_PostForm(location.pathname, fields);
        return;
    }
    if (key != null && key !== '') {
        f.elements['srchbr_bookmark'].value = key;
    }
    f.elements['srchbr_akce'].value = action;
    f.submit();
}

function OpenWindowIfRequest(formname, bar, admin_url) {
    const doc = document[formname];
    const idx = doc.elements['srchbr_field[' + bar + ']'].selectedIndex;
    const idx2 = doc.elements['srchbr_oper[' + bar + ']'].selectedIndex;

    if (doc.elements['srchbr_oper[' + bar + ']'].options[idx2].value === "select") {
        let sel_val  = doc.elements['srchbr_field[' + bar + ']'].options[idx].value;
        let sel_name = "srchbr_value[" + bar + "]";
        let sel_text = doc.elements['srchbr_value[' + bar + ']'].value;
        OpenConstantsWindow(sel_name, sel_val, sel_text, admin_url);
        if (field_types.charAt(idx) === '3') {
            doc.elements['srchbr_oper[' + bar + ']'].selectedIndex = 2; // equals
        } else if (field_types.charAt(idx) === '4') {                    // numconstants
            doc.elements['srchbr_oper[' + bar + ']'].selectedIndex = 0; // =
        }
    }
}
function ChangeOperators(formname, bar, selectedVal ) {
    const doc = document[formname];
    let idx = doc.elements['srchbr_field[' + bar + ']'].selectedIndex;
    const type = field_types.charAt(idx);

    // added by haha
    // get index of form element named srchbr_value[bar]
    // in order to set default value of this text field
    let srch_field_index = 0;
    while(doc.elements[srch_field_index].name!=="") {
      if(doc.elements[srch_field_index].name ==="srchbr_value["+bar+"]") {
        break;
      }
      srch_field_index++;
    }

    if ((type==='2') && (doc.elements[srch_field_index].value==='')){ // date field
      doc.elements[srch_field_index].value = getToday();
    }
    //else
    //  document.'.$this->form_name.'.elements[srch_field_index].value = "";

    // clear selectbox
    let i;
    for(i = (doc.elements['srchbr_oper[' + bar + ']'].options.length - 1); i>=0; i--){
      doc.elements['srchbr_oper['+bar+']'].options[i] = null
    }
    idx = -1;         // overused variable idx
    // fill selectbox from the right slice
    for( i=0; i<operator_names[type].length ; i++) {
      doc.elements['srchbr_oper['+bar+']'].options[i] = new Option(operator_names[type][i], operator_values[type][i]);
      if( operator_values[type][i] == selectedVal ) {
          idx = i;
      }
    }
    if( idx !== -1 ) {
        doc.elements['srchbr_oper[' + bar + ']'].selectedIndex = idx;
    }
}

/** @return today's date in m/d/y format
 *  @author haha
 */
function getToday(){
    const now = new Date();
    return (now.getMonth() + 1) + "/" + now.getDate() + "/" + ((now.getYear() < 200) ? now.getYear() + 1900 : now.getYear());
}

var constantswindow;  // window for related stories

function OpenConstantsWindow(varname, field_name, sel_text, admin_url) {
    if ((constantswindow != null) && (!constantswindow.closed)) {
        constantswindow.close()    // in order to preview go on top after open
    }
    constantswindow = open(admin_url + "&field_name=" + field_name + "&var_id=" + varname + "&sel_text=" + encodeURIComponent(sel_text) , "popup", "scrollbars=1,resizable=1,width=640,height=500");
}

/** allows to select multiple checkboxes by holding shift key */
function AA_AllowMultiselect(chb_css_rule, title) {
    const chbxs = AA_$$(chb_css_rule);
    let lastChecked = null;
    chbxs.forEach(checkbox => {
        checkbox.title = title;
        checkbox.addEventListener('click', function (e) {
            if (lastChecked && e.shiftKey) {
                let [start, end] = [Array.from(chbxs).indexOf(this), Array.from(chbxs).indexOf(lastChecked)].sort((a, b) => a - b);
                for (let i = start; i <= end; i++) {
                    chbxs[i].checked = lastChecked.checked;
                }
            }
            lastChecked = this;
        });
    });
}
