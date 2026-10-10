<?php

/**
 * $Id$
 *
 * PHPLib Sessions using PHP 4 built-in Session Support.
 *
 * WARNING: code is untested!
 *
 * @copyright 1998,1999 NetUSE AG, Boris Erdmann, Kristian Koehntopp
 *            2000 Teodor Cimpoesu <teo@digiro.net>
 * @author    Teodor Cimpoesu <teo@digiro.net>, Ulf Wendel <uw@netuse.de>, Maxim Derkachev <kot@books.ru
 * @version   $Id$
 * @access    public
 * @package   PHPLib
*/
class Session
{
    /**
    * [Current] Session name.
    *
    * @var  string
    * @see  name(), Session()
    */
    protected $name = "";

    /**
    *
    * @var  string
    */
    protected $cookie_path = '/';


    /**
    *
    * @var  string
    */
    protected $cookiename = "";


    /**
    *
    * @var  int
    */
    protected $lifetime = 0;


    /**
    * See the session_cache_limit() options
    *
    * @var  string
    */
    protected $allowcache = 'nocache';

    /**
    * Do we need session forgery check?
    * This check prevents from exploiting SID-in-request vulnerability.
    * We check the user's last IP, and start a new session if the user
    * has no cookie with the SID, and the IP has changed during the session.
    * We also start a new session with the new id, if the session does not exists yet.
    * We don't check cookie-enabled clients.
    * @var boolean
    */
    protected $forgery_check_enabled = false;

    /**
    * the name of the variable to hold the IP of the session
    * @see $forgery_check_enabled
    * @var string
    */
    protected $session_ip = '__session_ip';

    /**
     * register_globals leftover: session variables still written as globals
     * ($r_state[...] = ...), which freeze() copies back into $_SESSION at page
     * end. Every other key belongs to $_SESSION alone. Rewrite a variable to
     * direct $_SESSION access and remove it here; empty list = freeze() can go.
     * ('auth' is an object - same handle in both arrays - listed only for fidelity.)
     */
    public const LEGACY_GLOBAL_VARS = [
        'auth', '__session_ip',
        'r_state', 'r_hidden', 'r_err', 'r_msg',                        // Manager, admin pages, links module
        'permission_uid', 'permission_to', 'r_superuser',               // perm_core.php cache
        'rusr', 'rgrp', 'r_wm_state', 'r_userstate', 'r_sr_state', 'recover',
        'r_packed_state_vars', 'slices', 'scr',                         // slice.php
    ];

    /**
     * Store domain for which the session cookie is set.
     *
     * @var  string
     */
    protected $cookie_domain = '';


    /**
    * Sets the session name before the session starts.
    *
    * Make sure that all derived classes call the constructor
    *
    * @see  name()
    */
    public function __construct()
    {
        $this->name          = $this->cookiename ?: get_class($this);
        // $this->cookie_domain = $_SERVER['SERVER_NAME']; // this does not work - it depends on server configuration so it breaks login on some sites
    }

    /**
    * Register the variable(s) that should become persistent - legacy, only for
    * names in LEGACY_GLOBAL_VARS; new code writes $_SESSION['key'] directly.
    *
    * @param   array|string $var_names  String with the name of one or more variables seperated by comma
    *                             or a list of variables names: "foo"/"foo,bar,baz"/{"foo","bar","baz"}
    * @access public
    */
    public function register($var_names): void
    {
        if (!is_array($var_names)) {
            // spaces spoil everything
            $var_names = trim($var_names);
            $var_names = explode(",", $var_names);
        }

        // If register_globals is off -> store session variables values
        foreach ($var_names as $var_name) {
            if (!in_array($var_name, self::LEGACY_GLOBAL_VARS, true)) {
                // registered but never frozen - the global would be lost at page end
                trigger_error("Session::register('$var_name') is not in Session::LEGACY_GLOBAL_VARS - write to \$_SESSION directly, or add it to the list", E_USER_WARNING);
            }
            if (!isset($_SESSION[$var_name])) {
                $_SESSION[$var_name] = $GLOBALS[$var_name];
            }
        }
    }

