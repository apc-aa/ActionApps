<?php


namespace AA\Util;


trait LastErrTrait {

    /** lastErrMsg function
     * @return string - last error message - it is grabbed from static variable
     *  of lastErr() method */
    public static function lastErrMsg() {
        return self::lastErr(null, null, true);
    }

    /** lastErr function
     *  Method returns or sets last itemContent error
     *  The trick for static class variables is used
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
        return $getmsg ? $lastErrMsg : $lastErr;
    }
}