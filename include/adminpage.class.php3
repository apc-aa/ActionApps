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

require_once __DIR__."/menu_util.php3";


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
     * displayed at the end of page or in the right column (later)
     * @param string $name
     * @param string $html
     */
    public function addHelpbox($name, $html) {
        $this->helpboxes[] = ['name'=>$name, 'html'=>$html];
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

        return $ret. '{generate:FOOT}</body></html>';
    }

    public function printHead($err, $Msg)
    {
        AA::setEncoding(AA_Langs::getCharset());
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

        if ($this->menu != '') {
            showMenu($this->menu, $this->submenu);
        }

        echo "<h1>" . $this->title . "</h1>";
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
            $html .= GetHtmlTable( [[$helpbox['html']]], 'td', $helpbox['name'], true );  // clasic aa table
        }
        if ($html) {
            $html = '<br>'. $html;
            $html = AA::Stringexpander()->unalias($html);
            $html = AA::Stringexpander()->postprocess($html);
            echo $html;
        }
    }

    public function printFoot() {
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
}


/** following classes are not used, yet - just prepared for later admin page approach */

/**
 * With AA_iEditable the object can be edited by AA_Form
*/
class AA_Adminpage implements AA_iEditable {
    protected $module_id;

    function __construct($module_id, $title) {
        $this->module_id = $module_id;
    }

    public function getName()                                { return ''; }
    public function getPerm()                                { return ''; }
    public function getId()                                  { return ''; }
    public function getOwnerId()                             { return ''; }
    public function getProperty($property_id, $default=null) { return ''; }

    protected function getCancelUrl() {
        return get_admin_url('index.php3');
    }
    protected function isPerm() {
        return IfSlPerm($this->getPerm(),$this->module_id);
    }

    public function process() {
        if ($_POST['cancel']) {
            go_url($this->getCancelUrl());
        }
        if (!$this->isPerm()) {
            MsgPageMenu($this->getCancelUrl(), _m('You have not permissions to this page').': '.$this->getName(), 'admin');
        }
        if ($_POST['update'] AND !empty($_POST['aa'])) {
            $err = [];          // error array (Init - just for initializing variable
            // update or insert
            $this->setFromForm();
            if ($this->validateData()) {
                if ($this->save()) {
                    AA::Pagecache()->invalidateFor($this->module_id);  // invalidate old cached values
                    // @todo msg ok
                    $Msg = MsgOk(_m("Fulltext format update successful"));
                } else {
                    $err["DB"] = MsgErr( _m("Can't change slice settings") );
                    // @todo msg err
                }
            } else {
                // @todo msg err
            }
        } else {
            $this->doLoad();
            huhl($this);
        }

        // Print HTML start page (html begin, encoding, style sheet, no title)
        HtmlPageBegin();
        // manager javascripts - must be included
        $html_start = '<title>'. $title .'</title>
             {generate:HEAD}
             </head>
             <body>
                <h1>' . $title .'</h1>
        ';
        $html_end = "{generate:FOOT}</body></html>";


        // print the inputform
        $form = AA_Form::factoryForm(get_called_class(), $this->module_id, $this->module_id);

        // this also fills AA::Stringexpander required libs, so we can continue with postprocess
        $out        = $form->getObjectEditHtml();
        $out        = AA::Stringexpander()->postprocess($out); // to replace  {generate:HEAD} with dynamic javascripts needed by inputform
        $html_start = AA::Stringexpander()->unalias($html_start);
        $html_start = AA::Stringexpander()->postprocess($html_start);
        $html_end   = AA::Stringexpander()->unalias($html_end);
        $html_end   = AA::Stringexpander()->postprocess($html_end);

        echo $html_start;

        showMenu("sliceadmin", "fulltext");
        PrintArray($r_err);
        PrintArray($r_msg);
        unset($r_err);
        unset($r_msg);

        echo '<form name="f" method="post" action="'. StateUrl('') .'">';

        $form_buttons = [
            "update",
                       "update" => ['type' => 'hidden', 'value'=>'1'],
                       "cancel" => ["url"=>"se_fields.php3"],
                       "default" => [
                           'type'  => 'button',
                                          'value' => _m("Default"),
                                          'add'   => 'onclick="Defaults()"'
                       ]
        ];

        FrmTabCaption(_m("Object Edit"), $form_buttons);
        // FrmHidden('ret_url', $ret_url);
        // FrmHidden('oid',     $oid);
        // FrmHidden('otype',   $otype);

        echo '<tr><td colspan="2">';
        echo $out;
        echo '</td></tr>';

        FrmTabEnd($form_buttons);
        echo '</form>';
        echo $html_end;
    }

    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows($form) {}
    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {}
    /** AA_iEditable method - save the object to the database
     *    @return string|bool - id of saved data or false
     */
    public        function save() {}
    public        function validateData() {}
}

class AA_Adminpage_Table extends AA_Adminpage {
    const DB_TABLE = '';
    protected $record;

    function __construct($module_id) {
        parent::__construct($module_id);
        $this->doLoad();
    }

    public function getId()                                  { return $this->module_id; }
    public function getOwnerId()                             { return $this->module_id; }
    public function getProperty($property_id, $default=null) { return $this->record[$property_id]; }

    /** managed table columns */
    public static function getTableColumns() { return []; }

    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows($form) {
        return $form->addProperties(static::getClassProperties());
    }

    // needed by /data/www/aaa/include/form.class.php3(339): AA_Object->getContent(Array, Object(zids))
    public static function getClassProperties()  {
        $props = AA_MetabaseTableEdit::defaultGetClassProperties(static::DB_TABLE);
        if (count($cols = static::getTableColumns())) {
            // restrict table columns
            $props = array_intersect_key($props, array_flip($cols));
        }
        return $props;
    }

    // public static function load($id, $type=null) {
    //     return new AA_Mailtemplate($id);
    // }

    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {
        $otype = get_called_class();
        return (new $otype($oowner))->setFromForm();
    }

    protected function setFromForm() {
        $grabber = new Form();
        $grabber->prepare();    // maybe some initialization in grabber
        // we expect just one form - no need to loop through contents
        $content    = $grabber->getContent();  // @todo
        //$store_mode = $grabber->getStoreMode();        // add | update | insert
        $grabber->finish();    // maybe some finalization in grabber

        $cols = static::getColumns();
        $this->record = [];
        foreach ($cols as $name) {
            $this->record[$name] = $content->getValue($name);
        }
        return $this;
    }

    public static function load($id, $type=null) {
        $otype = get_called_class();
        return (new $otype($id))->doLoad();  // or AA::$module_id ?
    }

    protected function doLoad() {
        $this->record = [];
        if ($this->module_id) {
            $content = AA_Metabase::getContent(['table' => static::DB_TABLE], new zids($this->module_id, 'l'));
            if ($content4id = $content[$this->module_id]) {
                // filter only used columns
                $cols = static::getTableColumns();

                foreach ($cols as $name) {
                    $this->record[$name] = $content4id[$name][0]['value'];
                }
            }
        }
        return $this;
    }

    /** AA_iEditable method - save the object to the database */
    public        function save() {}
}