    /**
    * see if a variable is registered in the current session
    *
    * @param  $var_name string - variable name
    * @return false if variable not registered true on success.
    * @access public
    */
    public function is_registered($var_name): bool
    {
        return isset($_SESSION[trim($var_name)]);
    }


    /**
     * Recall the session registration for named variable(s)
     *
     * @param  string  $var_names  String with the name of one or more variables separated by comma
     *                             or a list of variables names: "foo"/"foo,bar,baz"/{"foo","bar","baz"}
     * @return bool
     */
    public function unregister($var_names): bool
    {
        foreach (explode(',', $var_names) as $var_name) {
            $var_name = trim($var_name);
            unset($_SESSION[$var_name]);  // unset is no more a function in php4
        }
        return true;
    }

    /**
    * @access public
    */
    public function id(): false|string
    {
        return session_id();
    }

    /**
    * Delete the cookie holding the session id.
    *
    * RFC: is this really needed? can we prune this function?
    * 		 the only reason to keep it is if one wants to also
    *		 unset the cookie when session_destroy()ing,which PHP
    *		 doesn't seem to do (looking @ the session.c:940)
    * uw: yes we should keep it to remain the same interface, but deprec.
    *
    * @deprec $Id$
    */
    protected function put_id(): void
    {
        if (get_cfg_var('session.use_cookies') == 1) {
            $cookie_params = session_get_cookie_params();
            setcookie($this->name, '', 0, $cookie_params['path'], $cookie_params['domain']);
            $_COOKIE[$this->name] = "";
        }

    } // end func put_id

    /**
    * Delete the current session destroying all registered data.
    *
    * Note that it does more but the PHP 4 session_destroy it also
    * throws away a cookie is there's one.
    *
    * @return boolean session_destroy return value
    * @access public
    */
    public function delete(): bool
    {
        $this->put_id();
        return session_destroy();
    } // end func delete


    /**
    * Helper function: returns $url concatenated with the current session id
    *
    * Don't use this function any more. Please use the PHP 4 build in
    * URL rewriting feature. This function is here only for compatibility reasons.
    *
    * @param	$url string URL to which the session id will be appended
    * @return string  rewritten url with session id included
    * @deprec $Id$
    * @access public
    */
    public function url($url): string
    {
        return $url;
    } // end func url

    /**
    * Get current request URL: the script plus the query string. The login form
    * posts to it.
    *
    * SCRIPT_NAME, not PHP_SELF: PHP_SELF carries PATH_INFO, so a request for
    * /admin/index.php3/admin/index.php3/ got a form posting to that very path
    * and a scanner following it grew the URL by one segment per attempt
    * (2 875 login attempts on a 255-byte URL, toolkit.ecn.cz 2026-08-06).
    *
    * WARNING: Apache variable $REQUEST_URI used -
    * this it the best you can get but there's warranty the it's set beside
    * the Apache world.
    *
    * @return string
    * @access public
    */
    public function self_url(): string
    {
        if ($_SERVER['REQUEST_URI'] && strpos($_SERVER['REQUEST_URI'], '?')) {
            $qs = substr($_SERVER['REQUEST_URI'], strpos($_SERVER['REQUEST_URI'], '?'));
        } else {
            $qs = (isset($_SERVER["QUERY_STRING"]) && "" != $_SERVER["QUERY_STRING"]) ? '?' . $_SERVER["QUERY_STRING"] : '';
        }
        return $this->url(($_SERVER['SCRIPT_NAME'] ?? '' ?: $_SERVER['PHP_SELF']) . $qs);
    }

    /**
    * Returns the name of the current session
    *
    * @return string  session_name() return value
    * @access public
    */
    public function name(): string
    {
        return $this->name;         //$ok = session_name();
    }

    /**
    * Returns the session id for the current session.
    *
    * If id is specified, it will replace the current session id.
    *
    * @param  string  $sid  If given, sets the new session id
    * @return string  current session id
    * @access public
    */
    public function set_id($sid = ''): string
    {
        if ($sid = (string) $sid) {
            return session_id($sid);
        }
        return session_id();
    } // end func id

