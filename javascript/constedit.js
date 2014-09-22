/* JavaScript functions to be used with constedit.php3
    Hierarchical constant editor */

var hcEasyDelete = [];
var hcLevelCount = [];
var hcConsts     = [];

// contains selected index (or -1 when nothing selected) for all levels
var hcSelectedItems = [];
// selected box number
var hcSelectedBox   = [];
// list of IDs of items deleted
var hcDeletedIDs    = [];

// ID counter to be set for new items
var hcNewID = 0;

function clearSelectBox (formname, boxname) {
    selectBox = document.forms[formname][boxname];
    for (i=selectBox.options.length-1; i >= 0; i--)
        selectBox.options[i] = null;
}

function hcResolveValues (arr) {
    var i = 0;
    for (i=0; i < arr.length; ++i) {
        if (arr[i][colValue] == '#')
            arr[i][colValue] = arr[i][colName];
        if (arr[i].length > colChild)
            hcResolveValues (arr[i][colChild]);
    }
}

// call at the start of this page
function hcInit(hcid) {
    hcResolveValues(hcConsts[hcid]);
    hcSelectedItems[hcid] = [];
    hcSelectedBox[hcid]   = -1;


    // init the hcSelectedItems array
    for (i=0; i < hcLevelCount[hcid]; ++i) {
        hcSelectedItems[hcid][i] = -1;
    }

    // fill in the top level box
    var selectBox = document[hcForm]['hclevel0_'+hcid];
    selectBox[0] = null;
    for (i=0; i < hcConsts[hcid].length; ++i) {
        opt = new Option(hcConsts[hcid][i][colName],hcConsts[hcid][i][colValue],false,false);
        selectBox.options[i] = opt;
    }
    if (hcConsts[hcid].length > 0) {
        selectBox.selectedIndex = 0;
    } else {
        selectBox.selectedIndex = -1;
    }
    hcSelectItem(hcid,0);
}

/** returns the array with selected item */

function getSelectedArray(hcid) {
    var arr = hcConsts[hcid];
    for (i=0; i <= hcSelectedBox[hcid]; ++i) {
        if (hcSelectedItems[hcid][i] == -1)
            return new Array();
        arr = arr[hcSelectedItems[hcid][i]];
        if (i < hcSelectedBox[hcid]) arr = arr[colChild];
    }
    return arr;
}

/** hcSelectItem: fill the following box with child values,
    clear the next boxes */

function hcSelectItem(hcid, iBox, admin) {
    hcSelectedBox[hcid] = iBox;

    var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
    hcSelectedItems[hcid][iBox] = selectBox.selectedIndex;

    var arr = getSelectedArray(hcid);
    if (arr.length > 0 && admin) {
        var f = document[hcForm];
        f['hcfName'].value = arr[colName];
        f['hcfValue'].value = arr[colValue];
        f['hcfDesc'].value = arr[colDesc];
        f['hcfPrior'].value = arr[colPrior];
    }

    ++iBox;
    if (iBox < hcLevelCount[hcid]) {
        var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
        if (arr.length > colChild) {
            arr = arr[colChild];
            for (i=0; i < arr.length; ++i) {
                opt = new Option(arr[i][colName],arr[i][colValue],false,false);
                selectBox.options[i] = opt;
            }
            selectBox.selectedIndex = -1;
        }
        else i=0;
        while (i < selectBox.options.length)
            selectBox.options[i] = null;
        while (++iBox < hcLevelCount[hcid]) {
            var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
            while (selectBox.options.length)
                selectBox.options[0] = null;
        }
    }
}

function refreshBox(hcid, iBox) {
    var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
    oldSelectedIndex = selectBox.selectedIndex;
    var arr = hcConsts[hcid];
    if (arr.length > 0) {
        for (i=0; i < iBox; ++i)
            arr = arr[hcSelectedItems[hcid][i]][colChild];
        for (i=0; i < arr.length; ++i) {
            opt = new Option(arr[i][colName],arr[i][colValue],false,false);
            selectBox.options[i] = opt;
        }
    }
    selectBox.selectedIndex = oldSelectedIndex;
    while (i < selectBox.options.length)
        selectBox.options[i] = null;
}

