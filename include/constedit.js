/* JavaScript functions to be used with constedit.php3
	Hierarchical constant editor */

// contains selected index (or -1 when nothing selected) for all levels
var hcSelectedItems = new Array ();
// selected box number
hcSelectedBox = -1;

// list of IDs of items deleted
var hcDeletedIDs = new Array ();

// ID counter to be set for new items
var hcNewID = 0;

// call at the start of this page 
function hcInit () {
	// init the hcSelectedItems array
	for (i=0; i < hcLevelCount; ++i)
		hcSelectedItems[i] = -1;

	// fill in the top level box
	var selectBox = document[hcForm]['hclevel0'];
	selectBox[0] = null;
	for (i=0; i < hcConsts.length; ++i) {
		opt = new Option(hcConsts[i][colName],i,false,false);
		selectBox.options[i] = opt;
	}
	if (hcConsts.length > 0) 
		selectBox.selectedIndex = 0;
	else selectBox.selectedIndex = -1;
	hcSelectItem (0);
}

/** returns the array with selected item */

function getSelectedArray () {
	var arr = hcConsts;
	for (i=0; i <= hcSelectedBox; ++i) {
		if (hcSelectedItems[i] == -1)
			return new Array();
		arr = arr[hcSelectedItems[i]];
		if (i < hcSelectedBox) arr = arr[colChild];
	}
	return arr;
}		

/** hcSelectItem: fill the following box with child values,
	clear the next boxes */

function hcSelectItem (iBox, admin) {
	hcSelectedBox = iBox;
		
	var selectBox = document[hcForm]['hclevel'+iBox];
	hcSelectedItems[iBox] = selectBox.selectedIndex;

	var arr = getSelectedArray();
	if (arr.length > 0 && admin) {
		var f = document[hcForm];
		f['hcfName'].value = arr[colName];	
		if (arr[colValue] == '#')
			f['hcfValue'].value = arr[colName];
		else f['hcfValue'].value = arr[colValue];
		f['hcfDesc'].value = arr[colDesc];
		f['hcfPrior'].value = arr[colPrior];
	}
	
	++iBox;
	if (iBox < hcLevelCount) {
		var selectBox = document[hcForm]['hclevel'+iBox];
		if (arr.length > colChild) {
			arr = arr[colChild];
			for (i=0; i < arr.length; ++i) {
				value = arr[i][colValue];
				if (value == '#') value = arr[i][colName];
				opt = new Option(arr[i][colName],value,false,false);
				selectBox.options[i] = opt;
			}
			selectBox.selectedIndex = -1;
		}
		else i=0;
		while (i < selectBox.options.length)
			selectBox.options[i] = null;
		while (++iBox < hcLevelCount) {
			var selectBox = document[hcForm]['hclevel'+iBox];
			while (selectBox.options.length) 
				selectBox.options[0] = null;
		}
	}
}

