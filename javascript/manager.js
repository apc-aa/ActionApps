//  Fills 'akce' hidden field and submits the form
function SubmitItems(act) {
    document.itemsform.akce.value = act
    document.itemsform.submit()
}

function MarkedActionGo() {
    var ms = document.itemsform.markedaction_select;
    if( ms.options[ms.selectedIndex].value &&  (ms.options[ms.selectedIndex].value != 'nothing') ) {
        SubmitItems(ms.options[ms.selectedIndex].value);
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


