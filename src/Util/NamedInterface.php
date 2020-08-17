<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 3.6.19
 * Time: 13:51
 */

namespace AA\Util;

/**
 * Interface NamedInterface
 * @package AA\Util
 */
interface NamedInterface {

    /** name function     */
    public function name(): string;

    /** necessary for parameter wizard */
    public function description(): string;
}