<?php
/**
 * Image manipulation functions - all functions require GD Library
 *
 * Should be included to other scripts
 *
 * @version $Id$
 * @author Stanislav Kühn <kuhn@changenet.sk>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications 
*/
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
if (!defined("INCLUDE_IMAGEFUNC_INCLUDED"))
     define ("INCLUDE_IMAGEFUNC_INCLUDED",1);
else return;


####################################
# all functions require GD Library #
####################################

#function check if GD lib is installed
######################################

function GDinstalled() {
    if (!extension_loaded('gd'))
        //      if (!dl('gd.so'))
        return false;
    return true;        
}

#resample function
##################

function ResampleImage($simage,$dimage,$new_w,$new_h) {
    global $debug;

    #determine type, width, height of image.
    $imginfo=GetImageSize($simage);
    switch ($imginfo[2]) {
            case 1: $imagetype="gif"; break;
            case 2: $imagetype="jpeg"; break;
            case 3: $imagetype="png"; break;
    }
    
    # if dimensions of new picture are not set, then set default heigth
    if (!$new_w && !$new_h) $new_h=120;
    
    #set ratio of width and height
    if ($new_w!=0) $x=$imginfo[0]/$new_w;
    if ($new_h!=0) $y=$imginfo[1]/$new_h;
    
    #set for witch max dimension will be image resampled
    if ($x>$y) $new_h="";   #use max width and calculate height
    if ($y>$x) $new_w="";   #use max height and calculate width
    
    #if image is smaller then limits, do not resample
    if ($x < 1 && $y < 1) return true;
    
    #calculate second dimension of image with maintain aspect ratio 
    if ((!$new_w && $new_h) || (!$new_h && $new_w)) {
        if (!$new_w) $new_w=$imginfo[0]/$imginfo[1]*$new_h;
        if (!$new_h) $new_h=$imginfo[1]/$imginfo[0]*$new_w;
    }
    
    #debug
    //echo "height: $new_h, width: $new_w";
    
    #array of image types supported by system
    $suptypes=GetSupportedTypes();
    if ($suptypes[$imginfo[2]]) {
        if ($imginfo[2]<4 && $imginfo!=NULL) {
            $dst_img=ImageCreate($new_w,$new_h); 
            $f="ImageCreateFrom".$imagetype;
            $src_img=$f($simage);
            imageCopyResized($dst_img,$src_img,0,0,0,0,$new_w,$new_h,$imginfo[0],$imginfo[1]); 
            //Header("Content-type: image/png");
            $f="Image".$imagetype;
            //$f($src_img);//,$img
            $f($dst_img,$dimage);
        };
        return true;
    } 
    else
        //image type is not supported
        return false;
}



# function checks type of images supported by GD library
# if type is defined return true ro false, 
# if type is not defined return array of supported types
######################################################

function GetSupportedTypes($type="") { //type 1-gif, 2-jpeg, 3-png;
    if (!GDInstalled()) 
        return(false);
    
    if ($type=="") {
        if (ImageTypes() & IMG_GIF)
            $suptypes[1]=true;
        if (ImageTypes() & IMG_JPG)
            $suptypes[2]=true;
        if (ImageTypes() & IMG_PNG)
            $suptypes[3]=true;
        return $suptypes;
    } 
    else {
        switch($type) {
            case 1: if (ImageTypes() & IMG_GIF) 
                        return true;
            case 2: if (ImageTypes() & IMG_JPG) 
                        return true;
            case 3: if (ImageTypes() & IMG_PNG) 
                        return true;
        }
        return false;
    }
}
?>
