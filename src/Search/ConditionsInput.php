<?php

declare(strict_types=1);

namespace AA\Search;

/**
 * The form control for named conditions (StoredConditions): a row a condition - its name, one field
 * or more (any of them may match), the operator and the value - with buttons to add a condition, add
 * a field to one and remove one (javascript/widgets/aa-conditions.js, required as aa-conditions@1).
 * It posts <name>[<row>][name], [fields][], [operator] and [value]; StoredConditions::fromForm() reads them.
 * The rows are plain form fields in the charset of the page - no script puts the values together.
 */
final class ConditionsInput
{
    /** as the value of the conditions 1 - 3 of a view */
    private const VALUE_MAXLENGTH = 254;

    /** in a new row: takes the place of its key and of its name - the next free number */
    private const NUMBER = '__N__';

    /**
     * @param string                $name      of the posted array
     * @param array<string, string> $fields    field id => label; a key starting with AA_OPTGROUP is the caption of a group
     * @param array<string, string> $operators operator => label, grouped the same way (AA_Operators::forSelect())
     * @param int                   $first     the number the first condition the control adds gets for its name
     */
    public static function html(string $name, StoredConditions $conditions, array $fields, array $operators, int $first): string
    {
        $fields = ['' => ' '] + $fields;   // no field chosen
        $rows   = '';
        foreach ($conditions->all() as $condition_name => $condition) {
            $rows .= self::row($name, (string) $condition_name, $condition['fields'], $condition['operator'], $condition['value'], $fields, $operators);
        }
        return '<aa-conditions first="' . $first . '">' . $rows
            . '<template>' . self::row($name, self::NUMBER, [''], 'LIKE', '', $fields, $operators) . '</template>'
            . '<button type="button" data-aa-add>+ ' . safe(_m('Add a condition')) . '</button>'
            . '</aa-conditions>';
    }

    /**
     * @param string                $condition the name of the condition - also the key of its row: names differ
     * @param list<string>          $chosen
     * @param array<string, string> $fields
     * @param array<string, string> $operators
     */
    private static function row(string $name, string $condition, array $chosen, string $operator, string $value, array $fields, array $operators): string
    {
        $base    = $name . '[' . $condition . ']';
        $selects = '';
        foreach ($chosen as $field) {
            $selects .= getFrmSelectEasy($base . '[fields][]', $fields, $field);
        }
        $name_hint = safe(_m('Name of the condition: a number, or up to 6 small letters and digits'));
        return '<div class="aa-conditions-row">'
            . '<input type="text" name="' . safe($base) . '[name]" value="' . safe($condition) . '" size="' . StoredConditions::NAME_MAXLENGTH . '" maxlength="' . StoredConditions::NAME_MAXLENGTH
            . '" pattern="' . StoredConditions::NAME_PATTERN . '" data-aa-name title="' . $name_hint . '" aria-label="' . $name_hint . '"> '
            . '<span class="aa-conditions-fields">' . $selects
            . '<button type="button" data-aa-add-field title="' . safe(_m('Another field - the condition holds when any of the fields matches')) . '">+</button></span> '
            . getFrmSelectEasy($base . '[operator]', $operators, $operator)
            . ' <input type="text" name="' . safe($base) . '[value]" size="40" maxlength="' . self::VALUE_MAXLENGTH . '" value="' . safe($value) . '">'
            . ' <button type="button" data-aa-remove title="' . safe(_m('Remove the condition')) . '">&times;</button></div>';
    }
}
