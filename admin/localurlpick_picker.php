<!DOCTYPE html>
<html lang="zxx">
<head>
  <meta charset=ISO-8859-1>
  <link rel="StyleSheet" href="../admin.css">
  <title></title>
<script>
var count=1;

function changeURLlocation() {
    let splitURL = document.forms[0].url.value.split("/");
    if (splitURL[2] === parent._navigator.location.host) {
        parent._navigator.location = this.url.value
    }
}

function feedFields() {
    top.opener.document.inputform.elements['<?php echo $_GET["var_id"]; ?>'].value = document.forms[1].localURL.value;
}

function startURLcheck() {
    changeURLcontent();
    if (count > 0) {
        window.setTimeout("startURLcheck()", 100);
    }
}

function changeURLcontent() {
    if (document.forms[1].localURL.value !== parent.frames[1].location) {
      document.forms[1].localURL.value=parent.frames[1].location;
      document.forms[0].url.value=parent.frames[1].location;
  }
}
</script>
</head>
<body onLoad="startURLcheck()">
<table style="text-align: left; width: 100%;">
  <tbody>
    <tr>
      <td class=tablename>
      <form enctype="text/plain" method="post" name="navigator" onSubmit="changeURLlocation();return false;">
<input type=button value=Back onClick="history.go(-1)">
<input type=button value=Forward onClick="history.go(+1)">
<input type=button value=Home onClick="parent.frames[1].location='<?php echo htmlspecialchars($_GET["url"]); ?>'">
<input type="url" size="60" name="url" placeholder="https://" value="">
          <input value="GO" name="submit" type="submit">
      </form>
      </td>
      <td class=tablename>

      <form enctype="text/plain"
 method="post" name="catcher"><input type="button"
 value="Pick this URL" name="catcher" onClick="feedFields();top.self.close()">
<input type="hidden" name="localURL" value="">
</form>
      </td>
<td class=tablename>
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

