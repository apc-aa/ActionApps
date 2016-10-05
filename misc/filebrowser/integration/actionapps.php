<?php

/** This file is part of KCFinder project
  *
  *      @desc CMS integration code: Drupal
  *   @package KCFinder
  *   @version 3.12
  *    @author Dany Alejandro Cabrera <otello2040@gmail.com>
  * @copyright 2010-2014 KCFinder Project
  *   @license http://opensource.org/licenses/GPL-3.0 GPLv3
  *   @license http://opensource.org/licenses/LGPL-3.0 LGPLv3
  *      @link http://kcfinder.sunhater.com
  */


require_once "../../include/config.php3";
require_once AA_INC_PATH. "locsess.php3";
require_once AA_INC_PATH. "locauth.php3";


function CheckAuthentication() {
    global $sess;
    pageOpen();


              //  if (!isset($_SESSION['KCFINDER'])) {
              //      $_SESSION['KCFINDER'] = array();
              //      $_SESSION['KCFINDER']['disabled'] = false;
              //  }
              //
              //  // User has permission, so make sure KCFinder is not disabled!
              //  if(!isset($_SESSION['KCFINDER']['disabled'])) {
              //      $_SESSION['KCFINDER']['disabled'] = false;
              //  }
              //
              //  global $user;
              //  $_SESSION['KCFINDER']['uploadURL'] = 'sites/default/files/kcfinder';
              //  $_SESSION['KCFINDER']['uploadDir'] = 'honza/'.$sess->get_id();
              //  //$_SESSION['KCFINDER']['theme'] = variable_get('kcfinder_theme', 'default');
              //
              //  print_r($sess->id);
              //  print_r($sess->get_id());
          //      print_r($sess);
          //      print_r('-----');
          //      print_r(AA::$module_id);
          //      print_r('-----');
          //      print_r($module_id);
          //      print_r('-----');
          //      print_r($slice_id);
          //      print_r('-----');
          //      print_r($_COOKIES);
          //      print_r('-----');
          //      print_r($_REQUEST);
          //      print_r('-----');
          //      print_r($r_state);
          //      print_r('-----');
          //      print_r($_SESSION['r_state']['module_id']);
          //      print_r('-----');
          //      print_r($_SESSION);
          //      print_r('-----');
          //    //  print_r('-----');
          //     print_r($GLOBALS['module_id']);
              //  print_r('-----');
              //  print_r($GLOBALS['slice_id']);
              //  print_r('-----');
              //  print_r($_SESSION);
              //  print_r('-----');
              //
              //
              //  echo '<br />uploadURL: ' . $_SESSION['KCFINDER']['uploadURL']. '<br />';
              //  echo '<br />uploadDir: ' . $_SESSION['KCFINDER']['uploadDir']. '<br />';

              $_SESSION['KCFINDER']['uploadURL'] = IMG_UPLOAD_URL. $_SESSION['r_last_module_id'];
              //$_SESSION['KCFINDER']['uploadURL'] = $_SESSION['r_last_module_id'];
              $_SESSION['KCFINDER']['uploadDir'] = IMG_UPLOAD_PATH. $_SESSION['r_last_module_id'];



                //chdir($current_cwd);

                return true;
}

CheckAuthentication();
