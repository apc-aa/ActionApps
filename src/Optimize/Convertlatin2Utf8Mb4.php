<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;
use const DB_NAME;

/**
 *
 */
class Convertlatin2Utf8Mb4 extends \AA\Optimize\Convert2utf8mb4 {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Convert database from 1Byte latin1 encoding to utf8mb4 collation");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Convert database from 1Byte latin1 encoding to utf8mb4 for all tables and set database default to utf8mb4");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        $collations = $this->get_tables('latin1_');
        $count = 0;
        foreach ($collations as $tablename => $collation) {
            if (str_starts_with($tablename, 'bck_')) {
                continue;
            }
            $this->message(_m('collation for %1 table is %2', [$tablename, $collation]));
            $count++;
        }
        $this->message(_m('We found %1 tables which needs to be converted', [$count]));
        return true;
    }

    /**
     */
    public function repair(): bool {
        $new_collation1 = $this->convert('latin1_swedish_', 'utf8mb4_unicode_');  // if default encoding is used, convert to general  (*_unicode_* is better for most cases than *_general_*)
        $new_collation2 = $this->convert('latin1_', 'utf8mb4_');

        $new_collation = $new_collation1 or $new_collation2 or DB_AA::select1('TABLE_COLLATION', "SELECT TABLE_COLLATION FROM information_schema.TABLES", [
            [
                'TABLE_SCHEMA',
                DB_NAME,
            ],
            [
                'TABLE_NAME',
                'content',
            ],
        ]);
        $this->query("ALTER DATABASE " . DB_NAME . " CHARACTER SET = utf8mb4 COLLATE = $new_collation;");
        $this->message(_m('Database default set to %1', [$new_collation]));
        return true;
    }
}