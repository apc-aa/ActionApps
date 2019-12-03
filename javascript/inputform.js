// array of listboxes where all selection should be selected
// array of listboxes where all selection should be selected
var listboxes = [];
var myform    = document.inputform;
var relatedwindow;  // window for related stories
var urlpickerwindow; // window for local URL picking


if (typeof window.AA_GetConf === 'undefined') {
    window.AA_GetConf = function(prop) {
        if (typeof AA_GetConf.aa_install_path == 'undefined') {
            var src = document.querySelector('script[src*="javascript/inputform"]').getAttribute('src');
            AA_GetConf.aa_install_path = src.replace(/javascript\/inputform.*$/, '');
        }
        switch (prop) {
            case 'path':
                return AA_GetConf.aa_install_path;
            case 'loader':
                return '<img src="' + AA_GetConf.aa_install_path + 'images/loader.gif" border=0 width=16 height=16>';
        }
    }
}

function SelectAllInBox( listbox ) {
    for (var i = 0; i < document.inputform[listbox].length; i++) {
        // select all rows without the wIdThTor one, which is only for <select> size setting
        document.inputform[listbox].options[i].selected = ( document.inputform[listbox].options[i].value != "wIdThTor" );
    }
}

// before submit the form we need to select all selections in some
// listboxes (2window, relation) in order the rows are sent for processing
function BeforeSubmit() {
    for(var i = 0; i < listboxes.length; i++) {
        SelectAllInBox( listboxes[i] );
    }
    return true;  // proove_fields();
}


function OpenRelated(varname, sid, mode, design, frombins, conds, condsrw, slice_field, relwind_url) {
    if ((relatedwindow != null) && (!relatedwindow.closed)) {
        relatedwindow.close()    // in order to preview go on top after open
    }
    var url = GetUrl(relwind_url, ["sid=" + sid, "var_id=" + varname, "mode=" + mode, "design=" + design, "frombins=" + frombins, "showcondsro=" + conds, "showcondsrw=" + condsrw, "slice_field=" + slice_field]);
    relatedwindow = open( url, "relatedwindow", "scrollbars=1, resizable=1, width=570");
}

function sb_RemoveItem(selectbox) {
    var si = selectbox.selectedIndex;
    if ( si > -1 ) {
        selectbox.removeChild(selectbox.options[si]);
        if (selectbox.options.length) {
            selectbox.selectedIndex = Math.max(si-1, 0);
        }
    }
}

function sb_SetValue(selectbox, index, text, value) {
    if (selectbox != null && selectbox.options != null) {
        if (index=='new') {
            // find "empty" row
            for( i=0; i < selectbox.options.length; i++ ) { // maxcount is global as well as relmessage
                if( (selectbox.options[i].value == 'wIdThTor') || selectbox.options[i].value == '') break;
            }

            if ( i == selectbox.options.length ) {
                selectbox.options[selectbox.options.length] = new Option(text, value);
                return;
            }
            index = i;
        }
        if ((text != null) && (value != null)) {
            if (value == '') {
                value = 'wIdThTor';
            }
            selectbox.options[index].value = value;
            selectbox.options[index].text  = text;
        }
    }
}

function sb_UpdateValue(selectbox, old_value, text, value) {
    // find row to update (contains old_value)
    for ( i=0; i < selectbox.length; i++ ) {
        if ( selectbox.options[i].value == old_value )
        {
            sb_SetValue(selectbox, i, text, value);
            break;
        }
    }
}

function sb_AddValue(selectbox, text) {
    new_val = prompt(text, '');
    if (new_val != null) {
        sb_SetValue(selectbox, 'new', new_val, new_val);
    }
}

function sb_EditValue(selectbox, text) {
    if ( selectbox.selectedIndex == -1 ) {
        return;
    }
    val = selectbox.value;
    // wIdThTor is special AA constant, which behaves as empty string, but the
    // width of selectbox is not zero for it
    new_val = prompt(text, (val=='wIdThTor') ? '' : val);
    if (new_val != null) {
        sb_SetValue(selectbox, selectbox.selectedIndex, new_val, new_val);
    }
}

function EditItemInPopup(inputformurl, selectbox) {
    OpenWindowTop(inputformurl+'&id='+selectbox.value);
}

function SelectRelations(var_id, tag, prefix, taggedid, headline) {
    sb_SetValue( window.opener.document.inputform.elements[var_id], 'new', prefix + headline, taggedid);
}

function UpdateRelations(var_id, tag, prefix, taggedid, headline) {
    sb_UpdateValue( window.opener.document.inputform.elements[var_id], taggedid, prefix + headline, taggedid);
}

function moveItem(selectbox, type) {
    var si = selectbox.selectedIndex;
    if ( (type=='up') && (si > 0)) {
        selectbox.insertBefore(selectbox.options[si], selectbox.options[si - 1]);
    } else if ((type=='down') && ((si+1) < selectbox.length)) {
        selectbox.insertBefore(selectbox.options[si], selectbox.options[si+1].nextSibling);
    }
}

