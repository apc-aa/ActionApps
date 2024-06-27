<?php
require_once __DIR__."/../../include/config.php";
require_once __DIR__ . "/../../include/locsess.php";   // DB_AA definition
require_once __DIR__ . "/../../include/util.php";

Datovka::possiblyRedirectToAa($_GET['appToken']);

$datovka = Datovka::getFromToken($_GET['appToken']);

if ($datovka) {
    $datovka->run();
}
