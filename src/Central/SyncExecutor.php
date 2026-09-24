<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA_Identifier;
use AA_Sync_Action;

/** Runs a synchronization action (AA_Sync_Action) on this AA - the remote end of Central
 *  Synchronize (responder Do_Synchronize). AA_Sync_Action travels between AAs and keeps its
 *  name and properties; its methods delegate here. */
final class SyncExecutor
{
    /** the data of the action as $metabase->doInsert/Update needs it - the params, under the
     *  column name for a one-column action */
    public static function dataArray(AA_Identifier $idf, $params)
    {
        $column = $idf->getColumn();
        return $column ? [$column => $params] : $params;
    }

    /** does the action in the destination slice
     *  @param string $type  DELETE | INSERT | UPDATE_ROW | UPDATE_VALUE
     *  @return string the report line */
    public static function run(string $type, AA_Identifier $idf, $params): string
    {
        // commands are stored as tree - like:
        // 6353636737->field->category........
        // 6353636737 is the id of the destination slice (compareWith() builds the actions for it)
        $module_id   = $idf->getModuleId();
        $table       = $idf->getTable();
        $row         = $idf->getRow();
        //$column      = $idf->getColumn();

        if (!in_array($table, AA_Sync_Action::TABLES, true)) {
            return _m('Wrong command - this table is not synchronized');
        }
        $metabase    = AA::Metabase();

        if (!$row) {
            return _m('Wrong command - row is not defined - %1', [$idf->toString()]);
        }
        // nohalt: a DB error must not die() in the middle of the batch - it is reported instead
        if ($type === 'UPDATE_ROW' || $type === 'UPDATE_VALUE') {
            // an older Central (or a queued AA_Task_Sync) still sends the installation columns
            $data = array_diff_key(self::withoutOwnNumbering($table, (array) self::dataArray($idf, $params)), array_flip(AA_Sync_Action::INSTALLATION_COLUMNS[$table] ?? []));
            $metabase->fillKeys($data, $idf);
            if (!array_diff_key($data, array_flip($metabase->getKeys($table)))) {
                return _m('%1 %2 in slice %3: nothing to update', [$table, $row, $module_id]);
            }
            return !$metabase->doUpdate($table, $data, 'nohalt')
                ? _m('Error: %1 %2 in slice %3 not updated', [$table, $row, $module_id])
                : _m('%1 %2 in slice %3 updated', [$table, $row, $module_id]);
        }
        if ($type === 'INSERT') {
            $data = self::withoutOwnNumbering($table, (array) $params);
            if ($table === 'module' || $table === 'slice') {
                $data['deleted'] = 0;   // a template flagged deleted must not create a deleted slice
            }
            $metabase->fillKeys($data, $idf);
            return !$metabase->doInsert($table, $data, 'nohalt')
                ? _m('Error: %1 %2 not inserted into slice %3', [$table, $row, $module_id])
                : _m('%1 %2 inserted into slice %3', [$table, $row, $module_id]);  // field xy inserted into slice yz
        }
        if ($type === 'DELETE') {
            $data = [];
            $metabase->fillKeys($data, $idf);
            return !$metabase->doDelete($table, $data, 'nohalt')
                ? _m('Error: %1 %2 not deleted from slice %3', [$table, $row, $module_id])
                : _m('%1 %2 deleted from slice %3', [$table, $row, $module_id]);
        }
        return _m("Unknown action (%1) for field %2 in slice %3", [$type, $row, $module_id]);
    }

    /** $data without the auto_increment columns which are not keys (constant.short_id) - each
     *  installation numbers its rows itself */
    private static function withoutOwnNumbering(string $table, array $data): array
    {
        foreach (AA::Metabase()->getColumns($table) as $colname => $column) {
            if ($column->isAutoIncrement() && !$column->isKey()) {
                unset($data[$colname]);
            }
        }
        return $data;
    }
}
