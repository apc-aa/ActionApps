<?php

function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }
foreach ($_REQUEST as $k => $v) {
    $$k = AddslashesDeep($v);
}

require_once __DIR__."/./../../include/config.php";
require_once __DIR__ . "/../../include/util.php";

if (!$view)     { $view = false; }
if (!$encoding) { $encoding = defined('CONV_DEFAULTENCODING') ?: 'utf-8'; }

$uploadpath = IMG_UPLOAD_PATH;

echo '
<!DOCTYPE html>
<html>
  <head>
    <title>'. _m('Foreign Formats Convertor') .'</title>
    <meta charset="'.$encoding.'">
  </head>';

  if ($submit=="Import") {
      echo "<body onload=\"PasteHTML();\">";
      $view=false;
  } else {
      echo "<body>";
      $view=true;
  }

  echo '<h2>'._m('Action Apps PDF/DOC Convertor').'</h2>
  <p>'._m('Experimental feature').'</p><br><br>';


if (!array_key_exists('userfile', $_FILES)) {
    ?>
    <form method="post" action="" enctype="multipart/form-data">
    <input type="file" name="userfile"> <br>
    <input type="submit" value="Preview">
    <input type="submit" name="submit" value="Import">
    <?php
        if ($encoding) echo "<input type=\"hidden\" name=\"encoding\" value=\"$encoding\">";
    ?>
    </form>
    <?php
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
            if ( defined('CONV_HTMLFILTERS_DOC')) {
                exec(str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_DOC),$out);
            }
            unlink ("$uploadpath$file_name");
            $insidesection=false;
            $buffer = [];
            $output = [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/^<!--Section/",$line)) {
                    if ($insidesection==true) {
                        $output = array_merge($output,$buffer);
                        $buffer = [];
                    } else {
                        $buffer= [];
                    }
                    $insidesection=true;
                } else {
                    $line = str_replace("&scaron;","š",$line);
                    $line = str_replace("&Scaron;","Š",$line);
                    $buffer[]=$line;
                }
            }
            $output = array_merge($output,$buffer);
        } elseif (preg_match("/.docx$/i",$realname)) {
            $safe_file_name=escapeshellcmd($file_name);
            if ( defined('CONV_HTMLFILTERS_DOCX')) {
                exec(str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_DOCX),$out);
            }
            unlink ("$uploadpath$file_name");
            $insidesection=false;
            $buffer = [];
            $output = [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/^<!--Section/",$line)) {
                    if ($insidesection==true) {
                        $output = array_merge($output,$buffer);
                        $buffer = [];
                    } else {
                        $buffer= [];
                    }
                    $insidesection=true;
                } else {
                    $buffer[]=$line;
                }
            }
            $output = array_merge($output,$buffer);
        } elseif (preg_match("/.docx$/i",$realname)) {
            $safe_file_name=escapeshellcmd($file_name);
            if ( defined('CONV_HTMLFILTERS_DOCX')) {
                exec(str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_DOCX),$out);
            }
            unlink ("$uploadpath$file_name");
            $insidesection=false;
            $buffer = [];
            $output = [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/^<!--Section/",$line)) {
                    if ($insidesection==true) {
                        $output = array_merge($output,$buffer);
                        $buffer = [];
                    } else {
                        $buffer= [];
                    }
                    $insidesection=true;
                } else {
                    $buffer[]=$line;
                }
            }
            $output = array_merge($output,$buffer);
        } elseif (preg_match("/.pdf$/i",$realname)){
            $safe_file_name=escapeshellcmd($file_name);
            if ( defined('CONV_HTMLFILTERS_PDF')) {
                exec (str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_PDF),$out);
                //$out=join("\n",$out);
            }
            unlink ("$uploadpath$file_name");
            if (!$out) {
                $out = " ";
            }
            $insidesection=false;
            $buffer= [];
            $output= [];
            foreach ($out as $linenum => $line) {
                if (preg_match("/body/i",$line)) {
                    if ($insidesection==true) {
                        $output=array_merge($output,$buffer);
                        $buffer= [];
                    } else $buffer= [];
                    $insidesection = true;
                } else {
                    $buffer[] = $line;
                }
            }
        } elseif (preg_match("/.xls$/i",$realname)){
            $safe_file_name=escapeshellcmd($file_name);
            if ( defined('CONV_HTMLFILTERS_XLS')) {
                exec (str_replace('%1',"$uploadpath$safe_file_name",CONV_HTMLFILTERS_XLS),$out);
                //$out=join("\n",$out);
            }
            unlink ("$uploadpath$file_name");
            if (!$out) {
                $out[] = " ";
            }
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
        $stringoutput=safe($stringoutput);
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
