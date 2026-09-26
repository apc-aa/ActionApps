<?php

use AA\FormArray;
use AA\IO\Grabber\ObjectForm;
use AA\Managed;
use AA\Util\NamedInterface;
use AA\Widget\Widget;
use AA\Widget\HidWidget;

/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php)
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


class AA_Formrow extends AA_Storable
{
    public function getRowProperty(): ?AA_Property
    {
        return null;
    }

    /**
     * @return AA\Widget\Widget|null
     */
    public function getWidget()
    {
        return null;
    }

    /**
     * @param  AA_Content  $content
     * @param  string      $type
     *
     * @return string
     * @throws Exception
     */
    public function getRowHtml(AA_Content $content, string $type = ''): string
    {
        $widget = $this->getWidget();
        // $widget->registerRequires(); // now in _finalizeHtml
        $prop = $this->getRowProperty();
        return match ($type) {
            'ajax'  => $widget->getAjaxHtml($prop, $content),
            'live'  => $widget->getLiveHtml($prop, $content),
            'tr'    => $widget->stdHtml($prop, $content),
            default => $widget->getHtml($prop, $content),
        };

        $this->echoo($widget->stdFormStart($aa_property));

        if (is_null($item) || $item->isEmpty()) {
            $this->echovar($field->getWidgetNewHtml(null, null, null, null, $this->getAaValue(), null, true));
        } else {
            // another approach would be, but it had troubles in InfWidget with {manager:...{_#ITEM_ID_}...}
            //$content = (new AA_Content() )->setOwnerId($slice_id)->setId($item->getId())->setAaValue($this->id, $this->getAaValue());
            $content = $item->getItemContent();

            $this->echovar($widget->getOnlyHtml($aa_property, $content));
        }
        //        if (is_null($item)) {
        //            $this->echovar(AA::Un('{inputonly:'.$slice_id.':'.$this->id.'}'));
        //        } else {
        //            $this->echovar(AA::Un('{edit:'.$item->getId().':'.$this->id.'}','',$item));
        //        }
        $this->echoo($widget->stdFormEnd($aa_property));



    }

    // in order we can display row widget (used for editing forms)
    public static function addFormrows($form): void
    {
        $slice  = AA_Slice::getModule($form->object_owner);
        $fields = $slice->getFields();
        $f_arr  = $fields->getNameArray();
        // AA_Property($id='', $name='',              $type='text', $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_HTML, $perms=null, $default=null) {
        $p = new AA_Property('rows', _m("Fields to show"), 'AA_Formrow_Field', true, false);
        $w = new FormrowWidget(['const_arr' => $f_arr]);
        //$w = new AA\Widget\MchWidget(array('columns'=>1,'const_arr' => $f_arr));
        $form->addRow(new AA_Formrow_Full($p, $w));  // use default widget for the field
    }
}

class AA_Formrow_Text extends AA_Formrow
{
    protected $text;        // protected - we need the data visible for AA_Statestore
    public function __construct($text = null) // default values are needed for AA_Storable's construction
    {
        $this->text = $text;
    }
    public function getRowHtml(AA_Content $content, string $type = ''): string
    {
        return '<div>' . $this->text . '</div>';
    }
}

/** Raw HTML row — emits text verbatim with no surrounding <div>.
 *  Useful when the text itself opens or closes a block element that must
 *  span multiple form rows (e.g. <details>…</details> grouping).
 */
class AA_Formrow_Html extends AA_Formrow
{
    protected $html;
    public function __construct($html = null)
    {
        $this->html = (string) $html;
    }
    public function getRowHtml(AA_Content $content, string $type = ''): string
    {
        return $this->html;
    }
}

/** Fully qualified form row */
class AA_Formrow_Full extends AA_Formrow
{
    protected $property;        // protected - we need the data visible for AA_Statestore
    protected $widget;

    /** Constructor - use the default for AA_Object */
    public function __construct($property = null, $widget = null) // default values are needed for AA_Storable's construction
    {
        $this->property       = $property;
        $this->widget         = $widget;
    }

    public function getRowProperty(): ?AA_Property
    {
        return $this->property;
    }

    public function getWidget()
    {
        return $this->widget;
    }

