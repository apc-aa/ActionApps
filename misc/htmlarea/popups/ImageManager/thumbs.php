<?php 
/***********************************************************************
** Title.........:	Thumbnail generator, with cache.
** Version.......:	1.0
** Author........:	Xiang Wei ZHUO <wei@zhuo.org>
** Filename......:	thumbs.php
** Last changed..:	1 Mar 2003 
** Notes.........:	Configuration in config.inc.php

                    - if the thumbnail does not exists or the source
					  file is newer, create a new thumbnail.

***********************************************************************/
/* changed for APC-AA by pavelji@ecn.cz */
$directory_depth = "../../../";
include $directory_depth."../include/init_page.php3";     # This pays attention to $change_id

include 'config.inc.php';
require_once '../ImageEditor/Transform.php';

$img = $BASE_DIR.urldecode($_GET['img']);

if(is_file($img)) {
	make_thumbs(urldecode($_GET['img']), $regenerate);
}

/* changed for APC-AA by pavelji@ecn.cz 

  new function for sending thumbnails. it was changed beacuse of browsers
  cache thumbnails... */ 
function send_image($img) {
    global $BASE_DIR;
    // Date in the past
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
    // always modified
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    // HTTP/1.1
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Cache-Control: post-check=0, pre-check=0", false);
    // HTTP/1.0
    header("Pragma: no-cache");
	$path_info = pathinfo($img);
	$path = $path_info['dirname']."/";
	$img_file = $path_info['basename'];
	$thumb = $path.'.'.$img_file;
    $filesize = filesize($BASE_DIR.$thumb);
    header("Content-type: image/".$path_info["extension"]);
    header("Content-Length: $filesize");
    
    $fp = fopen($BASE_DIR.$thumb, 'rb');
    $buffer = fread($fp, $filesize);
    fclose ($fp);
    print $buffer;
}

function make_thumbs($img, $regenerate=false) 
{
	global $BASE_DIR, $BASE_URL;

	$path_info = pathinfo($img);
	$path = $path_info['dirname']."/";
	$img_file = $path_info['basename'];

	$thumb = $path.'.'.$img_file;

	$img_info = getimagesize($BASE_DIR.$path.$img_file);
	$w = $img_info[0]; $h = $img_info[1];
	
	$nw = 96; $nh = 96;

	if($w <= $nw && $h <= $nh) {
		//header('Location: '.$BASE_URL.$path.$img_file);
        send_image($img);
		exit();		
	}

	if(is_file($BASE_DIR.$thumb) && ($regenerate == false)) {

		$t_mtime = filemtime($BASE_DIR.$thumb);
		$o_mtime = filemtime($BASE_DIR.$img);

		if($t_mtime > $o_mtime) {
			//header('Location: '.$BASE_URL.$path.'.'.$img_file);
            send_image($img);
			exit();		
		}
	}

	$img_thumbs = Image_Transform::factory(IMAGE_CLASS);
	$img_thumbs->load($BASE_DIR.$path.$img_file);


	if ($w > $h) 
         $nh = unpercent(percent($nw, $w), $h);          
    else if ($h > $w) 
         $nw = unpercent(percent($nh, $h), $w); 

	$img_thumbs->resize($nw, $nh);

	$img_thumbs->save($BASE_DIR.$thumb);
	$img_thumbs->free();

	chmod($BASE_DIR.$thumb, 0666);

	if(is_file($BASE_DIR.$thumb)) {
		//echo "Made:".$BASE_URL.$path.'.'.$img_file;
		//header('Location: '.$BASE_URL.$path.'.'.$img_file);
        send_image($img);
		exit();
	}
}
function percent($p, $w) 
    { 
    return (real)(100 * ($p / $w)); 
    } 

function unpercent($percent, $whole) 
    { 
    return (real)(($percent * $whole) / 100); 
    } 

?>