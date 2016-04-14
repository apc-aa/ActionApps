<?php
/**
 * Copyright (c) 1998-2000 NetUSE AG
 *                    Boris Erdmann, Kristian Koehntopp
 *
 * Copyright (c) 1998-2000 Sascha Schumann <sascha@schumann.cx>
 *
 * $Id$
 *
 * PHPLIB Data Storage Container using a SQL database
 */

// Based on ct_sql.inc,v 1.7 2006/03/14 22:16:18 richardarcher

class CT_Sql {
  //
  // Define these parameters by overwriting or by
  // deriving your own class from it (recommened)
  //

  var $database_table          = "active_sessions";
  var $database_class          = "DB_Sql";
  var $database_lock_semaphore = "";
  var $encoding_mode           = "base64";

  // end of configuration

  var $db;

  function ac_start() {
    $name = $this->database_class;
    $this->db = new $name;
  }

  function ac_get_lock() {
    if ( "" != $this->database_lock_semaphore ) {
      $query = sprintf("SELECT get_lock('%s')", $this->database_lock_semaphore);
      while ( ! $this->db->query($query)) {
        $t = 1 + time(); while ( $t > time() ) { ; }
      }
    }
  }

  function ac_release_lock() {
    if ( "" != $this->database_lock_semaphore ) {
      $query = sprintf("SELECT release_lock('%s')", $this->database_lock_semaphore);
      $this->db->query($query);
    }
  }

  function ac_gc($gc_time, $name) {
    $timeout = time();
    $sqldate = date("YmdHis", $timeout - ($gc_time * 60));
    $this->db->query(sprintf("DELETE FROM %s WHERE changed < '%s' AND name = '%s'",
                    $this->database_table,
                    $sqldate,
                    addslashes($name)));
    }

  function ac_store($id, $name, $str) {
    $ret = true;

    switch ( $this->encoding_mode ) {
      case "slashes":
        $str = addslashes($name . ":" . $str);
      break;

      case "base64":
      default:
        $str = base64_encode($name . ":" . $str);
    };

    $name = addslashes($name);

    // update duration of visit
    $now = date("YmdHis", time());
    $uquery = sprintf("update %s set val='%s', changed='%s' where sid='%s' and name='%s'",
      $this->database_table,
      $str,
      $now,
      $id,
      $name);
    $squery = sprintf("select count(sid) as count from %s where val='%s' and changed='%s' and sid='%s' and name='%s'",
      $this->database_table,
      $str,
      $now,
      $id,
      $name);
    $iquery = sprintf("insert into %s ( sid, name, val, changed ) values ('%s', '%s', '%s', '%s')",
      $this->database_table,
      $id,
      $name,
      $str,
      $now);

    $this->db->query($uquery);

    // FIRST test to see if any rows were affected.
    //   Zero rows affected could mean either there were no matching rows
    //   whatsoever, OR that the update statement did match a row but made
    //   no changes to the table data (i.e. UPDATE tbl SET col = 'x', when
    //   "col" is _already_ set to 'x') so then,
    // SECOND, query(SELECT...) on the sid to determine if the row is in
    //   fact there,
    // THIRD, verify that there is at least one row present, and if there
    //   is not, then
    // FOURTH, insert the row as we've determined that it does not exist.

    if ( $this->db->affected_rows() == 0
        && $this->db->query($squery)
    && $this->db->next_record() && $this->db->f("count") == 0
        && !$this->db->query($iquery)) {

        $ret = false;
    }
    return $ret;
  }

  function ac_delete($id, $name) {
    $this->db->query(sprintf("delete from %s where name = '%s' and sid = '%s'",
      $this->database_table,
      addslashes($name),
      $id));
  }

  function ac_get_value($id, $name) {
    $this->db->query(sprintf("select val from %s where sid  = '%s' and name = '%s'",
      $this->database_table,
      $id,
      addslashes($name)));
    if ($this->db->next_record()) {
      $str  = $this->db->f("val");
      $str2 = base64_decode( $str );

      if ( preg_match("/^".$name.":.*/", $str2) ) {
         $str = preg_replace("/^".$name.":/", "", $str2 );
      } else {

        $str3 = stripslashes( $str );

        if ( preg_match("/^".$name.":.*/", $str3) ) {
          $str = preg_replace("/^".$name.":/", "", $str3 );
        } else {

          switch ( $this->encoding_mode ) {
            case "slashes":
              $str = stripslashes($str);
            break;

            case "base64":
            default:
              $str = base64_decode($str);
          }
        }
      };
      return $str;
    };
    return "";
  }

