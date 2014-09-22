// moves selected row of left listbox to the right one
function MoveSelectedCat(fromsbx,right)
{
    idx = eval(fromsbx).selectedIndex;
    if( idx < 0 )
        return
    if( eval(fromsbx).options[idx].text == '..' )
        return

    catid = eval(fromsbx).options[idx].value;

    var length = eval(right).length
    if( (length == 1) && (eval(right).options[0].value=='wIdThTor') ){  // blank rows are just for <select> size setting
      eval(right).options[0].text = "(-) " + a[catid];
      eval(right).options[0].value = catid;
    } else
      eval(right).options[length] = new Option("(-) " + a[catid], catid);
}

// moves selected row of left listbox to the right one
function MoveSelectedTo(fromsbx, totxt, toval)
{
  var idx;
  var catid;

  idx = eval(fromsbx).selectedIndex;
  if( idx < 0 )
      catid = downcat[level];
  else if( eval(fromsbx).options[idx].text == '..' )
      catid = downcat[level];
  else
      catid = eval(fromsbx).options[idx].value;

  MoveCategoryTo(catid, totxt, toval)
}

function MoveCategoryTo(catid, totxt, toval)
{
  var tmp="";
  var delim="";
  for( var j=select_depth-1; j<=level; j++) {
    tmp += delim + a[downcat[j]];
    delim = " > ";
  }
  if( catid != downcat[level])
    tmp += delim + a[catid];  // highlighted option
  SetContent(totxt,tmp);
  eval(toval).value = catid;
}

// Encodes all values from listbox to comma delimited string
// prepared for sending as url parameter
function CrossDelimited(listbox,valueVar,textVar,stateVar) {
    var delimiter=''
    var delimiter2=''

    eval(valueVar).value = "";
    eval(textVar).value  = "";

    for (var i = 0; i < eval(listbox).options.length; i++) {
        // blank rows are just for <select> size setting
        if (eval(listbox).options[i].value != 'wIdThTor' ) {
            if (eval(listbox).options[i].text.length >=4) {
                switch (eval(listbox).options[i].text.substring(0,4)) {
                    case "(!) ":
                        eval(stateVar).value += delimiter + "highlight"
                        break
                    case "(-) ":
                        eval(stateVar).value += delimiter + "visible"
                        break
                }
                eval(textVar).value += delimiter + eval(listbox).options[i].text.substring(4)
            } else {
                eval(stateVar).value += delimiter + "visible"
                eval(textVar).value += delimiter + eval(listbox).options[i].text
            }
            eval(valueVar).value += delimiter2 + eval(listbox).options[i].value
            delimiter='#'
            delimiter2=','
        }
    }
}

function DelCateg(msg) {
  var i=document.f.selcat.selectedIndex
  if( !confirm(msg) )
    return

  if( (i>=0) && (i<document.f.selcat.length)) {
    document.f.selcat.options[i] = null
  }
  if( i>0 ) {
    document.f.selcat.options.selectedIndex = i-1
  }
}

function NewCateg(msg) {
  var temptxt=prompt(msg, "")
  if ((temptxt != "") && (temptxt != null)) {
    document.f.selcat.options[document.f.selcat.length] = new Option("(-) " + temptxt, 0)
  }
}


// Clears all options in listbox
function ClearListbox(sbx) {
  var len = sbx.length
  for (var i=0; i < len; i++) {
    sbx.options[0] = null
  }
}

// finds category ancesor
function GetParent( catid ) {
  for (var i=0; i < assignno; i++) {  //assignno, s[] - global variables (tree)
    if ( (t[i] == catid) && (b[i] != '@') )
      return s[i];
  }
  return -1
}

function FindPathTo(catid) {
  var current = catid;
  var i=0;

  path2cat   = [];
  path2cat[i++] = current;

  while( current != treeStart ) {
      current = GetParent( current );
      if( current == -1 )
          return false;
      path2cat[i++] = current;
  }
  return path2cat;
}

function ClearTree() {
  level=0;
  downcat = [];
  downcat[level] = treeStart
}

function GoToCategoryID(catid, sbx, pathid, cat_id_fld) {
  path2cat = [];
  if( catid == "" ) {
    if( sbx.selectedIndex < 0 ) {
      return
    } else {
      catid = sbx.options[sbx.selectedIndex].value;
    }
  }

  path2cat = FindPathTo(catid);
  if( path2cat != false ) {
    ClearTree();
    for (var i=path2cat.length-1; i >= 0; i--) {  // stored in reverse order
      ChangeCategory( path2cat[i], sbx, pathid, cat_id_fld )
    }
  }
}

function GoCategory(sbx, pathid, cat_id_fld) {
  if( sbx.selectedIndex < 0 )
    return
  ChangeCategory( sbx.options[sbx.selectedIndex].value, sbx, pathid, cat_id_fld )
}

// Returns true, if specified category have a subcategories
function HaveSubcategories(catid) {
  var have_subcat = false;
  for (var i=0; i < assignno; i++)   //assignno, s[] - global variables (tree)
    if (s[i] == catid)
      return true;
  return false;
}

