<?php

/**
 * AA speech-to-text endpoint — direct PHP entry point.
 *
 * Reached as /api/transcribe.php — a real file, so api/.htaccess's rewrite
 * skips it (the !-f guard) and it works on hosts where mod_rewrite is off
 * or .htaccess is ignored entirely. Receives a multipart POST with `audio`
 * (file) and `lang` (optional locale), proxies to OpenAI's
 * /v1/audio/transcriptions, returns JSON. Replaces the previous
 * /api/v1/speech/transcribe front controller, which depended on rewrites.
 *
 * @package Api
 */

use AA\Api\Controller\SpeechController;
use AA\Api\JsonResponse;

// Catch any stray bootstrap output (BOM, notice, login HTML) so it doesn't
// leak into the JSON response. Discarded before we emit.
ob_start();

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/locsess.php';
require_once __DIR__ . '/../include/util.php';

// Anonymous-friendly bootstrap; the controller checks $auth->is_user() itself
// and returns 401 if needed.
pageOpen('nobody');

ob_end_clean();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    JsonResponse::error(405, 'POST required')->emit();
    exit;
}

(new SpeechController())->transcribe()->emit();
