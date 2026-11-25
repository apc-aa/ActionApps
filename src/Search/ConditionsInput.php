<?php

declare(strict_types=1);

namespace AA\Search;

/**
 * The form control for numbered conditions (StoredConditions): a row a condition - its number, one field
 * or more (any of them may match), the operator and the value - with buttons to add a condition, add
 * a field to one and remove one (javascript/widgets/aa-conditions.js, required as aa-conditions@1).
 * It posts <name>[<number>][fields][], [operator] and [value]; StoredConditions::fromForm() reads them.
 * The rows are plain form fields in the charset of the page - no script puts the values together.
 */
final class ConditionsInput
{
    /** takes the place of the number in the names of a new row */
    private const NUMBER = '__N__';

    /**
     * @param string                $name      of the posted array
     * @param array<string, string> $fields    field id => label; a key starting with AA_OPTGROUP is the caption of a group
     * @param array<string, string> $operators operator => label, grouped the same way (AA_Operators::forSelect())
     * @param int                   $first     the number of the first condition the control adds
     */
    public static function html(string $name, StoredConditions $conditions, array $fields, array $operators, int $first = 1): string
    {
        $fields = ['' => ' '] + $fields;   // no field chosen
        $rows   = '';
        foreach ($conditions->all() as $number => $condition) {
            $rows .= self::row($name, (string) $number, $condition['fields'], $condition['operator'], $condition['value'], $fields, $operators);
        }
        return '<aa-conditions first="' . $first . '">' . $rows
            . '<template>' . self::row($name, self::NUMBER, [''], 'LIKE', '', $fields, $operators) . '</template>'
            . '<button type="button" data-add>+ ' . safe(_m('Add a condition')) . '</button>'
            . '</aa-conditions>';
    }

    /**
     * @param list<string>          $chosen
     * @param array<string, string> $fields
     * @param array<string, string> $operators
     */
    private static function row(string $name, string $number, array $chosen, string $operator, string $value, array $fields, array $operators): string
    {
        $base    = $name . '[' . $number . ']';
        $selects = '';
        foreach ($chosen ?: [''] as $field) {
            $selects .= getFrmSelectEasy($base . '[fields][]', $fields, $field);
        }
        return '<div class="aa-conditions-row" data-number="' . safe($number) . '"><b class="aa-conditions-number">' . safe($number) . '</b> '
            . '<span class="aa-conditions-fields">' . $selects
            . '<button type="button" data-add-field title="' . safe(_m('Another field - the condition holds when any of the fields matches')) . '">+</button></span> '
            . getFrmSelectEasy($base . '[operator]', $operators, $operator)
            . ' <input type="text" name="' . safe($base) . '[value]" size="40" maxlength="' . (StoredConditions::VALUE_MAX - 1) . '" value="' . safe($value) . '">'
            . ' <button type="button" data-remove title="' . safe(_m('Remove the condition')) . '">&times;</button></div>';
    }
}
