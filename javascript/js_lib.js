var popupwindows = [];
var popup_date = new Date();         // we need just to create unique id for the
var popupwindows_id = popup_date.getTime();   // popups (think of popup in popup)

function OpenWindowTop(url) {
    popupwindows[popupwindows.length] = open(url,'popup'+popupwindows_id+popupwindows.length,'scrollbars=1,resizable=1');
}

//moves selected rows of left listbox to the right one
function MoveSelected(left, right) {
  var temptxt
  var tempval
  var length
  var i=left.selectedIndex
  var last_selected = i;
  while( !left.disabled && ( i >= 0 ) ) {
    temptxt = left.options[i].text
    tempval = left.options[i].value
    length  = right.length
    if( (length == 1) && (right.options[0].value=='wIdThTor') ){  // blank rows are just for <select> size setting
      right.options[0].text = temptxt;
      right.options[0].value = tempval;
    } else {
      right.options[length] = new Option(temptxt, tempval);
    }
    left.options[i] = null
    last_selected = i;
    i=left.selectedIndex
  }
  // now select next option
  if( left.length != 0 ) {
      left.selectedIndex = ((last_selected < left.length) && (last_selected > 0) ? last_selected : 0);
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

