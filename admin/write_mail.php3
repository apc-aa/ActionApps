<?php
/**
 * Form displayed in popup window sending emails to user/group_of_users
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @param $items[] - OLD VERSION : array of selected users (in reader management slice)
 * @param $chb[] - array of selected users (in reader management slice)
 */
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]. "formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]. "searchbar.class.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/util.php3";
require_once $GLOBALS["AA_INC_PATH"]. "varset.php3";

/** Print one row with one 'sliceexport' radiobuttons */
function PrintRadioBookmarkRow( $name, $value, $safe=true, $bookparams="") {
    global $slice_id, $items;

    static $checked = ' checked';  // mark first option when no $group selected

    if ( isset( $GLOBALS['group'] ) ) {
        $checked = (((string)$GLOBALS['group'] == (string)$value) ? ' checked' : '');
    }

    if ( $safe ) $name = safe($name);
    echo "
    <tr>
      <td align=center><input type=\"radio\" name=\"group\" value=\"$value\" $checked></td>";
      if ((string)$value == (string)"testuser") {
          echo "<td colspan=6>";
      } else {
          echo "<td>";
      }

      echo "$name</td>";
      if (is_array($bookparams)) {
          $event = getLogEvents("BM_%", "", "", false, false, $bookparams['id']);
          $lastused = getLogEvents("EMAIL_SENT", "", "", false, false, (string)$value);
          if (is_array($event)) {
              foreach ($event as $evkey => $evval) {
                  if ($evval["type"] == "BM_CREATE") {
                      $created = $evval["time"];
                      $createdby = $evval["user"];
                  }
              }
              rsort($event);
              $last_edited = $event[key($event)]["time"];
              echo "<td>". perm_username($createdby) . "</td><td>". date("j.n.Y G:i:s",$created). "</td>";
              echo "<td>". date("j.n.Y G:i:s",$last_edited). "</td>";
             if (is_array($lastused)) {
                 rsort($lastused);
                 $last_used = $lastused[key($lastused)]["time"];
                 echo "<td>". date("j.n.Y G:i:s",$last_used). "</td>";
             }
          } else {
              echo "<td colspan=4></td>";
          }
      }
      echo "<td>";
      if ((string)$value != (string)"testuser") {
          $grp = $value;
          $js = "OpenUsershowPopup('".get_admin_url("usershow.php3")."&sid=".$slice_id."&group=".$grp."')";
/*          if ((string)$value == (string)"user") {
              $js .= "&group=user";
              foreach ($items as $key=>$it) {
                  $js .= "&items[$key]";
              }
          } else {
              $js .= "&group=$value";
          }
          $js.= "', 'user_popup', 'scrollbars=1,resizable=1,width=700,height=600');"; */
          echo "<a href=\"javascript:$js;\">". _m("View Recipients"). "</a>";
      }
    echo "</td>
    </tr>";
    $checked = '';
}

function TestMailAddress($mail, &$good, &$bad) {
    global $err;
    if ( ValidateInput("mail", _m('User mail') , $mail, $err, true, 'email') ) {
        $good[] = $mail;
    } else {
        $bad[] = $mail;  // used for listing bad mails
    }
}

/** Gets list of good_mails and bad_mails form the form or boormark, ...
 *  Values returned in $good_mail and $bad_mail arrays */
function GetMails2Send($group,$testemail,&$items,&$searchbar,$slice_id,&$good_mail,&$bad_mail ) {
    global $err;
    if ( $group == 'testuser') {
        TestMailAddress($testemail, $good_mail, $bad_mail);
    } else {
        // --- get user ids ---
        if ( $group == 'user' ) {  // user specified users
            $zids = new zids(null, 'l');
            $zids->set_from_item_arr($items);
        } else {                   // user defined by bookmark
            $slice = new slice($slice_id);
            $searchbar->setFromBookmark($group);
            $conds = $searchbar->getConds();
            $zids=QueryZIDs($slice->fields('record'), $slice_id, $conds, "", "", 'ACTIVE');
        }
        // --- get user emails ---
        $content = GetItemContent($zids);
        if ( isset($content) AND is_array($content) ) {
            foreach ( $content as $content4id ) {
                $mail = $content4id[FIELDID_EMAIL][0]['value'];
                TestMailAddress($mail, $good_mail, $bad_mail);
            }
        }
    }
}


$searchbar = new searchbar();   // mainly for bookmarks

$items=$chb;

