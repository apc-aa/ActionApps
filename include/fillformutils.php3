<?php 
//$Id$
/* 
Copyright (C) 2002 Association for Progressive Communications 
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/* These are JavaScript functions used by the fillform script. 
	They should run on Netscape 4.7 as well as Internet Explorer 5.0 and above.
	PHP is used only to make commentaries.

	Created by Jakub Adamek, January 2002 */ ?>

<SCRIPT language=JavaScript>
<!--

myAlertCount = 0;

function myAlert (x) {
	if (myAlertCount++ < 150) alert (x);
}

function hex2dec (hex) {
	hex = hex.toUpperCase();
	dec = 0;
	for (i=0; i < hex.length; ++i) {
		dec *= 16;
		c = hex.charAt (i);
		if (c >= '0' && c <= '9') 
			dec += hex.charCodeAt (i) - 48; // '0' is 48 ASCII
		else if (c >= 'A' && c <= 'F')
			dec += hex.charCodeAt (i) - 55; // 'A' is 65 ASCII
		else { /*alert ('Error: hex2dec called with ' + hex);*/	return 0; }
	}
	return dec;
}

function packID (unpackedID) {
	var packedID = new String ();
	for (j=0; j < unpackedID.length; j += 2) {
		code = hex2dec (unpackedID.substr (j,2));
		if (code != 0) 
			packedID += String.fromCharCode (code);
		else return '';
	}
	return packedID;
}		

<?php /* prooves all given fields are filled
	params: fields = array of controls' names */ ?>
	
function prooveFilled (formName, fields) {
	myform = document.forms[formName];
	for (var i=0; i < fields.length; ++i) {
		control = myform[fields[i]];
		if (control.value == "") {
			control.focus();
			alert ("You didn't fill in some required field.");
			return false;
		}
	}
	return true;
}

function getSelected (selectBox) {
	return selectBox.options [selectBox.selectedIndex].value;
}

// the crazy Netscape returns 104 as year 2004 (hoping nobody will use it in the 21th century perhaps)
function getYearNetscapeSafe (myDate) {
	year = myDate.getYear();
	if (year < 200) year += 1900;
	return year;
}

<?php /* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * 

	A A   D A T E   F U N C T I O N 

	Functions working with AA-like date: 
	It is in three select boxes (day,month,year), which usually have the name in the form of
	tdctr_vUNPACKED_FIELD_NAME_day, .._month, .._year
	
	'Cause it's used in public forms as well, we assume the web author added one option
	marking not used fields. 
*/ ?>
	
<?php /* saves the date value from an AA-like 3-field form to targetField in m/d/y format
 	emptyValue in any of the 3 select-boxes means date is not set */ ?>

function saveDate (formName,dateField,targetField,emptyValue) {
	myDate = getAADate (formName,dateField,emptyValue);
	var myField = document.forms[formName][targetField];
	if (myDate != 0) {
		var d = new Date();
		d.setTime (myDate);
		year = getYearNetscapeSafe(d);
		month = d.getMonth()+1;
		myField.value = month+"/"+d.getDate()+"/"+year;
	}
	else myField.value = "";
}

<?php /* sets given date in an AA-like form
 params: startYear is the top year in the year select box */ ?>
 
function setDay (formName,dateField,startYear,myDate) {
	document.forms[formName][dateField+"_day"].selectedIndex   = myDate.getDate()-1;
	document.forms[formName][dateField+"_month"].selectedIndex = myDate.getMonth();
	document.forms[formName][dateField+"_year"].selectedIndex  = getYearNetscapeSafe(myDate)-startYear;
}

<?php /* sets today's date in an AA-like form
 params: startYear is the top year in the year select box */ ?>

function setToday (formName,dateField, startYear) {
	setDay (formName,dateField,startYear, new Date());
}

<?php /* returns a date read from the fields of an AA-like form as Unix timestamp
	emptyValue in any of the day,month,year select boxes will return 0 */ ?>

function getAADate (formName,dateField,emptyValue) {
	var f = document.forms[formName];
	month = getSelected(f[dateField+"_month"]);
	day = getSelected(f[dateField+"_day"]);
	year = getSelected(f[dateField+"_year"]);
	if (month != emptyValue && day != emptyValue && year != emptyValue)
		return new Date (year,month-1,day).getTime();
	else
		return 0;
}	 

<?php /* Sets the control, trying: 
	1. given controlName - if succeeds, tries to set the HTML radio button as well
	2. controlName + '[]' - usefull on multiple selectboxes, checkboxes, radios
	3. AA date

	sets the AA like date in 3 select boxes:
	Params:
		datePrefix is usually tdctr_ on forms created from itemedit.php3, '' on search forms
		html is usually 'h' for HTML, 't' for Plain text, 0 when not needed 
*/ ?>

function setControlOrAADate (formName, controlName, newValue, datePrefix, html) {
	if (getControlByName (formName, controlName) != null) {
		setControl (formName, controlName, newValue);
		if (html) setControl (formName, controlName+'html', html);
	}
	else if (getControlByName (formName, controlName+"[]") != null)
		setControl (formName, controlName+"[]", newValue);
	else if (!isNaN (newValue)) {
		myName = datePrefix + controlName;
		if (getControlByName (formName, myName+'_day') != null) { 
			var myDate = new Date ();
			myDate.setTime (newValue * 1000);
			setControl(formName,myName+'_day',myDate.getDate());
			setControl(formName,myName+'_month',myDate.getMonth()+1);
			setControl(formName,myName+'_year',getYearNetscapeSafe(myDate));
		}
	}
}

<?php /* E N D  O F  A A   D A T E   F U N C T I O N */ ?>


<?php /* moves the page to top avoiding display in frames
	returns true if the page is on top, false otherwise */ ?>
	
function setMeTop () 
{
	if (top.document.location.href != document.location.href) {
		top.document.location.href = document.location.href;
		return false;
	}
	else return true;
}

<? /* gets a control by name in IE and Netscape */ ?>

function getControlByName (formName, controlName) {
	return document.forms[formName][controlName];
}

<? /* sets a control value to the given one - works fine with text fields, hidden fields,
	select boxes (multiple not tried, but should be OK) and check boxes (multiple OK) */ ?>
	
function setControl (formName, controlName, newValue) {
	var myControl = getControlByName (formName, controlName);
	if (myControl != null) {
		if (typeof (myControl.type) == "undefined") {
			// multiple checkboxes
		  	for (var iCtrl = 0; iCtrl < myControl.length; ++iCtrl) {
				if (myControl[iCtrl].value == newValue) {
					if (myControl[iCtrl].type == "checkbox" || myControl[iCtrl].type == "radio") 
						myControl[iCtrl].checked = 1;
					break;
				}
			}
		}
        else if (myControl.type.substr (0,6) == "select") 
			for (var i = 0; i < myControl.options.length; i++) {
				if (myControl.options[i].value == newValue) 
					myControl.options[i].selected = true;
			}
		else if (myControl.type == "checkbox")  
			myControl.checked = newValue != 0;
		else 
			myControl.value = newValue;
	}
}

// -->
</SCRIPT>

