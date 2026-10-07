<?php

namespace AA\Optimize;

/** Replaces the f_t: alias (without parameter) by f_1, where f_1 prints every stored value of the field the same way */
class ChangeF_t2F_1 extends ChangeF_h2F_1
{
    protected const FNC = 'f_t:';

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Optimize f_t alias to f_1");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Replaces f_t alias for field marked as non multiple and html by quicker f_1 function for direct content print");
    }
}
