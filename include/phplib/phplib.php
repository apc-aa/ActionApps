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
    $squery = sprintf("select count(sid) count from %s where val='%s' and changed='%s' and sid='%s' and name='%s'",
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
}


/**
 * $Id$
 */

class Session {
  var $classname = "Session";         // Needed for object serialization.

  // Define the parameters of your session by either overwriting
  // these values or by subclassing session (recommended).

  var $magic = "";                    // Some string you should change.
  var $mode = "cookie";               // We propagate session IDs with cookies
  var $fallback_mode;                 // If this doesn't work, fall back...
  var $lifetime = 0;                  // 0 = do session cookies, else minutes
  var $cookiename = "";               // Defaults to classname
  var $cookie_path = "/";             // The path for which the session cookie is set.
  var $cookie_domain = "";            // If set, the domain for which the
                                      // session cookie is set.

  var $gc_time  = 1440;               // Purge all session data older than 1440 minutes.
  var $gc_probability = 1;            // Garbage collect probability in percent

  var $auto_init = "";                // Name of the autoinit-File, if any.
  var $secure_auto_init = 1;          // Set to 0 only, if all pages call
                                      // page_close() guaranteed.

  var $allowcache = "no";             // "passive", "no", "private" or "public"
  var $allowcache_expire = 1440;      // If you allowcache, data expires in this
                                      // many minutes.
  var $block_alien_sid  = false;      // do not accept IDs in URL for session creation

  var $that_class = "";               // Name of data storage container

  //
  // End of parameters.
  //

  var $name;                          // Session name
  var $id;                            // Unique Session ID
  var $newid;                         // Newly Generated ID Flag
  var $that;

  var $pt = array();                  // This Array contains the registered things
  var $in = 0;                        // Marker: Did we already include the autoinit file?

  // register($things):
  //
  // call this function to register the things that should become persistent

  function register($things) {
      $things = explode(",",$things);
      foreach ($things as $thing) {
          $thing = trim($thing);
          if ( $thing ) {
              $this->pt[$thing] = true;
          }
      }
  }

  function is_registered($name) {
      return (isset($this->pt[$name]) && $this->pt[$name] == true);
  }

  function unregister($things) {
      $things = explode(",", $things);
      foreach ($things as $thing) {
          $thing = trim($thing);
          if ($thing) {
              unset($this->pt[$thing]);
          }
      }
  }

  // get_id():
  //
  // Propagate the session id according to mode and lifetime.
  // Will create a new id if necessary.

  function get_id() {
      $this->name  = $this->cookiename=="" ? $this->classname : $this->cookiename;
      $this->newid = false;
      switch ($this->mode) {
      case "get":
          if ("" == ($id = isset($_GET[$this->name]) ? $_GET[$this->name] : "")) {
              $id = isset($_POST[$this->name]) ? $_POST[$this->name] : "";
          }
          break;
      case "cookie":
          $id = isset($_COOKIE[$this->name]) ? $_COOKIE[$this->name] : "";
          break;
      default:
          die("This has not been coded yet.");
          break;
      }

      // if not valid id, then reset it
      if ( (strlen($id) != 32) OR (strspn($id, "0123456789abcdefABCDEF") != strlen($id))) {
          $id = '';
      }

      //# do not accept user provided ids for creation
      if ($id != "" && $this->block_alien_sid) {   # somehow an id was provided by the user
          if($this->that->ac_get_value($id, $this->name) == "") {
              # no - the id doesn't exist in the database: Ignore it!
              $id = "";
          }
      }

      if ( "" == $id ) {
          $this->newid = true;
          $id          = $this->that->ac_newid();
      }

      switch ($this->mode) {
      case "cookie":
          if ( $this->newid && ( 0 == $this->lifetime ) ) {
              SetCookie($this->name, $id, 0, $this->cookie_path, $this->cookie_domain);
          }
          if ( 0 < $this->lifetime ) {
              SetCookie($this->name, $id, time()+$this->lifetime*60, $this->cookie_path, $this->cookie_domain);
          }

          // Remove session ID info from QUERY String - it is in cookie
          if ( isset($_SERVER["QUERY_STRING"]) && ("" != $_SERVER["QUERY_STRING"]) ) {
              // subst *any* preexistent sess
              $_SERVER["QUERY_STRING"] = preg_replace("/(^|&)".preg_quote(urlencode($this->name),'/')."=(.)*(&|$)/", "\\1", $_SERVER["QUERY_STRING"]);
          }
          break;
      case "get":
          #we don't trust user input; session in url doesn't
          #mean cookies are disabled
          if ($this->newid &&( 0 == $this->lifetime ))  {   // even if not a newid
              SetCookie($this->name, $id, 0, $this->cookie_path, $this->cookie_domain);
          }
          if ( 0 < $this->lifetime ) {
              SetCookie($this->name, $id, time()+$this->lifetime*60, $this->cookie_path, $this->cookie_domain);
          }

          if ( isset($_SERVER["QUERY_STRING"]) && ("" != $_SERVER["QUERY_STRING"]) ) {
              // subst *any* preexistent sess
              $_SERVER["QUERY_STRING"] = preg_replace("/(^|&)".preg_quote(urlencode($this->name),'/')."=(.)*(&|$)/", "\\1", $_SERVER["QUERY_STRING"]);
          }
          break;
      default:
          ;
          break;
      }
      $this->id = $id;
  }

