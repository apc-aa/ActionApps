<SCRIPT Language="JavaScript" type="text/javascript"><!--

// moves selected row of left listbox to the right one
function MoveSelected(left, right)
{
  var i=eval(left).selectedIndex
  if( !eval(left).disabled && ( i >= 0 ) )
  {
    var temptxt = eval(left).options[i].text
    var tempval = eval(left).options[i].value
    var length = eval(right).length
    if( (length == 1) && (eval(right).options[0].value=='wIdThTor') ){  // blank rows are just for <select> size setting
      eval(right).options[0].text = temptxt;
      eval(right).options[0].value = tempval;
    } else
      eval(right).options[length] = new Option(temptxt, tempval);
    eval(left).options[i] = null
    if( eval(left).length != 0 )
      if( i==0 )
        eval(left).selectedIndex=0
       else
        eval(left).selectedIndex=i-1
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
function CommaDelimeted(listbox) {
  var foo=''
  var delimeter=''
  for (var i = 0; i < eval(listbox).options.length; i++) {
    if( eval(listbox).options[i].value != 'wIdThTor' ){  // blank rows are just for <select> size setting
      foo += delimeter + escape(eval(listbox).options[i].value)
      delimeter=','
    }
  }
  return foo
}

// Encodes all values from listbox to array of name "name"
// prepared for sending as url parameter
function EncodeList2UrlArray(name, listbox) {
  var arr
  for (var i = 0; i < eval(listbox).options.length; i++) {
    if( (eval(listbox).options[i].value != "0") && (eval(listbox).options[i].value != 'wIdThor') )
      arr += "&" + name + "%5B" + i + "%5D=" + escape(eval(listbox).options[i].value)
  }
  return arr
}

// This script invokes Word/Excel convertor (used in textareas on inputform)
// You must have the convertor it installed
// @param string aa_instal_path - relative path to AA on server (like"/apc-aa/")
// @param string textarea_id    - textarea fomr id (like "v66756c6c5f746578742e2e2e2e2e2e31")
function CallConvertor(aa_instal_path, textarea_id) {
  page = aa_instal_path + "misc/msconvert/index.php?inputid=" + textarea_id;
  conv = window.open(page,"convwindow","width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes");
  conv.focus();
}

// -->
</SCRIPT>

