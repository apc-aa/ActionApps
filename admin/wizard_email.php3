<?php
require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."mail.php3";
require_once $GLOBALS["AA_INC_PATH"]."item_content.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/reader_field_ids.php3";

add_post2shtml_vars (false);

$email_templates = GetUserEmails ();
asort ($email_templates);

if (! $example_email) {
    $me = GetUser ($auth->auth["uid"]);  
    $example_email = $me["mail"][0];
}

if ($send_example_email) 
    $mail_count = send_mail_from_table ($email_template, $example_email);

if ($send_emails) {
    reset ($chb);
    $emails = "";
    $itemContent = new ItemContent;
    while (list ($item_xid) = each ($chb)) {
        $itemContent->setByItemID (substr ($item_xid, 1));
        $emails[] = $itemContent->getValue (FIELDID_EMAIL);
    }
    $mail_count = send_mail_from_table ($email_template, $emails);
}

$wizard_steps[1] = array (
    "brief" => is_array ($chb) 
         ? _m("Select readers<br><i>%1 reader(s) selected</i>", array(count($chb)))
         : _m("Select readers"),
    "desc" => ! is_array ($chb)
        ? "<b>"._m("You can not proceed until you select at least one reader!")."</b> " 
        . _m("Find readers using the Search conditions in Item Manager.")
        : "");
$wizard_steps[] = array (
    "brief" => _m("Create or edit email template"),
    "desc" => _m("Use Slice Admin / Email templates to create or edit an email template."),
    "aa_href" => "admin/tabledit.php3?set_tview=email",
    "optional" => 1);
$wizard_steps[] = array (
    "brief" => _m("Choose email template"),
    "inner" => 1,
    "desc" =>  FrmSelectEasyCode ("email_template", $email_templates, $email_template)
        ."<br>". _m("If you have just created the template, click on 'Step' and the template appears in the select box."));
$wizard_steps[] = array (
    "brief" => _m("Send example email to"),
    "inner" => 1,
    "desc" => '<input type=text name=example_email value="'.$example_email.'">&nbsp;
        <input type=submit name=send_example_email value="'._m("Go!").'">');    
$wizard_steps[] = array (
    "brief" => _m("Send emails"),
    "desc" => _m("This will send emails to all readers selected in Step 1.")
            . '<br><input type=submit name=send_emails value="'._m("Go!").'">');
$wizard_steps[] = array (
    "brief" => _m("Delete the email template"),
    "aa_href" => "admin/tabledit.php3?set_tview=email",
    "desc" => _m("If this was a one-off template, delete it."));        

HTMLPageBegin ();
echo "<title>"._m("Send Emails Wizard")."</title>
</head>
<body>
<form name=\"wizard_form\" method=post action=\"".self_complete_url()."\">
<input type=hidden name=step value=$step>
<table border=0 cellspacing=0 cellpadding=0 width=\"100%\">
<tr><td class=tabtit><h1>"._m("Send Emails Wizard")."</h1></td></tr>";

if (isset ($mail_count)) echo "<tr><td class=tabtxt>"._m("%1 email(s) were sent.", array ($mail_count))."</td></tr>";

if (! $step) $step = 1;

reset ($wizard_steps);
while (list ($istep, $wizard_step) = each ($wizard_steps)) {
    echo "<tr><td class=tabtxt><b>";
    echo '<a href="javascript:document.wizard_form.step.value='.$istep.';';
    if ($wizard_step["aa_href"])
        echo 'top.aaFrame.location.href=\''.$sess->url($AA_INSTAL_PATH.$wizard_step["aa_href"]).'\';';
    echo 'document.wizard_form.submit()">', 
        _m("Step"), " ", $istep, ":</a> ", $wizard_step["brief"], "</b>";
    echo "<br>", $wizard_step["desc"];
    echo "</td></tr>";
}
echo '<tr><td class=tabtit align=center height=30>
<input type=button onclick="top.location.href=top.aaFrame.location.href" value="'._m("Close the wizard").'"></td></tr>';
echo "</table></form>";
echo "</body></html>";
?>