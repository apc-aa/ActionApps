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

    Created by Jakub Adamek, January 2002 */

myAlertCount = 0;

function myAlert(x) {
    if (myAlertCount++ < 150) {
        alert(x);
    }
}

function dec2hex(dec) {
    if (dec <= 0) return "0";
    hex = "";
    while (dec > 0) {
        digit = dec % 16;
        hex   = String.fromCharCode(digit + ((digit < 10) ? 48 : 87)) + hex; // '0' is 48 ASCII
        dec   = Math.floor(dec / 16);
    }
    return hex;
}

function unpackID(packedID) {
    retval = "";
    for (i = 0; i < packedID.length; i ++) {
        retval += dec2hex(packedID.charCodeAt(i));
    }
    return retval;
}

function hex2dec(hex) {
    hex = hex.toUpperCase();
    dec = 0;
    for (i=0; i < hex.length; ++i) {
        dec *= 16;
        c = hex.charAt(i);
        if (c >= '0' && c <= '9') {
            dec += hex.charCodeAt(i) - 48; // '0' is 48 ASCII
        } else if (c >= 'A' && c <= 'F') {
            dec += hex.charCodeAt(i) - 55; // 'A' is 65 ASCII
        } else {
            /*alert ('Error: hex2dec called with ' + hex);*/
            return 0;
        }
    }
    return dec;
}

function packID(unpackedID) {
    var packedID = new String();
    for (j=0; j < unpackedID.length; j += 2) {
        code = hex2dec(unpackedID.substr(j,2));
        if (code != 0) {
            packedID += String.fromCharCode(code);
        } else {
            return '';
        }
    }
    return packedID;
}

/* prooves all given fields are filled
    params: fields = array of controls' names */

function prooveFilled(formName, fields) {
    var myform = document.forms[formName];
    for (var i=0; i < fields.length; ++i) {
        val = getControlValue(formName, fields[i]);
        if (val == "") {
            myform[fields[i]].focus();
            alert("You didn't fill in some required field.");
            return false;
        }
    }
    return true;
}

/* gets array of 3-items arrays(field name, parent field name, parent field value)
    if (parent_field_name contains parent_field_value)
    or (parent_field_name is filled and parent_field_value is empty)
    then proove field_name is filled
    params: fields = array of controls' names */

function prooveFilledIf(formName, fields) {
    var myform = document.forms[formName];
    for (var i=0; i < fields.length; ++i) {
        parVal = getControlValue(formName, fields[i][1]);
        val    = fields[i][2];
        proove = (val == "" && parVal != "") || (parVal == val);
        if (!proove) {
            myform[fields[i][0]].focus;
            alert("You didn't fill in some required field.");
            return false;
        }
    }
}

function getSelected(selectBox) {
    return selectBox.options [selectBox.selectedIndex].value;
}

