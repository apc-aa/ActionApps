<?php

namespace AA\Optimize;

use AA_Field;
use AA_Module;
use function _m;

/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class ChangeF_t2F_1 extends ChangeF_h2F_1 {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Optimize f_t alias to f_1");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Replaces f_t alias for field marked as non multiple and html by quicker f_1 function for direct content print");
    }

    protected function fieldtest(AA_Field $fld, string $aliasfld): string {
        $alias = $fld[$aliasfld];
        $func  = $fld[$aliasfld.'_func'];

        if (($func == 'f_t:') AND ((($fld['html_default']==1) AND ($fld['html_show']==0) AND ($fld['multiple']==0)) OR (strlen($fld['in_item_tbl'])>1) OR ($fld['text_stored']==0))) {
            $slice_id = $fld->getSliceId();
            $slicename = AA_Module::getModuleName($slice_id);
            $aliaslink = a_href(get_admin_url('se_inputform.php', ['change_id'=>$slice_id, 'fid'=>$fld['id']]).'#alias'.substr($alias,2), $alias);
            return "<br>$aliaslink ($slicename) - f_t: used with field marked as non-multiple and html - consider using quicker f_1 function for direct content print";
        }
        return '';
    }

    /**
     * @param AA_Field $fld
     * @param string    $aliasfld  alias1 | alias2 | alias3
     * @return string
     */
    protected function fieldrepair(AA_Field $fld, string $aliasfld): string {
        return parent::fieldrepair($fld, $aliasfld);
    }
}