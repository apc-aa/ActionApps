<?php


namespace AA\Util;

trait LastErrTrait {

    /** lastErrMsg function
     * @return string - last error message - it is grabbed from static variable
     *  of lastErr() method */
    public static function lastErrMsg(): string {
        return self::lastErr(null, null, true);
    }

    /** lastErr function
     *  Method returns or sets last itemContent error
     *  The trick for static class variables is used
     *  Use as:
     *    MyClass::lastErr(NO_SLICE_ID, _m("No Slice Id specified"));   // set error code
     *    MyClass::lastErr();      // get last Error ID (int)
     *    MyClass::lastErrMsg();   // get last Error Message (string)
     *
     * @param int    $err_id
     * @param string $err_msg
     * @param bool   $getmsg
     * @return int|string
     */
    public static function lastErr( ?int $err_id = null, ?string $err_msg = null, bool $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? (string)$lastErrMsg : (int)$lastErr;
    }
}
