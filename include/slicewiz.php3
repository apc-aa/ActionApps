<?php

function resolve_email_aliases ($aliases, $text) {
    $retval = $text;
    reset ($aliases);
    while (list ($alias, $value) = each ($aliases))
        $retval = str_replace ($alias, $value, $retval);
    return $retval;
}

/*  Assigns user privileges and sends a welcome email, if the email address is filled.
    Returns error description or empty string. */

function add_user_and_welcome ($welcome_id, $user_login, $slice_id, $role) {
    global $auth;

    // 1. Assign user privileges
    $userinfo = find_user_by_login ($user_login); 
    if (!is_array ($userinfo) || count ($userinfo) != 1) 
        return _m("User not found");              
        
    reset ($userinfo);
    $GLOBALS[UsrAdd] = key ($userinfo);
    $GLOBALS[role] = $role;
    ChangeRole (); // in include/se_users.php3                
    
    // 2. Send a welcome email message
    $user = current ($userinfo);
    if (!$user["mail"]) 
        return "";

    $db = new DB_AA;
    $db->query ("SELECT name FROM slice WHERE id = '".q_pack_id($slice_id)."'");
    if (!$db->next_record()) return L_SLICE_NOT_FOUND;
    $slice_name = $db->f("name");    
    $me = GetUser ($auth->auth["uid"]);  

    $aliases = array (
        "_#SLICNAME" => $slice_name,
        "_#LOGIN___" => $user_login,
        "_#NAME____" => $user["name"],
        "_#ROLE____" => $role,
        "_#ME_MAIL_" => $me["mail"][0],
        "_#ME_NAME_" => $me["cn"]);

    $db->tquery ("SELECT * FROM wizard_welcome WHERE id=$welcome_id");
    if (!$db->next_record()) return _m("Internal error");
    $mail_subject = resolve_email_aliases($aliases, $db->f("subject"));
    $mail_body = resolve_email_aliases($aliases, $db->f("email"));
    $mail_from = resolve_email_aliases($aliases, $db->f("mail_from"));

    if ($GLOBALS[debug]) {            
        echo "<h1>$mail_subject</h1>";
        echo $mail_body;
    }

    if (!mail ($me["mail"][0], $mail_subject." "._m("sent to")." $user[mail]", $mail_body, "From: $mail_from")
        || !mail ( $user["mail"], $mail_subject, $mail_body, "From: $mail_from"))
        return _m("Error mailing");
}
?>