<?php
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
http://www.apc.org/

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

/* Adds new user to alerts and sends him e-mail to confirm. 
   Doesn't show anything interesting.
   Global parameters:
       $email (required)
       $password
       $firstname
       $lastname
       $lang - set language
*/
        
require "./lang.php3";

$err = alerts_subscribe ($email, $password, $firstname, $lastname, $lang);
if ($err) echo $err;
else echo "OK. User subscribed.";    
?>