<?php

namespace AA\Optimize;

use AA_Slice;
use Files;
use function _m;

/** Creates upload directory for current slice (if not already created) **/
class CreateUploadDir extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Create upload directory for current slice");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("see IMG_UPLOAD_PATH parameter in config.php3 file");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Test function
     * @return string - a message
     */
    public function test(): bool {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        if ($path = Files::destinationDir(AA_Slice::getModule($GLOBALS['slice_id']))) {
            $this->message(_m('OK, %1 created', [$path]));
            return true;
        }
        $this->message(Files::lastErr());
        return false;
    }
}