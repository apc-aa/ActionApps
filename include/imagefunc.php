<?php
/**
 * Image manipulation functions - all functions require_once GD Library
 *
 * Should be included to other scripts
 *
 * PHP version 7.2+
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
 * @package   ImageManager
 * @version   $Id$
 * @author    Stanislav K�hn <kuhn@changenet.sk>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/
//###################################
// all functions require_once GD Library //
//###################################


/** @todo  replace with some library - maybe https://github.com/claviska/SimpleImage */



// function checks type of images supported by GD library
// if type is defined return true ro false,
// if type is not defined return array of supported types
//#####################################################

