<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:31
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;
use datectrl;

/** Date widget */
class DteWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Date');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['start_year', 'end_year', 'relative', 'show_time']);
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        // time parameter==date or all is totally unset - use alternative
        if (($this->getProperty('show_time',0)==2) OR (!$this->getProperty('relative',0) AND !$this->getProperty('start_year',0) AND !$this->getProperty('end_year',0) AND !$this->getProperty('show_time',0)))  {
            return $this->_getRawHtml_dateinput($aa_property, $content, $type);
        }

        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $base_name_add = $base_name . '[dte]';
        $widget_add = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';

        $widget = '';

        $delim = '';
        $from_now = $this->getProperty('relative', 0);
        $y_range_minus = $this->getProperty('start_year', $from_now ? 1  : date('Y')-1);
        $y_range_plus = $this->getProperty('end_year',    $from_now ? 10 : date('Y')+10);
        $display_time = $this->getProperty('show_time', 0);

        $datectrl = new datectrl('', $y_range_minus, $y_range_plus, $from_now, $display_time, $aa_property->isRequired());

        $value = $content->getAaValue($aa_property->getId());
        $count = max($value->count(), 1);
        for ($i = 0; $i < $count; ++$i) {
            $datectrl->setdate_int($value->getValue($i));
            $input_name = $base_name_add . "[d][$i]";
            $input_id = FormArray::formName2Id($input_name);
            $widget .= $delim . "\n<select name=\"$input_name\" id=\"$input_id\"$widget_add>" . $datectrl->getDayOptions() . "</select>";
            $input_name = $base_name_add . "[m][$i]";
            $input_id = FormArray::formName2Id($input_name);
            $widget .= $delim . "\n<select name=\"$input_name\" id=\"$input_id\"$widget_add>" . $datectrl->getMonthOptions() . "</select>";
            $input_name = $base_name_add . "[y][$i]";
            $input_id = FormArray::formName2Id($input_name);
            $widget .= $delim . "\n<select name=\"$input_name\" id=\"$input_id\"$widget_add " . ($aa_property->isRequired() ? 'required' : '') . ">" . $datectrl->getYearOptions() . "</select>";
            if ($datectrl->isTimeDisplayed()) {
                $input_name = $base_name_add . "[t][$i]";
                $input_id = FormArray::formName2Id($input_name);
                $widget .= $delim . "\n<input type=\"text\" size=\"8\" maxlength=\"8\" value=\"" . $datectrl->getTimeString() . "\" name=\"$input_name\" id=\"$input_id\"$widget_add>";
            }
            $delim = '<br />';
        }

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /** Alternative to classical selectbox-based widget using HTML5 input type=date
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml_dateinput($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $base_name_add = $base_name . '[dte]';
        $widget_add = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';

        $widget = '';
        $delim = '';
        $attrs = ['type' => 'date'];

        $from_now = $this->getProperty('relative', 0);
        if ($start_year = $this->getProperty('start_year', 0)) {
            $attrs['min'] = $from_now ? ((date('Y') - $start_year) . '-01-01') : ( is_numeric($start_year) ? "$start_year-01-01" : date('Y-m-d', strtotime($start_year)));
        }
        if ($end_year  = $this->getProperty('end_year', 0)) {
            $attrs['max'] = $from_now ? ((date('Y') + $end_year) . '-12-31') : ( is_numeric($end_year) ? "$end_year-12-31" : date('Y-m-d', strtotime($end_year)));
        }
        // $display_time = $this->getProperty('show_time', 0);

        $required = $aa_property->isRequired() ? ' required' : '';

        $attrs['pattern']     = '[0-9]{4}-[0-9]{2}-[0-9]{2}';
        $attrs['placeholder'] = 'yyyy-mm-dd';
        //$attrs = array_merge($attrs, $aa_property->getValidator()->getHtmlInputAttr());

        $value = $content->getAaValue($aa_property->getId());
        if ($value->isEmpty()) {
            $value->addValue('');   // display empty field at least
        }

        $count = max($value->count(), 1);
        for ($i = 0; $i < $count; ++$i) {
            $input_name = $base_name_add . "[i][$i]";
            $input_id   = FormArray::formName2Id($input_name);
            $val_attr   = ($val = $value->getValue($i)) ? date('Y-m-d',$val) : '';

            $curr_attrs = $attrs;

            // move the boundaries if the current value is outside
            if ($val_attr) {
                if (isset($curr_attrs['max']) and (strtotime($curr_attrs['max']) < strtotime($val_attr))) {
                    $curr_attrs['max'] = $val_attr;
                }
                if (isset($curr_attrs['min']) and (strtotime($curr_attrs['min']) > strtotime($val_attr))) {
                    $curr_attrs['min'] = $val_attr;
                }
            }
            $attr_string = join(' ', array_map(function ($k, $v) { return "$k=\"$v\""; }, array_keys($attrs), $curr_attrs));

            $widget    .= $delim . "\n<input $attr_string name=\"$input_name\" value=\"$val_attr\" id=\"$input_id\"$widget_add{$required}>";
            $delim      = '<br>';
        }

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }



    /** @return AA_Value for the data send by the widget
     *   This is compound widgets, which consists from more than one input, so
     *   the inputs looks like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
     *   where "dte" points to the AA\Widget\DteWidget.
     *
     *   This method AA\Widget\DteWidget::getValue() is called to grab the value
     *   (or multivalues) from the submitted form
     *
     * @param $data4field - array('y'=>array(), 'm'=>array(), 'd'=>array(), 't'=>array())  - for select based date OR ...
     *                      array('i'=>array())                                            - for <input type=date> based date
     */
    public static function getValue($data4field): AA_Value
    {
        $values = [];

        // special case - we use <input type=date
        $input_date = (array)$data4field['i'];
        if ($input_date) {
            foreach ($input_date as $dte) {
                $values[] = strtotime($dte);
            }
        } else {
            $years = (array)$data4field['y'];
            $months = (array)$data4field['m'];
            $days = (array)$data4field['d'];
            $times = (array)$data4field['t'];

            // date could be also multivalue
            $max = max(count($years), count($months), count($days), count($times));

            for ($i = 0; $i < $max; ++$i) {
                // no date
                if (isset($years[$i]) AND !(int)$years[$i]) {
                    $values[] = 0;
                    continue;
                }
                // check if anything is filled
                if (!(int)$years[$i] AND !(int)$months[$i] AND !(int)$days[$i] AND !$times[$i]) {
                    continue;
                }
                $year = $years[$i] ? $years[$i] : date('Y'); // specified year or current
                $month = $months[$i] ? $months[$i] : 1;         // specified month or January
                $day = $days[$i] ? $days[$i] : 1;         // specified day or 1st
                $time = explode(':', $times[$i] ? $times[$i] : "0:0:0");         // specified time or midnight

                $values[] = mktime($time[0], $time[1], $time[2], (int)$month, (int)$day, (int)$year);
            }
        }

        return new AA_Value($values);
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}