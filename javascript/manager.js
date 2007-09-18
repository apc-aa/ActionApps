// Scripts used in manager.class.php3
// - folloving global javascript variables should be set before calling
//   AA_Config structure - see javascript/aajslib.php3

//  Fills 'akce' hidden field and submits the form or opens popup window
function MarkedActionGo() {
    var ms = document.itemsform.markedaction_select;
    var add_items;
    if( ms.options[ms.selectedIndex].value &&  (ms.options[ms.selectedIndex].value != 'nothing') ) {
        document.itemsform.akce.value = ms.options[ms.selectedIndex].value
        // markedactionur is global variable defined in manager.class.php3
        if( markedactionurl[ms.selectedIndex] != null ) {
            var iftarget = document.itemsform.target;
            var ifaction = document.itemsform.action;
            add_items = (markedactionurladd[ms.selectedIndex] == null) ? false :  markedactionurladd[ms.selectedIndex]
            OpenItemWindow(markedactionurl[ms.selectedIndex], add_items);
            document.itemsform.action = ifaction;
            document.itemsform.target = iftarget;
        } else {
            document.itemsform.submit()
        }
    }
}

//  Fills 'akce' hidden field and submits the form or opens popup window
function MarkedActionSelect() {
    if( $F('markedaction_select') && ($F('markedaction_select') != 'nothing') ) {
        // markedactionsetting is global variable defined in manager.class.php3
        // which contain serialized state of the AA object, which should be
        // instatnionated in AA and asked for displaying htmlSetting of the action
        if( markedactionsetting[$('markedaction_select').selectedIndex] != null ) {
            $('makrekactionparams').update('<img src="' + AA_Config.AA_INSTAL_PATH + 'images/loader.gif">');
            new Ajax.Updater('makrekactionparams', AA_Config.AA_INSTAL_PATH + 'admin/index.php3?' + AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID +"&display_params="+markedactionsetting[$('markedaction_select').selectedIndex]);
        } else {
            $('makrekactionparams').update('');
        }
    } else {
        $('makrekactionparams').update('');
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


// Selects/deselect all item chckboxes on the page
function SelectVis() {
    var len = document.itemsform.elements.length
    state = 2
    for( var i=0; i<len; i++ ) {
        if( document.itemsform.elements[i].name.substring(0,3) == 'chb') { // checkboxes
            if (state == 2) {
                state = ! document.itemsform.elements[i].checked;
            }
            document.itemsform.elements[i].checked = state;
        }
    }
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
    if (url.indexOf("rXn=1") != -1) {
        var items;
        if( add_items ) {    // defines items string separator ('&' or '?') in url
            items =  GetItemsArrayString();
            if( items ) {
                url += add_items + items
            }
        }

        if( itemwindow != null )
            itemwindow.close();    // in order to itemwindow go on top after open
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
    var srchform = eval( "document."+formname );
    var answer;
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
    var srchform = eval( "document."+formname );
    if ( confirm(confirmtxt) ) {
        srchform.elements['srchbr_akce'].value = srchbr_akce;
        srchform.submit();
        return true;
    }
}

// called by the f_k alias function (see item.php3)
// - folloving global javascript variables should be set before calling
function CallLiveCheckbox(controlName) {
    myimg     = document.itemsform[controlName];
    myimg.src = AA_Config.AA_INSTAL_PATH + "images/cb_2off.gif";
    imgsrc    = AA_Config.AA_INSTAL_PATH + "live_checkbox.php3?" + AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID +"&"+controlName+"=1&no_cache="+Math.random();
    setTimeout("ChangeImgSrc ('"+controlName+"','"+imgsrc+"')", 1);
}

// - folloving global javascript variables should be set before calling
function CallLiveChange(controlName, status) {
    switch (status) {
      case "change" :
            controlName = controlName + "_chb";
            imgsrc = A_Config.AA_INSTAL_PATH + "images/cb_off.gif";
            ChangeImgSrc(controlName,imgsrc);
           break;
      case "click" :
            mysel = eval("document.itemsform['"+controlName+"']");
            val = mysel.options[mysel.selectedIndex].value;

            imgsrc = A_Config.AA_INSTAL_PATH + "images/cb_off.gif";
            ChangeImgSrc(controlName+"_chb",imgsrc);

            imgsrc    = AA_Config.AA_INSTAL_PATH + "live_change.php3?" + AA_Config.SESS_NAME + '=' + AA_Config.SESS_ID +"&"+controlName+"="+val+"&no_cache="+Math.random();
            setTimeout("ChangeImgSrc('"+controlName+"_chb', '"+imgsrc+"')", 1);
           break;
    }
}

function ChangeImgSrc(imageName, newsrc) {
    document.itemsform[imageName].src = newsrc;
}

function OpenWindowIfRequest(form_name, slice_id, bar, admin_url) {
    var doc = eval("document."+form_name);
    var idx=doc.elements['srchbr_field['+bar+']'].selectedIndex;
    var idx2=doc.elements['srchbr_oper['+bar+']'].selectedIndex;

    if ((field_types.charAt(idx) == 3) && (doc.elements['srchbr_oper['+bar+']'].options[idx2].value == "select")) {
        sel_val = doc.elements['srchbr_field['+bar+']'].options[idx].value;
        sel_name = "srchbr_value["+bar+"]";
        sel_text = doc.elements['srchbr_value['+bar+']'].value;
        OpenConstantsWindow(sel_name,slice_id,sel_val,1, sel_text, admin_url);
        doc.elements['srchbr_oper['+bar+']'].selectedIndex = idx2 - 1;
    }
}
function ChangeOperators(form_name, bar, selectedVal ) {
    var doc = eval("document."+form_name);
    var idx=doc.elements['srchbr_field['+bar+']'].selectedIndex;
    var type = field_types.charAt(idx);

    // added by haha
    // get index of form element named srchbr_value[bar]
    // in order to set default value of this text field
    srch_field_index=0;
    while(doc.elements[srch_field_index].name!="") {
      if(doc.elements[srch_field_index].name =="srchbr_value["+bar+"]") {
        break;
      }
      srch_field_index++;
    }

    if ((type=='2') && (doc.elements[srch_field_index].value=='')){ // date field
      doc.elements[srch_field_index].value = getToday();
    }
    //else
    //  document.'.$this->form_name.'.elements[srch_field_index].value = "";

    // clear selectbox
    for( i=(doc.elements['srchbr_oper['+bar+']'].options.length-1); i>=0; i--){
      doc.elements['srchbr_oper['+bar+']'].options[i] = null
    }
    idx = -1;         // overused variable idx
    // fill selectbox from the right slice
    for( i=0; i<operator_names[type].length ; i++) {
      doc.elements['srchbr_oper['+bar+']'].options[i] = new Option(operator_names[type][i], operator_values[type][i]);
      if( operator_values[type][i] == selectedVal )
          idx = i;
    }
    if( idx != -1 )
        doc.elements['srchbr_oper['+bar+']'].selectedIndex = idx;
}

/*
added by haha
function returning today's date in m/d/y format
*/
function getToday(){
    now = new Date();
    today = (now.getMonth()+1)+"/"+ now.getDate() + "/"+ ((now.getYear() < 200) ? now.getYear()+1900 : now.getYear());
    return today;
}

var constantswindow;  // window for related stories

function OpenConstantsWindow(varname, sid, field_name, design, sel_text, admin_url) {
    if ((constantswindow != null) && (!constantswindow.closed)) {
        constantswindow.close()    // in order to preview go on top after open
    }
    constantswindow = open(admin_url+"&sid=" + sid +
        "&field_name=" + field_name + "&var_id=" + varname + "&design=" + design +
        "&sel_text=" + sel_text.replace(/&/gi,"%26") , "popup", "scrollbars=1,resizable=1,width=640,height=200");
}


function ReplaceFirstChar( str, ch ) {
    return   ch + str.substring(1,str.length);
}

