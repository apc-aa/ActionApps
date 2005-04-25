<?php
/** This is an example of the SHOW RESULT script used in anonymous
*   forms to show the result of an action when a reader added or updated an item.
*   Add a parameter "show_result=http://complete_URL_to_this_script" to
*   the SSI include of fillform.php3 to use this script in your anonymous form.
*
*   See more documentation in doc/anonym.html.
*
*   $Id$
*   Created by Jakub Adamek, Econnect, February 2003
*/

    // No results, probably the page is called first time and no form data
    // are sent.
    if (! $result)
        return;

    $resarr = unserialize (stripslashes($result));        
    
    // If there were too many errors, the array is not complete and thus
    // unserialize does not work.
    if (!is_array($resarr)) {
        echo '<font class="tabtxt">Too many errors. Please contact the webmaster
            and tell him about the message: '.$result.'</font><br>'."\n";
        return;
    }
    
    reset ($resarr);            
    while (list ($error, $prop) = each ($resarr)) {
        switch ($error) {
    
        // Success: everything went O.K.    
        case "success":
            if ($prop == "insert")
                echo "Thank you! Please confirm your subscription by using the
                      URL which you receive in a short time in a welcome email.";
            else echo "OK. Changes were successful.";
            break;
        
        // Called by fillform when the URL contains a parameter sent to readers in
        // confirmation emails.
        case "email_confirmed":
            echo "Thank you! You have just confirmed your subscription and from
                now on you will receive our news by email.";
            break;
        
        // Error when validating some field.
        case "validate": 
            switch (key ($prop)) {
            case "con_email.......":
                echo '<font class="tabtxt">
                    Error in email. This email address is already subscribed.
                    Please use another one.</font>';
                break; 
            case "headline........":
                echo '<font class="tabtxt">
                    Error in username. This username is already used.
                    Please try another. 
                </font>'."\n";
                break;
            default:
                echo '<font class="tabtxt">Error in field value ('.key($prop).').</font><br>'."\n";
                break;
            }
            break;
            
        // Error: permissions K.O.
        case "permissions":
            echo '<font class="tabtxt">Wrong password. You must
                    fill the correct password every time you send info.</font><br>'."\n";
            break;
            
        // Other error.
        default:                   
            echo '<font class="tabtxt">Some error: '.$prop.'</font><br>'."\n";
            break;
        }
    }
?>
   