// fills array with values in the edit boxes on screen
function setEditedValues (arr) {
    var f = document[hcForm];
    if (f['hcCopyValue'] == null || f['hcCopyValue'].checked)
        f['hcfValue'].value = f['hcfName'].value;
    arr[colName] = f ['hcfName'].value;
    arr[colValue] = f['hcfValue'].value;
    arr[colPrior] = f['hcfPrior'].value;
    arr[colDesc] = f['hcfDesc'].value;
    arr[colDirty] = true;
}

function hcUpdateMe(hcid) {
    setEditedValues(getSelectedArray(hcid));
    refreshBox(hcid, hcSelectedBox[hcid]);
}

/** Array::splice partially implemented (only the delete part)
    deletes given count of items beginning with iStart
    IE knows splice from version 5.5 only */

function array_splice (arr, iStart, deleteCount) {
    if (iStart > 0)
        begin = arr.slice (0, iStart);
    else begin = new Array();
    end = arr.slice (iStart+deleteCount,arr.length);
    return begin.concat (end);
}

/** Saves recursively IDs of the whole branch beginning with given item */

function saveDeletedIDs (arr) {
    if (!isNaN (arr[colID]))
        hcDeletedIDs [hcDeletedIDs.length] = arr[colID];
    if (arr.length > colChild) {
        arr = arr[colChild];
        var i = new Number();
        for (i=0; i < arr.length; ++i)
            saveDeletedIDs (arr[i]);
    }
}

/** Deletes the item edited with / without children */

function hcDeleteMe(hcid, withChildren) {
    iBox = hcSelectedBox[hcid];

    var f = document[hcForm];
    if (f['hcDoDelete'].checked == false) {
        alert ('Check the box prior to delete anything.');
        return;
    }
    if (!hcEasyDelete[hcid]) {
        f['hcDoDelete'].checked = false;
    }

    var arr = hcConsts[hcid];
    for (i=0; i < iBox; ++i) {
        arr = arr[hcSelectedItems[hcid][i]];
        if (i == iBox-1) arrParent = arr;
        else if (i == iBox-2) grandParent = arr;
        arr = arr[colChild];
    }
    if (arr[hcSelectedItems[hcid][iBox]].length > colChild && !withChildren) {
        alert ('Error: This item has children in next levels.');
        return;
    }

    saveDeletedIDs(arr[hcSelectedItems[hcid][iBox]]);

    // IE knows splice from version 5.5 only
    if (arr.splice)
        arr.splice(hcSelectedItems[hcid][iBox],1);
    else {
        arr = array_splice(arr, hcSelectedItems[hcid][iBox], 1);
        if (iBox > 0) {
            arrParent[colChild] = arr;
        } else {
            hcConsts[hcid] = arr;
        }
    }

    if (arr.length == 0) {
        if (iBox == 0) hcConsts[hcid] = new Array();
        else if (arr.splice) {
            arrParent.splice (colChild,1);
        } else {
            arrParent = array_splice (arrParent, colChild, 1);
            if (iBox == 1) hcConsts[hcid][hcSelectedItems[hcid][iBox-1]] = arrParent;
            else grandParent[colChild][hcSelectedItems[hcid][iBox-1]] = arrParent;
        }
    }

    var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
    selectBox.selectedIndex = -1;
    if (iBox > 0) {
        hcSelectItem(hcid, iBox-1);
    } else {
        var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
        selectBox.selectedIndex = -1;
        refreshBox(hcid, iBox);
        if (hcConsts[hcid].length > 0) {
            selectBox.selectedIndex = 0;
            hcSelectItem(hcid, iBox);
        }
        else refreshBox(hcid, 1);
    }
}

