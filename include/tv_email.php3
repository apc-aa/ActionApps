<?php
/**
 *
 * PHP versions 4 and 5
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
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tableview.html

require_once AA_BASE_PATH."modules/alerts/util.php3";


/** get_email_types function
 *  List of email types with translated description.
 *  You should never list email types directly, always call this function.
 */
function get_email_types() {
    return array (
        "alerts alert"         => _m("alerts alert"),
        "alerts welcome"       => _m("alerts welcome"),
        "slice wizard welcome" => _m("slice wizard welcome"),
        "user template"        => _m("user template"),
        "password change"      => _m("password change"),
        "other"                => _m("other")
    );
}

/** ShowRefreshWizardJavaScript function
 *  Shows JavaScript which updates the Wizard frame, if it exists.
 */
function ShowRefreshWizardJavaScript() {
    FrmJavascript( 'if (top.wizardFrame != null) top.wizardFrame.wizard_form.submit();' );
}

/** ShowEmailAliases function
 *
 */
function ShowEmailAliases() {
    $ali[] = array (
        "group" => _m("Aliases for Alerts Alert"),
        "aliases" => array (
            "_#FILTERS_" => _m("complete filter text"),
            "_#HOWOFTEN" => _m("howoften")." (".join(", ",get_howoften_options()).")",
            "_#COLLFORM" => _m("Anonym Form URL (set in Alerts Admin - Settings)"),
            "_#UNSBFORM" => _m("Unsubscribe Form URL"),
        ));

    $ali[] = array (
        "group" => _m("Aliases for Alerts Welcome"),
        "aliases" => array (
            "_#HOWOFTEN" => _m("howoften")." (".join(", ",get_howoften_options()).")",
            "_#COLLFORM" => _m("Collection Form URL (set in Alerts Admin - Settings)"),
            "_#CONFIRM_" => _m("email confirmed"),
        ));

    // these aliases are used in include/slicewiz.php3
    $ali[] = array (
        "group" => _m("Aliases for Slice Wizard Welcome"),
        "aliases" => array (
            "_#SLICNAME" => _m("Slice name"),
            "_#NAME____" => _m("New user name"),
            "_#LOGIN___" => _m("New user login name"),
            "_#ROLE____" => _m("New user role (editor / admin)"),
            "_#ME_NAME_" => _m("My name"),
            "_#ME_MAIL_" => _m("My email")
         ));

    $ali[] = array (
        "group" => _m("Aliases for User Templates (you can use also all aliases of the user)"),
        "aliases" => array (
            "_#BODYTEXT" => _m("Slice name"),
            "_#SUBJECT_" => _m("New user name")
         ));

    $ali[] = array (
        "group" => _m("Aliases for Password Change email (you can use also all aliases of the user)"),
        "aliases" => array (
            "_#PWD_LINK" => _m("HTML link to the password change page for current user")
         ));


    echo "<br><table border=\"0\" cellspacing=\"0\" cellpadding=\"0\">";
    foreach ($ali as $aligroup) {
        echo "<tr><td class=\"tabtit\" colspan=\"2\"><b>&nbsp;".$aligroup['group']."&nbsp;</b></td></tr>";
        foreach ($aligroup["aliases"] as $alias => $desc) {
            echo "<tr><td class=\"tabtxt\">&nbsp;$alias&nbsp;</td><td class=\"tabtxt\">&nbsp;$desc&nbsp;</td></tr>";
        }
    }
    echo "</table>";
}

// Settings for emails table views
/** GetEmailTableView function
 *  see class tabledit :: var $getTableViewsFn for an explanation of the parameters
 * @param $viewID
 */
