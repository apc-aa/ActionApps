<?php
/**
 * Redirects to the TableEdit with Alerts Collection info,
 * kept only for compatibility with the modules interface.
 * 
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
    $set_tview = "modedit";
    $cmd[$set_tview]["show_new"] = 1;
    $directory_depth = "../";
    $no_slice_id = true;
    
    require_once "tabledit.php3";
?>