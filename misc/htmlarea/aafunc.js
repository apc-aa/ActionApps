// APC-AA javascript functions for HTMLArea


isIE = document.all && !window.opera;
RecID = Array();
function ConfirmRemove() {
    if (confirm("Remove ?"))  return true;
    return false;
}

function RemoveRecord(e) {
    if (ConfirmRemove()) {
        if (!e && window.event) e = window.event;
        if (!e.target) e.target = e.srcElement;
        if (isIE) {
            e.returnValue = false;
            var record = e.target.id;
        } else {
            e.preventDefault();
            var record = this.id;
        }
        var brindex = record.lastIndexOf('_');
        var tbid = record.slice(7,brindex);
        var reciid = record.slice(brindex+1);
        var tbody = document.getElementById('tbody_'+tbid);
        var record = document.getElementById(tbid + '_' + reciid);
        tbody.removeChild(record);
    }
}

function RemoveRecord2(record) {
    if (ConfirmRemove()) {
        var brindex = record.lastIndexOf('_');
        var tbid = record.slice(7,brindex);
        var reciid = record.slice(brindex+1);
        var tbody = document.getElementById('tbody_'+tbid);
        var record = document.getElementById(tbid + '_' + reciid);
        tbody.removeChild(record);
    }
}

// function replaces html code of a an HTML element (identified by id)
// by another code
function SetContent(id,txt) {
  if (document.all) {                    // IE 4+
    el = document.all[id];
    if ( el != null ) {
      el.innerHTML=txt;
    }
  } else if (document.layers) {            // NS 4
    eval('document.ids.'+id).document.write(txt);
    eval('document.ids.'+id).document.close();
  } else if (document.getElementById){   // NS 6 (new DOM)
    rng = document.createRange();
    el = document.getElementById(id);
    if ( el != null ) {  // in case the element do not exist => do nothing
      rng.setStartBefore(el);
      htmlFrag = rng.createContextualFragment(txt);
      while (el.hasChildNodes())
        el.removeChild(el.lastChild);
      el.appendChild(htmlFrag);
    }
  }
}


function addRecord(name,f_text,f_value,f_additional) {
    var ident = name + '_' + RecID[name];
    var i_caption          = document.createElement('INPUT');
        i_caption.type     = 'text';
        i_caption.name     = name + '[' + RecID[name] + '][caption]';
        i_caption.size     = '30';
        i_caption.value    = f_value;
    var i_comment          = document.createElement('TEXTAREA');
        i_comment.name     = name + '[' + RecID[name] + '][comment]';
        i_comment.cols     = '30';
        i_comment.rows     = '4';
        i_comment.value    = f_additional;
    var newDelButton       = document.createElement('INPUT');
        newDelButton.type  = 'button';
        newDelButton.id    = 'delete-' + ident;
        newDelButton.value = 'x';

    var tbody              = document.getElementById('dynamic'+name);
    var tr                 = tbody.insertRow(tbody.rows.length);
        tr.id              = ident;
    SetContent(ident,'<td><b>juuch</b>:'+f_text+'</td>');
    RecID[name]++;
}




    function switchHTML(name) {
        elem = document.getElementById(name+"html");
        //elem = document.inputform.eval(name+"html");
        if ( elem != null ) {
            for (i=0; i<elem.length; i++) {
                if (elem[i].value == "h") {
                    elem[i].checked = true;
                }
            }
        }
        if (HTMLArea.checkSupportedBrowser) {
            elem = document.getElementById("htmlplainspan"+name);
            if ( elem != null ) {
                elem.style.display = "none";
            }
        }
    }

    function showHTMLAreaLink(name) {
        if (HTMLArea.checkSupportedBrowser()) {
            elem = document.getElementById("arealinkspan"+name);
            if( elem && (elem != null) ) {
                elem.style.display = "inline";
            }
        }
    }

    function generateArea(name, tableop, spell, rows, cols, session) { // generate HTMLArea from textarea
        area = new HTMLArea(name);
        area.session = session;
        var config = area.config;
        config.height = eval(rows*16+100)+"px";
        config.width = eval(cols*12)+"px";
        if (tableop == true) area.registerPlugin("TableOperations");
        if (spell == true) area.registerPlugin("SpellChecker");
        area.registerPlugin("InsertFile");
        area.registerPlugin("ImageManager");
        area.generate();
        switchHTML(name);
        return false;
    }

    function openHTMLAreaFullscreen(name, session) { // open HTMLArea in popupwindow
        ha = new HTMLArea(name); // create dummy HTMLArea object
        HTMLArea.session = session;
        ha._textArea = document.getElementById(name); // set textarea name
        HTMLArea._object = ha; // HTMLArea object is used in popupwindow
        HTMLArea._object.isnormal = "1"; // parent area is normal textarea
        if (HTMLArea.is_ie) { // different window opening for IE and other browsers
            window.open(long_editor_url+"popups/fullscreen.html", "ha_fullscreen",
                        "toolbar=no,location=no,directories=no,status=no,menubar=no," +
                        "scrollbars=no,resizable=yes,width=640,height=480");
        } else {
            window.open(long_editor_url+"popups/fullscreen.html", "ha_fullscreen",
                        "toolbar=no,menubar=no,personalbar=no,width=640,height=480," +
                        "scrollbars=no,resizable=yes");
        }
        switchHTML(name);
    }