function hcAddNew(hcid, iBox) {
    var selectBox = document[hcForm]['hclevel'+iBox+'_'+hcid];
    if (hcConsts[hcid].length == 0 && iBox > 0) {
        alert ('There are no hcConsts. You can add only to level 0.');
        return;
    }
    if (iBox > hcSelectedBox[hcid] + 1) {
        alert ('You can not add to this level. An item in the preceding level must be selected.');
        return;
    }
    var arr = hcConsts[hcid];
    for (i=0; i < iBox; ++i) {
        arr = arr[hcSelectedItems[hcid][i]];
        if (i < iBox-1) arr = arr[colChild];
    }
    var f = document[hcForm];
    var newItem = new Array(colChild);
    setEditedValues(newItem);
    newItem[colID] = '#'+hcNewID;
    ++hcNewID;
    if (iBox > 0) {
        if (arr.length <= colChild)
            arr[colChild] = new Array ();
        arr = arr[colChild];
    }
    arr[arr.length] = newItem;
    refreshBox(hcid, iBox);
    selectBox.selectedIndex = selectBox.length-1;
    hcSelectItem(hcid, iBox);
}

function careQuotes (str) {
    str = str.replace (/'/g,"\\'");
    str = str.replace (/\n/,"");
    // separates rows
    str = str.replace (/:/g,"\\:");
    // separates columns
    str = str.replace (/~/g,"\\~");
    return str;
}

function sendDirtyBranch(arr,ancestors) {
    //alert (ancestors+" "+arr);
    if (arr[colDirty]) {
        info = ":"
            +careQuotes(arr[colName])+"~"
            +careQuotes(arr[colValue])+"~"
            +careQuotes(arr[colPrior])+"~"
            +careQuotes(arr[colDesc])+"~"
            +arr[colID]+"~"
            +ancestors;
    }
    else info = "";
    if (arr.length > colChild) {
        // JavaScript doesn't create new values on recursion
        var myID = new String(arr[colID]);
        arr = arr[colChild];
        var i = new Number();
        for (i=0; i < arr.length; ++i)
            info += sendDirtyBranch(arr[i], ancestors+","+myID);
    }
    return info;
}

function sendDirty(hcid) {
    info = "";
    for (i=0; i < hcConsts[hcid].length; ++i) {
        info += sendDirtyBranch(hcConsts[hcid][i],'');
    }
    if (info > "") {
        return " :changes: "+info;
    }
    return "";
}

function sendDeleted () {
    alldata = "";
    for (i=0; i < hcDeletedIDs.length; ++i) {
        if (i > 0) alldata += ",";
        alldata += hcDeletedIDs[i];
    }
    return alldata;
}

function hcSendAll(hcid) {
    var f = document[hcForm];
    alldata = sendDeleted() + sendDirty(hcid);
    f['hcalldata'].value = alldata;
    f.submit();
}

function hcAddItemTo(hcid,i,targetBox) {
    var f = document[hcForm];
    var selectBox = document[hcForm]['hclevel'+i+'_'+hcid];
    if (selectBox.selectedIndex == -1) return;
    myname = selectBox.options[selectBox.selectedIndex].text;
    myvalue = selectBox.options[selectBox.selectedIndex].value;
    //alert ('name '+name+' value '+value);
    var target = document[hcForm][targetBox];

    // if an option with the same name is already selected, replace it
    for (i = 0; i < target.length; i ++)
        if (target.options[i].text == myname) {
            target.options[i].value = myvalue;
            target.options[i].selected = true;
            return;
        }

    opt = new Option(myname,myvalue,true,true);
    target.options [target.length] = opt;
}

function hcClearBox (boxName) {
    var box = document[hcForm][boxName];
    for (i=box.length-1; i >= 0; --i)
        box.options[i] = null;
}

function hcDelete(boxName) {
    var box = document[hcForm][boxName];
    if (box.selectedIndex > -1) {
        box.options[box.selectedIndex] = null;
    }
}

function hcDeleteLast(boxName) {
    var box = document[hcForm][boxName];
    box.options[box.length-1] = null;
}