    /**
    * Copies the legacy global-scope session variables back into $_SESSION
    * (see LEGACY_GLOBAL_VARS). Keys written straight into $_SESSION are left
    * untouched - PHP saves them itself at request end.
    */
    public function freeze(): void
    {
        foreach (self::legacyKeysInSession() as $key) {
            $_SESSION[$key] = $GLOBALS[$key] ?? null;
        }
    }

    /** LEGACY_GLOBAL_VARS present in $_SESSION. $_SESSION is not set at all when session_start()
     *  failed (headers already sent, a forged SID) - production site.php fataled on that, 2026-09-23.
     *  @return string[]
     */
    private static function legacyKeysInSession(): array
    {
        return is_array($_SESSION ?? null) ? array_intersect(self::LEGACY_GLOBAL_VARS, array_keys($_SESSION)) : [];
    }

    /** lifetime in seconds
     * @return int
     */
    public function get_sec_lifetime(): int
    {
        // day or more - the day added, because we experienced some relogin requests, so we are trying to check, if this doesn't help
        return ($this->lifetime > 0) ? max($this->lifetime * 60, 24 * 3600) : 0;
    }

    /**
    * ?
    *
    */
    protected function set_tokenname(): void
    {
        session_name($this->name);

        if (!$this->cookie_domain) {
            $this->cookie_domain = get_cfg_var('session.cookie_domain');
        }

        if (!$this->cookie_path) {
            $this->cookie_path = get_cfg_var('session.cookie_path') ?: '/';
        }

        $lifetime = $this->get_sec_lifetime();
        if ($lifetime > 0 && ini_get('session.gc_maxlifetime') < $lifetime) {
            // default value is 1440, so session would expire after 24 mins
            ini_set('session.gc_maxlifetime', $lifetime);
        }
        session_set_cookie_params($lifetime, $this->cookie_path, $this->cookie_domain);
    } // end func set_tokenname


    /**
    * How to deal with caching
    *
    */
    protected function put_headers(): void
    {
        // set session.cache_limiter corresponding to $this->allowcache.
        switch ($this->allowcache) {
            case 'passive':
            case 'public':   session_cache_limiter('public');
                break;
            case 'private':  session_cache_limiter('private');
                break;
            default:         session_cache_limiter('nocache');
        }
    } // end func put_headers


    /** get configuration array for session or cookies
     * @return array
     */
    protected function session_setup(): array
    {
        $ret = [];
        $ret['use_only_cookies'] = 1;
        $ret['use_trans_sid']    = 0;
        $ret['cookie_httponly']  = 1;
        $ret['use_strict_mode']  = 1;
        if (isset($_SERVER['HTTPS'])) {
            $ret['cookie_secure'] = 1;
        }
        $ret['cookie_samesite'] = 'Lax';   // we changed it from Strict, which do not accept links from e-mails to the site, where you are logged in (HM 2020-04-21)
        // $ret['read_and_close'] = true;  // no locking - @todo - use it when we do not store to the session - just we read
        return $ret;
    }

    protected function cookie_setup(): array
    {
        $ret = [];
        $ret['httponly']  = 1;
        if (isset($_SERVER['HTTPS'])) {
            $ret['secure'] = 1;
        }
        $ret['samesite'] = 'Lax';    // we changed it from Strict, which do not accept links from e-mails to the site, where you are logged in (HM 2020-04-21)
        return $ret;
    }

