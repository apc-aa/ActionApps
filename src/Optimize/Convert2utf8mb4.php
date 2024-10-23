<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;
use const DB_NAME;

/**
 *
 */
class Convert2utf8mb4 extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Convert old database collation utf8 to utf8mb4");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Convert old database collation utf8 (or utf8mb3) to utf8mb4 for all tables and set database default to utf8mb4");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        $collations = $this->get_tables('utf8_');
        $collations = array_merge($collations, $this->get_tables('utf8mb3_'));
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
        $new_collation1 = $this->convert('utf8_', 'utf8mb4_');
        $new_collation2 = $this->convert('utf8mb3_', 'utf8mb4_');

        $new_collation = $new_collation1 ?: $new_collation2 ?: DB_AA::select1('TABLE_COLLATION', "SELECT TABLE_COLLATION FROM information_schema.TABLES", [['TABLE_SCHEMA', DB_NAME,], ['TABLE_NAME', 'content',]]);
        $this->query("ALTER DATABASE " . DB_NAME . " CHARACTER SET = utf8mb4 COLLATE = $new_collation;");
        $this->message(_m('Database default set to %1', [$new_collation]));
        $this->message(_m('Please do not forget to change DB_CHARACTER_SET to "utf8mb4" and DB_COLLATION_CONNECTION to "%1" in your config.php', [$new_collation]));
        return true;
    }

    protected function convert(string $from_colation_prefix, string $to_colation_prefix) {
        $collations = $this->get_tables($from_colation_prefix);
        $converted = 0;
        $new_collation = '';
        foreach ($collations as $tablename => $collation) {
            if (str_starts_with($tablename, 'bck_')) {
                continue;
            }
            $new_collation = str_replace($from_colation_prefix, $to_colation_prefix, $collation);
            $this->message(_m('Convert table %1 (%2) to %3', [$tablename, $collation, $new_collation]));
            $this->query("ALTER TABLE `$tablename` CONVERT TO CHARACTER SET utf8mb4 COLLATE $new_collation");
            $converted++;
        }
        return $new_collation;
    }

    protected function get_tables(string $colation_prefix) {
        return DB_AA::select(['TABLE_NAME' => 'TABLE_COLLATION'], "SELECT * FROM information_schema.TABLES", [['TABLE_SCHEMA', DB_NAME], ['TABLE_COLLATION','', 'NOTNULL'], ['TABLE_COLLATION', $colation_prefix, 'BEGIN']]);
    }
}