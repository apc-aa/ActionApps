<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;

/** Adds cron entry for Checking link status in Links module into AA cron **/
class AddLinkcheckCronEntry extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface  */
    public static function name(): string {
        return _m("Adds cron entry for Link checking in Links Module into AA cron");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type) { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM cron', [
            [
                'script',
                'modules/links/linkcheck.php3',
            ],
        ]);
        if ($row_count < 1) {
            $this->message(_m('Cron entry for "Checking link status in Links module" is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair(): bool {

        $this->message(_m('Deleting AA Cron entry for Checking link status in Links module'));
        $this->query("DELETE FROM cron WHERE script='modules/links/linkcheck.php3'");

        $this->message(_m('Adding AA cron entry for Checking link status in Links module'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('35',     '*', '*', '*', '*', 'modules/links/linkcheck.php3', '', NULL)");

        $this->message(_m('AA cron entry for "Checking link status in Links module" - done.'));
        return true;
    }
}