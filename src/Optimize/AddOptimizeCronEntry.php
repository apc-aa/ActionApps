<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;

/** Adds cron entry for Database Optimalization into AA cron **/
class AddOptimizeCronEntry extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string {
        return _m("Adds cron entry for Database Optimalization into AA cron");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    function isType($type): bool { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM cron', [['script', 'misc/optimize.php']]);
        if ($row_count < 1) {
            $this->message(_m('Cron entry for Database Optimalization is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair(): bool {

        $this->message(_m('Deleting AA Cron entry for Database Optimalization'));
        $this->query("DELETE FROM cron WHERE script='misc/optimize.php'");

        $this->message(_m('Adding AA cron entry for Database Optimalization'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('38',     '2', '*', '*', '2', 'misc/optimize.php', 'key=" . substr(DB_PASSWORD, 0, 5) . "', NULL)");

        $this->message(_m('AA cron entry for Database Optimalization - done.'));
        return true;
    }
}