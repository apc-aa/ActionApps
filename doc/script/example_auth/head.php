<?php

// This example used to say: copy include/request.class.php next to this file and you are done.
// That stopped being true years ago - the file needs AA's autoloader - and since 2026-09 it also
// takes its transport from AA\Http\Client. Load a whole AA instead; on your own site, replace
// this with the path to the installation you are authenticating against.
require_once __DIR__ . "/../../../include/init_page.php";

$options = [
    'aa_url'          => 'https://example.org/apc-aa/',
    'cookie_lifetime' => 60*60*24*365  // one year
];
$a = new AA_ClientAuth($options);

function displayUserbox($a): void
{
    // getUid() returns username of the user (=login name) or false, if the user
    // is not known. In such case we provide user with loginform
    // getUid() do not perform any remote checks in the authentication,
    // it just checks, if the cookie with the username is pressent
    $user_uid = $a->getUid();
    echo "<div style=\"background: #CCF; border: solid 1px #666\">";
    if ($user_uid) {
        // We know who you are (or at least who is the person who uses this browser
        // while accessing our pages)
        echo "Hello $user_uid!<br/>";
        echo "<a href=\"logout.php\">logout</a><br/>";
    } else {
        // Not authenticated
        echo "<form method=\"post\" action=\"/apc-aa/doc/script/example_auth/login.php\">";
        echo "Username: <input type=\"text\" name=\"username\"><br/>";
        echo "Password: <input type=\"password\" name=\"password\"><br/>";
        echo "<input type=\"submit\">";
        echo "</form>";
    }
    echo "</div>";

}


