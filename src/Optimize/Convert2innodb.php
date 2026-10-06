<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/**
 *
 */
class Convert2innodb extends AbstractOptimize
{
    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Convert database engine to InnoDB");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Converts older MyISAM database storage engine to InnoDB for all tables");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool
    {
        //$metabase = AA::Metabase();
        //$tables   = $metabase->getTableNames();
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES", [['TABLE_SCHEMA', constant('DB_NAME')], ['ENGINE', '', 'NOTNULL'], ['ENGINE', 'InnoDB', 's<>']]);
        $count = 0;
        foreach ($engines as $tablename => $engine) {
            if (str_starts_with($tablename, 'bck_')) {
                continue;
            }
            $this->message(_m('engine for %1 table is %2', [$tablename, $engine]));
            $count++;
        }
        $this->message(_m("We found %1 tables which need to be converted", [$count]));
        return !$count;
    }


    /** The engine finding, and one naming the slices whose seq fields cannot keep their numbers
     *  unique: AA_Inserter_Seq serialises new items by locking the slice row, which only InnoDB does.
     *  @return Finding[] */
    public function findings(): array
    {
        $findings = parent::findings();
        $engines  = DB_AA::select(['TABLE_NAME' => 'ENGINE'], "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES", [['TABLE_SCHEMA', constant('DB_NAME')], ['TABLE_NAME', ['slice', 'item', 'content']], ['ENGINE', 'InnoDB', 's<>']]);
        if (!$engines) {
            return $findings;
        }
        $slices = DB_AA::select(['id' => 'name'], 'SELECT DISTINCT LOWER(HEX(slice.id)) AS id, slice.name FROM `field`, `slice`', [['field.slice_id', 'slice.id', 'j'], ['field.input_insert_func', 'seq', 'BEGIN']]);
        if ($slices) {
            $evidence = [];
            foreach ($slices as $id => $name) {
                $evidence[] = [_m('Slice') => safe($name), 'ID' => safe($id)];
            }
            $findings[] = new Finding(Finding::ERROR, _m('Unique numbers (seq) may repeat'), count: count($slices), evidence: $evidence, advice: _m('Tables %1 do not support transactions, so two items added at the same moment may get the same number. Convert the tables to InnoDB.', [safe(implode(', ', array_keys($engines)))]), repairable: true, key: 'seq');
        }
        return $findings;
    }

    public function repair(): bool
    {
        //$tables   = $metabase->getTableNames();
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES", [['TABLE_SCHEMA', constant('DB_NAME')], ['ENGINE', '', 'NOTNULL'], ['ENGINE', 'InnoDB', 's<>']]);
        $converted = 0;
        foreach ($engines as $tablename => $engine) {
            if (str_starts_with($tablename, 'bck_')) {
                continue;
            }
            $this->message(_m('Convert table %1 (%2) to InnoDB', [$tablename, $engine]));
            $this->query("ALTER TABLE `$tablename` ENGINE=InnoDB");
            $converted++;
        }
        $this->message(_m('Converted %1 tables', [$converted]));
        return true;
    }
}
