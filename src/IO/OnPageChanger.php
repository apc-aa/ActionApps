<?php
/**
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\IO;

use AA;
use ConvertCharset;

/** OnPageChanger class - reacts to AJAX calls on page and changes page parts
 */
class OnPageChanger  {

    protected $content_function;

    function __construct($content_function) {
        $this->content_function = $content_function;
    }

    public function checkAndProcessCall($additional_validity_check=true) {
        global $auth;
        if (IsAjaxCall()) {
            header("Content-type: application/json");
            if (!$additional_validity_check) {
                if (is_object($auth) AND $auth->is_user()) {
                    echo json_encode(['message'=>_m('No permission for current user.')]);
                } else {
                    // @todo - show login
                    echo json_encode(['message'=>_m('No permission - no user is authenticated. Try to relogin, please.')]);
                }
                exit;
            }

            if (is_array($_POST['aa']) OR is_array($_FILES['aa'])) {
                $grabber = new AA\IO\Grabber\Form();
                $saver   = new AA\IO\Saver($grabber, null, null, 'by_grabber');
                // $saver - > check Perms @todo
                
                [$saved_ok,$saved_err] = $saver->run();

                $err_msg = $saved_err ? $saver->report() : ''; //"$saved_ok,$saved_err"; // ''
                $this->sendPartsAndExit($saver->changedModules(),$err_msg);
            }
            if (isset($_GET['aaedit'])) {
                $this->sendPartsAndExit([$_GET['aaedit']]);
            }
        }
    }

    protected function sendPartsAndExit($vars, $add_error_msg ='') {
        // we need to evaluate $page_content in order to reevaluate DependentParts
        call_user_func($this->content_function);

        $parts = AA::Stringexpander()->getDependentParts($vars);
        if (AA::$encoding !== 'utf-8') {
            $convertor = ConvertCharset::singleton();
            foreach ($parts as $k => $v) {
                $parts[$k] = $convertor->Convert($v, AA::$encoding, 'utf-8');
            }
        }
        if ($add_error_msg) {
            $parts['message'] = $add_error_msg;
        }

        echo json_encode($parts);
        exit;
    }
}
