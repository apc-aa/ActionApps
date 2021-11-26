<?php

namespace AA\Optimize;

use function _m;

/** Prints out the JS library definition array used in include/stringexpand.php for update to last version of libs on jsDeliver CDN
 *  (used by AA developers to update js libs in AA)"
 **/
class GenerateJslibsArray extends AbstractOptimize {

    /** Name function
     * @return string message
     */
    public static function name(): string {
        return _m("Generate JS Library definition array");
    }

    /** Description function
     * @return string message
     */
    public static function description(): string {
        return _m("Prints out the JS library definition array used in include/stringexpand.php for update to last version of libs on jsDeliver CDN (used by AA developers to update js libs in AA)");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $libupdater = new \AA\Util\LibUpdater();
        $this->message($libupdater->getUpdatedsystemDefinitions());
        return true;
    }
}