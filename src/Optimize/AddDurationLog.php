<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use function _m;

/** Add column to hit_archive table which will store duration/cache/not_modified records for the hit
 * Optional AA feature
 **/
class AddDurationLog extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Add hit duration log feature");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Add column 'duration' to hit_archive table which will store duration/cache/not_modified records for the hit");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['opt_feature']; }

    /** @inheritDoc  */
    public static function actions(): array { return ['test', 'repair', 'revert']; }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        if (!DB_AA::exists_column('hit_archive', 'duration')) {
            $this->message(_m('The "duration" column missing in hit_archive table'));
            return false;
        }
        $this->message(_m('OK - The "duration" column found in hit_archive table. Duration evaluated from %1', [date('Y-m-d H:i:s', $this->getFromTime())]));
        return true;
    }

    /** Main update function - add the duration column to hit_archive table
     * @return bool
     */
    public function repair(): bool {
        if ($this->test()) {
            $this->message(_m('Table already has the column "duration"'));
            return true;
        }
        $this->message(_m('Do alter table hit_archive to add duration column'));
        DB_AA::sql('ALTER TABLE `hit_archive` ADD COLUMN `duration` TINYINT UNSIGNED NOT NULL DEFAULT 0');
        $this->message(_m('Column added'));
        return true;
    }

    /** @inheritDoc   */
    public function revert(): bool {
        $this->message(_m('Do alter table hit_archive to remove duration column - it disables the feature'));
        DB_AA::sql('ALTER TABLE `hit_archive` DROP COLUMN `duration`');
        $this->message(_m('Column deleted'));
        return true;
    }

    /** get time from which we measure duration */
    public function getFromTime() {
        return DB_AA::select1('time', 'SELECT `time` FROM `hit_archive`', [['duration', 0, '<>']], ['time']);
    }
}