    /**
     * @return array
     */
    public static function getClassProperties(): array
    {
        return [                   //       id                   name                  type        multi  persist validator, required, help, morehelp, example
            'property'       => new AA_Property('property', _m("Property"), 'AA_Property', false, true),
            'widget'         => new AA_Property('widget', _m("Widget"), 'AA\Widget\Widget', false, true),
        ];
    }
}

class AA_Formrow_Field extends AA_Formrow
{
    protected $field_id;        // protected - we need the data visible for AA_Statestore
    protected $slice_id;        // protected - we need the data visible for AA_Statestore

    /** Constructor - use the default for AA_Object */
    public function __construct($field_id = '', $slice_id = '') // default values are needed for AA_Storable's construction
    {
        $this->field_id       = $field_id;
        $this->slice_id       = $slice_id;
    }

    public function getRowProperty(): ?AA_Property
    {
        $field  = $this->_getField();
        $widget = $field?->getWidget();
        return $field?->getAaProperty(['multiple' => $widget::multiple()]);
    }

    public function getWidget()
    {
        return $this->_getField()?->getWidget();
    }

    private function _getField(): ?AA_Field
    {
        return AA_Slice::getModule($this->slice_id)?->getFields()->getField($this->field_id);
    }

    /**
     * @return array
     */
    public static function getClassProperties(): array
    {
        return [                   //       id                   name               type        multi  persist validator, required, help, morehelp, example
            'field_id'       => new AA_Property('field_id', _m("Field"), 'string', false, true),
            'slice_id'       => new AA_Property('slice_id', _m("Slice ID"), 'string', false, true),
        ];
    }
}



/** Form Row - special widget for form setting */
class FormrowWidget extends Widget
{
    /** Constructor - use the default for AA_Object */
    // function __construct($params=array()) { parent::__construct($params); }  // not needed - called as default

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */

    /** name function */
    public static function name(): string
    {
        return _m('Form Row - special widget for form setting');
    }   // widget name