// the crazy Netscape returns 104 as year 2004 (hoping nobody will use it in the 21th century perhaps)
function getYearNetscapeSafe(myDate) {
    year = myDate.getYear();
    if (year < 200) year += 1900;
    return year;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *

    A A   D A T E   F U N C T I O N

    Functions working with AA-like date:
    It is in three select boxes (day,month,year), which usually have the name in the form of
    tdctr_vUNPACKED_FIELD_NAME_day, .._month, .._year

    'Cause it's used in public forms as well, we assume the web author added one option
    marking not used fields.
*/

/* saves the date value from an AA-like 3-field form to targetField in m/d/y format
    emptyValue in any of the 3 select-boxes means date is not set */

function saveDate(formName,dateField,targetField,emptyValue) {
    myDate = getAADate(formName,dateField,emptyValue);
    var myField = document.forms[formName][targetField];
    if (myDate != 0) {
        var d = new Date();
        d.setTime(myDate);
        year = getYearNetscapeSafe(d);
        month = d.getMonth()+1;
        myField.value = month+"/"+d.getDate()+"/"+year;
    }
    else myField.value = "";
}

/* sets given date in an AA-like form
 params: startYear is the top year in the year select box */

function setDay(formName,dateField,startYear,myDate) {
    tryDate = new Date(2000,1,1);
    dayShift = 1-tryDate.getDate();
    document.forms[formName][dateField+"_day"].selectedIndex   = myDate.getDate()-dayShift-1;
    document.forms[formName][dateField+"_month"].selectedIndex = myDate.getMonth();
    document.forms[formName][dateField+"_year"].selectedIndex  = getYearNetscapeSafe(myDate)-startYear;
}

/* sets today's date in an AA-like form
 params: startYear is the top year in the year select box */

function setToday(formName,dateField, startYear) {
    setDay(formName,dateField,startYear, new Date());
}

/* returns a date read from the fields of an AA-like form as Unix timestamp
    emptyValue in any of the day,month,year select boxes will return 0 */

function getAADate(formName,dateField,emptyValue) {
    var f = document.forms[formName];
    month = getSelected(f[dateField+"_month"]);
    day = getSelected(f[dateField+"_day"]);
    year = getSelected(f[dateField+"_year"]);
    if (month != emptyValue && day != emptyValue && year != emptyValue)
        return new Date (year,month-1,day).getTime();
    else
        return 0;
}

/* Function: setControlOrAADate
   Purpose: sets different control types in a common way

   Sets the control, trying:
    1. given controlName - if succeeds, tries to set the HTML radio button as well
    2. controlName + '[]' - usefull on multiple selectboxes, checkboxes, radios
    3. AA date

    sets the AA like date in 3 select boxes:
    Params:
        datePrefix is usually tdctr_ on forms created from itemedit.php3, '' on search forms
        html is usually 'h' for HTML, 't' for Plain text, 0 when not needed
*/

function setControlOrAADate(formName, controlName, newValue, datePrefix, html, timeZone) {
    if (getControlByName(formName, controlName) != null) {
        setControl(formName, controlName, newValue);
        if (html) setControl(formName, controlName+'html', html);
    }
    else if (getControlByName(formName, controlName+"[]") != null)
        setControl(formName, controlName+"[]", newValue);
    else if (!isNaN(newValue)) {
        myName = datePrefix + controlName;
        if (getControlByName(formName, myName+'_day') != null) {
            var myDate = new Date();
            var timeStamp = new Number(newValue);
            timeStamp += (myDate.getTimezoneOffset()/60 - timeZone) * 3600;
            myDate.setTime(timeStamp * 1000);
            //alert("Date coming to JavaScript is "+timeStamp+". That means "+myDate);
            setControl(formName,myName+'_day',myDate.getDate());
            setControl(formName,myName+'_month',myDate.getMonth()+1);
            setControl(formName,myName+'_year',getYearNetscapeSafe(myDate));
        }
    }
}

/*  E N D  O F  A A   D A T E   F U N C T I O N S  */


/* moves the page to top avoiding display in frames
    returns true if the page is on top, false otherwise */

function setMeTop()
{
    if (top.document.location.href != document.location.href) {
        top.document.location.href = document.location.href;
        return false;
    }
    else return true;
}

/* gets a control by name in IE and Netscape */

function getControlByName(formName, controlName) {
    return (document.forms[formName] == null) ? null : document.forms[formName][controlName];
}

/* sets a control value to the given one - works fine with text fields, hidden fields,
    select boxes (multiple not tried, but should be OK) and check boxes (multiple OK) */

function setControl(formName, controlName, newValue) {
    var myControl = getControlByName(formName, controlName);
    if (myControl != null) {
        if (typeof(myControl.type) == "undefined") {
            // multiple checkboxes or radio buttons
            for (var iCtrl = 0; iCtrl < myControl.length; ++iCtrl) {
                if (myControl[iCtrl].value == newValue) {
                    if (myControl[iCtrl].type == "checkbox" || myControl[iCtrl].type == "radio")
                        myControl[iCtrl].checked = 1;
                    break;
                }
            }
        }
        else if (myControl.type.substr(0,6) == "select")
            for (var i = 0; i < myControl.options.length; i++) {
                if ( (myControl.options[i].value == newValue) ||
                    ((myControl.options[i].value == "") && (myControl.options[i].text == newValue)))
                    myControl.options[i].selected = true;
            }
        else if (myControl.type == "checkbox")
            myControl.checked = newValue != 0;
        else
            myControl.value = newValue;
    }
}

/* Function: setControlArray
   Purpose: sets multiple control types in a common way (multiple select box)
   ToDo: implement multiple check-boxes
*/

function setControlArray(formName, controlName, newArray) {
    var myControl = getControlByName(formName, controlName);
    if (myControl != null) {
        if (myControl.type == "select-multiple") {
            for (var j = 0; j < newArray.length; j++) {
                for (var i = 0; i < myControl.options.length; i++) {
                    if ( (myControl.options[i].value == newArray[j]) ||
                        ((myControl.options[i].value == "") && (myControl.options[i].text == newArray[j]))) {
                        myControl.options[i].selected = true;
                        break;
                    }
                }
                if (i == myControl.options.length) {
                    opt = new Option(newArray[j],newArray[j],false,false);
                    myControl.options [i] = opt;
                    myControl.options[i].selected = true;
                }
            }
        } else {
            if (myControl[0].type == "checkbox") {
                for (var j = 0; j < newArray.length; j++) {
                            for (var i = 0; i < myControl.length; i++) {
                            if ( (myControl[i].value == newArray[j]) ||
                            ((myControl[i].value == "") && (myControl[i].text == newArray[j]))) {
                                            myControl[i].checked = true;
                            break;
                        }
                    }
                    if (i == myControl.length) {
                                    opt = new Option(newArray[j],newArray[j],false,false);
                        myControl[i] = opt;
                        myControl[i].checked = true;
                    }
                }
            }
        }
    }
}

/* Function: getControlOrAADate
   Purpose:  get value set in various form controls
   Works with: see getControlValue + AA three-select-boxes-date
*/

function getControlOrAADate(formName, controlName, datePrefix, emptyValue) {
    var myControl = getControlByName(formName, controlName);
    if (myControl != null)
        return getControlValue(formName, controlName);
    else {
        var myControl = getControlByName(formName, datePrefix + controlName + '_day');
        if (myControl != null)
            return getAADate(formName, datePrefix + controlName, emptyValue) / 1000;
        else return null;
    }
}

/* Function: getControlValue
   Purpose: get value set in various form controls
   Works with: <input>,<select>,<check>
*/

function getControlValue(formName, controlName) {
    var myControl = getControlByName(formName, controlName);
    if (myControl != null) {
        if (myControl.type.substr(0,6) == "select")
            return getSelected(myControl);
        else if (myControl.type == "checkbox")
            return myControl.checked ? 1 : 0;
        else return myControl.value;
    }
    else return null;
}

/* ITEMEDIT.PHP3 field JavaScript functions */

function getField(fieldID) {
    return getControlOrAADate('inputform','v'+unpackID(fieldID), 'tdctr_', '');
}

function setField(fieldID, newValue) {
    var myDate = new Date();
    setControlOrAADate('inputform','v'+unpackID(fieldID),newValue,'tdctr_', 0, myDate.getTimezoneOffset()/60);
}

function hideField(fieldID) {
    $$('.cont-v'+unpackID(fieldID)).invoke('hide');
}

function showField(fieldID) {
    $$('.cont-v'+unpackID(fieldID)).invoke('show');
}

/* Miscellaneous */

/* select all items in a multiple select box
   (items not selected are not sent when a form is submitted) */

function selectAllInBox(formName, controlName) {
    var myControl = getControlByName(formName, controlName);
    if (myControl != null) {
        for (var i = 0; i < myControl.options.length; i++)
            myControl.options[i].selected = true;
    }
}
