<?php

/**
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

// Editor of one display condition of the slice (AA\Form\ShowConditions), shown in a dialog of the Fields page.
// key = the condition (8 hex) or "new". After saving or deleting the page only tells the Fields page by
// parent.AA_ShowIfSaved({key, name, deleted}); Cancel calls it without an argument.

use AA\Form\ShowConditions;
use AA\Util\FormToken;
use AA\Util\InputVars;
use AA\Util\ScriptTag;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

const SHOWIF_TOKEN = 'se_showif';

try {
    $key       = InputVars::stringGETPOST('key');
    $update    = InputVars::boolPOST('update');
    $saveasnew = InputVars::boolPOST('saveasnew');
    $delete    = InputVars::boolPOST('delete');
    $token     = InputVars::stringPOST('token');
    $name      = InputVars::stringPOST('name');
    $rules     = InputVars::arrayPOST('rule');
    $expr      = InputVars::stringPOST('expr');
    $clear     = InputVars::boolPOST('clear');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

$slice = AA_Slice::getModule(AA::$module_id);
if (!IfSlPerm(PS_FIELDS) || !($slice instanceof AA_Slice)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to change fields settings"), "admin");
}

$conds  = ShowConditions::forSlice(AA::$module_id);
$cond   = $conds->get($key);
$asked  = $key;
$key    = $cond ? $key : '';   // "new", or a condition the slice does not have
$fields = $slice->getFields();
$names  = $fields->getNameArray();
$posted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$errors = [];

/** tells the Fields page and ends; null = closed without a change */
function ShowIfDone(?array $result): never
{
    echo '<!DOCTYPE html><script>parent.AA_ShowIfSaved(' . ($result ? json_encode($result, ScriptTag::JS_JSON) : '') . ');</script>';
    Session::page_close();
    exit;
}

if ($update || $saveasnew || $delete) {
    if (!FormToken::checkPost(SHOWIF_TOKEN, $token, $_SERVER)) {
        $errors[] = _m('The form has expired. Close it and open it again.');
    } elseif ($delete) {
        // deleted meanwhile (another tab) - only tell the Fields page
        if ($key !== '') {
            $conds->delete(AA::$module_id, $key, $fields);
        }
        ShowIfDone(ShowConditions::isKey($asked) ? ['key' => $asked, 'name' => '', 'deleted' => true] : null);
    } else {
        $as_new  = $saveasnew || ($key === '');
        $post    = ['name' => $name, 'rule' => $rules, 'expr' => $expr] + ($clear ? ['clear' => 1] : []);
        [$new, $errors] = $conds->fromPost($post, $names, $as_new ? '' : $key);
        if ($new) {
            $new_key = $as_new ? ShowConditions::newKey() : $key;
            $conds->set($new_key, $new);
            $conds->save(AA::$module_id);
            ShowIfDone(['key' => $new_key, 'name' => $new['name'], 'deleted' => false]);
        }
    }
}

// the form shows what was posted (a field or a comparison picked in a rule, an error), else the condition
if ($posted) {
    $form = ['name' => $name, 'rows' => [], 'expr' => $expr, 'clear' => $clear];
    foreach ($rules as $row) {
        if (is_array($row) && is_string($row['field'] ?? null) && isset($names[$row['field']])) {
            // a field picked anew in the row starts without the values of the previous one
            $same           = ($row['was'] ?? null) === $row['field'];
            $form['rows'][] = ['field' => $row['field'], 'op' => $same ? (string) ($row['op'] ?? '') : '', 'values' => $same ? ShowConditions::postedValues($row) : []];
        }
    }
} else {
    $form = $cond ? ['rows' => $conds->rows($key)] + $cond : ['name' => '', 'rows' => [], 'expr' => '', 'clear' => true];
}
$form['rows'][] = ['field' => '', 'op' => '', 'values' => []];   // the row for a new rule

/** one row of the rule table: the field and the comparison (changing them redraws the form), the values */
function ShowIfRuleRow(int $i, array $rule, array $names, AA_Fields $fields): string
{
    $field   = $rule['field'] ? $fields->getField($rule['field']) : null;
    $is_chb  = $field?->getWidget() instanceof AA\Widget\ChbWidget;
    // a checked checkbox stores 1
    $ops     = $is_chb ? ['=' => _m('is checked'), '<>' => _m('is not checked')]
                       : ['=' => _m('is'), '<>' => _m('is not'), 'BEGIN' => _m('begins with'), 'CONTAIN' => _m('contains'), 'NOTNULL' => _m('is filled'), 'ISNULL' => _m('is empty')];
    $op      = isset($ops[$rule['op']]) ? $rule['op'] : array_key_first($ops);
    $choices = ['' => $field ? '—' : _m('— add a rule —')] + $names;

    $ret  = '<div class="aa-showif-rule">';
    $ret .= getFrmSelectEasy("rule[$i][field]", $choices, $rule['field'], ['onchange' => 'this.form.submit()', 'aria-label' => _m('Field')]);
    if (!$field) {
        return $ret . '</div>';
    }
    $ret .= '<input type="hidden" name="rule[' . $i . '][was]" value="' . safe($rule['field']) . '">';
    $ret .= getFrmSelectEasy("rule[$i][op]", $ops, $op, ['onchange' => 'this.form.submit()', 'aria-label' => _m('Comparison')]);
    if ($is_chb) {
        $ret .= '<input type="hidden" name="rule[' . $i . '][values][]" value="1">';
    } elseif (in_array($op, ['BEGIN', 'CONTAIN'], true)) {
        $ret .= '<input type="text" name="rule[' . $i . '][text]" value="' . safe($rule['values'][0] ?? '') . '" size="30" aria-label="' . safe(_m('Value')) . '">';
    } elseif (in_array($op, ['=', '<>'], true)) {
        try {
            $options = $field->getWidget()?->getFormattedOptions('', null, '', false, 101) ?? [];
        } catch (Throwable) {
            $options = [];
        }
        if ($options && (count($options) <= 100)) {
            $ret   .= '<span class="aa-showif-values">';
            $others = array_diff($rule['values'], array_map('strval', array_keys($options)));   // stored values the options no longer have
            foreach ($options + array_combine($others, $others) as $value => $label) {
                $checked = in_array((string) $value, $rule['values'], true) ? ' checked' : '';
                $ret    .= '<label><input type="checkbox" name="rule[' . $i . '][values][]" value="' . safe((string) $value) . '"' . $checked . '> ' . safe(is_array($label) ? (string) ($label['name'] ?? $value) : (string) $label) . '</label> ';
            }
            $ret .= '</span>';
        } else {
            $ret .= '<textarea name="rule[' . $i . '][text]" rows="3" cols="30" aria-label="' . safe(_m('Values, one per line')) . '" placeholder="' . safe(_m('Values, one per line')) . '">' . safe(implode("\n", $rule['values'])) . '</textarea>';
        }
    }
    return $ret . '</div>';
}