  // put_id():
  //
  // Stop using the current session id (unset cookie, ...) and
  // abandon a session.
  function put_id() {
      switch ($this->mode) {
      case "cookie":
          $this->name = $this->cookiename == "" ? $this->classname : $this->cookiename;
          SetCookie($this->name, "", 0, $this->cookie_path, $this->cookie_domain);
          $_COOKIE[$this->name] = "";
          break;

      default:
          // do nothing. We don't need to die for modes other than cookie here.
          break;
      }
  }

  // delete():
  //
  // Delete the current session record and put the session id.

  function delete() {
      $this->that->ac_delete($this->id, $this->name);
      $this->put_id();
  }

  // url($url):
  //
  // Helper function: returns $url concatenated with the current
  // session $id.

  function url($url) {
    // Remove existing session info from url
    // We clean any(also bogus) sess in url
    $url = preg_replace("/([&?])".preg_quote(urlencode($this->name), '/')."=(.)*(&|$)/", "\\1", $url);

    // Remove trailing ?/& if needed
    $url = preg_replace("/[&?]+$/", "", $url);

    switch ($this->mode) {
      case "get":
          $url .= ( strpos($url, "?") !== false ?  "&" : "?" ). urlencode($this->name)."=".$this->id;
      break;
      default:
        ;
      break;
    }

    // Encode naughty characters in the URL
    $url = str_replace(array("<", ">", " ", "\"", "'"),
                       array("%3C", "%3E", "+", "%22", "%27"), $url);
    return $url;
  }

  function self_url() {
    return $this->url($_SERVER["PHP_SELF"] .
        ((isset($_SERVER["QUERY_STRING"]) && ("" != $_SERVER["QUERY_STRING"])) ? "?" . $_SERVER["QUERY_STRING"] : ""));
  }

  function get_hidden_session() {
      return sprintf("<input type=\"hidden\" name=\"%s\" value=\"%s\">\n", $this->name, $this->id);
  }

  function hidden_session() {
      print $this->get_hidden_session();
  }

  // serialize($var,&$str):
  //
  // appends a serialized representation of $$var
  // at the end of $str.
  //
  // To be able to serialize an object, the object must implement
  // a variable $classname (containing the name of the class as string)
  // and a variable $persistent_slots (containing the names of the slots
  // to be saved as an array of strings).

  function serialize($var, &$str) {
      static $t,$l,$k;

      // Determine the type of $$var
      eval("\$t = gettype(\$$var);");
      switch ( $t ) {

      case "array":
          // $$var is an array. Enumerate the elements and serialize them.
          eval("reset(\$$var); \$l = gettype(list(\$k)=each(\$$var));");
          $str .= "\$$var = array(); ";
          while ( "array" == $l ) {
              // Structural recursion
              $this->serialize($var."['".preg_replace("/([\\'])/", "\\\\1", $k)."']", $str);
              eval("\$l = gettype(list(\$k)=each(\$$var));");
          }

          break;
      case "object":
          // $$var is an object. Enumerate the slots and serialize them.
          eval("\$k = \$${var}->classname; \$l = reset(\$${var}->persistent_slots);");
          $str.="\$$var = new $k; ";
          while ( $l ) {
              // Structural recursion.
              $this->serialize($var."->".$l, $str);
              eval("\$l = next(\$${var}->persistent_slots);");
          }

          break;
      default:
          // $$var is an atom. Extract it to $l, then generate code.
          eval("\$l = \$$var;");
          $str.="\$$var = '".preg_replace("/([\\'])/", "\\\\1", $l)."'; ";
          break;
      }
  }

  function get_lock() {
      $this->that->ac_get_lock();
  }

  function release_lock() {
      $this->that->ac_release_lock();
  }

  // freeze():
  //
  // freezes all registered things ( scalar variables, arrays, objects ) into
  // a database table

