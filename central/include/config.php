<?php
/**
 * File contains definition of AA_Actionapps class - holding information about
 * one AA installation.
 *
 * Should be included to other scripts (as /admin/index.php3)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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


$ACTIONAPPS   = [];
$ACTIONAPPS[] = ['name'=>'Example AA Dev',  'url'=>'https://example.org/apc-aa-dev/',  'user'=>'superuser1', 'pwd'=>'superpassword1'];
$ACTIONAPPS[] = ['name'=>'Example AAA',     'url'=>'https://example.org/aa/',          'user'=>'superuser2', 'pwd'=>'superpassword2'];
$ACTIONAPPS[] = ['name'=>'Example AA test', 'url'=>'http://test.example.org/apc-aa/',  'user'=>'superuser3', 'pwd'=>'superpassword3'];


