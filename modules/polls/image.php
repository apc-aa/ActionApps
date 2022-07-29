<?php
/** 
* Polls module is based on Till Gerken's phpPolls version 1.0.3. Thanks!
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
* @version   $Id$
* @author    Pavel Jisl <pavel@cetoraz.info>, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      https://www.apc.org/ APC
*
*/




$COLORNAMES = [
    "black"  => "#000000",
    "silver" => "#C0C0C0",
    "gray"   => "#808080",
    "white"  => "#FFFFFF",
    "maroon" => "#800000",
    "red"    => "#FF0000",
    "purple" => "#800080",
    "fuchsia"=> "#FF00FF",
    "green"  => "#008000",
    "lime"   => "#00FF00",
    "olive"  => "#808000",
    "yellow" => "#FFFF00",
    "navy"   => "#000080",
    "blue"   => "#0000FF",
    "teal"   => "#008080",
    "aqua"   => "#00FFFF"
];

function mkcolor($color){  
    global $COLORNAMES;
    
    if (substr($color,0,1) != "#") {
        $color = strtolower($color);
        $color = $COLORNAMES[$color];
    }
    $color = str_replace("#","",$color);
    
    $out["red"]   = hexdec(substr($color,0,2));
    $out["green"] = hexdec(substr($color,2,2));
    $out["blue"]  = hexdec(substr($color,4,2));
    
    return($out);
}

if (isset($width) && isset($height) && isset($color)){
    
    header("Content-type: image/jpeg");
    
    $image   = imagecreate($width, $height);
    $rgb     = mkcolor($color);
    $bgcolor = imagecolorallocate($image, $rgb["red"], $rgb["green"], $rgb["blue"]);
    imagefill($image, 0,0, $bgcolor);
    
    imagejpeg($image);
    imagedestroy($image);
} else { 
    echo "Ko";
}

