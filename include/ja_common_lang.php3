<?php
//$Id$
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

# common language file

// setup constats
define("L_SETUP_PAGE_BEGIN", 
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=EUC-JP">');
define("L_SETUP_TITLE", "AA 設定");
define("L_SETUP_H1", "AA 設定");
define("L_SETUP_NO_ACTION", "このスクリプトは、設定済のシステムでは使えません。");
define("L_SETUP_INFO1", "Welcome! Use this script to create " .
                        "the superadmin account.<p>" .
      "If you are installing a new copy of AA, press <b>Init</b>.<br>");
define("L_SETUP_INFO2", "If you deleted your superadmin account by mistake, press <b>Recover</b>.<br>");
define("L_SETUP_INIT", " Init ");  
define("L_SETUP_RECOVER", "Recover");
define("L_SETUP_TRY_RECOVER", "Can't add primary permission object.<br>" .
       "Please check the access settings to your permission system.<br>" .
       "If you just deleted your superadmin account, use <b>Recover</b>");
define("L_SETUP_USER", "超管理者アカウント");
define("L_SETUP_LOGIN", "ログイン名");
define("L_SETUP_PWD1", "パスワード");
define("L_SETUP_PWD2", "パスワードを繰り返す");
define("L_SETUP_FNAME", "名前");
define("L_SETUP_LNAME", "姓");
define("L_SETUP_EMAIL", "E-メール");
define("L_SETUP_CREATE", "作成");
define("L_SETUP_DELPERM", "Invalid permission deleted (no such user/group): ");
define("L_SETUP_ERR_ADDPERM", "Can't assign super access permission.");
define("L_SETUP_ERR_DELPERM", "Can't delete invalid permission.");
define("L_SETUP_OK", "Congratulations! The account was created.");
define("L_SETUP_NEXT", "Use this account to login and add your first slice:");
define("L_SETUP_SLICE", "スライスの追加");

// loginform language constants
define("L_LOGIN", "ようこそ!");
define("L_LOGIN_TXT", "ようこそ! ご自分を利用者名とパスワードで証明してください：");
define("L_LOGINNAME_TIP", "利用者名とメールをタイプして下さい");
define("L_SEARCH_TIP", "List is limitted to 5 users.<br>If some user is not in list, try to be more specific in your query");
define("L_USERNAME", "利用者名:");
define("L_PASSWORD", "パスワード:");
define("L_LOGINNOW", "ログインする");
define("L_BAD_LOGIN", "利用者名またはパスワードが間違っています。");
define("L_TRY_AGAIN", "もう一度ためして下さい!");
define("L_BAD_HINT", "もし、自分がタイプしたパスワードが間違えないことが確かでしたら、こちらへ e-メールをお送り下さい。 <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.");
define("LOGIN_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=EUC-JP">');
		
// scroller language constants
define("L_NEXT", "次");
define("L_PREV", "前");
define("L_BACK", "戻る");
define("L_HOME", "ホーム");

// permission language constants (perm_ldap.php3, perm_all.php3)
define("L_USER", "User");
define("L_GROUP", "Group");

// permission configuration constants um_uedit
define("L_NEW_USER", "新しい利用者");
define("L_NEW_GROUP", "新しいグループ");
define("L_EDIT_GROUP", "グループの編集");

// application not specific strings
define("NO_PICTURE_URL", $AA_INSTAL_PATH ."images/pixel_blank.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)

define("L_ALLCTGS", "All categories");
define("L_NO_SUCH_FILE", "No such file");
define("L_BAD_INC", "Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters");
define("L_SELECT_CATEGORY", "Select Category ");
define("L_NO_ITEM", "No item found");
define("L_SLICE_INACCESSIBLE", "Invalid slice number or slice was deleted");
define("L_APP_TYPE", "Slice type");
define("L_SELECT_APP", "Select slice type");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

// log texts
define( "LOG_EVENTS_UNDEFINED", "Undefined" );

// offline filling --------------
define( "L_OFFLINE_ERR_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="./'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=EUC-JP">
  </HEAD>
  <BODY>');
define( "L_OFFLINE_OK_BEGIN",L_OFFLINE_ERR_BEGIN);
define( "L_OFFLINE_ERR_END","</body></html>");
define( "L_OFFLINE_OK_END",L_OFFLINE_ERR_END);
define( "L_NO_SLICE_ID","スライスIDが定義されていません");
define( "L_NO_SUCH_SLICE","スライス IDが 良くないです");
define( "L_OFFLINE_ADMITED","このスライスをオフラインで書き込む許可がありません。");
define( "L_WDDX_DUPLICATED","Duplicated item send - skipped");
define( "L_WDDX_BAD_PACKET","Wrong data (WDDX packet)");
define( "L_WDDX_OK","項目はOKです - データベースに格納されました");
define( "L_CAN_DELETE_WDDX_FILE","ローカルなファイルを消してかまいません ");
define( "L_DELETE_WDDX"," 消す ");

// copyright message for all screens
define( "L_COPYRIGHT", 'Copyright (C) 2001 the 
						<a href="http://www.apc.org">Association for Progressive  Communications (APC)</a> 
						under the 
						<a href="http://www.gnu.org/copyleft/gpl.html">GNU General Public License</a>'); 

define("DEFAULT_CODEPAGE","EUC-JP");

# ------------------- New constants (not in other lang files ------------------
# define( ...

?>
