<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Field;
use AA_Module;
use AA_Slice;
use function _m;

/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class ChangeF_h2F_1 extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Optimize f_h alias to f_1");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Replaces f_h alias for field marked as non multiple and html by quicker f_1 function for direct content print");
    }

    /** implemented actions within this class
     * @return string[]
     */
    public static function actions(): array { return ['test', 'repair', 'test4modules', 'repair4modules']; }

    /** Test function
     * @return bool - true if everything is OK - no action needed
     */
    public function test4modules(array $modules): bool {
        $all_ok = true;
        foreach ($modules as $module_id) {
            $s_fields = AA_Slice::getModule($module_id)->getFields();
            foreach ($s_fields as $fld) {
                foreach (['alias1', 'alias2', 'alias3',] as $aliasfld) {
                    $alias = $fld[$aliasfld];
                    if (!trim($alias) or ($alias == '_#UNDEFINE')) {
                        continue;
                    }
                    if ($msg = $this->fieldtest($fld, $aliasfld)) {
                        $all_ok = false;
                        $this->message($msg);
                    }
                }
            }
        }
        return $all_ok;
    }

    /** Repair function
     * @param array $modules
     * @return bool
     */
    public function repair4modules(array $modules): bool {
        // $all_ok = true;
        foreach ($modules as $module_id) {
            $s_fields = AA_Slice::getModule($module_id)->getFields();
            foreach ($s_fields as $fld) {
                foreach (['alias1', 'alias2', 'alias3',] as $aliasfld) {
                    $alias = $fld[$aliasfld];
                    if (!trim($alias) or ($alias == '_#UNDEFINE')) {
                        continue;
                    }
                    if ($this->fieldtest($fld, $aliasfld)) {
                        // $all_ok = false;
                        $msg = $this->fieldrepair($fld, $aliasfld);
                        $this->message($msg);
                    }
                }
            }
        }
        return true; // $all_ok; ?
    }

    protected function fieldtest(AA_Field $fld, string $aliasfld): string {
        $alias = $fld[$aliasfld];
        $func  = $fld[$aliasfld.'_func'];

        if (($func == 'f_h:') AND ((($fld['html_default']==1) AND ($fld['html_show']==0) AND ($fld['multiple']==0)) OR (strlen($fld['in_item_tbl'])>1) OR ($fld['text_stored']==0))) {
            $slice_id = $fld->getSliceId();
            $slicename = AA_Module::getModuleName($slice_id);
            $aliaslink = a_href(get_admin_url('se_inputform.php', [
                    'change_id' => $slice_id,
                    'fid'       => $fld['id']
                ]) . '#alias' . substr($alias, 2), $alias);
            return "<br>$aliaslink ($slicename) - f_h: used with field marked as non-multiple and html - consider using quicker f_1 function for direct content print";
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
        $slice_id = $fld->getSliceId();
        $slicename = AA_Module::getModuleName($slice_id);
        $this->query("UPDATE field SET `${aliasfld}_func`='f_1:' ". DB_AA::makeWhere([['slice_id', $fld->getSliceId(), 'l'], ['id', $fld['id']]]));
        return "<br>$alias ($slicename) changed to f_1";
    }

    /**
     * @return bool
     */
    public function repair(): bool {
        return $this->repair4modules(array_keys(AA_Module::getUserModules('S')));
    }
    public function test(): bool {
        return $this->test4modules(array_keys(AA_Module::getUserModules('S')));
    }

}