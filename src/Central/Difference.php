<?php

declare(strict_types=1);

namespace AA\Central;

use AA_Definition_Module;
use AA_Identifier;
use AA_Metabase;
use AA_Sync_Action;

/** One difference Central found between a template slice and a destination slice (a row of
 *  central/synchronize3.php), with the AA_Sync_Actions offered for it.
 *  Built and printed on Central only - never sent to another AA nor queued. */
class Difference
{
    private string $description;
    private string $type;           // INFO | DIFFERENT_ROW | DIFFERENT_VALUE | NEW | DELETED
    /** Views and e-mails are numbered by each AA itself, so the number pairs nothing - they are
     *  paired by the first non-empty of these columns (field3 is the name {view.php?vid=} finds),
     *  case-sensitively, as AA finds a named view. */
    private const MATCH_COLUMNS = ['view' => ['field3', 'name'], 'email' => ['description']];

    /** array of AA_Sync_Actions defining, what we can do with this difference */
    private array $actions;
    /** false - the actions are offered unchecked (see AA_Sync_Action::printToForm()) */
    private bool $preselect;

    public function __construct(string $type, string $description, $actions = [], bool $preselect = true)
    {
        $this->type        = $type;
        $this->description = $description;
        $this->actions     = empty($actions) ? [] : (is_array($actions) ? $actions : [$actions]);
        $this->preselect   = $preselect;
    }

    public function printOut(): void
    {
        echo "\n<tr class=\"diff_" . strtolower($this->type) . "\"><td>" . $this->description . '</td><td>';
        foreach ($this->actions as $action) {
            $action->printToForm($this->preselect);
        }
        echo '</td></tr>';
    }

    /// Static

    /** What AA_Definition_Module_Slice::compareWith() returns - the template slice (its data and id)
     *  against the destination definition, table by table (AA_Sync_Action::TABLES)
     *  @return Difference[] - an INFO row alone when a side is missing; an empty destination gives NEW rows */
    public static function compareSlice($data, $module_id, $dest_def): array
    {
        // nothing loaded (unknown id, remote error) - comparing would offer pre-checked DELETEs of the destination slice
        if (empty($data['module']) || empty($data['slice'])) {
            return [new Difference('INFO', _m('Template slice %1 has no definition', [safe((string) $module_id)]))];
        }
        if (!($dest_def instanceof AA_Definition_Module)) {
            return [new Difference('INFO', _m("Comparison slice (%1) does not exist", [safe((string) $module_id)]))];
        }
        /** @todo check the state, when the name contains "->" */
        $dest_module_id = $dest_def->getId();
        $dest_data      = $dest_def->getArray();
        $diff           = [];
        foreach (AA_Sync_Action::TABLES as $table) {
            $template    = $data[$table] ?? null;
            $destination = $dest_data[$table] ?? null;
            $identifier  = new AA_Identifier($dest_module_id, $table);
            if ($table === 'module' || $table === 'slice') {  // one row per module - compare just its data
                $template    = is_array($template) ? reset($template) : null;
                $destination = is_array($destination) ? reset($destination) : null;
                $identifier  = new AA_Identifier($dest_module_id, $table, $dest_module_id);
            }
            $ignore = array_merge([AA_Metabase::getModuleFields()[$table] ?? null], AA_Sync_Action::INSTALLATION_COLUMNS[$table] ?? []);
            $diff   = array_merge($diff, isset(self::MATCH_COLUMNS[$table])
                ? self::compareByName($template, $destination, $identifier, $ignore)
                : self::compareArray($template, $destination, $identifier, $ignore));
        }
        return $diff;
    }

    /** Compares the rows of a MATCH_COLUMNS table pair by pair - each pair under the destination's
     *  number (the one an UPDATE writes), a template-only row under its template number - an INSERT
     *  needs one, but it is not a number in the destination (a current AA numbers the row itself,
     *  an older one fails when the number is taken).
     *  A row without a name, or with a name repeated on either side, could pair with the wrong
     *  row - it is not offered at all, just reported. */
    private static function compareByName($template, $destination, AA_Identifier $identifier, array $ignore): array
    {
        if (!is_array($template) && !is_array($destination)) {
            return self::compareArray($template, $destination, $identifier, $ignore);
        }
        $table    = $identifier->getTable();
        $diff     = [];
        if (!is_array($template)) {
            // a failed load must not look like a slice whose rows are all to be deleted
            $diff[] = new Difference('INFO', _m('The template sent no %1 rows', [$table]));
        }
        $t_ids    = self::idsByName($table, is_array($template) ? $template : []);
        $d_ids    = self::idsByName($table, is_array($destination) ? $destination : []);
        $ignore[] = 'id';
        foreach (array_keys($t_ids + $d_ids) as $name) {
            $t_id = $t_ids[$name] ?? [];
            $d_id = $d_ids[$name] ?? [];
            if ($name === '' || count($t_id) > 1 || count($d_id) > 1) {
                $why = ($table === 'view') ? _m('no unique named view or name') : _m('no unique description');
                foreach ([[_m('Template'), $t_id], [_m('Destination'), $d_id]] as [$side, $ids]) {
                    foreach ($ids as $id) {
                        $diff[] = new Difference('INFO', _m("%1: %2 %3 '%4' not compared - %5", [$side, $table, safe((string) $id), safe((string) $name), $why]));
                    }
                }
                continue;
            }
            $row  = new AA_Identifier($identifier->getModuleId(), $table, $d_id[0] ?? $t_id[0]);
            $diff = array_merge($diff, self::compareArray($t_id ? $template[$t_id[0]] : null, $d_id ? $destination[$d_id[0]] : null, $row, $ignore));
        }
        if (!$diff) {
            $idf    = safe($identifier->toString());
            $diff[] = new Difference('INFO', is_array($destination) ? _m('%1 are identical', [$idf]) : _m('%1 is not array in destination slice', [$idf]));
        }
        return $diff;
    }