  function ac_newid() {
      return md5(uniqid('',true));
  }

  function ac_halt($s) {
      $this->db->halt($s);
  }

  /** begin transaction */
  function start_transaction() {
      $this->db->query('START TRANSACTION;');
  }

  /** commit transaction */
  function commit() {
      $this->db->query('COMMIT;');
  }

  /** rollback transaction */
  function rollback() {
      $this->db->query('ROLLBACK;');
  }
}


/**
 * $Id$
 */
/**
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

    /**
    * Session name
    *
    */
    var $classname = "Session";

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
        $var_name = trim($var_name);  // to be sure
        return isset($_SESSION[$var_name]);
    }



    /**
    * Recall the session registration for named variable(s)
    *
    * @param	  mixed   String with the name of one or more variables seperated by comma
    *                   or a list of variables names: "foo"/"foo,bar,baz"/{"foo","bar","baz"}
    * @access public
    */
    function unregister($var_names) {
        $ok = true;
        foreach (explode (',', $var_names) as $var_name) {
            $var_name=trim($var_name);
            unset($_SESSION[$var_name]);  // unset is no more a function in php4
        }
        return $ok;
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
    * Stores session id in a hidden variable (part of a form).
    *
    * @return string
    * @access public
    */
    function get_hidden_session() {
        return "";
    }

    /**
    * @brother  get_hidden_session
    * @return   void
    */
    function hidden_session() {
        print $this->get_hidden_session();
    } // end func hidden_session



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
    * Get the serialized string of session variables
    *
    * Note that the serialization format is different from what it
    * was in session3.inc. So clear all session data when switching
    * to the PHP 4 code, it's not possible to load old session.
    *
    * @return string
    */
    function serialize() {
        return session_encode();
    } // end func serialze


    /**
    * Import (session) variables from a string
    *
    * @param  string
    *
    * @return boolean
    */
    function deserialize (&$data_string) {
        return session_decode($data_string);
    } // end func deserialize

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
        reset($_SESSION);

        while(list($key,) = each($_SESSION)) {
            // foreach ($_SESSION as $key => $value) {
            $_SESSION[$key] = $GLOBALS[$key];
        }
    }

    /**
    * ?
    *
    */
    function set_tokenname(){

        $this->name = ("" == $this->cookiename) ? $this->classname : $this->cookiename;
        session_name ($this->name);

        if (!$this->cookie_domain) {
            $this->cookie_domain = get_cfg_var ("session.cookie_domain");
        }

        if (!$this->cookie_path && get_cfg_var('session.cookie_path')) {
            $this->cookie_path = get_cfg_var('session.cookie_path');
        } elseif (!$this->cookie_path) {
            $this->cookie_path = "/";
        }

        if ($this->lifetime > 0) {
            //$lifetime = time()+$this->lifetime*60; //this is incorrect
            $lifetime = $this->lifetime*60;
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

        case "passive":
        case "public":
            session_cache_limiter ("public");
            break;

        case "private":
            session_cache_limiter ("private");
            break;

        default:
            session_cache_limiter ("nocache");
            break;
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
  var $persistent_slots = array("auth");

  var $lifetime = 15;             // Max allowed idle time before
                                  // reauthentication is necessary.
                                  // If set to 0, auth never expires.

  var $refresh = 0;               // Refresh interval in minutes.
                                  // When expires auth data is refreshed
                                  // from db using auth_refreshlogin()
                                  // method. Set to 0 to disable refresh

  //  var $mode = "log";              // "log" for login only systems,
                                      // "reg" for user self registration

  var $magic = "";                // Used in uniqid() generation

  var $nobody = false;            // If true, a default auth is created...

  var $cancel_login = "cancel_login"; // The name of a button that can be
                                      // used to cancel a login form

  // End of user qualifiable settings.

  var $auth = array();            // Data array
  var $in   = false;

  //
  // Initialization
  //
  function start() {
    global $sess;

    // This is for performance, I guess but I'm not sure if it could
    // be safely removed -- negro
    if (! $this->in) {
      $sess->register("auth");
      $this->in = true;
    }

    // Check current auth state. Should be one of
    //  1) Not logged in (no valid auth info or auth expired)
    //  2) Logged in (valid auth info)
    //  3) Login in progress (if $this->cancel_login, revert to state 1)
    if ($this->is_authenticated()) {
      $uid = $this->auth["uid"];
      switch ($uid) {
        case "form":
          // Login in progress
          if ((isset($_POST[$this->cancel_login]) && $_POST[$this->cancel_login]) or
              (isset($_GET[$this->cancel_login]) && $_GET[$this->cancel_login])) {
            // If $this->cancel_login is set, delete all auth info and set
            // state to "Not logged in", so eventually default or automatic
            // authentication may take place
            $this->unauth();
            $state = 1;
          } else {
            // Set state to "Login in progress"
            $state = 3;
          }
          break;
        default:
          // User is authenticated and auth not expired
          $state = 2;
          break;
      }
    } else {
      // User is not (yet) authenticated
      $this->unauth();
      $state = 1;
    }

    switch ($state) {
      case 1:
        // No valid auth info or auth is expired

        // Check for user supplied automatic login procedure
        if ( $uid = $this->auth_preauth() ) {
          $this->auth["uid"] = $uid;
          $this->auth["exp"] = time() + (60 * $this->lifetime);
          $this->auth["refresh"] = time() + (60 * $this->refresh);
          return true;
        }

        if ($this->nobody) {
          // Authenticate as nobody
          $this->auth["uid"] = "nobody";
          // $this->auth["uname"] = "nobody";
          $this->auth["exp"] = 0x7fffffff;
          $this->auth["refresh"] = 0x7fffffff;
          return true;
        } else {
          // Show the login form
          $this->auth_loginform();
          $this->auth["uid"] = "form";
          $this->auth["exp"] = 0x7fffffff;
          $this->auth["refresh"] = 0x7fffffff;
          $sess->freeze();
          exit;
        }
        break;
      case 2:
        // Valid auth info
        // Refresh expire info
        // DEFAUTH handling: do not update exp for nobody.
        if ($uid != "nobody") {
          $this->auth["exp"] = time() + (60 * $this->lifetime);
        }
        break;
      case 3:
        // Login in progress, check results and act accordingly
        if ( $uid = $this->auth_validatelogin() ) {
          $this->auth["uid"] = $uid;
          $this->auth["exp"] = time() + (60 * $this->lifetime);
          $this->auth["refresh"] = time() + (60 * $this->refresh);
          return true;
        } else {
            if ($this->nobody) {
                $this-> unauth();
                // Authenticate as nobody
                $this->auth["uid"] = "nobody";
                $this->auth["exp"] = 0x7fffffff;
                $this->auth["refresh"] = 0x7fffffff;
                return true;
            } else {
                $this->auth_loginform();
                $this->auth["uid"] = "form";
                $this->auth["exp"] = 0x7fffffff;
                $this->auth["refresh"] = 0x7fffffff;
                $sess->freeze();
                exit;
            }
        }
        break;
      default:
        // This should never happen. Complain.
        echo "Error in auth handling: invalid state reached.\n";
        $sess->freeze();
        exit;
        break;
    }
  }

  function login_if( $t ) {
    if ( $t ) {
      $this->unauth();       // We have to relogin, so clear current auth info
      $this->nobody = false; // We are forcing login, so default auth is
                             // disabled
      $this->start();        // Call authentication code
    }
  }

  /** auth4 */
  // function __sleep () {
  //   $this->persistent_slots[]="classname";
  //   return $this->persistent_slots;
  //  }


  function unauth($nobody = false) {
    $this->auth["uid"]   = "";
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


  function logout($nobody = "") {
    global $sess;

    $sess->unregister("auth");
    unset($this->auth["uname"]);
    $this->unauth($nobody == "" ? $this->nobody : $nobody);
  }

  function is_authenticated() {
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

  function auth_loginform() { ; }

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

