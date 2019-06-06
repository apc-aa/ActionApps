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
    //pageOpen();
    pageOpen('noauth');

    $_SESSION['KCFINDER']['uploadURL'] = IMG_UPLOAD_URL. $_SESSION['r_last_module_id'];
    $_SESSION['KCFINDER']['uploadDir'] = IMG_UPLOAD_PATH. $_SESSION['r_last_module_id'];
    return true;
}

CheckAuthentication();
