<?php
/*
 * Session Management for PHP3
 *
 * Copyright (c) 1998-2000 NetUSE AG
 *                    Boris Erdmann, Kristian Koehntopp
 *
 * $Id$
 *
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
