<?php
/**
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


use AA\IO\Grabber\Form;
use AA\Manager;
use AA\Util\Langs;

require_once __DIR__ . "/menu_util.php";


/** Admin page helper class
 *  Simplifies and unifies admin page apptroach. It uses {generate:head} for automatic script load, ...
 *  Used for slower move towards more radical AA_Adminpage approach below...
 */
class AA_Adminpageutil {
    protected $modulemenu = 'include';
    protected $menu       = '';
    protected $submenu    = '';
    protected $title      = '';
    protected $subtitle   = '';
    protected $require    = [];
    protected $form       = ['name' => 'f', 'id'=>'', 'method'=>'post', 'action' => '', 'enctype'=>'', 'onsubmit'=>''];
    protected $helpboxes  = [];

    /**
     * AA_Adminpageutil constructor.
     * @param string $menu
     * @param string $submwenu
     */
    public function __construct($menu='', $submenu='')
    {
        $this->menu    = $menu;
        $this->submenu = $submenu;
        $formsetup = "document.querySelectorAll('form').forEach(function(el) { el.addEventListener('submit', function(e) { e.target.classList.add('aa-updating'); }); });";
        $this->addRequire($formsetup, 'AA_Req_Load');
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @param string $title
     */
    public function setForm(array $settings= [])
    {
        if ( empty($settings) ) {
            $this->form = [];
        } else {
            foreach ($settings as $k => $v) {
                $this->form[$k] = $v;
            }
        }
    }

    /**
     * @param string $modulemenu
     */
    public function setModuleMenu($modulemenu)
    {
        $this->modulemenu = $modulemenu;
    }

    /**
     * @param string $subtitle
     */
    public function setSubtitle($subtitle)
    {
        $this->subtitle = $subtitle;
    }

    /**
     * @param string $class
     */
    public function printWithManager(Manager $manager) {
        $manager->performActions();

        //$zids = $manager->getZids();
        $html = $manager->getDisplayPage();

        $this->setForm();

        // this allows to automatically load codemirror in the head (printHead is called after $form->getObjectEditHtml)
        $this->printAllPage($html, $err, $Msg);
    }

    /**
     * displayed at the end of page or in the right column (later)
     * @param string $name
     * @param string $html
     */
    public function addHelpbox($name, $html, bool $packed=false) {
        $this->helpboxes[] = ['name'=>$name, 'html'=>$html, 'packed'=>$packed];
    }

    /**
     * @param string $require
     * @param string $type
     */
    public function addRequire($require, $type='')
    {
        $this->require[] = [$require, $type];
    }


    public function sendHeaders()
    {
        $headers = AA::getHeaders();
        // fix for Chrome 57 which do not allow to send HTML in POST request sometimes (ERR_BLOCKED_BY_XSS_AUDITOR) - HM 2017-04-24
        $headers['x-xss-protection'] = 'X-XSS-Protection: 0';
        AA::sendHeaders($headers);
    }

    public function getPageBegin()
    {
        $out = '<!DOCTYPE html>
<html>
<head>
  <link rel="SHORTCUT ICON" href="' . AA_INSTAL_PATH . 'images/favicon.ico">
  <meta charset="' . AA::$encoding . '">
  <title>' . $this->title . '</title>
  {generate:HEAD}
  </head>
  <body>
';
        return $out;
    }

    public function getPageEnd()
    {
        $ret ='';
        if ($this->form) {
            $ret = '</form>';
        }

        return $ret. '</div></div>{generate:FOOT}</body></html>';
    }

    public function printHead($err, $Msg)
    {
        AA::setEncoding(Langs::getCharset());
        $this->sendHeaders();

        $html_start = $this->getPageBegin();

        if ($this->require) {
            foreach ($this->require as $req) {
                AA::Stringexpander()->addRequire($req[0], $req[1]);
            }
        }
        AA::Stringexpander()->addRequire('css-aa-system');
        AA::Stringexpander()->addRequire(AA_INSTAL_PATH . ADMIN_CSS);

        if ($errtext = trim(join('<br>',(array)$err))) {
            //$errtext = 'some text';
            AA::Stringexpander()->addRequire('aa-jslib');
            AA::Stringexpander()->addRequire("AA_Message('".escape4js($errtext)."', 'err')", 'AA_Req_Load');
        };

        // this also fills AA::Stringexpander required libs, so we can continue with postprocess
        // $out        = $form->getObjectEditHtml();
        // $out        = AA::Stringexpander()->postprocess($out); // to replace  {generate:HEAD} with dynamic javascripts needed by inputform

        $html_start = AA::Stringexpander()->unalias($html_start);
        $html_start = AA::Stringexpander()->postprocess($html_start);

        echo $html_start;

        // this is old code used everywhere, so it is copied here. It should be rewritten - without globals...

        echo getMenu($this->menu, $this->submenu); // opens two divs - in all cases

        if ($this->title) {
            echo "<h1>" . $this->title . "</h1>";
        }
        if ($this->subtitle) {
            echo "<div>" . $this->subtitle . "</div>";
        }

        echo $Msg;

        // PrintArray($r_err);
        // PrintArray($r_msg);
        // unset($r_err);
        // unset($r_msg);
        if ($this->form) {
            // to remember the page scroll position in order we can return back
            AA::Stringexpander()->addRequire("document.querySelectorAll('form').forEach(function(f) { f.addEventListener('submit', function() {sessionStorage.setItem('aa_pgtop', window.pageYOffset);});})", 'AA_Req_Load');
            AA::Stringexpander()->addRequire("if (sessionStorage.getItem('aa_pgtop')>0) { window.scrollTo(0,sessionStorage.getItem('aa_pgtop')); sessionStorage.setItem('aa_pgtop', 0); }", 'AA_Req_Load');
            $frm = "\n<form";
            foreach ($this->form as $k => $v) {
                if ($k == 'action') {
                    $frm .= " $k=\"".StateUrl($v) ."\"";
                } elseif (strlen($v)) {
                    $frm .= " $k=\"$v\"";
                }
            }
            echo $frm.'>';
        }
    }


    public function printHelpbox() {
        $html = '';
        foreach ($this->helpboxes as $helpbox) {
            $html = GetHtmlTable( [[$helpbox['html']]], 'td', $helpbox['name'], true );  // classic aa table
            if ($helpbox['packed']) {
                $html = "<details><summary>".$helpbox['name']."</summary>". $html . "</details>";
            }
        }
        if ($html) {
            // not necessary to unalias - help text for aliases was executed
            // $html = AA::Stringexpander()->unalias($html);
            // $html = AA::Stringexpander()->postprocess($html);
            echo '<br>'.$html;
        }
    }

    public function printFoot() {
        $this->printHelpbox();
        $html_end = $this->getPageEnd();
        $html_end = AA::Stringexpander()->unalias($html_end);
        $html_end = AA::Stringexpander()->postprocess($html_end);
        echo $html_end;
        page_close();
    }

    public function printAllPage($body_html, $err, $Msg) {
        $this->printHead($err, $Msg);
        echo $body_html;
        $this->printFoot();
    }

    /** MsgPageMenu function
     *  Displays page with message and link to $url
     * @param $url - where to go if user clicks on Back link on this message page
     * @param $msg - displayed message
     * @param $mode - items/admin/standalone for surrounding of message
     * @param $menu
     */
    public static function MsgPageMenu($url, $msg, $mode, $menu = "") {
        global $sess;

        if (!isset($sess)) {
            require_once __DIR__ . "/locauth.php";
            pageOpen();
        }

        $apage = new AA_Adminpageutil($mode == 'admin' ? 'aaadmin' : 'sliceadmin', $menu);
        $apage->setForm();
        $apage->printHead($err, $msg);

        echo "<a href=\"$url\">" . _m("Back") . "</a>";
        $apage->printFoot();

        exit;
    }
}
