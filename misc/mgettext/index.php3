<?php
/**
 * Shows a HTML page allowing to create and update mini-gettext language files.
 * See @link include/lang/readme.html for more info.
 *
 * If you want to use MiniGetText otherwere than in AA, take this file only as an
 * example on how to move your scripts to this system and how to provide 
 * a HTML page for easy updating the language files.
 * 
 * @package MiniGetText
 * @version $Id$
 * @author Jakub Adamek, Econnect, January 2003
 * @copyright Copyright (C) 1999-2003 Association for Progressive Communications 
*/
/* 
Copyright (C) 1999-2003 Association for Progressive Communications 
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

require_once "../../include/config.php3";
require_once "./xmgettext.php3";
require_once "./translate.php3";
require_once "./createlogs.php3";

// list of all languages. Useful if you want e.g. update only one language file.
$lang_list = array ("cz"=>1,"sk"=>1,"es"=>1,"en"=>1,"ro" => 1,"ja"=>1,"de"=>1);

// $aadir is the source dir for updating translation files
$aadir = $AA_BASE_PATH; 
endslash ($aadir);
/* IMPORTANT:
   $destdir is the destination dir where are the copies of language files to which
   PHP has read-write access. By default, it is set in config.php3. */
$destdir = $XMGETTEXT_DESTINATION_DIR;
endslash ($destdir);

/* IMPORTANT:
   Groups of directories / files assigned to individual language files.
   Assign individual files or whole directories (must be terminated by '/'). 
   Skip files by adding minus sign before the file name (e.g. "-include/mgettext.php3").
   
   A file may appear in several groups (like files from "output" do), 
   but than the translations must be entered several times in the language files. 
   
   Why so many groups? To save time on reading language translations. */
   
// _alerts_lang.php3 language files, used in the Alerts module
$lang_groups ["alerts"] =
    array ("modules/alerts/");
// _news_lang.php3 language files, used almost everywhere in Control panel
$lang_groups ["news"] =
    array ("./",
           "admin/",
           "include/",
           "-./slice.php3",
           "-admin/param_wizard.php3",
           "-include/view.php3",
           "-include/constants_param_wizard.php3",
           "-include/mgettext.php3");
// _site_lang.php3 language files, used in the Site module           
$lang_groups ["site"] = 
    array ("modules/site/");               
// _links_lang.php3 language files, used in the Links module           
$lang_groups ["links"] = 
    array ("modules/links/",
           "include/manager.class.php3",
           "include/searchbar.class.php3",
           "include/util.php3" );
// _output_lang.php3 language files, used in the output, i.e. slice.php3 and view.php3    
$lang_groups ["output"] =
    array ("./slice.php3",
           "./view.php3",
           "./discussion.php3",
           "include/slice.php3",
           "include/view.php3",    
           "include/discussion.php3",
           "include/item.php3",
           "include/scroller.php3",
           "include/easy_scroller.php3",
           "include/itemview.php3");
// _param_wizard_lang.php3 language files, used only in the parameter wizard           
$lang_groups ["param_wizard"] = 
    array ("admin/param_wizard.php3",
           "include/constants_param_wizard.php3",
           "doc/param_wizard_list.php3");
           
/* ----------------------------------------------------------------------------
   CREATING MGETTEXT LANGUAGE FILES

   Here are the settings used only when creating mgettext language files, 
   changing all language constants to _m() calls in PHP source files and using
   the language constants to fill translations into mgettext language files. */

// set to true to show the related HTML
$show_create_related = false;
   
/* $translateddir is needed only on moving PHP scripts to mgettext. 
   The PHP files which you translate from language constants to mgettext will be placed there.*/
$translateddir = $destdir."translated/";
// should be the translations from language constants added to the mgettext language files?
if (!$addlogs) $addlogs = false;

/* $old_group, $old_lang_files and $log_files are used when creating log files
   which enable to include language constants into mgettext language files. */
$old_group = "common";
$old_lang_files = $aadir."include/??_".$old_group."_lang.php3"; 
$log_files = $destdir."log_??_".$old_group."_lang.php3";

// directories from which to translate files (replace language constants by _m() calls)
$translate_dirlist = array (".","include","admin","modules","modules/module_TEMPLATE",
    "modules/jump", "modules/alerts", "misc/oldDb2db", "modules/links");
  
    
/*
// special settings for converting the site module
$old_group = "site";
$old_lang_files = $aadir."include/??_".$old_group."_lang.php3"; 
$log_files = $destdir."log_??_".$old_group."_lang.php3";
$translate_dirlist = array ("modules/site");
// end of special
*/

/* The file taken as the base for translations, usually the English one.
   If you are using English messages in _m() calls in source code, use the English file. */
$translate_lang_file = str_replace ("??","en",$old_lang_files);
// ---------------------------------------------------------------------------- 
        
// Command called from the form:        
if ($cmd == "create logs")
    create_logs ($lang_list, $old_lang_files, $log_files);    
else if ($cmd == "update language files" && is_array ($update)) 
    update_language_files ($aadir, $destdir, false);        
else if ($cmd == "convert scripts")
    translate_aa_files ($aadir, $translateddir);

// ---------------------------------------------------------------------------- 

