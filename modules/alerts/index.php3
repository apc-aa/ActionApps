<?php
    require "../../include/config.php3";

    header("Status: 302 Moved Temporarily");
    header("Location: ".$AA_INSTAL_PATH."admin/tabledit.php3?set_tview=au&setTab=app &AA_CP_Session=$AA_CP_Session");
?>