<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
  <meta
 content="text/html; charset=ISO-8859-1"
 http-equiv="content-type">
<LINK rel="StyleSheet" href="/apc-aa/admin.css" type="text/css">
  <title></title>
<script>
<!--
var count=1;

function changeURLlocation()
{
splitURL = document.forms[0].url.value.split("/")
if (splitURL[2] == parent._navigator.location.host)
{
parent._navigator.location=this.url.value
}
}

function feedFields()
{
top.opener.document.inputform.elements['<? echo $_GET["var_id"]; ?>'].value = document.forms[1].localURL.value;
}

function startURLcheck(){
changeURLcontent();
if (count>0){
 Id = window.setTimeout("startURLcheck()",100); 
} 
}	

function changeURLcontent(){
if (document.forms[1].localURL.value != parent.frames[1].location)
{
document.forms[1].localURL.value=parent.frames[1].location;
document.forms[0].url.value=parent.frames[1].location;
}
}
// -->
</script>
</head>
<body onLoad="startURLcheck()">
<table style="text-align: left; width: 100%;"
 border="0" cellpadding="2" cellspacing="2">
  <tbody>
    <tr>
      <td class=tablename>
      <form 
 enctype="text/plain" method="post" name="navigator" onSubmit="changeURLlocation();return false;">
<input type=button value=Back onClick="history.go(-1)">
<input type=button value=Forward onClick="history.go(+1)">
<input type=button value=Home onClick="parent.frames[1].location='<? echo htmlspecialchars($_GET["url"]); ?>'">
<input
 size="60" name="url"
 value="http://"> <input
 value="GO" name="submit" type="submit">
</form>
      </td>
      <td class=tablename align=center>

      <form enctype="text/plain"
 method="post" name="catcher"><input type="button"
 value="Pick this URL" name="catcher" onClick="feedFields();top.self.close()">
<input type="hidden" name="localURL" value="">
</form>
      </td>
<td class=tablename align=center>
<form enctype="text/plain"
 method="post" name="cancel"><input type="button"
 value="Cancel" name="catcher" onClick="top.self.close()">
</form></td>
    </tr>
  </tbody>
</table>
<br>
</body>
</html>

