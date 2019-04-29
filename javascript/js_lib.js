var popupwindows = [];
var popup_date = new Date();         // we need just to create unique id for the
var popupwindows_id = popup_date.getTime();   // popups (think of popup in popup)

function OpenWindowTop(url) {
    popupwindows[popupwindows.length] = open(url,'popup'+popupwindows_id+popupwindows.length,'scrollbars=1,resizable=1');
}

//moves selected rows of left listbox to the right one
function MoveSelected(left, right)
{
  var temptxt
  var tempval
  var length
  var i=eval(left).selectedIndex
  var last_selected = i;
  while( !eval(left).disabled && ( i >= 0 ) ) {
    temptxt = eval(left).options[i].text
    tempval = eval(left).options[i].value
    length  = eval(right).length
    if( (length == 1) && (eval(right).options[0].value=='wIdThTor') ){  // blank rows are just for <select> size setting
      eval(right).options[0].text = temptxt;
      eval(right).options[0].value = tempval;
    } else {
      eval(right).options[length] = new Option(temptxt, tempval);
    }
    eval(left).options[i] = null
    last_selected = i;
    i=eval(left).selectedIndex
  }
  // now select next option
  if( eval(left).length != 0 ) {
      eval(left).selectedIndex = ((last_selected < eval(left).length) && (last_selected > 0) ? last_selected : 0);
  }
}

// moves selected row of listbox up
function MoveSelectedUp(listbox)
{
  var i=eval(listbox).selectedIndex
  if( !eval(listbox).disabled && ( i > 0 ) )
  {
    var temptxt = eval(listbox).options[i].text
    var tempval = eval(listbox).options[i].value
    eval(listbox).options[i].text = eval(listbox).options[i-1].text;
    eval(listbox).options[i].value = eval(listbox).options[i-1].value;
    eval(listbox).options[i-1].text = temptxt;
    eval(listbox).options[i-1].value = tempval;
    eval(listbox).selectedIndex=i-1
  }
}

// moves selected row of listbox down
function MoveSelectedDown(listbox)
{
  var i=eval(listbox).selectedIndex
  if( !eval(listbox).disabled && ( i < eval(listbox).length-1 ) )
  {
    var temptxt = eval(listbox).options[i].text
    var tempval = eval(listbox).options[i].value
    eval(listbox).options[i].text = eval(listbox).options[i+1].text;
    eval(listbox).options[i].value = eval(listbox).options[i+1].value;
    eval(listbox).options[i+1].text = temptxt;
    eval(listbox).options[i+1].value = tempval;
    eval(listbox).selectedIndex=i+1
  }
}

// Encodes all values from listbox to comma delimeted string
// prepared for sending as url parameter
function CommaDelimeted(elid) {
  if (el = document.getElementById(elid)) {
      return [].slice.call(el.querySelectorAll('option')).map(function (v) {
          return encodeURIComponent(v.value);
      }).join(",");
  }
  return '';
}

function GoIfConfirmed(url, text) {
  if (confirm(text)) {
    document.location = url;
  }
}

/** Appends any number of QUERY_STRING parameters (separated by &) to given URL,
 *  using apropriate ? or &. */
function GetUrl(url, params) {
    url_components = url.split('#', 2);
    url_path       = url_components[0];
    url_fragment   = (url_components.length > 1) ? ('#' + url_components[1]) : '';
    url_params     = params.join('&');
    return url_path + ((url_path.search(/\?/) == -1) ? '?' : '&') + url_params + url_fragment;
}

