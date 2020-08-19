<?php
require_once __DIR__."/./../../include/config.php3";
require_once __DIR__."/../../include/util.php3";

if (!$view)     $view     = false;
if (!$encoding) $encoding = CONV_DEFAULTENCODING;
if (!$sysenc)   $sysenc   = CONV_SYSTEMENCODING;

$uploadpath = IMG_UPLOAD_PATH;

echo '
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
  "http://www.w3.org/TR/html4/loose.dtd">
<HTML>
  <HEAD>
    <title>'. _m('Foreign Formats Convertor') .'</title>
    <meta http-equiv="Content-Type" content="text/html; charset='.$encoding.'">
  </HEAD>';

  if ($submit=="Import") {
      echo "<body onload=\"PasteHTML();\">";
      $view=false;
  } else {
      echo "<body>";
      $view=true;
  }

  echo '<h2 align="center">'._m('Action Apps PDF/DOC Convertor').'</h2>
  <p align="center">'._m('Experimental feature').'</p><br><br>';


if (!$userfile) {
    ?>
    <form method="post" action="" enctype="multipart/form-data">
    <input type="file" name="userfile"> <br>
    <input type="submit" value="Preview">
    <input type="submit" name="submit" value="Import">
    <?php
    if ($encoding) echo "<input type=\"hidden\" name=\"encoding\" value=\"$encoding\">";
    echo "</form>";
} else {
    $file_name    = Files::getTmpFilename('msc');
    $realname     = $_FILES['userfile']['name'];
    $stringoutput = '';
    $dest_file    = Files::uploadFile('userfile', $uploadpath, '', 'new', $file_name);
    if ($dest_file === false) {   // error
        $error = Files::lastErrMsg();
    }
    if ($error) die($GLOBALS["IMG_UPLOAD_PATH"]);
    if (!$error) {
        if (preg_match("/.doc$/i",$realname)) {
            $safe_file_name=escapeshellcmd($file_name);
            $safe_encoding=escapeshellarg ($encoding);
            if ( defined('CONV_HTMLFILTERS_DOC')) {
                exec(str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_DOC),$out);
                $out=join("\n",$out);
            }
            unlink ("$uploadpath$file_name");
            $insidesection=false;
            $buffer= [];
            $output= [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/^<!--Section/",$line)) {
                    if ($insidesection==true) {
                        $output=array_merge ($output,$buffer);
                        $buffer= [];
                    } else $buffer= [];
                    $insidesection=true;
                } else {
                    $line=str_replace("&scaron;","�",$line);
                    $line=str_replace("&Scaron;","�",$line);
                    $buffer[]=$line;
                }
            }
        } elseif (preg_match("/.pdf$/i",$realname)){
            $safe_file_name=escapeshellcmd($file_name);
            if ( defined('CONV_HTMLFILTERS_PDF')) {
                exec (str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_PDF),$out);
                $out=join("\n",$out);
            }
            unlink ("$uploadpath$file_name");
            if (!$out) $out[]=" ";
            $insidesection=false;
            $buffer= [];
            $output= [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/body/i",$line)) {
                    if ($insidesection==true) {
                        $output=array_merge ($output,$buffer);
                        $buffer= [];
                    } else $buffer= [];
                    $insidesection=true;
                } else {
                    $buffer[]=$line;
                }
            }
        } elseif (preg_match("/.xls$/i",$realname)){
            $safe_file_name=escapeshellcmd($file_name);
            $safe_encoding=escapeshellarg ($encoding);
            $safe_sysenc=escapeshellarg ($sysenc);
            if ( defined('CONV_HTMLFILTERS_XLS')) {
                exec (str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_XLS),$out);
                $out=join("\n",$out);
            }
            unlink ("$uploadpath$file_name");
            if (!$out) $out[]=" ";
            $insidesection=true;
            $output= [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/<HR>/",$line)) {
                    $line=str_replace("<HR>","",$line);
                    $output[]=$line;
                    $insidesection=false;
                } else {
                    if ($insidesection==true) $output[]=$line;
                }
            }
        }
        if ($view AND count($output) ) {
            echo join("\n",$output)."\n";
        }
        echo "<form name=\"aform\">";
        echo "<input type=hidden name=content value=\"";
        $stringoutput = join('',$output);
        // we need to remove added background color, ...
        $stringoutput=preg_replace("/<\/?div.*>/i","",$stringoutput); // remove all DIVs
        $stringoutput=preg_replace("/ style=\".+\"/i","",$stringoutput); // remove all styles
        $stringoutput=myspecialchars($stringoutput);
        echo $stringoutput;
        echo "\"></form>";
        ?>
        <script>
        function PasteHTML() {
            window.opener.document.inputform.elements["<?php echo $inputid?>"].value = document.aform.content.value;
            window.opener.document.inputform.<?php echo $inputid?>html[0].checked = true;
            window.close();
        }
        </script>
        <a href='javascript: PasteHTML();'>Paste and close </a>
        <?php
    } else {
        // ERROR uploading...
        if ($my_uploader->errors) {
            echo join("<br>",$my_uploader->errors)."<br>";
        }
    }
}
?>

</body>
</html>
