<?php
/**
 * Form displayed in popup window sending emails to user/group_of_users
 *
 *
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Jakub Adamek, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 * @param $items[] - OLD VERSION : array of selected users (in reader management slice)
 * @param $chb[] - array of selected users (in reader management slice)
*/

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../modules/alerts/util.php3";
require_once __DIR__."/../include/varset.php3";

$searchbar = new AA_Searchbar();   // mainly for bookmarks
$items     = $chb;

//if ( IfSlPerm(PS_EDIT_ALL_ITEMS) ) {
//    MsgPage(StateUrl(self_base())."index.php3", _m("You do not have permission to send mails from this slice: ").AA_Slice::getModuleName($slice_id));
//    exit;
//}

// Allow to use current slice data without slice_pwd
AA_Credentials::singleton()->loadFromSlice($slice_id);

$slice       = AA_Slice::getModule($slice_id);

if ( !$send ) {               // for the first time - directly from item manager
    $sess->register('r_wm_state');
    unset($r_wm_state);       // clear if it was filled
    $r_wm_state['items'] = $items;
    $lang = get_mgettext_lang();
    $html = 1;
} else {
    $items     = $r_wm_state['items'];  // session variable holds selected items fmom item manager
    // we really want to send email - so store template
    do {
        // --- write the e-mail template to the table ---
        $varset = new Cvarset();
        $owner_module_id = q_pack_id($slice_id);
        $description     = date('Y-m-d H:i:s'). ', '. $auth->auth['uid'];
        $type            = 'bulk email';
        ValidateInput("subject",     _m("Subject"),            $subject,     $err, true,  "text");
        ValidateInput("body",        _m("Body"),               $body,        $err, true,  "text");
        ValidateInput("header_from", _m("From (email)"),       $header_from, $err, true,  "text");
        ValidateInput("reply_to",    _m("Reply to (email)"),   $reply_to,    $err, false, "text");
        ValidateInput("errors_to",   _m("Errors to (email)"),  $errors_to,   $err, false, "text");
        ValidateInput("sender",      _m("Sender (email)"),     $sender,      $err, false, "text");
        ValidateInput("lang",        _m("Language (charset)"), $lang,        $err, false, "text");
        ValidateInput("html",        _m("Use HTML"),           $html,        $err, false, "number");
        ValidateInput("template_id", _m("Email template"),     $template_id, $err, false, "number");

        foreach (['attachment1', 'attachment2', 'attachment3'] as $attach) {
            $uploadvar = $attach.'x';
            if ( $_FILES[$uploadvar]['name'] != '' ) {
                $dest_file = Files::uploadFile($uploadvar, Files::destinationDir($slice));

                if ($dest_file === false) {   // error
                    $err[] = Files::lastErrMsg();
                } else {
                    $$attach = $dest_file;
                }
            }
            if ($$attach) {
                $attachs[] = $$attach;
            }
        }

        $attachments = ParamImplode($attachs);

        if ( count($err)) {
            break;
        }

        $varset->addglobals( [
            'description', 'subject',
                                   'header_from', 'reply_to', 'errors_to', 'sender',
                                   'lang', 'owner_module_id', 'type', 'attachments'
        ],
                             'quoted');

        if ($template_id AND ($mailtemplate = AA_Mail::getTemplate((int)$template_id))) {
            $varset->add('body', 'text', str_replace(['_#BODYTEXT','_#SUBJECT_'], [$_POST['body'], $_POST['subject']],$mailtemplate['body']));
        } else {
            $varset->add('body', 'quoted', $body);
        }
        $varset->add('html', 'number', $html);

        $varset->doINSERT('email');
        $mail_id = $varset->last_insert_id();  // get mail template id

        if ( !ctype_digit((string)$mail_id) )  {
            $err["mail"] = MsgErr( _m("No template set (which is strange - template was just written to the database") );
            break;
        }

        // --- send emails
        if ( $group == 'testuser') {
            $mails_to    = explode(',',$testemail);
            $mails_sent  = AA_Mail::sendTemplate($mail_id, $mails_to);
            $users_count = count($mails_to);
        } else {
            // get reader's zids
            $zids = getZidsFromGroupSelect($group, $items, $searchbar);
            // following functionality could be extend by adding third
            // parameter $recipient (for testing e-mail)
            $mails_sent  = AA_Mail::sendToReader($mail_id, $zids);
            $users_count = $zids->count();
        }
        $Msg = MsgOk(_m("Email sucessfully sent (Users: %1, Emails sent (valid e-mails...): %2, template id: %3)", [$users_count, $mails_sent, $mail_id]));

        if ((string)$group == (string)"sel_item") {
            $sel = "LIST";
            $description .= ' - '. _m('Manualy selected');
        } elseif ((string)$group == (string)"testuser") {
            $sel = "TEST";
            $description .= " - ($testemail)";
        } else {
            $sel = get_if($group,"0");  // bookmarks groups are identified by numbers
            $description .= ' - '. _m('Filter'). " $sel";
        }
        AA_Log::write("EMAIL_SENT", $sel, [$mail_id, $users_count, $mails_sent]);
        // remove temporary email template from database
        $SQL = "UPDATE email SET description='".quote($description. " - $mails_sent/$users_count" )."' WHERE id='$mail_id'";
        if ( !$db->query($SQL)) {
            $err["DB"] = MsgErr( _m("Can't update email template") );
            break;    // not necessary - we have set the halt_on_error
        }
    } while (false);
}

