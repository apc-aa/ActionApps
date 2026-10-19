<?php

require_once __DIR__ . "/../../include/config.php";
require_once __DIR__ . "/../../include/locsess.php";   // DB_AA definition
require_once __DIR__ . "/../../include/util.php";

$app_token = Datovka::validAppToken($_GET['appToken'] ?? '');
if (($app_token === null) || !Datovka::possiblyRedirectToAa($app_token)) {
    http_response_code(404);
    exit;
}

$datovka = Datovka::getFromToken($app_token);
$datovka?->run();
