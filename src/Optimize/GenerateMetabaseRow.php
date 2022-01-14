<?php

namespace AA\Optimize;

use AA_Metabase;
use function _m;

/** Prints out the metabase row for include/metabase.class.php3 file
 *  (used by AA developers to update database definition tempate)"
 **/
class GenerateMetabaseRow extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Generate metabase PHP row");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("prints out the metabase row for include/metabase.class.php3 file (used by AA developers to update database definition tempate)");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $metabase = new AA_Metabase;
        $metabase->loadFromDb(true);
        echo '$instance = unserialize(\'' . str_replace("'", '\\\'', serialize($metabase)) . '\');';
        exit;
    }
}