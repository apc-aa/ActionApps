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
	Author: Jakub Adámek
	This file is under construction. I am learning to work with AA,
	and there is a lot to learn yet ...
*/

/*	
	Exports the slice definition as a template (without the data).
	Two kinds of export:
		* for another AA installation - allows to change the id
		* for backup reasons - allows to export more defs at once
*/

require "../include/init_page.php3";

if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD) ) {
	MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EXPORT_IMPORT, "standalone");
	exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

?>
<TITLE><?php echo L_E_EXPORT_TITLE?></TITLE>

<SCRIPT LANGUAGE="JAVASCRIPT" TYPE="TEXT/JAVASCRIPT">
	<!-- Hide script from old browsers
	function validate () {
		form = document.forms["f"];
		if (form.SliceID.value.length != 16) {
			alert (<?php echo '"'.L_E_EXPORT_IDLENGTH.'"' ?>
				+ form.SliceID.value.length);
			form.SliceID.focus();
		}
		else form.submit();
	};		
	-->
</SCRIPT>	

<?php include $GLOBALS[AA_INC_PATH]."js_lib.js"; ?>

</HEAD>

<BODY>

<?php
  	$show["sliceexp"] = false;
	require $GLOBALS[AA_INC_PATH]."se_inc.php3"; //show navigation column depending on $show 
?>

<h1><b><?php echo L_E_EXPORT_TITLE ?></b></h1>

<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">

<?php 
if ($SHOWTEXT == ""): ?>
	<form name="f" method=post action="<?php echo $sess->url("sliceexp.php3") ?>">
	<tr><td class=tabtit>
	<b><?php echo L_E_EXPORT_MEMO ?> </b></P>
	</td></tr>

	<?php
		$SQL= "SELECT id, name FROM slice ORDER BY name";
		$db->query($SQL);
		while($db->next_record())
			$all_slices[unpack_id($db->f(id))] = $db->f(name);
	?>
	
	<tr><td class=tabtxt>
	<table width="100%" border="0" cellspacing="0" cellpadding="0">
		<tr><td class=tabtxt>
		<b><?php echo L_E_EXPORT_DESC_BACKUP ?></b></P>
		<INPUT TYPE=SUBMIT NAME="b_export_type" VALUE="<?php echo L_E_EXPORT_SWITCH ?>">
		</td>
		<td class=tabtit width=200>
		<table width="100%" border="0" cellspacing="0" cellpadding="0">
			<tr><td class=tabtxt width="100%">
			<?php echo L_E_EXPORT_LIST ?>
			</td>
			<tr><td class=tabtit width="100%">
			<SELECT name="eexport" size=8 class=tabtxt>
			<?php
				reset($all_slices);
			    while(list($s_id,$name) = each($all_slices))
			        echo "<option value=\"$s_id\"> $name </option>";
			?> 
			</SELECT>
		</table>
		</td></tr>
	</table>
	</td></tr>

	<tr><td class=tabtit>
	<b><?php echo L_E_EXPORT_DESC ?></b></P>
	<INPUT TYPE=BUTTON NAME="b_export_type" VALUE="<?php echo L_E_EXPORT_SWITCH_BACKUP ?>" 
	onClick="javascript:validate()"></P>
	<b><?php echo L_E_EXPORT_MEMO_ID ?></b>
	<INPUT TYPE="TEXT" NAME="SliceID" VALUE="template" SIZE=16 MAXLENGTH=16></P>
	<INPUT TYPE="HIDDEN" NAME="SHOWTEXT" VALUE="OHYES">
	</form>
	</tr></td>
<?php
else:
	require "./tmp.php3";
//	require "./sliceexp_text.php3";
endif;
?>

</TABLE>
</BODY>
</HTML>
<?PHP
/*
$Log$
Revision 1.1  2001/10/02 11:33:53  honzam
new sliceexport/import feature

*/
?>

	