if ( !$send AND !$list ) {               // for the first time - directly from item manager
    $sess->register('r_wm_state');
    unset($r_wm_state);       // clear if it was filled
    $r_wm_state['items'] = $items;
    $lang = get_mgettext_lang();
    $html = 1;
} else {
    $items = $r_wm_state['items'];
    $good_mail = array();  // array of good (== syntax validated) mails
    $bad_mail = array();
    // --- get user's e-mails - returned in $good_mail and $bad_mail arrays --
    GetMails2Send( $group, $testemail, $items, $searchbar, $slice_id, $good_mail, $bad_mail );
    $good_mail_count = count($good_mail);
    $users_count = $good_mail_count + count($bad_mail);
    if ( $send ) {    // we really want to send email - so store template
        do {
            // --- write the e-mail template to the table ---
            $varset = new Cvarset();
            $description     = 'Bulk email '.now() . ' '. $auth->auth['uid'];
            $owner_module_id = q_pack_id($slice_id);
            $type            = 'bulk email';
            ValidateInput("subject",     _m("Subject"),            $subject,     $err, true,  "text");
            ValidateInput("body",        _m("Body"),               $body,        $err, true,  "text");
            ValidateInput("header_from", _m("From (email)"),       $header_from, $err, false, "text");
            ValidateInput("reply_to",    _m("Reply to (email)"),   $reply_to,    $err, false, "text");
            ValidateInput("errors_to",   _m("Errors to (email)"),  $errors_to,   $err, false, "text");
            ValidateInput("sender",      _m("Sender (email)"),     $sender,      $err, false, "text");
            ValidateInput("lang",        _m("Language (charset)"), $lang,        $err, false, "text");
            ValidateInput("html",        _m("Use HTML"),           $html,        $err, false, "number");

            if( count($err) > 1) break;

            $varset->addglobals( array('description', 'subject', 'body',
                                       'header_from', 'reply_to', 'errors_to', 'sender',
                                       'lang', 'owner_module_id', 'type'),
                                 'quoted');
            $varset->add('html', 'number', $html);

            $SQL = "INSERT email ". $varset->makeINSERT();
            if( !$db->tquery($SQL)) {
                $err["DB"] = MsgErr( _m("Can't change slice settings") );
                break;    # not necessary - we have set the halt_on_error
            }

            // --- write the e-mail template to the table - end ---

            $mail_id = get_last_insert_id($db, 'email');  // get mail template id

            if ( ($good_mail_count<1) OR !is_numeric($mail_id) )  {
                $err["mail"] = MsgErr( _m("No user or template set") );
                break;
            }

            // --- send emails
            $mails_sent = send_mail_from_table ($mail_id, $good_mail);
            $Msg = MsgOK(_m("Email sucessfully sent (Users: %1, Valid emails: %2, Emails sent: %3)",
                                               array($users_count, $good_mail_count, $mails_sent)));

            if ((string)$group == (string)"user") {
                $sel = "LIST";
            } elseif ((string)$group == (string)"testuser") {
                $sel = "TEST";
            } else {
                $sel = $group;
            }
            writeLog("EMAIL_SENT",array($users_count, $good_mail_count, $mails_sent),$sel);
            // remove temporary email template from database
            $SQL = "DELETE FROM email WHERE id='$mail_id'";
            if( !$db->tquery($SQL)) {
                $err["DB"] = MsgErr( _m("Can't delete email template") );
                break;    # not necessary - we have set the halt_on_error
            }
        } while(false);
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '
  <link rel=StyleSheet href="'.$AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">
  <title>'.  _m("Write email to users") .'</title>';
 IncludeManagerJavascript();
echo '
</head>
<body>
  <h1>'. _m("Bulk Email Wizard") .'</h1>
  <form name=mailform>';

PrintArray($err);
echo $Msg;

$slice = new slice($slice_id);

FrmTabCaption( (is_array($items) ? _m("Recipients") : ( _m("Stored searches for ").$slice->name()) ));
if( isset($items) AND is_array($items) ) {
    PrintRadioBookmarkRow( _m('Selected users').' ('.count($items).')', 'user');
} else {
    echo "<tr><td></td><td><b>"._m("Group Name")."</b></td><td><b>". _m("Created by"). "</td><td><b>"
         ._m("Created on"). "</b></td><td><b>". _m("Last updated") ."</b></td><td><b>"._m("Last used"). "</b></td></tr>";
    $book_arr = $searchbar->getBookmarkNames();
    if ( isset($book_arr) AND is_array($book_arr) ) {
        foreach ( $book_arr as $k => $v ) {
            $bookparams = $searchbar->getBookmarkParams($k);
            PrintRadioBookmarkRow( $v, $k, true, $bookparams);
        }
    }
}

PrintRadioBookmarkRow( '<input type="text" name="testemail" value="'.$testemail.'" size="80"> '._m('Test email address'), 'testuser', false);
/*
if ($list) {  // list users
    FrmTabSeparator( _m("User's e-mail list (Users: %1, Valid emails: %2)",
                                        array($users_count, $good_mail_count)));
    if ( isset($good_mail) AND is_array($good_mail) ) {
        foreach ( $good_mail as $mail ) {
            FrmTabRow(array($mail, _m('valid')));
        }
    }
    if ( isset($bad_mail) AND is_array($bad_mail) ) {
        foreach ( $bad_mail as $mail ) {
            FrmTabRow(array($mail, _m('invalid')));
        }
    }
}
*/
FrmTabSeparator( _m('Write the email') );

FrmInputText(  'subject',     _m('Subject'),           dequote($subject),     254, 80, true);
FrmTextarea(   'body',        _m('Body'),              dequote($body),         12, 80, true);
FrmInputText(  'header_from', _m('From (email)'),      dequote($header_from), 254, 80, false);
FrmInputText(  'reply_to',    _m('Reply to (email)'),  dequote($reply_to),    254, 80, false);
FrmInputText(  'errors_to',   _m('Errors to (email)'), dequote($errors_to),   254, 80, false);
FrmInputText(  'sender',      _m('Sender (email)'),    dequote($sender),      254, 80, false);
FrmInputSelect('lang',        _m('Language (charset)'), GetEmailLangs(),            $lang, false);
FrmInputSelect('html',        _m('Use HTML'),           array(_m('no'), _m('yes')), $html, false);

FrmTabEnd(array( 'send' =>array('type'=>'submit', 'value'=>_m('Send')),
//                 'list' =>array('type'=>'submit', 'value'=>_m('List users')),
                 'close'=>array('type'=>'button', 'value'=>_m('Close'), 'add'=>'onclick="window.close()"')),
          $sess, $slice_id);


echo '
  </form>
  <form name="itform" method="post">';
  if (is_array($items))
    foreach ($items as $key=>$it) {
      echo '<input type="hidden" name="items['.$key.']" value="">';
    }
echo '
  </form>
 </body>
</html>';
page_close();
?>


