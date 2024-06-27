/** shortcut to parent.querySelector() */
if (typeof AA_$ !== 'function')  { var AA_$ = (sel, el = document) => el.querySelector(sel); }
/** shortcut to parent.querySelectorAll() */
if (typeof AA_$$ !== 'function') { var AA_$$ = (sel, el = document) => Array.from(el.querySelectorAll(sel)); }
if (typeof AA_$N !== 'function') { var AA_$N = function (n) { let ret; return (ret = document.getElementsByName(n)) ? ret[0] : null; } }

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
    if ( question ) {
        if (confirm(question)) {
            open(url,"_parent");
        }
    }
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
        if( (document.itemsform.elements[i].name.substring(0,3) == 'chb') &&
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