  function freeze() {
      $str="";

      $this->serialize("this->in", $str);
      $this->serialize("this->pt", $str);

      reset($this->pt);
      while ( list($thing) = each($this->pt) ) {
          $thing=trim($thing);
          if ( $thing ) {
              $this->serialize("GLOBALS['".$thing."']", $str);
          }
      }

      $r = $this->that->ac_store($this->id, $this->name, $str);
      $this->release_lock();

      if (!$r) $this->that->ac_halt("Session: freeze() failed.");
  }

  // thaw:
  //
  // Reload frozen variables from the database and microwave them.

  function thaw() {
      $this->get_lock();

      $vals = $this->that->ac_get_value($this->id, $this->name);
      eval(sprintf(";%s",$vals));
  }

  //
  // All this is support infrastructure for the start() method
  //

  function set_container() {
      $name = $this->that_class;
      $this->that = new $name;
      $this->that->ac_start();
  }

  function set_tokenname() {
      $this->name = $this->cookiename=="" ? $this->classname : $this->cookiename;
  }

  function release_token() {
      # set the  mode for this run
      if ( isset($this->fallback_mode) && ("get" == $this->fallback_mode) && ("cookie" == $this->mode) && (! isset($_COOKIE[$this->name])) ) {
          $this->mode = $this->fallback_mode;
      }

      if ($this->mode=="get") {   // now it catches also when primary mode is get

          $this->get_id();
          if ($this->newid) {

              // You will need to fix suexec as well, if you
              // use Apache and CGI PHP
              $PROTOCOL = ( isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == 'on' ) ? 'https' : 'http';
              $this->freeze();
              header("Status: 302 Moved Temporarily");
              header("Location: " . $PROTOCOL . "://" . $_SERVER["HTTP_HOST"] . $this->self_url());
              exit;
          }
      }
  }

  function put_headers() {
      # Allowing a limited amount of caching, as suggested by
      # Padraic Renaghan on phplib@lists.netuse.de.
      #
      # Note that in HTTP/1.1 the Cache-Control headers override the Expires
      # headers and HTTP/1.0 ignores headers it does not recognize (e.g,
      # Cache-Control). Mulitple Cache-Control directives are split into
      # mulitple headers to better support MSIE 4.x.
      #
      # Added pre- and post-check for MSIE 5.x as suggested by R.C.Winters,
      # see http://msdn.microsoft.com/workshop/author/perf/perftips.asp#Use%20Cache-Control%20Extensions
      # for details
      switch ($this->allowcache) {

      case "passive":
          $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
          header("Last-Modified: " . $mod_gmt);
          # possibly ie5 needs the pre-check line. This needs testing.
          header("Cache-Control: post-check=0, pre-check=0");
          break;

      case "public":
          $exp_gmt = gmdate("D, d M Y H:i:s", time() + $this->allowcache_expire * 60) . " GMT";
          $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
          header("Expires: " . $exp_gmt);
          header("Last-Modified: " . $mod_gmt);
          header("Cache-Control: public");
          header("Cache-Control: max-age=" . $this->allowcache_expire * 60, false);
          break;

      case "private":
          $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
          header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
          header("Last-Modified: " . $mod_gmt);
          header("Cache-Control: private");
          header("Cache-Control: max-age=" . $this->allowcache_expire * 60, false);
          header("Cache-Control: pre-check=" . $this->allowcache_expire * 60, false);
          break;

      default:
          header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
          header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
          header("Cache-Control: no-cache");
          header("Cache-Control: post-check=0, pre-check=0", false);
          header("Pragma: no-cache");
          break;
      }
  }

  //
  // Garbage collection
  //
  // Destroy all session data older than this
  //
  function gc() {
      if ((rand()%100) < $this->gc_probability) {
          $this->that->ac_gc($this->gc_time, $this->name);
      }
  }

  //
  // Initialization
  //