    /**
    * Start a new session or recovers from an existing session
    *
    * @return boolean   session_start() return value
    * @access public
    */
    public function start(): bool
    {

        $start_array = $this->session_setup();
        $this->set_tokenname();
        $this->put_headers();

        if ($start_array) {  // defined for PHP >= 7.0
            $ok = session_start($start_array);
            // this way we renew lifetime of the cookie on each reload; a new session got its cookie from session_start()
            if ($ok && (($_COOKIE[session_name()] ?? '') === session_id())) {
                $cookie_setup = $this->cookie_setup();
                $cookie_setup['expires'] = time() + $this->get_sec_lifetime();
                $cookie_setup['path']    = '/';
                setcookie(session_name(), session_id(), $cookie_setup);
            }
        } else {
            $ok = session_start();
        }

        if ($this->forgery_check_enabled && $this->session_ip) {
            $sess_forged = false;
            $mysid = $this->name . '=' . session_id();

            // check cookies first.
            if (!isset($_COOKIE[$this->name]) &&  (strpos($_SERVER['REQUEST_URI'], $mysid) || $_POST[$this->name])) {
                if (isset($_SESSION[$this->session_ip]) && $_SESSION[$this->session_ip] != $_SERVER['REMOTE_ADDR']) {
                    // we have no session cookie, a SID in the request,
                    // the session exists, but the saved IP is
                    $sess_forged = true;
                    session_write_close();

                } elseif (!isset($_SESSION[$this->session_ip])) {
                    // session does not exist.
                    $sess_forged = true;
                    session_destroy();
                }
            }
            if ($sess_forged) {
                /* we redirect only if SID in the path part of the URL,
                to make sure they'll never hit again.
                We don't redirect when SID is in QUERY_STRING only,
                cause it will disappear with the next request
                */
                if (strpos($_SERVER['PHP_SELF'], $mysid)) {
                    // cut session info from PHP_SELF // and QUERY_STRING, for sure
                    $new_qs = 'https://' . $_SERVER['SERVER_NAME'] . str_replace($mysid, '', $_SERVER['PHP_SELF']) . (($_SERVER['QUERY_STRING']) ? '?' . str_replace($mysid, '', $_SERVER['QUERY_STRING']) : '');

                    // clear new cookie, if set
                    $cprm = session_get_cookie_params();
                    setcookie($this->name, '', time() - 3600, $cprm['path'], $cprm['domain'], $cprm['secure']);
                    header('Location: ' . $new_qs);
                    exit();
                }

                // maybe should seed better?
                $this->set_id(md5(uniqid(rand())));
                $ok = session_start();
            }
        }

        // restore the legacy session variables to global scope (the counterpart of freeze())
        foreach (self::legacyKeysInSession() as $key) {
            $GLOBALS[$key] = $_SESSION[$key];
        }

        if ($this->forgery_check_enabled && $this->session_ip) {
            // save current IP
            $GLOBALS[$this->session_ip] = $_SERVER['REMOTE_ADDR'];
            if (!$this->is_registered($this->session_ip)) {
                $this->register($this->session_ip);
            }
        }

        return $ok;
    } // end func start

    public static function page_close(): void
    {
        global $sess;

        if (is_object($sess)) {
            $sess->freeze();
        }
    }

} // end func session

/**
 * $Id$
 */

class Auth
{
    protected $lifetime = 200;      // Max allowed idle time before
    // reauthentication is necessary.
    // If set to 0, auth never expires.

    protected $refresh = 0;         // Refresh interval in minutes.
    // When expires auth data is refreshed
    // from db using auth_refreshlogin()
    // method. Set to 0 to disable refresh

    //  var $mode = "log";          // "log" for login only systems,
    // "reg" for user self registration

    protected $nobody = false;      // If true, a default auth is created...

    // var $cancel_login = "cancel_login"; // The name of a button that can be
    //                                     // used to cancel a login form
    //
    // End of user qualifiable settings.

    protected $auth = [];            // Data array

