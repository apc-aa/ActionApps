<?php
/**  aa_uiprefs.php - persist per-user admin UI preferences (theme, language)
 *     and relay user feedback to ERROR_REPORTING_EMAIL.
 *
 *  POST-only endpoint driven by the settings popover in the admin top bar
 *  (see getUiPrefsPopover() in include/menu_util.php). Actions (aa_action):
 *    - update_theme   : save theme key; 204 for the JS preview fetch, else redirect back
 *    - update_lang    : save language; redirect back (page reloads in the new language)
 *    - send_feedback  : CSRF-checked; email the note + session context, then confirm
 *
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @package   Admin
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Util\InputVars;
use AA\Util\UiPrefs;

require_once __DIR__ . "/../include/init_page.php";

if (!AuthGetUid()) {
    AA_Adminpageutil::MsgPageMenu(safe(get_admin_url('index.php')), _m("You must be logged in."), "admin");
}

try {
    $aa_action   = InputVars::stringPOST('aa_action');
    $ui_theme    = InputVars::stringPOST('ui_theme');
    $ui_lang     = InputVars::stringPOST('ui_lang');
    $aa_feedback = InputVars::stringPOST('aa_feedback');
    $ret_url     = InputVars::stringPOST('ret_url');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// CSRF: require the per-session token issued with the popover form
// (getUiPrefsPopover() in include/menu_util.php). It is stable per session - not
// the single-use AA_Session token store - because the theme control re-posts on
// every change and this popover renders on every admin page. Fails closed.
$token = (string) ($_POST['aa_token'] ?? '');
if (($_SESSION['aa_ui_csrf'] ?? '') === '' || !hash_equals((string) $_SESSION['aa_ui_csrf'], $token)) {
    http_response_code(403);
    exit;
}

// open-redirect guard: accept only a local absolute path (+ query), strip CR/LF
$ret_url = str_replace(["\r", "\n"], '', $ret_url);
$parts   = parse_url($ret_url) ?: [];   // parse_url() returns false on malformed input
$safe_ret = (empty($parts['host']) && isset($parts['path']) && $parts['path'][0] === '/')
    ? $parts['path']
    : (AA_INSTAL_PATH . 'admin/index.php');
if (!empty($parts['query'])) {
    $safe_ret .= '?' . $parts['query'];
}

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

switch ($aa_action) {
    case 'update_theme':
        UiPrefs::saveTheme(AuthGetUid(), $ui_theme);
        if ($is_ajax) {
            http_response_code(204);   // JS already applied the preview + cookie
            exit;
        }
        go_url(StateUrl($safe_ret));   // no-JS fallback: reload with the saved theme

    case 'update_lang':
        UiPrefs::saveLang(AuthGetUid(), $ui_lang);
        go_url(StateUrl($safe_ret));   // page reloads -> init_page re-resolves the language

    case 'send_feedback':
        $feedback = trim($aa_feedback);
        if ($feedback === '') {
            go_url(StateUrl($safe_ret));
        }
        $user_info = AA::$perm->getIDsInfo(AuthGetUid());
        $reply_to  = (is_array($user_info) && !empty($user_info['mails'][0])) ? $user_info['mails'][0] : '';
        $subject   = str_replace(["\r", "\n"], ' ', 'AA feedback [' . ORG_NAME . '] — ' . AuthGetUname());
        $body = "Feedback from: " . AuthGetUname() . " (" . AuthGetUid() . ")\n"
              . "Install:       " . ORG_NAME . " / " . ($_SERVER['HTTP_HOST'] ?? '') . "\n"
              . "Slice id:      " . AA::$module_id . "\n"
              . "Page:          " . ($_SERVER['HTTP_REFERER'] ?? $safe_ret) . "\n"
              . "AA version:    " . aa_version() . "\n"
              . "User agent:    " . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n"
              . "----------------------------------------\n"
              . $feedback;

        $mail = new AA_Mail();
        $sent = 0;
        if ($mail->setFromArray([
            'subject'     => $subject,
            'body'        => $body,
            'html'        => false,
            'lang'        => 'utf-8',
            'header_from' => ERROR_REPORTING_EMAIL,
            'reply_to'    => $reply_to,
            'errors_to'   => ERROR_REPORTING_EMAIL,
            'sender'      => ERROR_REPORTING_EMAIL,
            'cc'          => '',
            'bcc'         => '',
            'attachments' => '',
        ])) {
            $sent = $mail->send([ERROR_REPORTING_EMAIL]);
        }

        if ($sent) {
            AA_Adminpageutil::MsgPageMenu(safe(StateUrl($safe_ret)), MsgOk(_m("Thanks — your note reached the AA team.")), "admin");
        }
        AA_Adminpageutil::MsgPageMenu(safe(StateUrl($safe_ret)), MsgErr(_m("Couldn't send right now. Please email us directly: %1", [ERROR_REPORTING_EMAIL])), "admin");

    default:
        go_url(StateUrl($safe_ret));
}