//moves selected rows of left listbox to the right one
function MoveSelected(left, right) {
    var temptxt, tempval, length;
    var i=left.selectedIndex;
    var last_selected = i;
    while( !left.disabled && ( i >= 0 ) ) {
        temptxt = left.options[i].text
        tempval = left.options[i].value
        length  = right.length
        if( (length == 1) && (right.options[0].value=='wIdThTor') ){  // blank rows are just for <select> size setting
            right.options[0].text = temptxt;
            right.options[0].value = tempval;
        } else {
            right.options[length] = new Option(temptxt, tempval);
        }
        left.options[i] = null
        last_selected = i;
        i=left.selectedIndex
    }
    // now select next option
    if( left.length != 0 ) {
        left.selectedIndex = ((last_selected < left.length) && (last_selected > 0) ? last_selected : 0);
    }
}

function add_to_line(inputbox, value) {
    if (inputbox.value.length != 0) {
        inputbox.value=inputbox.value+","+value;
    } else {
        inputbox.value=value;
    }
}

// This script invokes Word/Excel convertor (used in textareas on inputform)
// You must have the convertor it installed
// @param string aa_instal_path - relative path to AA on server (like"/apc-aa/")
// @param string textarea_id    - textarea fomr id (like "v66756c6c5f746578742e2e2e2e2e2e31")
function CallConvertor(aa_instal_path, textarea_id) {
    page = aa_instal_path + "misc/msconvert/index.php3?inputid=" + textarea_id;
    conv = window.open(page,"convwindow","width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes");
    conv.focus();
}

// displays all tags 'classtoshow' of 'type', which is in 'where' id
// and hide all such tags which class begins with 'classmasktohide'
function ShowThisTagClass(where,type,classtoshow,classmasktohide) {
    // hide all input tab rows except the row of "class2togle"
    var yo = document.getElementById(where).getElementsByTagName(type);
    var yoclass;

    // hide all parts except the selected one
    for (var i=0; i < yo.length; i++) {
        yoclass = yo[i].className;
        if ( yoclass == classtoshow ) {
            yo[i].style.display = '';
        } else if ( yoclass && (yoclass.substring(0,classmasktohide.length) == classmasktohide) ) {
            yo[i].style.display = 'none';
        }
    }
}

// hide all input tab rows except the row of "class2togle"
function TabWidgetToggle(class2togle) {
    var els, i;

    els = document.querySelectorAll("#inputtabrows tr[class^=formrow]");
    for (i = 0; i < els.length; i++) { els[i].style.display = 'none'; }
    els = document.querySelectorAll('#inputtabrows tr[class^='+class2togle+']');
    for (i = 0; i < els.length; i++) { els[i].style.display =''; }

    els = document.querySelectorAll('#formtabs a, #formtabs2 a');
    for (i = 0; i < els.length; i++) { els[i].classList.add('tabsnonactiv'); els[i].classList.remove('tabsactiv'); }
    els = document.querySelectorAll('#formtabs'+class2togle + ', #formtabs2'+class2togle);
    for (i = 0; i < els.length; i++) { els[i].classList.add('tabsactiv'); els[i].classList.remove('tabsnonactiv'); }
}

//BEGIN// Local URL Picker | Omar/Jaime | 11-06-2005
function OpenLocalURLPick(varname, url, aa_instal_path, value) {
    if ((urlpickerwindow != null) && (!urlpickerwindow.closed)) {
        urlpickerwindow.close()    // in order to preview go on top after open
    }
    page = aa_instal_path + "/localurlpick.php3?var_id=" + varname + "&url=" + url + "&value=" + value
    urlpickerwindow = open(page, "urlpickerwindow", "scrollbars=1, resizable=1, height=600 width=800 menubar=no");
}

function sb_ClearField(field) {
    field.value='';
}
//END// Local URL Picker | Omar/Jaime | 11-06-2005


// APC-AA javascript 3 functions for HTMLArea -- was in htmlarea/aafunc.js
function showHTMLAreaLink(name) {
    var elem;
    if (CKEDITOR.env.isCompatible) {
        if ( elem = document.getElementById("arealinkspan"+name) ) {
            elem.style.display = "inline";
        }
    }
}

function OLDopenHTMLAreaFullscreen(name) {    // open HTMLArea in popupwindow
    if (CKEDITOR.env.isCompatible) {
        var elem = document.body.querySelectorAll('input[name="'+name+"html"+'"]');
        for (i=0; i<elem.length; i++) {
            if (elem[i].value == "h") {
                elem[i].checked = true;
            }
        }
        if ( elem = document.getElementById("htmlplainspan"+name) ) {
            elem.style.display = "none";
        }
        if ( elem = document.getElementById("arealinkspan"+name) ) {
            elem.style.display = "none";
        }
        CKEDITOR.replace(name);
    }
}

function openHTMLAreaFullscreen(name) {    //
    if (CKEDITOR.env.isCompatible) {
        if (el = document.body.querySelector('input[name="'+name+'html'+'"][value="h"]')) {
            el.checked = true;
        }
        if ( el = document.getElementById("htmlplainspan"+name) ) {
            el.style.display = "none";
        }
        if ( el = document.getElementById("arealinkspan"+name) ) {
            el.style.display = "none";
        }
        CKEDITOR.replace(name);
    }
}


