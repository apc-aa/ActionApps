<?php
require_once $GLOBALS["AA_INC_PATH"]."mail.php3";

/*  Assigns user privileges and sends a welcome email, if the email address is filled.
    Returns error description or empty string. */

function add_user_and_welcome ($welcome_id, $user_login, $slice_id, $role) {
    global $auth;

    // 1. Assign user privileges
    $userinfo = find_user_by_login ($user_login); 
    if (!is_array ($userinfo) || count ($userinfo) != 1) 
        return _m("User not found");              
        
    reset ($userinfo);
    $GLOBALS["UsrAdd"] = key ($userinfo);
    $GLOBALS["role"] = $role;
    ChangeRole (); // in include/se_users.php3                
    
    // 2. Send a welcome email message
    $user = current ($userinfo);
    if (!$user["mail"]) 
        return "";

    $db = new DB_AA;
    $db->query("SELECT name FROM slice WHERE id = '".q_pack_id($slice_id)."'");
    if (!$db->next_record()) return _m("Slice not found.");
    $slice_name = $db->f("name");    
    $me = GetUser ($auth->auth["uid"]);  

    $aliases = array (
        "_#SLICNAME" => $slice_name,
        "_#LOGIN___" => $user_login,
        "_#NAME____" => $user["name"],
        "_#ROLE____" => $role,
        "_#ME_MAIL_" => $me["mail"][0],
        "_#ME_NAME_" => $me["cn"]);

    if (send_mail_from_table ($welcome_id, array ($me["mail"][0], $user["mail"]), $aliases)
        != 2)
        return _m("Error mailing");
}
?>