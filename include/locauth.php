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
* @package   Include
* @version   $Id$
* @author    Jiri Hejsek, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      https://www.apc.org/ APC
*
*/

use AA\IO\DB\DB_AA;

require_once __DIR__ . "/locsess.php";
require_once __DIR__ . "/perm_core.php"; // always included!

// choice of the permission system library
// PERM_LIB is defined in config.php
require_once __DIR__."/perm_" . PERM_LIB . ".php";

class AA_Auth extends Auth {

    function __construct() {
        $this->lifetime = defined('AA_LOGIN_TIMEOUT') ? constant('AA_LOGIN_TIMEOUT') : 1440;   // day
        $this->nobody = false;
    }

    /** Is some user logged in? The is_authenticated() works differently, so we added this one  */
    public function is_user(): bool {
        return strlen($this->auth["uid"]) && $this->auth["uid"] !== 'nobody';
    }

    public function getUid(): string {
        return (string)$this->auth["uid"];
    }

    public function getUname(): string {
        return (string)$this->auth["uname"];
    }

    function set_nobody($state = true): void
    {
        $this->nobody = $state;
    }

    /** former relogin_if function
     */
    function relogin(): void
    {
        $this->unauth();
        $this->start();
    }

    /** auth_loginform
     *
     */
    function auth_loginform($login_msg = ''): void
    {
        global $sess, $_PHPLIB, $anonymous_user;
        $username = $_POST["username"] ?? '';  // there was problem with variables
        $password = $_POST["password"] ?? '';  // in cookies - if someone writes
        // to cookies username, then the
        // cookies username is used - error

        bind_mgettext_domain(DEFAULT_LANG_INCLUDE);

        if (!$login_msg && $username) {
            $login_msg = '<div style="color:red;" class="aa-err-login"><b>' . _m("Either your username or your password is not valid") . '</b></div>';
            $login_msg .= '<div>' . _m("Please try again!") . '</div>';
            $login_msg .= '<div>' . _m("If you are sure you have typed the correct password, please e-mail <a href=mailto:%1>%1</a>", [ERROR_REPORTING_EMAIL]) . '</div>';
        }

        require_once __DIR__ . "/loginform.inc";
    }

    /** auth_validatelogin function
     *
     * @return string|false uid
     */
    function auth_validatelogin(): string|false
    {
        $username = $_POST["username"];  // there was problem with variables
        $password = $_POST["password"];  // in cookies - if someone writes
        $code2fa  = $_POST["code2fa"];
        // to cookies username, then the
        // cookies username is used - error

        // is this necessary? Honza 2016-09-21
        // if (isset($username)){
        //     $this->auth["uname"]=$username;
        // }

        return $this->_validatelogin($username, $password, $code2fa);
    }

    protected function _validatelogin($username, $password, $code2fa=''): false|string
    {
        $this->auth['uname'] = ($uid = AA::$perm->authenticateUsername($username, $password, $code2fa)) ? $username : '';
        $this->auth['uid']   = $uid ?: '';
        AA_Log::write('LOGIN', AA_Log::context($uid), $username);
        return $uid;
    }

    public static function use2FA(): bool
    {
        $readerslices = AA_Reader::getReaderSlices();

        return DB_AA::test(['content', 'item'], [
            ['content.item_id', 'item.id', 'j'],
            ['item.slice_id', $readerslices, 'l'],
            ['content.field_id', FIELDID_2FA_SECRET],
            ['content.text', '', 'FILLED'],
        ]);
    }

    function auth_preauth(): false|string
    {
        if (isset($_POST['username']) && isset($_POST['password'])) {
            return $this->_validatelogin($_POST['username'], $_POST['password'], $_POST['code2fa']);
        }
        // JWT Bearer token authentication (used by Central for remote AA access)
        if (($auth_header = get_authorization_header()) && str_starts_with($auth_header, 'Bearer ')) {
            return $this->_validateJwt(substr($auth_header, 7));
        }
        return false;
    }

    /** Validate JWT token and set auth context
     *  JWT must be signed with this AA's AA_ID using HS256
     */
    protected function _validateJwt(string $jwt): false|string
    {
        try {
            $decoded = (array)\Firebase\JWT\JWT::decode(
                $jwt,
                new \Firebase\JWT\Key(AA_ID, 'HS256')
            );
            $this->auth['uname'] = $decoded['name'] ?? '';
            $this->auth['uid']   = $decoded['sub'] ?? '';
            return $decoded['sub'] ?: false;
        } catch (\Exception $e) {
            return false;
        }
    }
}

