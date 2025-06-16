<?php
header("Location: " . (($_GET["value"] != "") ? $_GET["value"] : $_GET["url"]));