    /** returns if the widget handles multivalue or just single value   */
    public static function multiple(): bool
    {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array
    {
        return [                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const_arr'              => new AA_Property('const_arr', _m("Values array"), 'string', true, true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
        ];
    }


    /** Creates base widget HTML, which will be surrounded by Live, Ajax
     *  or normal decorations (added by _finalize*Html)
     *
     * @param  AA_Property  $aa_property
     * @param  AA_Content   $content
     * @param  string       $type  normal|live|ajax
     *
     *  @return array
     *  @throws Exception
     */
    public function _getRawHtml(AA_Property $aa_property, AA_Content $content, string $type = 'normal'): array
    {
        // $property_id  = $aa_property->getId();
        // $input_name   = AA\FormArray::getName4Form($property_id, $content)."[formrow][]";

        $base_name   = FormArray::getName4Form($aa_property->getId(), $content);
        $input_name  = $base_name . "[]";
        $base_id     = FormArray::formName2Id($base_name);
        // $widget_add  = ($type === 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id')\"" : '';

        $ser_values   = $content->getValuesArray($aa_property->getId());
        $arr          = [];
        foreach ($ser_values as $ser_row) {
            $row   = unserialize($ser_row, ['allowed_classes' => [AA_Formrow::class, AA_Formrow_Text::class, AA_Formrow_Html::class, AA_Formrow_Full::class, AA_Formrow_Field::class]]);
            $field = $row->getRowProperty();
            $arr[] = $field->getId();
        }

        $fields    = new AA_Content();
        $selected  = new AA_Value($arr);
        $fields->setAaValue($aa_property->getId(), $selected);
        $widget    = "<select name=\"$input_name\" id=\"$base_id\" multiple size=20>";
        $options   = $this->getOptions($selected, $fields, false, false, $aa_property->isRequired() ? 2 : 1);
        $widget   .= $this->getSelectOptions($options);
        $widget   .= "</select>";

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @inheritDoc
     */
    public static function description(): string
    {
        return ''; // TODO: Implement description() method.
    }
}


/** Form manipulating class
 *  The code could be a bit confusing since this class manages forms, but the
 *  forms are also manageable as form - that's why it must implement some
 *  AA_Object's methods */
class AA_Form extends AA_Object implements NamedInterface
{
    /** @var AA_Formrow[] */
    protected $rows = [];

    public $object_type  = '';  // for which object class is current form
    public $object_id    = '';  // for which object is current form
    public $object_owner = '';  // to whom the form belongs

    public const PREPARED = 1;
    public const SAVED    = 2;
    public const REFUSED  = 3;

    /** why process() did not save the posted data */
    protected string $error = '';

    /** allows storing form in database
     *  AA_Object's method
     * @return array
     */
    public static function getClassProperties(): array
    {
        return [          //           id       name       type        multi  persist validator, required, help, morehelp, example
            'rows'        => new AA_Property('rows', _m("Rows"), 'AA_Formrow', true, true),
            // 'object_type' => new AA_Property( 'object_type', _m("Object Type"),  'text', false, true)
        ];
    }

    /**
     * @inheritDoc
     * needed as title to Alias manager page /modules/site/aliases.php
     */
    public static function name(): string
    {
        return _m('Forms');
    }

    /**
     * @inheritDoc
     */
    public static function description(): string
    {
        return '';
    }

    /** @inheritDoc */
    protected static function getAdminUrlTemplate(): string
    {
        return get_admin_url('se_forms.php', ['id=_#AA_ID___','module_id=_#AA_OWNER']);
    }

    /** add default property's row(s) for all props in array
     * @param AA_Property[] $props
     * @return $this
     * @throws Exception
     */
    public function addProperties(array $props): static
    {
        foreach ($props as $prop) {
            $prop->addPropertyFormrows($this);
        }
        return $this;
    }

    public function addRow(AA_Formrow $row): void
    {
        $this->rows[] = $row;
    }

    public function setObject($otype, $oid, $oowner): void
    {
        $this->object_type  = $otype;
        $this->object_id    = $oid;
        $this->object_owner = $oowner;
    }

    public static function factoryForm($otype, $oid, $oowner): AA_Form
    {
        $form = new AA_Form();
        $form->setObject($otype, $oid, $oowner);
        $otype::addFormrows($form);
        return $form;
    }

    /** saves the posted data into the object the form was built for (a new one, when none) - see getError() */
    public function process($aa): int
    {
        if (!empty($aa)) {
            // update or insert
            $managed     = Managed::factoryFromType($this->object_type);
            $this->error = (string) $managed->save($this->object_owner, $this->object_id);
            return ($this->error === '') ? self::SAVED : self::REFUSED;
        }
        return self::PREPARED;
    }

    /** why the last process() refused the posted data; '' - it did not */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getObjectEditHtml(bool $std_table = false): string
    {
        $content = $this->loadContent();

        if ($std_table) {
            $html    = $this->getRowsHtml($content, 'tr');
            $html   .= (new HidWidget())->stdHtml(AA_Object::getPropertyObject('aa_owner'), $content);
            $html   .= (new HidWidget())->stdHtml(AA_Object::getPropertyObject('aa_type'), $content);

            if ($this->object_id) {
                $html .= (new HidWidget())->stdHtml(AA_Object::getPropertyObject('aa_id'), $content);
            }
        } else {
            $html = $this->getRowsHtml($content, $std_table ? 'tr' : '');
            $html .= (new HidWidget())->getHtml(AA_Object::getPropertyObject('aa_owner'), $content);
            $html .= (new HidWidget())->getHtml(AA_Object::getPropertyObject('aa_type'), $content);

            if ($this->object_id) {
                $html .= (new HidWidget())->getHtml(AA_Object::getPropertyObject('aa_id'), $content);
            }
        }
        return $html;
    }

    /** Add item form
     * @param string $ret_code
     * @param string $type
     * @return string
     * @throws Exception
     */
    public function getAjaxHtml($ret_code, $type = 'add'): string
    {
        if (!isset($this->object_type)) {
            $this->object_type = 'AA_Item';
        } // older forms stored in database do not have this field set
        $this->object_owner = $this->getOwnerId();                          // slice_id is the same as the slice_id of the form (where it is defined)

        $id  = get_if($this->object_id, new_id());
        $content = $this->loadContent();

        $html  = "\n  <fieldset id=\"form$id\">";
        $html .= "\n    " . '<input id="inline" name="inline" value="1" type="hidden">';
        $html .= "\n    " . '<input id="slice_id" name="slice_id" value="' . $this->object_owner . '" type="hidden">';
        $html .= "\n    " . '<input id="ret_code" name="ret_code" value="' . $ret_code . '" type="hidden">';

        $html .= $this->getRowsHtml($content);

        switch ($type) {
            case 'add':      $html .= "\n    <input id=\"ajaxsend$id\" name=\"ajaxsend$id\" value=\"" . _m('Insert') . "\" onclick=\"AA_AjaxSendAddForm('form$id')\" type=\"button\">";
                break;
            case 'inplace':  $html .= "\n    <input id=\"ajaxsend$id\" name=\"ajaxsend$id\" value=\"" . _m('Insert') . "\" onclick=\"AA_AjaxSendForm('form$id', 'filler.php')\" type=\"button\">";
                break;
        }
        $html .= "\n  </fieldset>";

        return $html;
    }

    /** Edit item form
     *
     * @param  string  $type  ajax|live
     *
     * @return string
     * @throws Exception
     */
    public function getEditFormHtml(string $type = 'live'): string
    {
        if (!$this->object_id) {
            return '';
        }
        $content = $this->loadContent();

        $html  = "\n  <fieldset id=\"form" . $this->object_id . "\">";
        $html .= $this->getRowsHtml($content, $type);
        $html .= "\n  </fieldset>";

        return $html;
    }

    /** @param AA_Content $content
     * @param  string  $type  normal|ajax|live
     *
     * @return string
     * @throws Exception
     *@todo   write permission function, which modifies the form based on the
     *        actual user's permissions and profile
     */
    public function getRowsHtml(AA_Content $content, string $type = ''): string
    {
        $ret = '';
        foreach ($this->rows as $row) {
            $ret .= $row->getRowHtml($content, $type);
        }
        return $ret;
    }

    /**
     * @throws \RuntimeException
     * @return AA_Content
     */
    private function loadContent(): AA_Content
    {
        if (empty($this->object_id)) {
            $content = (new AA_Content())->setOwnerId($this->object_owner);
            // Pre-populate the empty form with each property's default so the
            // Add form shows defaults (not just relies on them at insert time).
            foreach ($this->rows as $row) {
                $prop = $row->getRowProperty();
                if ($prop && ($default = $prop->getDefault()) && !$default->isEmpty()) {
                    $content->setAaValue($prop->getId(), $default);
                }
            }
        } else {
            $managed  = Managed::factoryFromType($this->object_type);
            $contents = $managed->getContent(new zids($this->object_id, $managed->getZidsType()));

            // $contents = call_user_func_array([$this->object_type, 'getContent'], [['class'=>$this->object_type, 'owner'=>$this->object_owner], new zids($this->object_id)]);
            //$contents = AA_Object::getContent(['class'=>$this->object_type], new zids($this->object_id));
            if (empty($contents) || !isset($contents[$this->object_id])) {
                throw new \RuntimeException("Content for object {$this->object_type} with id {$this->object_id} not found.");
            }
            $content = $contents[$this->object_id];
        }
        $content->setAaValue('aa_type', new AA_Value($this->object_type));
        return $content;
    }

    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype = null): AA_Form
    {
        $grabber = new ObjectForm();
        $grabber->prepare();    // maybe some initialization in grabber
        // we expect just one form - no need to loop through contents
        $content    = $grabber->getItem();
        // while ($content = $grabber->getContent())
        // $store_mode = $grabber->getStoreMode();        // add | update | insert
        $grabber->finish();    // maybe some finalization in grabber

        // specific part for form
        $object = new AA_Form();
        $object->setId($content->getId());
        $object->setOwnerId($oowner);
        $object->setName($content->getName());
        $object->setObject('AA_Item', '', $oowner);

        $fields2show = $content->getValuesArray('rows');
        foreach ($fields2show as $field) {
            $object->addRow(new AA_Formrow_Field($field, $oowner));   // use default widget for the field (@todo - make possible to change the widget)
        }
        return $object;
    }

    /**
     * @return string
     */
    public function getObjectOwner(): string
    {
        return $this->object_owner;
    }

    /** AA_iEditable method - adds Object's editable properties to the $form */
    // public static function addFormrows($form);

    /** AA_iEditable method - save the object to the database */
    // public        function save(): false|string;
}
