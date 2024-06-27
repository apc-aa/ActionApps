<?php

namespace AA\Optimize;

use AA_Content;
use AA_Property;
use AA_Value;
use function getDB;
use function safe;

abstract class AbstractOptimize implements \AA\Util\NamedInterface {
    use \AA\Util\ReportTrait;

    /** @var array used in manager to store test results in order we can use it in alias _#RESULT__ */
    public static array $action_results = [];
    public static array $action_results_text = [];

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

    /** @return bool - true if everything is OK */
    public function repair(): bool { return true; }

    /** Reverts the changes made by repair() method
     *  Normally it should not be needed to revert the changes - used mainly for optional features like AddDurationLog
     * @return bool - true if everything is OK
     */
    public function revert(): bool { return true; }

    /** @return bool - true if everything is OK - no action needed */
    public function test4modules(array $modules): bool { return true; }   // test just for specified modules

    public function repair4modules(array $modules): bool { return true; } // repair just for specified modules

    /** implemented actions within this class
     * @return string[]
     */
    public static function actions(): array { return ['test', 'repair']; }  // full set is ['test', 'repair', 'revert', 'test4modules', 'repair4modules']

    /** is action present in this class
     * @param string $action
     * @return bool
     */
    public function hasAction(string $action): bool {
        return in_array($action, static::actions());
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update")
     *  full set is:
     *    sql_update  - should be run on every SQL update of AA
     *    opt_feature - optional features, which could be added to AA
     *    add_field   - just tag the add field optimizers
     *    private_fix - private_fix for site specific repairs - probably not useful for others
     */
    public static function types(): array { return []; }      // full set is ['sql_update','add_field', 'private_fix']  - private_fix for site specific repairs - probably not useful for others

    /** the optimizers are ordered by priority when executed in chain (like for sql_update) */
    public static function priority(): int { return 100; }


    /** used for generating aliases and content for Manager
     * @return array
     */
    public static function getClassProperties(): array {
        //                                          id              name            type     multi  persist validator, required, help, morehelp, example
        return [
                 'aa_id'       => new AA_Property( 'aa_id',       _m("ID"),          'string', false,  false),
                 'name'        => new AA_Property( 'name',        _m("Name"),        'string', false,  false),
                 'description' => new AA_Property( 'description', _m("Description"), 'string', false,  false),
                 'actions'     => new AA_Property( 'actions',     _m("Actions"),     'string', true,  false),
                 'types'       => new AA_Property( 'types',       _m("Types"),       'string', true,  false),
                 'priority'    => new AA_Property( 'priority',    _m("Priority"),    'int',    false, false),
                 'result'      => new AA_Property( 'result',      _m("Result"),      'string', false, false),
                 'resulttxt'   => new AA_Property( 'resulttxt',   _m("Result text"), 'string', false, false)
               ];
    }

    /** function to add content fields to getContent of Component for each type of Components (for Manager)*/
    public static function contentAdd(AA_Content $content) {
        $content->setAaValue('name',        new AA_Value(static::name()));
        $content->setAaValue('description', new AA_Value(static::description()));
        $content->setAaValue('actions',     new AA_Value(static::actions()));
        $content->setAaValue('types',       new AA_Value(static::types()));
        $content->setAaValue('priority',    new AA_Value(static::priority()));
        $res = AbstractOptimize::$action_results[get_class_name(static::class)] ?? '-';
        $content->setAaValue('result',      new AA_Value(($res==='-') ? '' : ($res ? 'OK' : 'ERR'))); // result is '' when no action was performed, OK or ERR as action result
        $content->setAaValue('resulttxt',   new AA_Value(AbstractOptimize::$action_results_text[get_class_name(static::class)] ?? ''));
    }

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