$apage = new AA_Adminpageutil();
$apage->setTitle(_m('Display condition'));
$apage->addRequire('aa-code@1');
$apage->printHead([], $errors ? MsgErr(implode('<br>', array_map('safe', $errors))) : '');

echo '<form method="post" class="aa-showif-form">' . FormToken::field(SHOWIF_TOKEN) . '<input type="hidden" name="key" value="' . safe($key) . '">';
FrmTabCaption();
FrmInputText('name', _m('Name'), $form['name'], 254, 40, true);

$rows = '';
foreach ($form['rows'] as $i => $rule) {
    $rows .= ShowIfRuleRow($i, $rule, $names, $fields);
}
FrmStaticText(_m('Show the field when all of these hold'), $rows, '', '', false);

echo '<tr><td colspan="2"><details class="aa-showif-expr"' . (($form['expr'] !== '') ? ' open' : '') . '><summary>' . _m('AA expression (optional)') . '</summary><table>';
FrmTextarea('expr', _m('Expression'), $form['expr'], 3, 60, false, _m('Leave empty to use the rules alone. When filled, the field is shown only if the result is not empty and not 0. Evaluated when the form is opened and when the item is saved.'), '', true, code: true);
echo '</table></details></td></tr>';

FrmInputChBox('clear', _m('Clear the field when a rule hides it'), (bool) $form['clear'], false, '', 1, false, _m('Unchecked: an edited item keeps its value, a new item gets the field\'s default. A field hidden by the expression is never changed.'));

if (($key !== '') && ($cond['conds'] !== '')) {
    FrmStaticText(_m('As a condition of a view'), '<code>' . safe($cond['conds']) . '</code>', '', '', false);
}
if ($key !== '') {
    $used = array_map(static fn($fid) => $fields->getField($fid)->getName(), array_keys(ShowConditions::assigned($fields), $key, true));
    FrmStaticText(_m('Used by'), $used ? implode(', ', $used) : _m('no field'));
}

$buttons = ['update' => ['type' => 'submit', 'value' => _m('Save'), 'title' => _m('Save the changes to this condition. They apply to every field that uses it.'), 'once' => true]];
if ($key !== '') {
    $buttons['saveasnew'] = ['type' => 'submit', 'value' => _m('Save as new'), 'title' => _m('Keep the original condition as it is and save this form as another condition under a new name.'), 'once' => true];
    $buttons['delete']    = ['type' => 'submit', 'value' => _m('Delete'), 'title' => _m('Delete the condition. The fields that use it will be shown always.'), 'once' => true,
                             'add' => 'onclick="return confirm(this.dataset.confirm)" data-confirm="' . safe(_m('Delete the condition? The fields that use it will be shown always.')) . '"'];
}
// not 'cancel' - that one leaves for a URL, this one closes the dialog
$buttons['close_dialog'] = ['type' => 'button', 'value' => _m('Cancel'), 'title' => _m('Close without saving.'), 'js' => 'parent.AA_ShowIfSaved()'];
FrmTabEnd($buttons);
echo '</form>';

$apage->addHelpbox(_m('How it works'), '<ul>'
    . '<li>' . _m('"is" holds when one of the field\'s values is among the checked values, "is not" when none is. Upper and lower case do not count, accents do. "is empty" holds also for a field without a value; in a field stored as a number 0 counts as empty.') . '</li>'
    . '<li>' . _m('The rules are AA conditions, written as in views. A view or {ids} given the condition shown below finds the items whose values satisfy the rules.') . '</li>'
    . '<li>' . _m('The rules follow the form while the user edits it. The expression is evaluated only when the form is opened and when the item is saved, always over the stored item (a new item: over the default values).') . '</li>'
    . '<li>' . _m('A field hidden by a rule is saved empty, unless "Clear the field" is unchecked. A password field, the dates, the status and a field hidden by the expression keep their value.') . '</li>'
    . '<li>' . _m('A field with a condition is shown and hidden by the condition, not by a Field Triggers script.') . '</li>'
    . '<li>' . _m('With an input form template from a view, a field hidden by the expression stays in the source of the page.') . '</li>'
    . '<li>' . _m('Conditions apply only to the item form of the administration. In anonymous forms and imports a required field is required always.') . '</li>'
    . '</ul>', true);
$apage->printFoot();
