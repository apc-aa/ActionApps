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
    protected $resource_id;
    protected $selector;
    protected $values;    // array of values

    /** AA\Util\ChangeProposal function
     * @param string $resource_id
     * @param string $selector
     * @param array $values
     */
    function __construct(string $resource_id, string $selector, array $values) {
        $this->resource_id = $resource_id;
        $this->selector = $selector;
        $this->values = $values;
    }

    /** getResourceId function */
    function getResourceId(): string
    {
        return $this->resource_id;
    }

    /** getSelector function */
    function getSelector(): string
    {
        return $this->selector;
    }

    /** getValues function */
    function getValues(): array
    {
        return $this->values;
    }
}