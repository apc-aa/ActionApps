<?php
//$Id$
/* 
Copyright (C) 2001 Association for Progressive Communications 
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

/* 
	Author: Jakub Adámek, Pavel  Jisl

	Exports the slice definition as a template
	Two kinds of export:
		* for another AA installation - allows to change the id
		* for backup reasons - allows to export more defs at once
	Now you can export slice data too (all data or some only, if date
	is specified.
			
	To show the exported text the page sliceexp_text.php3 is called.
*/

require "../include/init_page.php3";
require "./sliceexp_text.php3";

if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD) ) {
	MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EXPORT_IMPORT, "standalone");
	exit;
}

if (isset($b_export_to_file))
{
	exportToFile($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date);
    exit;
} else {
  
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

?>
<TITLE><?php echo L_E_EXPORT_TITLE?></TITLE>

<SCRIPT LANGUAGE="JAVASCRIPT" TYPE="TEXT/JAVASCRIPT">
<!-- Hide script from old browsers

function b_export_date_onchange(vstup)
{
	error = 0; tecka = 0; den = 0; rok = 0; mesic = 0;
	for (index = 0; index < vstup.value.length; index++) {
      	ch = vstup.value.charAt(index);
		if (ch != "0" && ch != "1" && ch != "2" && ch != "3" && ch != "4" && ch != "5" && ch != "6" && ch != "7" && ch != "8" && ch != "9" && ch != ".") 
				{ error = 1; }
		if ((ch == "0" || ch == "1" || ch == "2" || ch == "3" || ch == "4" || ch == "5" || ch == "6" || ch == "7" || ch == "8" || ch == "9") && (error == 0))
		{
			if (tecka == 0) {den=den + ch}
			if (tecka == 1) {mesic=mesic + ch}
			if (tecka == 2) {rok=rok + ch}
		}
		if (ch == "." && error == 0)
		{
			if (tecka == 1) {tecka=2}
			if (tecka == 0) {tecka=1}
		}	
	}
			
	if ((den<1 || den >31) && (error == 0)) { error = 1; }
	if ((mesic<1 || mesic>12) && (error == 0)) { error = 1;}
	if (rok<1990 && tecka == 2 && error == 0 && rok != "") { error = 1;}
	if ((tecka == 2 && rok == "") || (tecka > 2)) { error = 2;}
	if (error == 1)
	{
		alert(<?php echo '"'.L_EXPORT_DATE_ERROR.'"' ?>);
		vstup.focus();
	}
	if (error == 2) 
	{
		alert(<?php echo '"'.L_EXPORT_DATE_TYPE_ERROR.'"' ?>);
		vstup.focus();	
	}	
	document.forms["f"].b_export_spec_date.checked = true;
}

	function validate () {
		form = document.forms["f"];	
		if (form.SliceID.value.length != 16) {
			alert (<?php echo '"'.L_E_EXPORT_IDLENGTH.'"' ?>
				+ form.SliceID.value.length);
			form.SliceID.focus();
		} 
		else {
			form.submit();
		}
	};	
	function validate2() {
		sl_count = 0;
		x = document.f['export_slices[]'];
		form = document.forms["f"];
		for (i=0; i<x.length; i++) {
		  sl_count += (x.options[i].selected ? 1 : 0);
		};
		if (sl_count == 0) {
		  alert (<?  echo '"'.L_E_EXPORT_MUST_SELECT.'"' ?>);
		  return false;
		} 
		else {
			return true;
		}
	};
	//-->
</SCRIPT>	

<?php include $GLOBALS[AA_INC_PATH]."js_lib.js"; ?>

</HEAD>

<BODY>

<?php
	require $GLOBALS[AA_INC_PATH]."menu.php3";
    showMenu ($aamenus, "aaadmin","sliceexp");
?>

<h1><b><?php echo L_E_EXPORT_TITLE ?></b></h1>

<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">

<?php 
if ($SHOWTEXT == ""): ?>
	<form name="f" method=post action="<?php echo $sess->url("sliceexp.php3") ?>" onsubmit="return validate2();">
	
	<?php
		$SQL= "SELECT id, name FROM slice ORDER BY name";
		$db->query($SQL);
		while($db->next_record())
			$all_slices[unpack_id($db->f(id))] = $db->f(name);
	?>
	
	<tr><td class=tabtxt colspan=2>
	<table width="100%" border="0" cellspacing="0" cellpadding="0">
		<tr><td class=tabtxt>
		<b><?php echo L_E_EXPORT_DESC_EXPORT ?></b><br>
		<input type="checkbox" name="b_export_struct" value="1" checked><?php echo L_E_EXPORT_EXPORT_STRUCT ?><br>
		<input type="checkbox" name="b_export_data" value="1"><?php echo L_E_EXPORT_EXPORT_DATA ?><br>
		<?php if (function_exists('gzcompress')) { 
		// this is checking, if your php have zlib support
		// i don't know, if it works, because we have php with it...
		?>
		<input type="checkbox" name="b_export_gzip" value="1"><?php echo L_E_EXPORT_EXPORT_GZIP ?><br>
		<?php } ?>
		<input type="checkbox" name="b_export_to_file" value="1"><?php echo L_E_EXPORT_EXPORT_TO_FILE ?><br><br>
		<table>
		<tr>
			<td class=tabtxt><input type="checkbox" name="b_export_spec_date" value="1"><?php echo L_E_EXPORT_SPEC_DATE ?></td>
			<td class=tabtxt><?php echo L_E_EXPORT_FROM_DATE ?><input type="text" name="b_export_from_date" length="10" maxlength="10" width="10"  onChange="b_export_date_onchange(this)"></td>
			<td class=tabtxt><?php echo L_E_EXPORT_TO_DATE ?><input type="text" name="b_export_to_date" length="10"  maxlength="10" width="10"  onChange="b_export_date_onchange(this)"></td>
		</tr>
		</table>		
		</td>
	</tr>
	</table>
	</td></tr>

	<tr><td class=tabtit colspan=2>
	<br><p><b><?php echo L_E_EXPORT_MEMO ?> </b></P>
	</td></tr>

	<tr>
		<td class=tabtxt width=50%>
		<table width="100%" border="0" cellspacing="0" cellpadding="0">
			<tr><td class=tabtxt width="100%">
			<b><?php echo L_E_EXPORT_DESC_BACKUP ?></b></P>
			<?php echo L_E_EXPORT_LIST ?>
			</td>
			<tr><td class=tabtxt width="100%">
				<table width=200><tr><td width=100%>
				<SELECT name="export_slices[]" size=8 class=tabtxt MULTIPLE>
				<?php
					reset($all_slices);
				    while(list($s_id,$name) = each($all_slices))
				        echo "<option value=\"$s_id\"> $name </option>";
				?> 
				</SELECT>
				</td></tr>
				</table>							
		</table>		
		</td>	
		<td class=tabtxt width=50% valign=top>
			<b><?php echo L_E_EXPORT_DESC ?></b></P>
			<b><?php echo L_E_EXPORT_MEMO_ID ?></b>
			<INPUT TYPE="TEXT" NAME="SliceID" VALUE="template" SIZE=16 MAXLENGTH=16></P>
			<INPUT TYPE="HIDDEN" NAME="SHOWTEXT" VALUE="OHYES">			
		</td>
	</tr>
	<tr>
		<td><INPUT TYPE=submit NAME="b_export_type" VALUE="<?php echo L_E_EXPORT_SWITCH ?>"></td>
		<td><INPUT TYPE=button NAME="b_export_type" VALUE="<?php echo L_E_EXPORT_SWITCH_BACKUP ?>" onClick="javascript:validate()"></td>
	</tr>
	</form>
	</tr></td>
<?php
else:
	exportToForm($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date);
endif;
?>

</TABLE>
</BODY>
</HTML>
<?php page_close(); } ?>