// Changes selected category
function ChangeSelectedCat( catid, sbx, pathid, cat_id_fld) {
    var curcat;
    var cattxt="";
    if( !catid ) {  // get highlighted category (probably dblclicking tree traveling)
        if( sbx.selectedIndex < 0 )
            return;
        catid  = sbx.options[sbx.selectedIndex].value;
        cattxt = sbx.options[sbx.selectedIndex].text;
    }

    if( pathid ) {
        tmp='';
        // create path to the currently selected category
        for( var j=0; j<=level; j++) {
            curcat = downcat[j];
            tmp += (j==0 ? '': path_delimiter) + '<a href="javascript:GoToCategoryID(' +curcat+ ', eval(document.'+sbx.form.name+'.'+sbx.name+'), \''+pathid+'\', \''+cat_id_fld+'\')">' + a[curcat] + '</a>';  // path_delimiter - global javascript variable
        }
        // if the catid is not currently selected category, we have to add
        // specified category (probably highlighted in selectbox for dblclicking
        // tree traveling)
        if( (catid != curcat) && (cattxt != '..') ) {
            tmp += (j==0 ? '': path_delimiter) + '<a href="javascript:GoToCategoryID(' +catid+ ', eval(document.'+sbx.form.name+'.'+sbx.name+'), \''+pathid+'\', \''+cat_id_fld+'\')">' + a[catid] + '</a>';  // path_delimiter - global javascript variable
        }
//        tmp += eval(from).options[i].text
        SetContent(pathid,tmp);
    }
    if( cat_id_fld ) {       // update category path (probably hidden) field
        eval(cat_id_fld).value = catid;
    }
}

function isGeneral(cid) {
    return general.charAt(cid)=='1';
}

// Changes tree listbox (all categories) depending on given category
function ChangeCategory(catid, sbx, pathid, cat_id_fld) {
    var pos=0
    var txt
    var do_nothing = false;
    var tmp

    if( catid == treeStart )
        level = 0
    else if( downcat[level-1] == catid )   // we go back
        level--
    else if( go_into_empty_cat || HaveSubcategories(catid) )  // global settings
        level++
    else // empty subcategory, where we have not to go
        do_nothing = true;

    if (!do_nothing) {
        ClearListbox(sbx)
        downcat[level] = catid

        if( level != 0 ) // base
            sbx.options[pos++] = new Option('..', downcat[level-1])

        for( var i=0; i<assignno; i++ ) {
            if( s[i] == catid ) {
                sbx.options[pos] = new Option(a[t[i]] + b[i], t[i])
                if( isGeneral(t[i]) ) {
                    sbx.options[pos].className = 'general';
                }
                pos++;
            }
        }
    }
    ChangeSelectedCat( downcat[level], sbx, pathid, cat_id_fld);
}

// Fills hidden fields and submit
function UpdateCategory() {
  // fill subcatIds and subcatNames from selected category listbox
  CrossDelimited( 'document.f.selcat', 'document.f.subcatIds', 'document.f.subcatNames', 'document.f.subcatStates');
  document.f.submit()
}

function ChangeStateCateg(listbox) {

    // Is there at least one option?
    if (!eval(listbox).disabled && eval(listbox).length >= 1) {
        var i=eval(listbox).selectedIndex

        // is selected any option?
        if (i >= 0) {
            var temptxt = eval(listbox).options[i].text
            var prefix = temptxt.substring(0,3)
            var text = temptxt.substring(4)

            // option should begin with prefix
            if (prefix == "(-)" || prefix == "(!)") {
                switch (temptxt.charAt(1)) {
                    case '!':
                        temptxt = "(-) " + text
                        break
                    case '-':
                        temptxt = "(!) " + text
                        break
                }
                eval(listbox).options[i].text = temptxt
            }
            else
                eval(listbox).options[i].text = "(-) " + text
        }
    }
}

// js for linkedit.php3
function DeleteField(index) {
  SetContent('selcat'+index,'')
  field = "document.f.selcatSelect"+index
  eval(field).value = "";
}

function CheckURL() {
    document.f_hidden.url.value=document.f.url.value
    window.open("","message","resizable=yes, scrollbars=yes")
    document.f_hidden.submit()
}

function LinkVote(link1, link2, changetag) {
    // needs global aa_live_file, aa_img_null
  imgsrc = aa_live_file+"?type=linksvote&clicked="+link1+"&notclicked="+link2;
  img1 = getElementByName(""+link1+"");
  img1.src = imgsrc;  // now we write to database
  SetContent(changetag,thanks_text);
/*  img1.width = 68;
  img2 = getElementByName(""+link2+"");
  img2.src = aa_img_null;
  img2.width = 1; */
}


function AcceptChange(field_name,hidden_field_name) {
  var field         = "document.f."+field_name;
  var hidden_field  = "document.f."+hidden_field_name;
  eval(field).value =  eval(hidden_field).value;
}

