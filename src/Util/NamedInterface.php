<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 3.6.19
 * Time: 13:51
 */

namespace AA\Util;

interface NamedInterface {

    /** name function     */
    function name();

    /** necessary for parameter wizard */
    function description();
}