<?php 
/*  Author: Jakub Adámek, February 2002

	Hiearchical constant editor - allows to edit constants organized
	in a multi-level hierarchy. Called from the se_constant.php3 page.
*/

/*  Params: 
		group_id - name of constant group
		hide_value = 1 - don't show the "value" edit box, copy "value" from "name"
		levelCount = x - changes count of levels, default=3
		levelsHorizontal = 1 - to show level boxes horizontal
*/	

if (!$group_id) exit;
?>
							   
<?php 
	showHierConstInitJavaScript ($group_id, $levelCount);
?>
<input type=hidden name='hcalldata' value=''>
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_CONSTANTS_HIER_EDT?></b></td></tr>
<tr><td class=tabtxt>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>"><tr><td class=tabtxt>
<?php echo L_CONSTANT_HIER_SORT."<br>"; ?>
</td></tr><tr><td class=tabtxt>

<?php if ($hide_value) echo '<input type="hidden" name="hcfValue">'; ?>

<?php showHierConstBoxes ($levelCount, $levelsHorizontal, "", true, 0, 0, $levelNames); ?>

<table border=0>
	<tr><td>
	   <tr>
<?php echo "
		 <tr><td width='20%' class=tabtxt align=center><b>". L_CONSTANT_NAME ."</b><br>". L_CONSTANT_NAME_HLP ."</td>
      	 <td><textarea name='hcfName' cols=45 rows=3 wrap='soft'></textarea></td></tr>";
	 	  if (!$hide_value) { echo "
			 <tr><td class=tabtxt align=center><b>". L_CONSTANT_VALUE ."</b><br>". L_CONSTANT_VALUE_HLP ."</td>
	         <td><input type='text' name='hcfValue' size=60><br>
				<input type='checkbox' name='hcCopyValue' checked>
				&nbsp;".L_CONSTANT_COPY_VALUE."</td></tr>";
		  }
		echo "	   	
		 <tr><td class=tabtxt align=center><b>". L_CONSTANT_PRI ."</b><br>". L_CONSTANT_PRI_HLP ."</td>
	     <td><input type='text' name='hcfPrior' size=5></td></tr>
		 <tr><td class=tabtxt align=center><b>". L_CONSTANT_DESC . "</b>
	   	 <td><textarea name='hcfDesc' cols=45 rows=5 wrap='soft'></textarea></td></tr>"; ?>
	</td></tr>
	<tr><td valign=center align=center colspan=2>
		<input type="button" value="Update" onClick="hcUpdateMe();">&nbsp;&nbsp;
		<input type="button" value="Delete" onClick="hcDeleteMe(false);">&nbsp;&nbsp;
		<input type="button" value="Delete With All Children" onClick="hcDeleteMe(true);">&nbsp;&nbsp;<br>
		<input type="checkbox" name="hcDoDelete">
		<?php echo L_CONSTANT_CONFIRM_DELETE; ?>
	</td></tr>
	<tr><td colspan=2><hr></td></tr>
	<tr><td colspan=2 align=center><input type=button onClick="hcSendAll();" value="<?php echo L_CONSTANT_HIER_SAVE?>"></td></tr>
	<tr><td class=tabtxt align=center colspan=2><?php echo L_CONSTANT_VIEW_SETTINGS ?>: <input type="checkbox" name='hierarch' checked><?php echo L_CONSTANT_HIERARCHICAL ?>&nbsp;
<input type="checkbox" name="hide_value" <?php if ($hide_value) echo "checked"; echo ">".L_CONSTANT_HIDE_VALUE?>&nbsp;
<input type="checkbox" name="levelsHorizontal" <?php if ($levelsHorizontal) echo "checked"; echo ">".L_CONSTANT_LEVELS_HORIZONTAL; ?>&nbsp;&nbsp;
<?php echo L_CONSTANT_LEVEL_COUNT?>&nbsp;<input type="text" name="levelCount" value="<?php echo safe($levelCount); ?>" size=2></td></tr>
</table>
</td></tr></table>
</td></tr></table>
</form>

<script language=javascript>
<!--
	hcInit();
// -->
</script>

<?php 
HTMLPageEnd();
page_close(); 
exit;
?>