function update_language_files ($aadir, $destdir)
{    
    global $update, $lang_groups, $addlogs, $lang_list;
    //echo $aadir." ".$destdir;    @mkdir ($destdir, 0777);

    $logfile = $destdir."log_language_updates.php3";
    if (file_exists ($logfile)) 
        include $logfile;

    $xmgettext_logfile = $destdir."collect_msg_log.php3";
            
    reset ($lang_groups);
    while (list ($langfiles, $srcfiles) = each ($lang_groups)) {
        if (!$update[$langfiles])
            continue;
        if ($log_group_processed == $langfiles)
            unset ($log_group_processed);
        if (!$log_group_processed) {
            $fd = fopen ($logfile, "w");
            fwrite ($fd, "<?php \$log_group_processed = \"$langfiles\"; ?>\n");
            fclose ($fd);
            chmod ($logfile, 0664);
            if (!$addlogs)
                xmgettext ($lang_list, $xmgettext_logfile, $destdir."??_".$langfiles."_lang.php3", $aadir, $srcfiles, 0666, false);
            else xmgettext ($lang_list, $xmgettext_logfile, $destdir."??_".$langfiles."_lang.php3", $aadir, $srcfiles, 0666, false,
                $destdir."log_??_".$langfiles."_lang.php3");
        }
    }
    unlink ($logfile);
    echo '<font color="red"><b>Update Language Files has FINISHED</b></font><Br>';
}

// ---------------------------------------------------------------------------- 

function translate_aa_files ($aadir, $dstdir)
{
    global $translate_dirlist, $translate_lang_file;
    @mkdir ($dstdir,0777);
    
    reset ($translate_dirlist);
    while (list (,$dir) = each ($translate_dirlist)) {
        @mkdir ($dstdir.$dir,0777);
        translate_files ($translate_lang_file, 
                         $aadir.$dir."/",
                         $dstdir.$dir."/");
    }
    echo "Ready<br>";
}

// ---------------------------------------------------------------------------- 

/// Adds slash at the end of a directory name if it is not yet there.
function endslash (&$s) {
    if (strlen ($s) && substr ($s,-1) != "/")
        $s .= "/";
}

// ---------------------------------------------------------------------------- 

echo '
<HTML>
<HEAD><TITLE>Mini GetText</TITLE></HEAD>
<BODY>
<FORM name="f" METHOD="post" ACTION="index.php3">
<table border="1"><tr><td valign="top">
    <h1>Mini GetText maintenance</h1>
    <p>AA dir: <b>'.$aadir.'</b><br>
    Destination dir: <b>'.$destdir.'</b>
    </p>
</td><td valign="top">
    <p>This script creates and updates mini-gettext language files. ';
    
if ($show_create_related)
    echo 'It also allows to replace language constants to calls of the
    _m() function.';
    
echo '</p>
    
    <p>If some settings are not OK, edit them in the script source file.</p>
</td></tr>
<tr><td valign="top" colspan="2">
    <br><h2>Update language files</h2>
</td></tr>
<tr><td valign="top">
    <table border="1">
    <tr><td><b>language files</b></td><td><b>are updated from</b></td></tr>';
    
reset ($lang_groups);
while (list ($group, $members) = each ($lang_groups)) 
    echo '<tr><td>
        <input type="checkbox" name="update['.$group.']" checked>
        ??_'.$group.'_lang.php3</td><td>'.join (", ", $members).'</td></tr>';
    
echo '</table>';
    
$addlog_hint = "Add translations from language log files.";
if ($show_create_related) {
    echo '<p><input type="checkbox" name="addlogs"'.($addlogs ? ' checked' : '').'>';
    echo $addlog_hint.'</p>';
}

echo '    
    <p>Warning: Because the file parsing is a long action, perhaps the server will time out
       during the operation. Just Refresh (Ctrl+R) this page until the server finishes.</p>
       
    <INPUT type="submit" name="cmd" value="update language files">
</td>
<td valign="top">
    <p>This action will update the language files in the Destination dir. 
    You should:
    <ol><li>copy files from <tt>'.$aadir.'include/lang</tt> to <tt>'.$destdir.'</tt></li>
        <li>run this action</li>
        <li>copy the updated files back to <tt>'.$aadir.'include/lang</tt></li>
    </ol></p>';

if ($show_create_related)     
    echo '<p>If this is the first time you run Create or update, you should 
       first create log files (see below) and check the box 
       "'.$addlog_hint.'".</p>';
       
echo '</td></tr>';

if ($show_create_related) {
    echo '
    <tr><td valign="top" colspan="2">
        <br><h2>Convert scripts</h2>
    </td></tr>
    <tr><td valign="top">    
        <p>Warning: Because the file conversion is a long action, perhaps the server will time out
           during the operation. Just keep refreshing (Ctrl+R) this page until the server finishes.</p>
        <INPUT type="submit" name="cmd" value="convert scripts">
    </td>
    <td valign="top">
        <p>This will convert the scripts by replacing all occurences of L_ language constants
           by appropriate <tt>_m()</tt> calls. Translations are taken from the lang file <tt>'.$translate_lang_file.'</tt>.</p>
        <p>All files in subdirs <tt>'.join(", ",$translate_dirlist).'</tt> of <tt>'.$aadir.'</tt> will be converted and the results placed into <tt>'.$translateddir.'</tt>.</p>
    </td></tr>
    
    <tr><td valign="top" colspan="2">
        <br><h2>Create logs</h2>
    </td></tr>
    <tr><td valign="top">    
        <p>Call "create logs" several times until the message "All logs created" appears.</p>
           
        <INPUT type="submit" name="cmd" value="create logs">
    </td>
    <td valign="top">
        <p>This action is needed only the first time you prepare your PHP scripts
           for mini-gettext usage.</p>
        <p>It creates log files from old language files, one log file for one language a time.</p>
    </td></tr>'; 
}

echo '
</table>    

</FORM>
</BODY>
</HTML>';
?>