function GetEmailTableView($viewID) {
    global $slice_id;
    global $attrs_edit, $attrs_browse, $format, $langs;

    if ($viewID == "email_edit") {
        $mylangs = GetEmailLangs();
        return  array (
        "table" => "email",
        "type" => "edit",
        //"help" => _m("For help see FAQ: ")."<a target=\"_blank\" href=\"$url\">$url</a>",
        //"buttons_down" => array ("add"=>1, "update"=>1),
        "readonly" => false,
        "attrs" => $attrs_edit,
        "caption" => _m("Email template"),
        "addrecord" => false,
        "gotoview" => "email",
        "where" => GetEmailWhere(),
        "cond" => 1,
        "triggers" => array ("AfterInsert" => "EmailAfterInsert"),
        "fields" => array (
            "id" => array ("view" => array ("readonly" => true)),
            "description" => array (
                "required" => true,
                "caption" => _m("Description")),
            "type" => array (
                "required" => true,
                "caption" => _m("Email type"),
                "view" => array ("type"=>"select","source"=>get_email_types())),
            "subject" => array (
                "required" => true,
                "caption" => _m("Subject"),
                "view" => array ("type" => "area", "size" => array ("rows"=>2))),
            "body" => array (
                "required" => true,
                "caption" => _m("Body"),
                "view" => array ("type" => "area", "size" => array ("rows"=>8))),
            "header_from" => array (
                "required" => true,
                "caption" => _m("From (email)")),
            "reply_to" => array (
                "caption" => _m("Reply to (email)")),
            "errors_to" => array (
                "caption" => _m("Errors to (email)")),
            "sender" => array (
                "caption" => _m("Envelop sender (email)")),
            "lang" => array (
                "caption" => _m("Language (charset)"),
                "default" => get_mgettext_lang(),
                "view" => array ("type" => "select", "source" => $mylangs)),
            "html" => array (
                "caption" => _m("Use HTML"),
                "default" => 1,
                "view" => array ("type" => "checkbox")),
            "owner_module_id" => array (
                "caption" => _m("Owner"),
                "default" => pack_id($GLOBALS["slice_id"]),
                "view" => array ("type"=>"select","source"=>SelectModule(),"unpacked"=>true),
            )
        ));
    }

    // ------------------------------------------------------------------------------------
    // email: this view browses emails, it is currently used in Alerts module
    //        but may be added anywhere else

    if ($viewID == "email") {
        $mylangs = GetEmailLangs();
        return  array (
        "table" => "email",
        "type" => "browse",
        //"help" => _m("For help see FAQ: ")."<a target=\"_blank\" href=\"$url\">$url</a>",
        //"buttons_down" => array ("add"=>1, "update"=>1),
        "readonly" => true,
        "attrs" => $attrs_browse,
        "caption" => _m("Email templates"),
        "buttons_down" => array ("add"=>1,"delete_all"=>1),
        "buttons_left" => array ("delete_checkbox"=>1,"edit"=>1),
        "gotoview" => "email_edit",
        "cond" => 1,
        "where" => GetEmailWhere(),
        "fields" => array (
            "description" => array (
                "caption" => _m("Description")),
            "type" => array (
                "caption" => _m("Email type")),
            "subject" => array (
                "caption" => _m("Subject"), "view"=>array("maxlen"=>50)),
            "body" => array (
                "caption" => _m("Body"),
                "view" => array (
                    "maxlen" => 100,
                    "type" => "text",
                    "size" => array ("rows"=>8))),
            "header_from" => array (
                "caption" => _m("From")),
            "reply_to" => array (
                "caption" => _m("Reply to")),
            "errors_to" => array (
                "caption" => _m("Errors to")),
            "sender" => array (
                "caption" => _m("Sender"))
        ));
    }
}
/** GetEmailWhere function
 *
 */
function GetEmailWhere() {
    if (IsSuperadmin()) {
        return "(1=1)";
    }
    $myslices = GetUserSlices();
    if (is_array($myslices)) {
        reset ($myslices);
        while (list ($my_slice_id, $perms) = each ($myslices)) {
            if (strchr ($perms, PS_FULLTEXT)) {
                $restrict_slices[] = q_pack_id($my_slice_id);
            }
        }
        return "owner_module_id IN ('".join("','",$restrict_slices)."')";
    }
    return "(1=0)";
}

/** EmailAfterInsert function
 * @param $varset
 */
function EmailAfterInsert($varset) {
    ShowRefreshWizardJavaScript();
}
?>
