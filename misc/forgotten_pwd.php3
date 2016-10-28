<?php
require_once "./../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH."mail.php3";
require_once AA_INC_PATH."itemfunc.php3";

$KeyVal = 90;  // generated key validity in minutes
$script_path = AA_INSTAL_PATH."misc/forgotten_pwd.php3";
?>
<HTML>
<HEAD>
<SCRIPT LANGUAGE="JavaScript">
<!-- // Begin
function validatePwd() {
    var invalid = " "; // Invalid character is a space
    // var minLength = 6; // Minimum length
    var pw1 = document.myForm.password.value;
    var pw2 = document.myForm.password2.value;
    // check for a value in both fields.
    if (pw1 == '' || pw2 == '') {
        alert('Please enter your password twice.');
        return false;
    }
    // check for spaces
    if (document.myForm.password.value.indexOf(invalid) > -1) {
        alert("Sorry, spaces are not allowed.");
        return false;
    }
    else {
        if (pw1 != pw2) {
            alert ("You did not enter the same new password twice. Please re-enter your password.");
            return false;
        }
        else {
            return true;
        }
    }
}
//  End -->
</script>
</HEAD>
<BODY>
<?php

$protocol = ( isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == 'on' ) ? 'https://' : 'http://';

$full_path = $protocol.$_SERVER['HTTP_HOST'].$script_path;

if (!($do)) {?>
    Forgot your password ? Type in either your<br>
    <form method="get" action="<?php echo $script_path ?>" enctype="multipart/form-data">
    username: <input type="text" name="user"><br>
    OR<br>
    e-mail address:<input type="text" name="email"><br>
    <input type="hidden" name="do" value="chu">
    <input type="submit"  value="Proceed">
    </form>
    <?php
}
if ($do=="chu") { //CHeck User
    if (!($email.$user)) die ("Wrong way, go back !!!");
    if ($user) {
        $by = "headline........";
        $id = $user;
    }
    else {
        $by = "con_email.......";
        $id = $email;
    }
    // check if we can find the user either by username (preffered as it's unique) or email address
    if (!$userdata=GetUserData($id,$by)) die("Can't find the user, sorry. Check the spelling and try again !!!");
    // generate MD5 hash
    $username = $userdata["headline........"][0]['value'];
    $email    = $userdata["con_email......."][0]['value'];
    $pwdkey   = md5($username.$email.AA_ID.round(now()/60));
    // send it via email
    $mail     = new AA_Mail;
    $mail->setSubject ("Password reset information");
    $body     = "To reset your password, please visit this URL:<br>
    <a href=".$full_path."?do=chk&user=$username&key=$pwdkey>".$full_path."?do=chk&user=$username&key=$pwdkey</a><br>
    Please note that you have to to this within an hour, otherwise the key that has been send to you in this message will expire.";
    $mail->setHtml($body, html2text($body));
    $mail->setHeader("From", ERROR_REPORTING_EMAIL);
    $mail->setHeader("Reply-To", ERROR_REPORTING_EMAIL);
    $mail->setHeader("Errors-To", ERROR_REPORTING_EMAIL);
    //$mail->setCharset ($GLOBALS ["LANGUAGE_CHARSETS"][substr ($db->f("lang_file"),0,2)]);
    $mail->send(array($email));
    echo "The email with the key allowing to reset your password has been sent to you to this email address: $email";
}
if ($do=="chk" || $do=="chp") { //CHeck Key or CHange Password
    if (!($key.$user)) die ("Wrong way, go back !!!");
    if (!$userdata=GetUserData($user,"headline........")) die("Can't find the user, sorry. Check the spelling and try again !!!");
    // Check the key
    $username = $userdata["headline........"][0]['value'];
    $email    = $userdata["con_email......."][0]['value'];
    $i=-1;
    while ($i++<$KeyVal && $pwdkey!=$key) {
        $pwdkey = md5($username.$email.AA_ID.round(round(now()/60)-$i));
    }
    if ($i>=$KeyVal) die("Wrong or expired key, sorry.");
    if ($do=="chk") {
       echo "Type in a new password for $username:<br>";
       ?>
       <form name="myForm" method="post" action="<?php echo $script_path?>" enctype="multipart/form-data" onSubmit="return validatePwd()">
       New password: <input type="password" name="password"><br>
       Retype password:<input type="password" name="password2"><br>
       <input type="hidden" name="do" value="chp">
       <input type="hidden" name="user" value="<?php echo $username?>">
       <input type="hidden" name="key" value="<?php echo $key?>">
       <input type="submit"  value="Proceed">
       </form>
       <?php
    }
    else  { //CHange Password
       $sliceID = unpack_id($userdata["slice_id........"][0]['value']);
       $itemID  = unpack_id($userdata["id.............."][0]['value']);
       $fields = AA_Slice::getModule($sliceID)->fields('record');
       $fields["password........"]["input_insert_func"] = "qte:";
       $userdata["password........"][0]['value']        = ParamImplode(array('AA_PASSWD',$password));
       $update = StoreItem( $itemID, $sliceID, $userdata, false, true, false ); // insert, invalidatecache, feed
       if ($update) echo "Your password has been updated."; else
       die ("There was an error updating your password. Please contact ".ERROR_REPORTING_EMAIL);
    }
}
/** Fills content array for current loged user */
function GetUserData($identification,$findby="headline........") {
    // create fields array - headline........ for user name, con_email....... for email
    $ret=false;
    $conds[] = array( $findby => $identification );

    // getReaderManagement slices
    $db = getDB();
    $db->tquery("SELECT id FROM slice WHERE type='ReaderManagement'");
    while ($db->next_record()) {
        $slices[] = unpack_id($db->f('id'));
    }
    freeDB($db);
    // get item id of current user
    $zid = QueryZIDs($slices, $conds, '', 'ACTIVE', 1, false, '=' );
    //var_dump($conds);
    if ( $zid->count()<1 )      return false;

    $content = GetItemContent($zid);
    if ( !is_array($content) )  return false;

    $ret= reset($content);
    return $ret;
}
?>
