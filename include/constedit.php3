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
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Constants - Hiearchical editor")?></b></td></tr>
<tr><td class=tabtxt>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>"><tr><td class=tabtxt>
<?php echo _m("Changes are not saved into database until you click on the button at the bottom of this page.<br>Constants are sorted first by Priority, second by Name.")."<br>"; ?>
</td></tr><tr><td class=tabtxt>

<?php if ($hide_value) echo '<input type="hidden" name="hcfValue">'; ?>

<?php showHierConstBoxes ($levelCount, $levelsHorizontal, "", true, 0, 0, $levelNames); ?>

<table border=0>
    <tr><td>
       <tr>
<?php echo "
         <tr><td width='20%' class=tabtxt align=center><b>". _m("Name") ."</b><br>". _m("shown&nbsp;on&nbsp;inputpage") ."</td>
         <td><textarea name='hcfName' cols=45 rows=3 wrap='soft'></textarea></td></tr>";
          if (!$hide_value) { echo "
             <tr><td class=tabtxt align=center><b>". _m("Value") ."</b><br>". _m("stored&nbsp;in&nbsp;database") ."</td>
             <td><input type='text' name='hcfValue' size=60><br>
                <input type='checkbox' name='hcCopyValue'>
                &nbsp;"._m("Copy value from name")."</td></tr>";
          }
        echo "
         <tr><td class=tabtxt align=center><b>". _m("Priority") ."</b><br>". _m("constant&nbsp;order") ."</td>
         <td><input type='text' name='hcfPrior' size=5></td></tr>
         <tr><td class=tabtxt align=center><b>". _m("Description") . "</b>
         <td><textarea name='hcfDesc' cols=45 rows=5 wrap='soft'></textarea></td></tr>"; ?>
    </td></tr>
    <tr><td valign=center align=center colspan=2>
        <input type="button" value="Update" onClick="hcUpdateMe();">&nbsp;&nbsp;
        <input type="button" value="Delete" onClick="hcDeleteMe(false);">&nbsp;&nbsp;
        <input type="button" value="Delete With All Children" onClick="hcDeleteMe(true);">&nbsp;&nbsp;<br>
        <input type="checkbox" name="hcDoDelete">
        <?php echo _m("Check to confirm deleting"); ?>
    </td></tr>
    <tr><td colspan=2><hr></td></tr>
    <tr><td colspan=2 align=center><input type=button onClick="hcSendAll();" value="<?php echo _m("Save all changes to database")?>"></td></tr>
    <tr><td class=tabtxt align=center colspan=2><?php echo _m("View settings") ?>: <input type="checkbox" name='hierarch' checked><?php echo _m("Hierarchical") ?>&nbsp;
<input type="checkbox" name="hide_value" <?php if ($hide_value) echo "checked"; echo ">"._m("Hide value")?>&nbsp;
<input type="checkbox" name="levelsHorizontal" <?php if ($levelsHorizontal) echo "checked"; echo ">"._m("Levels horizontal"); ?>&nbsp;&nbsp;
<?php echo _m("Level count")?>&nbsp;<input type="text" name="levelCount" value="<?php echo safe($levelCount); ?>" size=2></td></tr>
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