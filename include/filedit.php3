<?php 

/* Single file part of file manager
   edit, download, rename a file here
*/

// returns true if file opening OK, false otherwise

$filedit_js = "
    <script language='javascript'>
    <!--

    var formname = 'fileman';
    function submitCommand (name) {
        document.forms[formname][name].value = 1;
        document.forms[formname].submit();
    }
    
    function command (name) {
        switch (name) {
        case 'reset':
            document.forms[formname].filecontent.value = filetext;
            break;
        default:
            submitCommand (name); 
            break;
        }
    }
    
    //-->
    </script>";

function filedit ($path, $filename, $script, $top, $bottom, $wwwpath) {
    // don't edit the file if you won't be able to save it
    $filedes = @fopen ($path.$filename, "a");
    if (!$filedes) {
        $GLOBALS[err][] = "Cannot open file.";
        return false;
    }
    fclose ($filedes);
    $filedes = fopen ($path.$filename, "r");
    $top ($filename);
    echo $GLOBALS[filedit_js];
    echo "
    <form name='fileman' method=post action='$script'>
    <input type=hidden name='filename' value='$filename'>"
    .fileAction ("cancel", 'Back to file list')
    .formatAction("<a href='$wwwpath$filename'>Download</a>&nbsp;&nbsp;")
    .fileAction ("rename","Rename to")."<input type=text name='renamename' value='".basename($filename)."'>";

    echo "<hr>".formatAction("Edit:")."<br>
    <textarea name='filecontent' cols=80 rows=30>
    </textarea><br>";
    echo fileAction ("savefile", 'Save changes')
        .fileAction ("reset", 'Reset content');
    echo "</td></tr></table>";
    echo "</form>";
    echo "<script language='javascript'>
    <!--
        filetext = ";
        while (!feof ($filedes)) {
            $row = fgets($filedes, 4096);
            $row = str_replace ("'", "\\'", $row);
            $row = str_replace ("\n", "\\n", $row);
            $row = str_replace ("\r", "\\r", $row);
            echo "'$row'+\n";
        }
        fclose ($filedes);           
    echo "'';
        document.forms['fileman'].filecontent.value = filetext;
    //-->
    </script>";
    $bottom ();
    return true;
}

function filedit_top ($filename) {
    HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
    echo "<TITLE>".L_A_FTP_TIT."</TITLE>";
    echo "</HEAD>";

    $GLOBALS[show]["fileman"] = false;
    require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

    echo "<H1><B>" . L_A_FTP_TIT . " - ".L_FILE." ".$filename . "</B></H1>";

    PrintArray($err);
    echo $Msg;
    
    echo '<table border="0" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';
    echo "<tr><td colspan=2 class=tabtxt>";
}

function filedit_bottom () {
    echo "</td></tr></table></body></html>";
}

?>

