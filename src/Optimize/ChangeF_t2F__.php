<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Field;
use AA_Module;
use function _m;

/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class ChangeF_t2F__ extends ChangeF_h2F_1 {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Optimize f_t alias to f__");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Replaces f_t alias used for code by quicker f__ function");
    }

    protected function fieldtest(AA_Field $fld, string $aliasfld): string {
        $alias = $fld[$aliasfld];
        $func  = $fld[$aliasfld.'_func'];

        $function = ParseFnc($func);
        $fce      = $function['fnc'];  // f_t, ...

        // conditions taken taken from f_t
        if ($fce == 'f_t' and strlen($param = $function['param'])) {
            $p = ParamExplode($param);
            if ( !isset($p[1]) or !in_array($p[1], ['csv', 'safe', 'javascript', 'urlencode', 'striptags', 'rss', 'conds', 'asis', 'substitute', 'debug', ''])) { // added '' since in f_t it takes different formula
                $slice_id = $fld->getSliceId();
                $slicename = AA_Module::getModuleName($slice_id);
                $aliaslink = a_href(get_admin_url('se_inputform.php', [
                        'change_id' => $slice_id,
                        'fid'       => $fld['id']
                    ]) . '#alias' . substr($alias, 2), $alias);
                return "<br>$aliaslink ($slicename) - f_t: used for code - consider using quicker f__ function";
            }
        }
        return '';
    }

    /**
     * @param AA_Field $fld
     * @param string    $aliasfld  alias1 | alias2 | alias3
     * @return string
     */
    protected function fieldrepair(AA_Field $fld, string $aliasfld): string {
        $alias = $fld[$aliasfld];
        $dbfield = $aliasfld . '_func';
        $slice_id = $fld->getSliceId();
        $slicename = AA_Module::getModuleName($slice_id);

        $this->query("UPDATE field SET `$dbfield`=CONCAT('f__', SUBSTRING(`$dbfield`,4)) ". DB_AA::makeWhere([['slice_id', $fld->getSliceId(), 'l'], ['id', $fld['id']]]));
        return "<br>$alias ($slicename) changed to f__";
    }
}
