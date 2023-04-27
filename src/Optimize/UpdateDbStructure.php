<?php

namespace AA\Optimize;

use AA;
use AA\Util\Difftext;
use AA_Metabase;
use function _m;

/** Updates the database structure for AA. It checks all the tables in current
 *  system and compare it with the newest database structure. The new table
 *  is created as tmp_*, then the content from old table is copied and if
 *  everything is OK, then the old table is renamed to bck_* and tmp_*
 *  is renamed to new table
 **/
class UpdateDbStructure extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Update database structure");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("[experimental] ") . _m("Updates the database structure for AA. It checks all the tables in current system and compare it with the newest database structure. The new table is created as tmp_*, then the content from old table is copied and if everything is OK, then the old table is renamed to bck_* and tmp_* is renamed to new table. (new version based on the metabase structure)");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    function isType($type): bool { return in_array($type, ['sql_update']); }

    /** the optimizers are ordered by priority (less first) when executed
     *  in chain (like for sql_update)
     *  Default value is 100
     *  Execute this first
     */
    function priority() { return 50; }  // quite big priority but not first

    /** Test function
     * @return string - a message
     */
    public function test(): bool {
        $template_metabase = AA::Metabase();
        $this_metabase = new AA_Metabase;
        $this_metabase->loadFromDb();
        $diffs = $template_metabase->compare($this_metabase);
        $different = false;

        $this->message(Difftext::renderHtml(" Actual Database ", " AA Template "));

        foreach ($diffs as $tablename => $diff) {
            if ($diff['equal']) {
                //huhl($diff);
                $this->message(_m('<em>Tables %1 are identical.</em><br>', [$tablename]));
            } else if ($diff['table1'] == '') {
                $this->message(_m('<em>Table <b>%1</b> is <b>not needed</b>. You can <b>manually</b> delete it.</em><br>', [$tablename]));
                $this->message(Difftext::renderHtml($diff['table2'], $diff['table1']));
                $different = true;
            } else if ($diff['table2'] == '') {
                $this->message(_m('<em>Table <b>%1</b> is <b>new</b>.</em><br>', [$tablename]));
                $this->message(Difftext::renderHtml($diff['table2'], $diff['table1']));
                $different = true;
            } else {
                $this->message(Difftext::renderHtml($diff['table2'], $diff['table1']));
                $different = true;
            }
        }
        return !$different;
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $template_metabase = AA::Metabase();
        $this_metabase = new AA_Metabase;
        $this_metabase->loadFromDb();
        $diffs = $template_metabase->compare($this_metabase);
        foreach ($diffs as $tablename => $diff) {
            if ($diff['equal']) {
                $this->message(_m('Tables %1 are identical. Skipping.', [$tablename]));
                continue;
            } else if ($diff['table1'] == '') {
                $this->message(_m('Table <b>%1</b> is <b>not needed</b>. You can <b>manually</b> delete it. Skipping.</em><br>', [$tablename]));
                continue;
            } else if ($diff['table2'] == '') {
                $this->message(_m('<em>Table <b>%1</b> is <b>new</b>.</em><br>', [$tablename]));
            }
            $this->message(_m('<em>Updating table <b>%1</b>.</em><br>', [$tablename]));

            $this->query('START TRANSACTION');

            // create temporary table
            $this->message(_m('Deleting temporary table tmp_%1, if exist.', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `tmp_$tablename`");
            $this->message(_m('Creating temporary table tmp_%1.', [$tablename]));
            $this->query($template_metabase->getCreateSql($tablename, 'tmp_', $tablename));

            // create new tables that do not exist in database
            // (we need it for next data copy, else it ends up with db error)
            $this->message(_m('Creating "old" data table %1 if not exists.', [$tablename]));
            $this->query($template_metabase->getCreateSql($tablename));


            // copy old data to tmp table
            $this->message(_m('copying old values to new table %1 -> tmp_%1', [$tablename]));

            $tmp_columns = $template_metabase->getColumnNames($tablename);
            $old_columns = $this_metabase->getColumnNames($tablename);

            $matches = array_intersect($tmp_columns, $old_columns);
            if (count($matches) > 1) {
                $field_list = '`' . join('`, `', $matches) . '`';
                // there was longer "type" column in old AA (contains "history"). Now we use just 'h', so we ignore the error for insert
                $ignore = ($tablename == 'change') ? 'IGNORE' : '';
                $this->query("INSERT $ignore INTO `tmp_$tablename` ($field_list) SELECT $field_list FROM `$tablename`");
            }

            // backup table and use the new one
            $this->message(_m('backup old table %1 -> bck_%1 and use new tables instead tmp_%1 -> %1', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `bck_$tablename`");
            $this->query("ALTER TABLE `$tablename` RENAME `bck_$tablename`");
            $this->query("ALTER TABLE `tmp_$tablename` RENAME `$tablename`");

            $this->query('COMMIT');
            $this->message(_m('%1 done.', [$tablename]));


        }
        return true;
    }
}