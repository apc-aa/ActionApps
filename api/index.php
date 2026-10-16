<?php

/**
 * AA JSON API front controller.
 *
 * All requests under /api/ are rewritten to this file by api/.htaccess so the
 * original path arrives as $_SERVER['PATH_INFO'] (e.g. "/v1/ping"). Endpoints
 * are registered on the router below; each returns a JsonResponse.
 *
 * @package Api
 */

use AA\Api\Controller\ExpressionsController;
use AA\Api\Controller\FilesController;
use AA\Api\Controller\SpeechController;
use AA\Api\Controller\TranslateController;
use AA\Api\JsonResponse;
use AA\Api\Router;

// Catch any stray output from AA's bootstrap (BOM, notice, HTML login form,
// etc.) so it doesn't leak into the JSON response. The buffer is discarded
// before we emit.
ob_start();

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/locsess.php';
require_once __DIR__ . '/../include/util.php';

// Start session + auth without triggering the HTML login form on anonymous
// requests. The per-endpoint handler is responsible for checking $auth->is_user()
// and returning JsonResponse::error(401, ...) when authentication is required.
pageOpen('nobody');

// Scrub anything AA's bootstrap printed (usually empty, but defensive).
ob_end_clean();

$router = new Router();

$router->register('GET', '/v1/ping', static function (): JsonResponse {
    return JsonResponse::ok([
        'pong' => true,
        'time' => time(),
    ]);
});

$router->register('POST', '/v1/speech/transcribe', static function (): JsonResponse {
    return (new SpeechController())->transcribe();
});

$router->register('POST', '/v1/translate', static function (): JsonResponse {
    return (new TranslateController())->translate();
});

$router->register('POST', '/v1/files', static function (): JsonResponse {
    return (new FilesController())->upload();
});

$router->register('GET', '/v1/files', static function (): JsonResponse {
    return (new FilesController())->list();
});

$router->register('GET', '/v1/expressions', static function (): JsonResponse {
    return (new ExpressionsController())->list();
});

$router->dispatch();
