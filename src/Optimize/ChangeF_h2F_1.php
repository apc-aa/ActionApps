<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Field;
use AA_Item;
use AA_Module;
use AA_Slice;

/** Replaces the f_h: alias (without parameter) by f_1, where f_1 prints every stored value of the field the same way */
class ChangeF_h2F_1 extends AbstractOptimize
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Optimize f_h alias to f_1");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Replaces f_h alias for field marked as non multiple and html by quicker f_1 function for direct content print");
    }

    /** implemented actions within this class
     * @return string[]
     */
    public static function actions(): array
    {
        return ['test', 'repair', 'test4modules', 'repair4modules'];
    }

    /** Test function
     * @return bool - true if everything is OK - no action needed
     */
    public function test4modules(array $modules): bool
    {
        $all_ok = true;
        foreach ($modules as $module_id) {
            $s_fields = AA_Slice::getModule($module_id)?->getFields();
            foreach ($s_fields as $fld) {
                foreach (['alias1', 'alias2', 'alias3',] as $aliasfld) {
                    $alias = $fld[$aliasfld];
                    if ($alias === '_#UNDEFINE') {
                        continue;
                    }
                    if (!trim($alias)) {
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
    public function repair4modules(array $modules): bool
    {
        // $all_ok = true;
        foreach ($modules as $module_id) {
            $s_fields = AA_Slice::getModule($module_id)?->getFields();
            foreach ($s_fields as $fld) {
                foreach (['alias1', 'alias2', 'alias3',] as $aliasfld) {
                    $alias = $fld[$aliasfld];
                    if ($alias === '_#UNDEFINE') {
                        continue;
                    }
                    if (!trim($alias)) {
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

    /** alias function (without parameter) this optimization replaces */
    protected const FNC = 'f_h:';

    /** @var bool[] slice_id.field_id => f_1 prints all stored values of the field the same way */
    private array $prints_as_is = [];

    protected function fieldtest(AA_Field $fld, string $aliasfld): string
    {
        $func  = $fld[$aliasfld . '_func'];

        if (($func === static::FNC) && $this->printsAsIs($fld)) {
            return self::alias_code($fld, $aliasfld, static::FNC . " used where every stored value prints as is - consider using quicker f_1 function for direct content print");
        }
        return '';
    }

    /** Would f_1 print every stored value of the field the same as f_h: / f_t: (without parameter)?
     *  They differ only for a value without FLAG_HTML - f_h escapes it and adds <br>. The field setting
     *  does not guarantee the flag of the stored values (CSV import, feeds, content older than the
     *  setting), so we look into the content.
     */
    protected function printsAsIs(AA_Field $fld): bool
    {
        // GetItemContent() gives FLAG_HTML to all values from the item table
        if (strlen((string) $fld['in_item_tbl']) > 1) {
            return true;
        }
        // f_h takes the flag of the first translation, but prints the current one
        if ($fld->isMultilingual()) {
            return false;
        }
        // values from the number column get FLAG_HTML, older text rows of the field keep their own flag
        if (($fld['text_stored'] != 0) && (($fld['html_default'] != 1) || ($fld['html_show'] != 0))) {
            return false;
        }
        $key = $fld->getSliceId() . $fld['id'];
        return $this->prints_as_is[$key] ??= !$this->hasPlainValueText2HtmlChanges($fld);
    }

    /** Is there a value without FLAG_HTML in the content of the field, which text2Html() would change?
     *  PHP reads a NULL flag as 0 (plain), so the SQL must too. */
    protected function hasPlainValueText2HtmlChanges(AA_Field $fld): bool
    {
        return DB_AA::test(['content', 'item'], self::plainValueText2HtmlChangesWhere($fld));
    }

    /** @return array the condition for hasPlainValueText2HtmlChanges() */
    public static function plainValueText2HtmlChangesWhere(AA_Field $fld): array
    {
        return [['content.item_id', 'item.id', 'j'], ['item.slice_id', $fld->getSliceId(), 'l'], ['content.field_id', $fld['id']], ['COALESCE(content.flag, 0)', FLAG_HTML, 'unset'], ['content.text', AA_Item::TEXT2HTML_CHANGES, 'CONTAIN']];
    }

    /**
     * @param AA_Field $fld
     * @param string    $aliasfld  alias1 | alias2 | alias3
     * @return string
     */
    protected function fieldrepair(AA_Field $fld, string $aliasfld): string
    {
        $alias = safe($fld[$aliasfld]);
        $slice_id = $fld->getSliceId();
        $slicename = AA_Module::getModuleNameSafe($slice_id);
        $this->query("UPDATE field SET `{$aliasfld}_func`='f_1:' " . DB_AA::makeWhere([['slice_id', $fld->getSliceId(), 'l'], ['id', $fld['id']]]));
        return "<br>$alias ($slicename) changed to f_1";
    }

    /** prints proposed change description */
    protected static function alias_code(AA_Field $fld, string $aliasfld, string $text): string
    {
        $slice_id  = $fld->getSliceId();
        $slicename = AA_Module::getModuleNameSafe($slice_id);
        $alias     = $fld[$aliasfld];
        $aliaslink = a_href(get_admin_url('se_inputform.php', ['change_id' => $slice_id, 'fid' => $fld['id']]) . '#alias' . substr($alias, 2), safe($alias));
        ['fnc' => $fnc, 'param' => $param ] = ParseFnc($fld[$aliasfld . '_func']);

        return "<div>$aliaslink ($slicename) - $text<div><pre style='font-size: .8rem; margin: 5px 20px; opacity: .7'>" . safe($param) . "</pre></div></div>";
    }


    /**
     * @return bool
     */
    public function repair(): bool
    {
        return $this->repair4modules(array_keys(AA_Module::getUserModules('S')));
    }
    public function test(): bool
    {
        return $this->test4modules(array_keys(AA_Module::getUserModules('S')));
    }

}
