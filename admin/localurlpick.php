<html lang="en">
   <head>
      <title>Local URL Picker</title>
      <meta charset=iso-8859-1>
   </head>
  <FRAMESET rows="50,*">
      <FRAME src="localurlpick_picker.php?var_id=<?php echo rawurlencode($_GET["var_id"]); ?>&value=<?php echo rawurlencode($_GET["value"]); ?>&url=<?php echo rawurlencode($_GET["url"]); ?>" name="_picker" noresize scrolling="no">
      <FRAME src="localurlpick_navigator.php?url=<?php echo rawurlencode($_GET["url"]); ?>&value=<?php echo rawurlencode($_GET["value"]); ?>" name="_navigator" noresize>
  <NOFRAMES>
      <P>Sorry, you can't use this Local URL Picker, your browser doesn't support frames.
  </NOFRAMES>
</FRAMESET>
</html>
    