function refreshBox (iBox) {
	var selectBox = document[hcForm]['hclevel'+iBox];	
	oldSelectedIndex = selectBox.selectedIndex;
	var arr = hcConsts;
	if (arr.length > 0) {
		for (i=0; i < iBox; ++i) 
			arr = arr[hcSelectedItems[i]][colChild];
		for (i=0; i < arr.length; ++i) {
			opt = new Option(arr[i][colName],i,false,false);
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
	
function hcUpdateMe () {
	setEditedValues(getSelectedArray());
	refreshBox (hcSelectedBox);
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

function hcDeleteMe (withChildren) {
	iBox = hcSelectedBox;

	var f = document[hcForm];
	if (f['hcDoDelete'].checked == false) {
		alert ('Check the box prior to delete anything.');
		return;
	}
	if (!hcEasyDelete) 
		f['hcDoDelete'].checked = false;

	var arr = hcConsts;
	for (i=0; i < iBox; ++i) {
		arr = arr[hcSelectedItems[i]];
		if (i == iBox-1) arrParent = arr;
		else if (i == iBox-2) grandParent = arr;
		arr = arr[colChild];
	}
	if (arr[hcSelectedItems[iBox]].length > colChild && !withChildren) {
		alert ('Error: This item has children in next levels.');
		return;
	}
	
	saveDeletedIDs (arr[hcSelectedItems[iBox]]);
	
	// IE knows splice from version 5.5 only
	if (arr.splice) 
		arr.splice (hcSelectedItems[iBox],1);
	else {
		arr = array_splice (arr, hcSelectedItems[iBox], 1);
		if (iBox > 0) 
			arrParent[colChild] = arr;
		else hcConsts = arr;
	}

	if (arr.length == 0) {
		if (iBox == 0) hcConsts = new Array();
		else if (arr.splice)
			arrParent.splice (colChild,1);
		else {
			arrParent = array_splice (arrParent, colChild, 1);
			if (iBox == 1) hcConsts[hcSelectedItems[iBox-1]] = arrParent;
			else grandParent[colChild][hcSelectedItems[iBox-1]] = arrParent;
		}
	}
				
	var selectBox = document[hcForm]['hclevel'+iBox];
	selectBox.selectedIndex = -1;
	if (iBox > 0)
		hcSelectItem (iBox-1);
	else {
		var selectBox = document[hcForm]['hclevel'+iBox];
		selectBox.selectedIndex = -1;
		refreshBox (iBox);
		if (hcConsts.length > 0) {
			selectBox.selectedIndex = 0;
			hcSelectItem (iBox);
		}
		else refreshBox (1);
	}
}
	
function hcAddNew (iBox) {
	var selectBox = document[hcForm]['hclevel'+iBox];
	if (hcConsts.length == 0 && iBox > 0) {
		alert ('There are no hcConsts. You can add only to level 0.');
		return;
	}
	if (iBox > hcSelectedBox + 1) {
		alert ('You can not add to this level. An item in the preceding level must be selected.');
		return;
	}
	var arr = hcConsts;
	for (i=0; i < iBox; ++i) {
		arr = arr[hcSelectedItems[i]];
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
	refreshBox (iBox);
	selectBox.selectedIndex = selectBox.length-1;
	hcSelectItem (iBox);
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

function sendDirtyBranch (arr,ancestors) {
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
			info += sendDirtyBranch (arr[i], ancestors+","+myID);
	}
	return info;
}

function sendDirty () {
	info = "";
	for (i=0; i < hcConsts.length; ++i) {
		info += sendDirtyBranch (hcConsts[i],'');
	}
	if (info > "") 
		return " :changes: "+info.substr (0,info.length-1);
	else return "";
}
		
function sendDeleted () {
	alldata = "";
	for (i=0; i < hcDeletedIDs.length; ++i) {
		if (i > 0) alldata += ",";
		alldata += hcDeletedIDs[i];
	}
	return alldata;
}

function hcSendAll () {
	var f = document[hcForm];
	alldata = sendDeleted() + sendDirty();
	f['hcalldata'].value = alldata;
	f.submit();
}

function hcAddItemTo (i,targetBox) {
	var f = document[hcForm];
	var selectBox = document[hcForm]['hclevel'+i];
	if (selectBox.selectedIndex == -1) return;
	name = selectBox.options[selectBox.selectedIndex].text;
	value = selectBox.options[selectBox.selectedIndex].value;
	opt = new Option(name,value,false,false);
	var target = document[hcForm][targetBox];
	target.options [target.length] = opt;
}

function hcClearBox (boxName) {
	var box = document[hcForm][boxName];
	for (i=box.length-1; i >= 0; --i)
		box.options[i] = null;
}

function hcDelete (boxName) {
	var box = document[hcForm][boxName];
	if (box.selectedIndex > -1)
		box.options[box.selectedIndex] = null;
}

function hcDeleteLast (boxName) {
	var box = document[hcForm][boxName];
	box.options[box.length-1] = null;
}

