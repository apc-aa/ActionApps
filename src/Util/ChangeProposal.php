<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 6.6.19
 * Time: 11:01
 */

namespace AA\Util;

/** Tracks one field change
 *  Usage:
 *     new AA\Util\ChangeProposal(<item_id>, <field_id>, <normal_array_of_values>);
 *     $changes = new AA\Util\ChangeProposal($this->getId(), $field_id, $content4id->getValuesArray($field_id));
 */
class ChangeProposal {
    var $resource_id;
    var $selector;
    var $values;    // array of values

    /** AA\Util\ChangeProposal function
     * @param $resource_id
     * @param $selector
     * @param $values
     */
    function __construct($resource_id, $selector, $values) {
        $this->resource_id = $resource_id;
        $this->selector = $selector;
        $this->values = $values;
    }

    /** getResourceId function */
    function getResourceId() {
        return ($this->resource_id);
    }

    /** getSelector function */
    function getSelector() {
        return ($this->selector);
    }

    /** getValues function */
    function getValues() {
        return ($this->values);
    }
}