  function start() {
      $this->set_container();
      $this->set_tokenname();
      $this->put_headers();
      $this->release_token();
      $this->get_id();
      $this->thaw();
      $this->gc();
  }

}

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

  var $mode = "log";              // "log" for login only systems,
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

    # Check current auth state. Should be one of
    #  1) Not logged in (no valid auth info or auth expired)
    #  2) Logged in (valid auth info)
    #  3) Login in progress (if $this->cancel_login, revert to state 1)
    if ($this->is_authenticated()) {
      $uid = $this->auth["uid"];
      switch ($uid) {
        case "form":
          # Login in progress
          if ((isset($_POST[$this->cancel_login]) && $_POST[$this->cancel_login]) or
              (isset($_GET[$this->cancel_login]) && $_GET[$this->cancel_login])) {
            # If $this->cancel_login is set, delete all auth info and set
            # state to "Not logged in", so eventually default or automatic
            # authentication may take place
            $this->unauth();
            $state = 1;
          } else {
            # Set state to "Login in progress"
            $state = 3;
          }
          break;
        default:
          # User is authenticated and auth not expired
          $state = 2;
          break;
      }
    } else {
      # User is not (yet) authenticated
      $this->unauth();
      $state = 1;
    }

    switch ($state) {
      case 1:
        # No valid auth info or auth is expired

        # Check for user supplied automatic login procedure
        if ( $uid = $this->auth_preauth() ) {
          $this->auth["uid"] = $uid;
          $this->auth["exp"] = time() + (60 * $this->lifetime);
          $this->auth["refresh"] = time() + (60 * $this->refresh);
          return true;
        }

        # Check for "log" vs. "reg" mode
        switch ($this->mode) {
          case "yes":
          case "log":
            if ($this->nobody) {
              # Authenticate as nobody
              $this->auth["uid"] = "nobody";
              # $this->auth["uname"] = "nobody";
              $this->auth["exp"] = 0x7fffffff;
              $this->auth["refresh"] = 0x7fffffff;
              return true;
            } else {
              # Show the login form
              $this->auth_loginform();
              $this->auth["uid"] = "form";
              $this->auth["exp"] = 0x7fffffff;
              $this->auth["refresh"] = 0x7fffffff;
              $sess->freeze();
              exit;
            }
            break;
          case "reg":
           if ($this->nobody) {
              # Authenticate as nobody
              $this->auth["uid"] = "nobody";
              # $this->auth["uname"] = "nobody";
              $this->auth["exp"] = 0x7fffffff;
              $this->auth["refresh"] = 0x7fffffff;
              return true;
            } else {
            # Show the registration form
              $this->auth_registerform();
              $this->auth["uid"] = "form";
              $this->auth["exp"] = 0x7fffffff;
              $this->auth["refresh"] = 0x7fffffff;
              $sess->freeze();
              exit;
            }
            break;
          default:
            # This should never happen. Complain.
            echo "Error in auth handling: no valid mode specified.\n";
            $sess->freeze();
            exit;
        }
        break;
      case 2:
        # Valid auth info
        # Refresh expire info
        // DEFAUTH handling: do not update exp for nobody.
        if ($uid != "nobody")
          $this->auth["exp"] = time() + (60 * $this->lifetime);
        break;
      case 3:
        # Login in progress, check results and act accordingly
        switch ($this->mode) {
          case "yes":
          case "log":
            if ( $uid = $this->auth_validatelogin() ) {
              $this->auth["uid"] = $uid;
              $this->auth["exp"] = time() + (60 * $this->lifetime);
              $this->auth["refresh"] = time() + (60 * $this->refresh);
              return true;
            } else {
              $this->auth_loginform();
              $this->auth["uid"] = "form";
              $this->auth["exp"] = 0x7fffffff;
              $this->auth["refresh"] = 0x7fffffff;
              $sess->freeze();
              exit;
            }
            break;
          case "reg":
            if ($uid = $this->auth_doregister()) {
              $this->auth["uid"] = $uid;
              $this->auth["exp"] = time() + (60 * $this->lifetime);
              $this->auth["refresh"] = time() + (60 * $this->refresh);
              return true;
            } else {
              $this->auth_registerform();
              $this->auth["uid"] = "form";
              $this->auth["exp"] = 0x7fffffff;
              $this->auth["refresh"] = 0x7fffffff;
              $sess->freeze();
              exit;
            }
            break;
          default:
            # This should never happen. Complain.
            echo "Error in auth handling: no valid mode specified.\n";
            $sess->freeze();
            exit;
            break;
        }
        break;
      default:
        # This should never happen. Complain.
        echo "Error in auth handling: invalid state reached.\n";
        $sess->freeze();
        exit;
        break;
    }
  }

  function login_if( $t ) {
    if ( $t ) {
      $this->unauth();  # We have to relogin, so clear current auth info
      $this->nobody = false; # We are forcing login, so default auth is
                             # disabled
      $this->start(); # Call authentication code
    }
  }

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

     # enable sess and all dependent features.
     if (isset($feature["sess"])) {
         global $sess;
         $sess = new $feature["sess"];
         $sess->start();

         # the auth feature depends on sess
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

 function sess_load($session) {
     foreach ($session as $k => $v) {
         $GLOBALS[$k] = new $v;
         $GLOBALS[$k]->start();
     }
 }

 function sess_save($session) {
     foreach ($session as $v) {
         $GLOBALS[$v]->freeze();
     }
 }
?>
