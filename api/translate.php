<?php

/**
 * AA text translation endpoint. POST `text`, `from`, `to`, get JSON back.
 *
 * A real file, not a route: api/.htaccess's rewrite skips it (the !-f guard), so
 * it also works where mod_rewrite is off. Why the server and not the browser:
 * see AA\Api\Controller\TranslateController.
 *
 * @package Api
 */

use AA\Api\Controller\TranslateController;
use AA\Api\JsonResponse;

// AA's bootstrap can print (BOM, notice, login HTML); buffer it so it cannot
// leak into the JSON. Discarded before we emit.
ob_start();

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/locsess.php';
require_once __DIR__ . '/../include/util.php';

// 'nobody' stops AA printing its HTML login form; the controller checks
// $auth->is_user() itself and answers 401.
pageOpen('nobody');

ob_end_clean();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    JsonResponse::error(405, 'POST required')->emit();
    exit;
}

(new TranslateController())->translate()->emit();