$apage = new AA_Adminpageutil();
$apage->setTitle(_m("Bulk Email Wizard"));
$apage->setSubtitle(_m("Write email to users"));
$apage->setForm();

$apage->addRequire('ckeditor@4');
// IncludeManagerJavascript() - start
// $apage->addRequire(get_aa_url('javascript/aajslib.php?v='.AA_JS_VERSION ));  // already loaded with ckeditor
$apage->addRequire(get_aa_url('javascript/manager.min.js?v='.AA_JS_VERSION ));
// IncludeManagerJavascript() - end
$apage->addRequire(get_aa_url('javascript/inputform.min.js?v='.AA_JS_VERSION ));

// removed - maybe we will have to put it back
//  <link rel="StyleSheet" href="'.AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">
// echo getHtmlareaJavascript($slice_id);

$apage->printHead($err, $Msg);

echo '
  <form name="mailform" method="post" enctype="multipart/form-data">';


FrmTabCaption((is_array($items) ? _m("Recipients") : (_m("Stored searches for ") . $slice->getName())));

$messages['view_items']     = _m("View Recipients");
$messages['selected_items'] = _m('Selected users');
$additional[]               = [
    'text'    => '<input type="text" name="testemail" value="'.$testemail.'" size="80"> '._m('Test email address(es)'),
                                     'varname' => 'testuser'
];

$user_templates = GetUserEmails("user template");  // false or array['id']=>'name'

FrmItemGroupSelect( $items, $searchbar, 'users', $messages, $additional);

FrmTabSeparator(_m('Write the email'));

FrmInputText(  'subject',     _m('Subject'),           $_POST['subject'],     254, 80, true);
//FrmTextarea(   'body',        _m('Body'),              $_POST['body'],         20, 80, true, '', '', '', true);  // enable rich text area

if ($user_templates) {
    FrmInputSelect('template_id', _m('Email template'), $user_templates,  $_POST['template_id'], false);
}

FrmTextarea(   'body',        _m('Body'),              $_POST['body'],         30, 80, true , '', '', '', true);
FrmInputText(  'header_from', _m('From (email)'),      $_POST['header_from'], 254, 80, true , '', '', false, 'email');
FrmInputText(  'reply_to',    _m('Reply to (email)'),  $_POST['reply_to'],    254, 80, false, '', '', false, 'email');
FrmInputText(  'errors_to',   _m('Errors to (email)'), $_POST['errors_to'],   254, 80, false, '', '', false, 'email');
FrmInputText(  'sender',      _m('Sender (email)'),    $_POST['sender'],      254, 80, false, '', '', false, 'email');
FrmInputSelect('lang',        _m('Language (charset)'),  AA_Langs::getNames(),  $_POST['lang'] ? $_POST['lang'] : $slice->getLang(), true);
FrmInputSelect('html',        _m('Use HTML'),           [_m('no'), _m('yes')], $_POST['html'], true);
FrmInputFile(  'attachment1',   _m('Attachement 1'), $attachment1, false, "*/*");
FrmInputFile(  'attachment2',   _m('Attachement 2'), $attachment2, false, "*/*");
FrmInputFile(  'attachment3',   _m('Attachement 3'), $attachment3, false, "*/*");


FrmTabEnd([
    'send' => ['type'=>'submit', 'value'=>_m('Send')],
                 'close'=> ['type'=>'button', 'value'=>_m('Close'), 'add'=>'onclick="window.close()"']
]);

// list selected items to special form - used by manager.min.js to show items (recipients)
echo "\n  </form>";
FrmItemListForm($items);

//$form = AA_Mail::getNewForm($slice_id);
//echo $form->getObjectEditHtml();

$apage->printFoot();

