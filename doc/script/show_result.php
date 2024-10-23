<?php
/** This is an example of the SHOW RESULT script used in anonymous
*   forms to show the result of an action when a reader added or updated an item.
*   Add a parameter "show_result=http://complete_URL_to_this_script" to
*   the SSI include of fillform.php to use this script in your anonymous form.
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
        echo 'Too many errors. Please contact the webmaster and tell him about the message: ' .$result. '<br>' ."\n";
        return;
    }

    foreach($resarr as $error => $prop) {
        switch ($error) {

        // Success: everything went O.K.
        case "success":
            if ($prop === "insert")
                echo "Thank you! Please confirm your subscription by using the
                      URL which you receive in a short time in a welcome email.";
            else echo "OK. Changes were successful.";
            break;

        // Called by fillform when the URL contains a parameter sent to readers in
        // confirmation emails.
        case "email_confirmed":
            echo "Thank you! You have just confirmed your subscription and from now on you will receive our news by email.";
            break;

        // Error when validating some field.
        case "validate":
            echo match (key($prop)) {
                "con_email......." => 'Error in email. This email address is already subscribed. Please use another one.',
                "headline........" => 'Error in username. This username is already used. Please try another.'."\n",
                default            => 'Error in field value ('.key($prop).').<br>'."\n",
            };
            break;

        // Error: permissions K.O.
        case "permissions":
            echo 'Wrong password. You must fill the correct password every time you send info.<br>'."\n";
            break;

        // Other error.
        default:
            echo 'Some error: '.$prop.'<br>'."\n";
            break;
        }
    }

