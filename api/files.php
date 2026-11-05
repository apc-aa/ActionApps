<?php

/**
 * Files of a slice for the rich-text editor: POST uploads one (multipart `file`, `slice`,
 * header X-AA-Upload-Token), GET lists them (`slice`, `kind`, `q`), GET `ask` gives an editor
 * written in a template its upload-* attributes (`slice` or `item`, header X-AA-Upload-Ask).
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
$response   = match (true) {
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' => $controller->upload(),
    isset($_GET['ask'])                              => $controller->ask(),
    default                                          => $controller->list(),
};
// the answers name the viewer's token and files - no proxy may keep them for the next visitor
if (!headers_sent()) {
    header('Cache-Control: private, no-store');
}
$response->emit();