    //
    // Initialization
    //
    public function start(): void
    {
        global $sess;

        $sess->register("auth");

        // Check for user supplied automatic login procedure
        if ($uid = $this->auth_preauth()) {
            $this->auth["uid"] = $uid;
            $this->auth["exp"] = time() + (60 * $this->lifetime);
            $this->auth["refresh"] = time() + (60 * $this->refresh);
            return;
        }


        // Check current auth state. Should be one of
        //  1) Not logged in (no valid auth info or auth expired)
        //  2) Logged in (valid auth info)
        //  3) Login in progress (if $this->cancel_login, revert to state 1)
        if ($this->is_authenticated()) {
            if ('form' === ($uid = $this->auth["uid"])) {
                // Set state to "Login in progress"
                // Login in progress, check results and act accordingly
                if ($uid = $this->auth_validatelogin()) {
                    $this->auth["uid"] = $uid;
                    $this->auth["exp"] = time() + (60 * $this->lifetime);
                    $this->auth["refresh"] = time() + (60 * $this->refresh);
                    return;
                }
                if ($this->nobody) {
                    $this->unauth();
                    // Authenticate as nobody
                    $this->auth["uid"] = "nobody";
                    $this->auth["exp"] = 0x7fffffff;
                    $this->auth["refresh"] = 0x7fffffff;
                    return;
                }
                $this->auth_loginform();
                $this->auth["uid"] = "form";
                $this->auth["exp"] = 0x7fffffff;
                $this->auth["refresh"] = 0x7fffffff;
                $sess->freeze();
                exit;
            }
            // User is authenticated and auth not expired

            // Valid auth info
            // Refresh expire info
            // DEFAUTH handling: do not update exp for nobody.
            if ($uid !== "nobody") {
                $this->auth["exp"] = time() + (60 * $this->lifetime);
            }
            return;
        }
        // User is not (yet) authenticated
        $this->unauth();

        // No valid auth info or auth is expired

        if ($this->nobody) {
            // Authenticate as nobody
            $this->auth["uid"] = "nobody";
            // $this->auth["uname"] = "nobody";
            $this->auth["exp"] = 0x7fffffff;
            $this->auth["refresh"] = 0x7fffffff;
            return;
        }
        // Show the login form
        $this->auth_loginform();
        $this->auth["uid"] = "form";
        $this->auth["exp"] = 0x7fffffff;
        $this->auth["refresh"] = 0x7fffffff;
        $sess->freeze();
        exit;
    }

    public function unauth($nobody = false): void
    {
        $this->auth["uid"]   = "";
        $this->auth["uname"] = "";
        $this->auth["perm"]  = "";
        $this->auth["exp"]   = 0;

        // Back compatibility: passing $nobody to this method is
        // deprecated
        if ($nobody) {
            $this->auth["uid"]   = "nobody";
            $this->auth["perm"]  = "";
            $this->auth["exp"]   = 0x7fffffff;
        }
    }


    public function logout(): void
    {
        global $sess;

        $sess->unregister("auth");
        // unset($this->auth["uname"]);  - now in unauth
        $this->unauth($this->nobody);
    }

    public function is_authenticated()
    {
        if (!$this->nobody && (($this->auth["uid"] ?? null) === 'nobody')) { // nobody setting could be changed for specific page. Honza
            return false;
        }

        if (isset($this->auth["uid"]) && $this->auth["uid"] && (($this->lifetime <= 0) || (time() < $this->auth["exp"]))) {
            // If more than $this->refresh minutes are passed since last check,
            // perform auth data refreshing. Refresh is only done when current
            // session is valid (registered, not expired).
            if (($this->refresh > 0) && ($this->auth["refresh"]) && ($this->auth["refresh"] < time())) {
                if ($this->auth_refreshlogin()) {
                    $this->auth["refresh"] = time() + (60 * $this->refresh);
                } else {
                    return false;
                }
            }
            return $this->auth["uid"];
        }
        return false;
    }

    ////////////////////////////////////////////////////////////////////////
    //
    // Helper functions
    //
    public function url()
    {
        return $GLOBALS["sess"]->self_url();
    }

    // This method can authenticate a user before the loginform
    // is being displayed. If it does, it must set a valid uid
    // (i.e. nobody IS NOT a valid uid) just like auth_validatelogin,
    // else it shall return false.

    public function auth_preauth(): false|string
    {
        return false;
    }

    //
    // Authentication dummies. Must be overridden by user.
    //
    public function auth_loginform($msg = '') {}

    /**
     */
    public function auth_validatelogin(): string|false
    {
        return '';
    }

    /**
     * @return bool
     */
    public function auth_refreshlogin()
    {
        return false;
    }

    public function auth_registerform() {}

    public function auth_doregister() {}
}
