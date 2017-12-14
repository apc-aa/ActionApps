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
class Session {

    /** Session name */
    var $classname;

    /**
    * Current session id.
    *
    * @var  string
    * @see  id(), Session()
    */
    var $id = "";

    /**
    * [Current] Session name.
    *
    * @var  string
    * @see  name(), Session()
    */
    var $name = "";

    /**
    *
    * @var  string
    */
    var $cookie_path = '/';


    /**
    *
    * @var  strings
    */
    var $cookiename = "";


    /**
    *
    * @var  int
    */
    var $lifetime = 0;


    /**
    * If set, the domain for which the session cookie is set.
    *
    * @var  string
    */
    var $cookie_domain = '';


    /**
    * Propagation mode is by default set to cookie
    * The other parameter, fallback_mode, decides wether
    * we accept ONLY cookies, or cookies and eventually get params
    * in php4 parlance, these variables cause a setting of either
    * the php.ini directive session.use_cookie or session.use_only_cookie
    * The session.use_only_cookie possibility was introdiced in PHP 4.2.2, and
    * has no effect on previous versions
    *
    * @var    string
    * @deprec $Id$
    */
    var $mode = "cookie";               // We propagate session IDs with cookies

    /**
    * If fallback_mode is set to 'cookie', php4 will impose a cookie-only
    * propagation policy, which is a safer  propagation method that get mode
    *
    * @var    string
    * @deprec $Id$
    */
    var $fallback_mode;                 // if fallback_mode is also 'cookie'
                                        // we enforce session.use_only_cookie

    /**
    * See the session_cache_limit() options
    *
    * @var  string
    */
    var $allowcache = 'nocache';

    /**
    * Do we need session forgery check?
    * This check prevents from exploiting SID-in-request vulnerability.
    * We check the user's last IP, and start a new session if the user
    * has no cookie with the SID, and the IP has changed during the session.
    * We also start a new session with the new id, if the session does not exists yet.
    * We don't check cookie-enabled clients.
    * @var boolean
    */
    var $forgery_check_enabled = false;

    /**
    * the name of the variable to hold the IP of the session
    * @see $forgery_check_enabled
    * @var string
    */
    var $session_ip = '__session_ip';


    /**
    * Sets the session name before the session starts.
    *
    * Make sure that all derived classes call the constructor
    *
    * @see  name()
    */
    function __construct() {
        $this->name($this->name);
        $this->classname = get_class($this);
    } // end constructor

    /**
    * Register the variable(s) that should become persistent.
    *
    * @param   mixed String with the name of one or more variables seperated by comma
    *                 or a list of variables names: "foo"/"foo,bar,baz"/{"foo","bar","baz"}
    * @access public
    */
    function register($var_names) {
        if (!is_array($var_names)) {
            // spaces spoil everything
            $var_names = trim($var_names);
            $var_names=explode(",", $var_names);
        }

        // If register_globals is off -> store session variables values
        foreach ($var_names as $key => $value ) {
            if (!isset($_SESSION[$value])) {
                $_SESSION[$value]= $GLOBALS[$value];
            }
        }
    }

    /**
    * see if a variable is registered in the current session
    *
    * @param  $var_name a string with the variable name
    * @return false if variable not registered true on success.
    * @access public
    */
    function is_registered($var_name) {
        return isset($_SESSION[trim($var_name)]);
    }



    /**
    * Recall the session registration for named variable(s)
    *
    * @param	  mixed   String with the name of one or more variables seperated by comma
    *                   or a list of variables names: "foo"/"foo,bar,baz"/{"foo","bar","baz"}
    * @access public
    */
    function unregister($var_names) {
        foreach (explode (',', $var_names) as $var_name) {
            $var_name=trim($var_name);
            unset($_SESSION[$var_name]);  // unset is no more a function in php4
        }
        return true;
    }

