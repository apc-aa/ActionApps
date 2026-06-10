<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/**
 *
 */
class Convert2innodb extends AbstractOptimize
{
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
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES", [['TABLE_SCHEMA', DB_NAME], ['ENGINE', '', 'NOTNULL'], ['ENGINE', 'InnoDB', 's<>']]);
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


    public function repair(): bool
    {
        //$tables   = $metabase->getTableNames();
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES", [['TABLE_SCHEMA', DB_NAME], ['ENGINE', '', 'NOTNULL'], ['ENGINE', 'InnoDB', 's<>']]);
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
