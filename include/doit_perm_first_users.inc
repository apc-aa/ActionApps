<?php
/**
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/
// demo

  $array['uid']            = 'demo';
  $array['mail']           = 'demo@igc.org';
  $array["givenname"]      = "NewsFlash";
  $array["sn"]             = "Demo";
  $array["userpassword"]   = 'demo';

  $user_id = AddUser($array);

// staff-only admin

  $array['uid']          = 'igc-admin';
  $array['mail']         = 'igc-admin@igc.org';
  $array["givenname"]    = "Admin";
  $array["sn"]           = "IGC Staff";
  $array["userpassword"] = 'demo!';

  $user_id = AddUser($array);

// staff-only author

  $array['uid']          = 'igc-staff';
  $array['mail']         = 'igc-staff@igc.org';
  $array["givenname"]    = "Authors";
  $array["sn"]           = "IGC Staff";
  $array["userpassword"] = 'demo!';

  $user_id = AddUser($array);

// corpwatch

  $corpwatch = array( 'mail'          => 'corpwatch@igc.org',
                      'uid'           => 'corpwatcho',
                      'givenname'     => "Tristi",
                      'sn'            => "Tanaka",
                      'userpassword'  => "all2good");

  $user_id = AddUser($corpwatch);

// iearn

  $iearn     = array( 'mail'          => 'iearn@igc.org',
                      'uid'           => 'iearno',
                      'givenname'     => "Ed",
                      'sn'            => "Gragert",
                      'userpassword'  => "even!bet");

  $user_id = AddUser($iearn);

   $corpwatch = array( 'mail'          => 'corpwatch@corpwatch.org',
                       'uid'           => 'corpwatch',
                       'givenname'     => "Tristi",
                       'sn'            => "Tanaka",
                       'userpassword'  => "all2good");

   echo  AddUser($corpwatch);
   echo "\n";

   $iearn = array(     'mail'          => 'ed1@us.iearn.org',
                       'uid'           => 'iearn',
                       'givenname'     => "Ed",
                       'sn'            => "Gragert",
                       'userpassword'  => "way2good");

   echo  AddUser($iearn);

?>
