<?php

$colornames = array (
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
  "aqua"   => "#00FFFF");

function mkcolor($image,$color){  
  global $colornames;

  if (substr($color,0,1) != "#") {
    $color = strtolower($color);
    $color = $colornames[$color];
  }
  $color = eregi_replace("#","",$color);

  $out["red"] = hexdec(substr($color,0,2));
  $out["green"] = hexdec(substr($color,2,2));
  $out["blue"] = hexdec(substr($color,4,2));

  return($out);
}

  if (isset($width) && isset($height) && isset($color)){

    header("Content-type: image/jpeg");

    $image = imagecreate($width, $height);
    $bgcolor = ImageColorAllocate($image, $out["red"], $out["green"], $out["blue"]);
    imagefill($image, 0,0, $bgcolor);

    imagejpeg($image);
    imagedestroy($image);
  }
  else
  { echo "Ko";}

?>