<?php

//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/** Authenticate to the second domain
 *
 * This is the blank pixel script, which can be used for authentication
 * to another domain if you are already logged in one - just include folowing
 * HTML code to sitemodule of example-one.org:
 *   <img src="//www.example-two.org/apc-aa/central/setcookie.php?s={cookie:AA_Session}">
 * This will set the auth cookie in example-two.org.
 *
 * Security (session fixation): the cookie is set ONLY when ?s= names an
 * existing, authenticated session in this install's local session store.
 * Otherwise an attacker could fix an arbitrary session id on a visitor and,
 * after the visitor logs in, inherit the session. The target session is
 * probed read-only (no cookie emitted); use_strict_mode makes PHP mint a
 * fresh id for an unknown $sid, so session_id() === $sid proves it existed.
 * Auth state is stored in the session as the serialized AA_Auth object
 * ($_SESSION['auth']), so we load AA's classes to read it via getUid() —
 * authenticated = uid set and not a sentinel ('form' = login in progress,
 * 'nobody' = anonymous). This path touches no DB (AA_Auth::$refresh = 0).
 * See SECURITY-AUDIT-FINDINGS.md B2.
 */
require_once __DIR__ . "/../vendor/autoload.php";   // classmap: AA_Auth, AA\* for unserialize
require_once __DIR__ . "/../include/config.php";    // PERM_LIB / AA_LOGIN_TIMEOUT / DB consts
require_once __DIR__ . "/../include/locauth.php";   // defines Auth / AA_Auth (no DB connect here)

$sid = (string) ($_GET['s'] ?? '');

// 1) format gate — PHP session id charset/length
if (preg_match('/^[A-Za-z0-9,-]{22,256}$/', $sid)) {
    // 2) probe the target session read-only, without emitting any cookie
    session_name('AA_Session');
    session_id($sid);
    session_start(['use_cookies' => 0, 'use_strict_mode' => 1, 'read_and_close' => true]);

    // 3) only adopt a genuinely logged-in session. We intentionally do not
    //    gate on session expiry here: freshness is re-validated authoritatively
    //    by the receiving domain's is_authenticated() on the next page load.
    $auth = $_SESSION['auth'] ?? null;
    $uid  = ($auth instanceof AA_Auth) ? $auth->getUid() : '';
    if (session_id() === $sid
        && $uid !== ''
        && $uid !== 'form'
        && $uid !== 'nobody'
    ) {
        // 4) set cookie with the same flags as Session::cookie_setup()
        setcookie('AA_Session', $sid, [
            'expires'  => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => isset($_SERVER['HTTPS']),
            'samesite' => 'Lax',
        ]);
    }
}

header('Content-Type: image/gif');
//pixel blank
echo "\x47\x49\x46\x38\x37\x61\x1\x0\x1\x0\x80\x0\x0\xfc\x6a\x6c\x0\x0\x0\x2c\x0\x0\x0\x0\x1\x0\x1\x0\x0\x2\x2\x44\x1\x0\x3b";
