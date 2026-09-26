<?php

/**
 * Files of a slice for the rich-text editor: POST uploads one (multipart `file`, `slice`,
 * header X-AA-Upload-Token), GET lists them (`slice`, `kind`, `q`).
 *
 * A real file, like translate.php: it works where the api/.htaccess rewrite does not
 * (the ECN servers ignore .htaccess). Rules: AA\Api\Controller\FilesController.
 *
 * @package Api
 */

use AA\Api\Controller\FilesController;

// AA's bootstrap can print (BOM, notice, login HTML); buffer it so it cannot leak into the JSON.
ob_start();

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/locsess.php';
require_once __DIR__ . '/../include/util.php';

// 'nobody': no HTML login form; the controller decides what an anonymous visitor may do.
pageOpen('nobody');

ob_end_clean();

$controller = new FilesController();
((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? $controller->upload() : $controller->list())->emit();
