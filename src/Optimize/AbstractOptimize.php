<?php

namespace AA\Optimize;

use function getDB;
use function safe;

abstract class AbstractOptimize implements \AA\Util\NamedInterface {
    use \AA\Util\ReportTrait;

    protected $db;

    /** \AA\Util\NamedInterface function */
    public static function name(): string { return ''; }

    /** \AA\Util\NamedInterface function */
    public static function description(): string { return ''; }

    /** true for the actions which should be performed from time to time on every AA
     *  false for the specific tasks which you run on specific cases - like converting character encodings, ...
     */
    // public static function isOptimizing(): bool { return true; }  // not used - better to use isType() here

    /** @return bool - true if everything is OK - no action needed */
    public function test(): bool { return true; }

    public function repair(): bool { return true; }

    /** @return bool - true if everything is OK - no action needed */
    public function test4modules(array $modules): bool { return true; }   // test just for specified modules

    public function repair4modules(array $modules): bool { return true; } // repair just for specified modules

    /** implemented actions within this class
     * @return string[]
     */
    public static function actions(): array { return ['test', 'repair']; }  // full set is ['test', 'repair', 'test4modules', 'repair4modules']

    /** is action present in this class
     * @param string $action
     * @return bool
     */
    public function hasAction(string $action): bool {
        return in_array($action, self::actions());
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type) { return in_array($type, []); }

    /** the optimizers are ordered by priority when executed in chain (like for sql_update) */
    function priority() { return 100; }


    /** This function stores global state for all optimizations.
     *  You can call it with bool parameter which will switch the state
     *  If you call it without parameters, then you get current stete
     *  true means, the DB queries are not written to the database, but just
     *  written to messages (report)
     *  Used by query($SQL) method
     *  Called as AA\Optimize\AA_Optimize::justPrint();
     * @todo convert to static class variable (after migration to PHP5)
     */
    static function justPrint($state = null) {
        static $just_print = false;
        if (!is_null($state)) {
            $just_print = $state;
        }
        return $just_print;
    }

    /** $db->query wrapper - always use it to not execute the command and just
     *  display it, ...
     */
    function query($SQL) {
        $this->message(safe($SQL));
        $just_print = AbstractOptimize::justPrint();

        if ($just_print) {
            return;
        }
        if (!$this->db) {
            $this->db = getDb();
            $this->db->set_halt('report');
        }
        $db = $this->db;
        $db->query($SQL);
    }
}