//  Fills 'akce' hidden field and submits the form or opens popup window
function MarkedActionGo() {
    var ms = document.itemsform.markedaction_select;
    if( ms.options[ms.selectedIndex].value &&  (ms.options[ms.selectedIndex].value != 'nothing') ) {
        document.itemsform.akce.value = ms.options[ms.selectedIndex].value
        // markedactionur is global variable defined in manager.class.php3
        if( markedactionurl[ms.selectedIndex] != null ) {
            OpenWindowTop(markedactionurl[ms.selectedIndex]);
        } else {
            document.itemsform.submit()
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

var popupwindow;

function OpenWindowTop(url) {
    if( popupwindow != null )
        popupwindow.close();    // in order to popupwindow go on top after open
    popupwindow = open(url,'popup','scrollbars')
}