    /**
    * @brother id()
    * @deprec  $Id$
    * @access public
    */
    function get_id($sid = '') {
        return $this->id($sid);
    } // end func get_id






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
    * @access public
    */
    function put_id() {
        if (get_cfg_var('session.use_cookies') == 1) {
            $cookie_params = session_get_cookie_params();
            setCookie($this->name, '', 0, $cookie_params['path'], $cookie_params['domain']);
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
    function delete() {
        $this->put_id();
        return session_destroy();
    } // end func delete


    /**
    * Helper function: returns $url concatenated with the current session id
    *
    * Don't use this function any more. Please use the PHP 4 build in
    * URL rewriting feature. This function is here only for compatibility reasons.
    *
    * @param	$url	  URL to which the session id will be appended
    * @return string  rewritten url with session id included
    * @deprec $Id$
    * @access public
    */
    function url($url) {
        return $url;
    } // end func url

    /**
    * Get current request URL.
    *
    * WARNING: I'm not sure with the $this->url() call. Can someone check it?
    * WARNING: Apache variable $REQUEST_URI used -
    * this it the best you can get but there's warranty the it's set beside
    * the Apache world.
    *
    * @return string
    * @global $REQUEST_URI
    * @access public
    */
    function self_url() {
      if ($_SERVER['REQUEST_URI'] AND strpos($_SERVER['REQUEST_URI'],'?')) {
          $qs = substr($_SERVER['REQUEST_URI'],strpos($_SERVER['REQUEST_URI'], '?'));
      } else {
          $qs = (isset($_SERVER["QUERY_STRING"]) AND ("" != $_SERVER["QUERY_STRING"])) ? '?' . $_SERVER["QUERY_STRING"] : '';
      }
      return $this->url($_SERVER["PHP_SELF"] . $qs);
    }

    /**
    * Sets or returns the name of the current session
    *
    * @param  string  If given, sets the session name
    * @return string  session_name() return value
    * @access public
    */
    function name($name = '') {

        if ($name = (string)$name) {
            $this->name = $name;
            $ok = session_name($name);
        } else {
            $ok = session_name();
        }
        return $ok;
    } // end func name


    /**
    * Returns the session id for the current session.
    *
    * If id is specified, it will replace the current session id.
    *
    * @param  string  If given, sets the new session id
    * @return string  current session id
    * @access public
    */
    function id($sid = '') {
        if (!$sid) {
            $sid = ("" == $this->cookiename) ? $this->classname : $this->cookiename;
        }

        if ($sid = (string)$sid) {
            $this->id = $sid;
            $ok = session_id($sid);
        } else {
            $ok = session_id();
        }

        return $ok;
    } // end func id

    /**
    * freezes all registered things ( scalar variables, arrays, objects )
    * by saving all registered things to $_SESSION.
    *
    * @access public
    *
    *
    */
    function freeze() {
        // If register_globals is off -> store session variables values
        foreach ($_SESSION as $key => $foo) {
            $_SESSION[$key] = $GLOBALS[$key];
        }
    }

    /**
    * ?
    *
    */
    function set_tokenname(){

        $this->name = $this->cookiename  ?: $this->classname;
        session_name($this->name);

        if (!$this->cookie_domain) {
            $this->cookie_domain = get_cfg_var('session.cookie_domain');
        }

        if (!$this->cookie_path) {
            $this->cookie_path = get_cfg_var('session.cookie_path') ?: '/';
        }

        if ($this->lifetime > 0) {
            $lifetime = max($this->lifetime*60, 24*3600);               // day or more - the day added, because we experienced some relogin requests, so we are trying to check, if this doesn't help
            if (ini_get('session.gc_maxlifetime') < $lifetime) {
                // default value is 1440, so session would expire after 40 mins
                ini_set('session.gc_maxlifetime', $lifetime);
            }
        } else {
            $lifetime = 0;
        }

        session_set_cookie_params($lifetime, $this->cookie_path, $this->cookie_domain);
    } // end func set_tokenname


    /**
    * ?
    *
    */
    function put_headers() {
        // set session.cache_limiter corresponding to $this->allowcache.
        switch ($this->allowcache) {
            case 'passive':
            case 'public':   session_cache_limiter('public');  break;
            case 'private':  session_cache_limiter('private'); break;
            default:         session_cache_limiter('nocache');
        }
    } // end func put_headers

    /**
    * Start a new session or recovers from an existing session
    *
    * @return boolean   session_start() return value
    * @access public
    */
    function start() {

        if ( $this->mode=="cookie" && $this->fallback_mode=="cookie")  {
            ini_set ("session.use_only_cookies","1");
        }

        $this->set_tokenname();
        $this->put_headers();

        $ok = session_start();
        $this->id = session_id();

        if($this->forgery_check_enabled && $this->session_ip) {
            $sess_forged = false;
            $mysid = $this->name.'='.$this->id;

            // check cookies first.
            if(!isset($_COOKIE[$this->name]) &&  (strpos($_SERVER['REQUEST_URI'],$mysid) || $_POST[$this->name])) {
                if(isset($_SESSION[$this->session_ip]) && $_SESSION[$this->session_ip] <> $_SERVER['REMOTE_ADDR']) {
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
                if(strpos($_SERVER['PHP_SELF'], $mysid)) {
                    // cut session info from PHP_SELF // and QUERY_STRING, for sure
                    $new_qs = 'http://'.$_SERVER['SERVER_NAME']. str_replace($mysid, '', $_SERVER['PHP_SELF']) .(($_SERVER['QUERY_STRING']) ? '?'.str_replace($mysid, '', $_SERVER['QUERY_STRING']) : '');

                    // clear new cookie, if set
                    $cprm = session_get_cookie_params();
                    setcookie($sname, '', time() - 3600, $cprm['path'], $cprm['domain'], $cprm['secure']);
                    header('Location: '.$new_qs);
                    exit();
                }

                // maybe should seed better?
                $this->id(md5(uniqid(rand())));
                $ok = session_start();
            }
        }

        // restore session variables to global scope
        if (is_array($_SESSION)) {
            foreach ($_SESSION as $key => $value) {
                $GLOBALS[$key] = $value;
            }
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

} // end func session

/**
 * $Id$
 */

class Auth {
    var $classname = "Auth";

    var $lifetime = 200;            // Max allowed idle time before
                                    // reauthentication is necessary.
                                    // If set to 0, auth never expires.

    var $refresh = 0;               // Refresh interval in minutes.
                                    // When expires auth data is refreshed
                                    // from db using auth_refreshlogin()
                                    // method. Set to 0 to disable refresh

    //  var $mode = "log";          // "log" for login only systems,
                                    // "reg" for user self registration

    var $nobody = false;            // If true, a default auth is created...

    // var $cancel_login = "cancel_login"; // The name of a button that can be
    //                                     // used to cancel a login form
    //
    // End of user qualifiable settings.

    var $auth = array();            // Data array

    //
    // Initialization
    //
    function start() {
        global $sess;

        $sess->register("auth");

        // Check for user supplied automatic login procedure
        if ( $uid = $this->auth_preauth() ) {
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
            if ( 'form' == ($uid = $this->auth["uid"]) ) {
                // Set state to "Login in progress"
                // Login in progress, check results and act accordingly
                if ( $uid = $this->auth_validatelogin() ) {
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
            if ($uid != "nobody") {
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

  function login_if( $t ) {
    if ( $t ) {
      $this->unauth();       // We have to relogin, so clear current auth info
      $this->nobody = false; // We are forcing login, so default auth is
                             // disabled
      $this->start();        // Call authentication code
    }
  }

  function unauth($nobody = false) {
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


  function logout() {
    global $sess;

    $sess->unregister("auth");
    // unset($this->auth["uname"]);  - now in unauth
    $this->unauth($this->nobody);
  }

  function is_authenticated() {
      if ( !$this->nobody AND ($this->auth["uid"]=='nobody') ) { // nobody setting could be changed for specific page. Honza
          return false;
      }

      if ( isset($this->auth["uid"]) && $this->auth["uid"] && (($this->lifetime <= 0) || (time() < $this->auth["exp"])) ) {
          // If more than $this->refresh minutes are passed since last check,
          // perform auth data refreshing. Refresh is only done when current
          // session is valid (registered, not expired).
          if ( ($this->refresh > 0) && ($this->auth["refresh"]) && ($this->auth["refresh"] < time()) ) {
              if ( $this->auth_refreshlogin() ) {
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
  function url() {
    return $GLOBALS["sess"]->self_url();
  }

  // This method can authenticate a user before the loginform
  // is being displayed. If it does, it must set a valid uid
  // (i.e. nobody IS NOT a valid uid) just like auth_validatelogin,
  // else it shall return false.

  function auth_preauth() { return false; }

  //
  // Authentication dummies. Must be overridden by user.
  //
  function auth_loginform($msg='') { ; }
  function auth_validatelogin() { ; }
  function auth_refreshlogin() { ; }
  function auth_registerform() { ; }
  function auth_doregister() { ; }
}

/*
* $Id$
*/
function page_open($feature) {

    // enable sess and all dependent features.
    if (isset($feature["sess"])) {
        global $sess;
        $sess = new $feature["sess"];
        $sess->start();

        // the auth feature depends on sess
        if (isset($feature["auth"])) {
            global $auth;

            if (!is_object($auth)) {
                $auth = new $feature["auth"];
            }
            $auth->start();
        }
    }
}

function page_close() {
    global $sess;

    if (is_object($sess)) {
        $sess->freeze();
    }
}
?>
