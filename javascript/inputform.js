// array of listboxes where all selection should be selected
var listboxes = [];
var myform    = document.inputform;
var relatedwindow;  // window for related stories
var urlpickerwindow; // window for local URL picking

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
    return proove_fields();
}


function OpenRelated(varname, sid, mode, design, frombins, conds, condsrw, relwind_url) {
    if ((relatedwindow != null) && (!relatedwindow.closed)) {
        relatedwindow.close()    // in order to preview go on top after open
    }
    var url = GetUrl(relwind_url, ["sid=" + sid, "var_id=" + varname, "mode=" + mode, "design=" + design, "frombins=" + frombins, "showcondsro=" + conds, "showcondsrw=" + condsrw]);
    relatedwindow = open( url, "relatedwindow", "scrollbars=1, resizable=1, width=570");
}

function sb_RemoveItem(selectbox) {
    if ( selectbox.selectedIndex == null ) {
        return;
    }
    s=selectbox.selectedIndex;
    for (i=s; i<selectbox.length; i++) {
        if (selectbox.options[i].value != "wIdThTor") {
            selectbox.options[i].value = selectbox.options[i+1].value;
            selectbox.options[i].text  = selectbox.options[i+1].text;
        }
    }
}

function sb_GetSelectedValue(selectbox) {
    if ( selectbox.selectedIndex == null ) {
        return null;
    }
    return selectbox.options[selectbox.selectedIndex].value;
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
    val     = sb_GetSelectedValue(selectbox);
    if ( val == null ) {
        return;
    }
    // wIdThTor is special AA constant, which behaves as empty string, but the
    // width of selectbox is not zero for it
    new_val = prompt(text, (val=='wIdThTor') ? '' : val);
    if (new_val != null) {
        sb_SetValue(selectbox, selectbox.selectedIndex, new_val, new_val);
    }
}

function EditItemInPopup(inputformurl, selectbox) {
    OpenWindowTop(inputformurl+'&id='+sb_GetSelectedValue(selectbox));
}

function SelectRelations(var_id, tag, prefix, taggedid, headline) {
/* new version ...
    var var_container = 'relation'+var_id;
    var content       = GetContent('dynamic'+var_id, window.opener.document);
    SetContent(var_container,content+'<tr><td><img src="up.gif" width="16" height="16"><img src="down.gif" width="16" height="16"></td><td>'+prefix + headline+'<input type="hidden" name="'+var_id+'[]" value="'+taggedid+'"></td><td>2</td></tr>',window.opener.document);
    */
    sb_SetValue( window.opener.document.inputform.elements[var_id], 'new', prefix + headline, taggedid);
}

function UpdateRelations(var_id, tag, prefix, taggedid, headline) {
    sb_UpdateValue( window.opener.document.inputform.elements[var_id], taggedid, prefix + headline, taggedid);
}

function moveItem(selectbox, type) {
    len = selectbox.length;
    s = selectbox.selectedIndex;
    if (type == "up") {
        s2 = s-1;
        if (s2 < 0) { s2 = 0;}
    } else {
        s2 = s+1;
        if (selectbox.options[s2].value == "wIdThTor") {
            s2 = s;
        }
        if (s2 >= len-1) { s2 = len-1; }
    }
    dummy_val = selectbox.options[s2].value;
    dummy_txt = selectbox.options[s2].text;
    selectbox.options[s2].value = selectbox.options[s].value;
    selectbox.options[s2].text = selectbox.options[s].text;
    selectbox.options[s].value = dummy_val;
    selectbox.options[s].text  = dummy_txt;
    selectbox.selectedIndex = s2;
}

function MoveSelected(left, right) {
    var i=eval(left).selectedIndex;
    if( !eval(left).disabled && ( i >= 0 ) )
    {
        var temptxt = eval(left).options[i].text;
        var tempval = eval(left).options[i].value;
        var length  = eval(right).length;
        if ( (length == 1) && (eval(right).options[0].value=='wIdThTor') ) {  // blank rows are just for <select> size setting
            eval(right).options[0].text = temptxt;
            eval(right).options[0].value = tempval;
        } else {
            eval(right).options[length] = new Option(temptxt, tempval);
        }
        eval(left).options[i] = null;
        if ( eval(left).length != 0 ) {
            eval(left).selectedIndex = ( (i==0) ? 0 : i-1 );
        }
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


// functions for formbreak (split form to more pages)
// ( we do not do it by separate pages, we use much easier approach
//   - switch off the unvanted table rows (display: none))

// (thanks to Bernard Marx from http://www.webmasterworld.com/forum91/1757.htm)
function getElementsbyclassName(className,container) {
  container = container||document ;
  var all = container.all||container.getElementsbyTagName('*');
  var arr = [] ;
  for(var k=0;k<all.length;k++) {
      if(all[k].getAttribute("className") == className) {
          arr[arr.length] = all[k];
      }
  }
  return arr
}

// Usage: getElementsbyclassName("test").setStyle("backgroundColor","red")
Array.prototype.setStyle = function(propName,val) {
    for(var k=0;k<this.length;k++) {
        this[k].style[propName] = val
    }
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

function TabWidgetToggle(class2togle) {
    // hide all input tab rows except the row of "class2togle"

    $$('#inputtabrows tr[class^=formrow]').invoke('hide');
    $$('#inputtabrows tr[class^='+class2togle+']').invoke('show');
    //ShowThisTagClass('inputtabrows', 'tr', class2togle, 'formrow');

    $$('#formtabs a').invoke('addClassName','tabsnonactiv').invoke('removeClassName','tabsactiv');
    $$('#formtabs'+class2togle).invoke('addClassName','tabsactiv').invoke('removeClassName','tabsnonactiv');

    $$('#formtabs2 a').invoke('addClassName','tabsnonactiv').invoke('removeClassName','tabsactiv');
    $$('#formtabs2'+class2togle).invoke('addClassName','tabsactiv').invoke('removeClassName','tabsnonactiv');
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
function switchHTML(name) {
    elem = $$('input[name="'+name+"html"+'"]');
    //elem = document.inputform.eval(name+"html");
    if ( elem != null ) {
        for (i=0; i<elem.length; i++) {
            if (elem[i].value == "h") {
                elem[i].checked = true;
            }
        }
    }
    if (CKEDITOR.env.isCompatible) {
        $("htmlplainspan"+name).hide();
    }
}

function showHTMLAreaLink(name) {
    if (CKEDITOR.env.isCompatible) {
        elem = document.getElementById("arealinkspan"+name);
        if( elem && (elem != null) ) {
            elem.style.display = "inline";
        }
    }
}

function openHTMLAreaFullscreen(name, session) {    // open HTMLArea in popupwindow
    switchHTML(name);
    CKEDITOR.replace(name);
}


