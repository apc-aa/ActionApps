<html>
   <head>
      <title>Local URL Picker</title>
      <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
   </head>
  <FRAMESET rows="50,*" border=0>
      <FRAME src="localurlpick_picker.php3?var_id=<? echo urlencode($_GET["var_id"]); ?>&value=<? echo urlencode($_GET["value"]); ?>&url=<? echo urlencode($_GET["url"]); ?>" name="_picker" noresize scrolling="no">
      <FRAME src="localurlpick_navigator.php3?url=<? echo urlencode($_GET["url"]); ?>&value=<? echo urlencode($_GET["value"]); ?>" name="_navigator" noresize>
  <NOFRAMES>
      <P>Sorry, you can't use this Local URL Picker, your browser
         doesn't support frames. 
  </NOFRAMES>
</FRAMESET>
</html>
    
