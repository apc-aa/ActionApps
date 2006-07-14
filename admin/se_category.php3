<?php  #se_category.php3 - assigns categories to specified slice
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
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

# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Category update successful)

require "../include/init_page.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CATEGORY);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--

function MoveCateg()
{
  var i=document.f.source.selectedIndex
  if( i >= 0 )
  {
    var temptxt = document.f.source.options[i].text
    var tempval = document.f.source.options[i].value
    tempval = tempval.substring(tempval.indexOf(":")+1, tempval.length)
    var length = document.f.dest.length
    document.f.dest.options[length] = new Option(temptxt, tempval);
  }   
}

function DelCateg() {
  var i=document.f.dest.selectedIndex
  if( (i>=0) && (i<document.f.dest.length)) {
    document.f.dest.options[i] = null
  }
  if( i>0 ) {
    document.f.dest.options.selectedIndex = i-1
  }
}

function NewCateg() {
  var temptxt=prompt("<?php echo L_NEW_CATEG ?>", "")
  if( (temptxt != "") && (temptxt != null)) {
    document.f.dest.options[document.f.dest.length] = new Option(temptxt, 0)
  }
}

catArray = new Array()
nameArray = new Array()
freeCatArray = new Array()   // free - array for unassigned categories
freeNameArray = new Array()

function SliceChange() {
  var i
  if( catArray.length <= 0 ) {     // just first time - change from --all--
    var index, value, freecatpos=0
    for( i=0; i< document.f.source.length; i++) {  
      str = document.f.source.options[i].value
      index = str.substring(0,str.indexOf(":"))
      if( index == 0 ) {
         freeCatArray[freecatpos] = str
         freeNameArray[freecatpos++] = document.f.source.options[i].text
      }
      else {   
        catArray[index] = str
        nameArray[index] = document.f.source.options[i].text
      }  
    }
  }
  var len = document.f.source.length
  for( i=len-1; i>=0; i--) {           //clear 
    document.f.source.options[i] = null    
  }
  var oldpos=0
  var number
  var foo = document.f.slisel.options[document.f.slisel.selectedIndex].value
  if( foo==0 ) {                      // all slices
    for( i=1; i<catArray.length; i++ )   // category numbers begins with 1
      document.f.source.options[i-1] = new Option(nameArray[i], catArray[i])
    for( i=0; i<freeCatArray.length; i++ )   // free array is zero based
      document.f.source.options[document.f.source.length] = new Option(freeNameArray[i], freeCatArray[i])
  }
  else {  
    while( (number=foo.substring(oldpos, foo.indexOf(",",oldpos))) > 0 ) {
      document.f.source.options[document.f.source.length] = new Option(nameArray[number], catArray[number]);  
      oldpos = foo.indexOf(",",oldpos)+1
    }
  }    
}

function UpdateCateg(slice_id)
{
  var url = "<?php echo $sess->url(self_base() . "se_category2.php3")?>"
  url += "&slice_id=" + slice_id
  for (var i = 0; i < document.f.dest.options.length; i++) {
    if(document.f.dest.options[i].value == "0")    // new category
      url += "&N%5B" + i + "%5D=" + escape(document.f.dest.options[i].text)
     else                                         // assigned category
      url += "&C%5B" + i + "%5D=" + escape(document.f.dest.options[i].value)
  }  
  document.location=url
}  
// -->
</SCRIPT>
</HEAD>

<?php
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "config"=>$xx, "category"=>false, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_SLICE_CAT . "</B></H1>";
  PrintArray($err);
  echo $Msg;

/*
$Log$
Revision 1.2  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.1.1.1  2000/06/21 18:39:58  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:47  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.16  2000/06/12 19:58:23  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.15  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.14  2000/04/28 09:48:13  honzama
Small bug in user/group search fixed.

Revision 1.13  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.12  2000/03/29 14:34:12  honzama
Better Netscape Navigator support in javascripts.

Revision 1.11  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/

?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center"><tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr bgcolor="#584011" align="center">
	<td width="45%" class=tabtit><b>&nbsp;<?php echo L_CAT_LIST ?></b></td>
	<td width="10%">&nbsp;</td>
	<td width="45%" class=tabtit><b>&nbsp;<?php echo L_CAT_SELECT ?></b></td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="slisel" size=1 class=tabtxt onChange="SliceChange()">
  <option value=0 selected> <?php echo L_ALL ?> </option>
  <?
  $SQL= "SELECT category_id, name, slice_id, short_name FROM slices, catbinds 
           LEFT JOIN categories ON categories.id=catbinds.category_id 
           WHERE catbinds.slice_id=slices.id ORDER BY short_name, name";
  $db->query($SQL);
  $foo="";          // comma delimeted list of categories for actual slice
  $counter = 1;     // begin with 1
  $oldslice = "";
  if ($db->next_record()) {
    do{ 
      if( $oldslice_id != $db->f(slice_id) ) {
        if($foo != "") {
          echo "<option value=\"$foo\"> ". $oldshort_name ." </option>";   //in value property are listed all categories from this slice
        }
        $oldshort_name = $db->f(short_name);
        $oldslice_id = $db->f(slice_id);
        $foo = "";
      }  
      if( $catnumbers[$db->f(category_id)] == "") {  // not assigned category, yet
        $catnumbers[$db->f(category_id)]=$counter;   // category id are shortered - renumbered
        $foo .= $counter++;
      }
      else                                           // assigned category
        $foo .= $catnumbers[$db->f(category_id)];     
      $foo .= ",";  
      if( !$db->next_record() )
        break;
    } while(true);
  }  
  echo "<option value=\"$foo\"> ". $oldshort_name ." </option>";   
  ?>
</SELECT>
</td>
<td>&nbsp;</td>
<td align="CENTER" valign="TOP">
  <?
  $SQL= "SELECT short_name FROM slices WHERE id ='". q_pack_id($slice_id). "'";
  $db->query($SQL);
  if($db->next_record())
    echo "<span class=tabtxt>". $db->f(short_name) ."</span>"; ?>
</td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="source" size=8 class=tabtxt>
  <?
  $SQL= "SELECT id, name FROM categories ORDER BY name";
  $db->query($SQL);
  while($db->next_record()) {
    $foo = $catnumbers[$db->f(id)];
    if( $foo=="" ) $foo = 0;
    echo "<option value=\"$foo:" . unpack_id($db->f(id)). "\"> ". $db->f(name) ." </option>"; 
  }  ?>
</SELECT>
</td>
<td><input type="button" VALUE="  >>  " onClick = "MoveCateg()" align=center></td>
<td align="CENTER" valign="TOP">
<SELECT name="dest" size=8 class=tabtxt>
  <?
  $SQL= "SELECT name, id FROM categories LEFT JOIN catbinds ON categories.id = catbinds.category_id WHERE catbinds.slice_id='".q_pack_id($slice_id)."'";
  $db->query($SQL);
  while($db->next_record()) 
    echo "<option value=".unpack_id($db->f(id)). "> ". $db->f(name) ." </option>"; ?>
</SELECT>
</td>
</tr>
<tr>
<td>&nbsp;</td>
<td>&nbsp;</td>
<td align="center" class=tabtxt><a href="javascript:NewCateg()"><?php echo L_NEW?></a>&nbsp;&nbsp;
                   <a href="javascript:DelCateg()"><?php echo L_REMOVE?></a>
</td>
</tr>
</table></td></tr>
<tr><td align="center">
<input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
<input type="button" VALUE="<?php echo L_UPDATE ?>" onClick = "UpdateCateg('<?php echo $slice_id ?>')" align=center>&nbsp;&nbsp;
<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>