    /** @return array<string, list<int|string>> the row ids by their name - "column:value", so that a
     *  named view never pairs with a view named only by its name ('' for no name). The import into an
     *  existing slice pairs by it too (ExistingSlices). */
    public static function idsByName(string $table, array $rows): array
    {
        $ids = [];
        foreach ($rows as $id => $row) {
            $name = '';
            foreach (self::MATCH_COLUMNS[$table] as $column) {
                $value = (is_array($row) && is_scalar($row[$column] ?? null)) ? (string) $row[$column] : '';
                if ($value !== '') {
                    $name = "$column:$value";
                    break;
                }
            }
            $ids[$name][] = $id;
        }
        return $ids;
    }

    /** @deprecated use compareArray() */
    public static function _compareArray($template_arr, $destination_arr, $identifier, $ignore)
    {
        return self::compareArray($template_arr, $destination_arr, $identifier, $ignore);
    }

    /** Remote data (keys, row ids, values) goes into the descriptions escaped - never as HTML
     *  or as an AA template, since the remote AA may be compromised. */
    public static function compareArray($template_arr, $destination_arr, $identifier, $ignore)
    {
        $diff       = [];
        $idf        = safe($identifier->toString());
        if (! is_array($template_arr) && is_array($destination_arr)) {
            return [0 => new Difference('DELETED', _m('%1 is not array in template slice', [$idf]), new AA_Sync_Action('DELETE', $identifier))];
        }
        if (is_array($template_arr) && !is_array($destination_arr)) {
            // a template-only view or e-mail may be a renamed destination row (shown as INSERT + DELETE)
            return [0 => new Difference('NEW', _m('%1 is not array in destination slice', [$idf]), new AA_Sync_Action('INSERT', $identifier, $template_arr), !isset(self::MATCH_COLUMNS[$identifier->getTable()]))];
        }
        if (!is_array($template_arr)) {   // and no destination array either - see the first condition
            return [0 => new Difference('INFO', _m('%1 is not defined for both AAs', [$idf]))];
        }

        // if we're comparing row values, we can also update whole row at once - so we mark it
        $is_different = false;
        $renames      = false;
        foreach ($template_arr as $key => $value) {
            $sub_identifier = clone($identifier);
            $sub_identifier->sub($key);

            // some fields we do not want to compare (like slice_ids)
            if (in_array($key, $ignore)) {
                // unset, so that only the keys the template lacks stay in $destination_arr
                unset($destination_arr[$key]);
                continue;
            }
            if (is_array($value)) {
                $diff = array_merge($diff, self::compareArray($value, $destination_arr[$key] ?? null, $sub_identifier, $ignore));
                unset($destination_arr[$key]);
            } elseif (!array_key_exists($key, $destination_arr)) {
                $diff[] = new Difference('DIFFERENT_VALUE', '&nbsp;&nbsp;&nbsp;-&nbsp;' . _m('There is no such key (%1) in destination slice for %2', [safe($key), $idf]), new AA_Sync_Action('UPDATE_VALUE', $sub_identifier, $value));
            } elseif ($value != $destination_arr[$key] && !(empty($value) && empty($destination_arr[$key]))) {  // '' and 0 is considered equal
                $is_different = true;
                $renames      = $renames || ($key === 'name' && in_array($identifier->getTable(), ['module', 'slice'], true));

                $code = '<details><summary>&gt;&gt;</summary>
                       <div style="background-color:#FFE0E0;border: solid 1px #F88;">' . _m('Destination') . ':<br>' . safe($destination_arr[$key]) . '</div>
                       <br>
                       <div style="background-color:#E0E0FF;border: solid 1px #88F;">' . _m('Template') . ':<br>' . safe($value) . '</div></details>';
                $diff[] = new Difference('DIFFERENT_VALUE', '&nbsp;&nbsp;&nbsp;-&nbsp;' . _m('The value for key %1 in %2 array is different %3', [safe($key), $idf, $code]), new AA_Sync_Action('UPDATE_VALUE', $sub_identifier, $value));
                unset($destination_arr[$key]);
            } else {
                unset($destination_arr[$key]);
            }
        }
        if ($is_different) {
            // the ignored columns are not the template's to set (the module column is set by fillKeys())
            $row_data = array_diff_key($template_arr, array_flip(array_filter($ignore)));
            // renaming the destination slice is the user's choice - offered unchecked
            array_unshift($diff, new Difference('DIFFERENT_ROW', _m('The record for %1 is different', [$idf]), new AA_Sync_Action('UPDATE_ROW', $identifier, $row_data), !$renames));
        }

        foreach ($destination_arr as $key => $value) {
            $sub_identifier = clone($identifier);
            $sub_identifier->sub($key);

            // there are no such keys in template
            if (is_array($value)) {
                $diff = array_merge($diff, self::compareArray('', $destination_arr[$key], $sub_identifier, $ignore));
            } else {
                $diff[] = new Difference('DELETED', '&nbsp;&nbsp;&nbsp;-&nbsp;' . _m('There is no such key (%1) in template slice for %2', [safe($key), safe($sub_identifier->toString())]), new AA_Sync_Action('UPDATE_VALUE', $sub_identifier, ''));
            }
        }
        if (count($diff) < 1) {
            $diff[] = new Difference('INFO', _m('%1 are identical', [$idf]));
        }
        return $diff;
    }
}
