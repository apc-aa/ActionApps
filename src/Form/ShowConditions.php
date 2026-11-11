<?php

declare(strict_types=1);

namespace AA\Form;

use AA\IO\DB\DB_AA;
use AA\Search\QueryExpression;

/**
 * Named display conditions of a slice: a field of the admin item form is shown only while its condition holds.
 * A condition is an array {name: string, conds: string, expr: string, clear: bool} stored under "showif"
 * in slice.aditional (JSON); a field picks one by key under "showif" in field.aditional.
 * The rules (conds) are AA conditions in the d- form of views - d-category.......1-=-"News" OR "Events" -
 * of the operators the browser evaluates alike (AA_Condition::matchesValues() and AA_ShowIfInit()), one field each.
 * It is not a permission - it only tidies the form.
 */
final class ShowConditions
{
    public const OPS    = ['=', '==', '<>', 'BEGIN', 'CONTAIN', 'ISNULL', 'NOTNULL'];
    /** hidden by the rules - the browser may show it again */
    public const LIVE   = 1;
    /** hidden by the expression - until the form is opened again */
    public const STATIC = 2;

    private const KEY_RE   = '/^[0-9a-f]{8}$/D';
    private const FIELD_RE = '/^[A-Za-z0-9_.]{16}$/D';

    /** @var array<string, array{name: string, conds: string, expr: string, clear: bool}> */
    private array $conds = [];
    /** @var array<string, list<\AA_Condition>> key => the rules of the condition */
    private array $rules = [];

    /** Reads slice.aditional; anything malformed is left out, so a broken condition never hides a field. */
    public static function fromJson(?string $slice_aditional): self
    {
        $ret  = new self();
        $list = self::decode($slice_aditional)['showif'] ?? null;
        if (is_array($list)) {
            foreach ($list as $key => $cond) {
                $key = (string) $key;
                if (self::isKey($key) && is_array($cond)) {
                    $ret->set($key, $cond);
                }
            }
        }
        return $ret;
    }

    public static function forSlice(string $slice_id): self
    {
        // false for a missing row, null for an unknown module
        return self::fromJson((string) \AA_Slice::getModuleProperty($slice_id, 'aditional'));
    }

    /** slice.aditional with these conditions under "showif"; other keys stay */
    public function toJson(string $slice_aditional): string
    {
        $data = self::decode($slice_aditional);
        if ($this->conds) {
            $data['showif'] = $this->conds;
        } else {
            unset($data['showif']);
        }
        return self::encode($data);
    }

    /** @return array<string, array> key => condition, by name */
    public function all(): array
    {
        $ret = $this->conds;
        uasort($ret, fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $ret;
    }

    public function isEmpty(): bool
    {
        return !$this->conds;
    }

    public function get(string $key): ?array
    {
        return $this->conds[$key] ?? null;
    }

    /** A condition without a name removes the key, a rule the browser could not evaluate is dropped. */
    public function set(string $key, array $cond): void
    {
        $name = is_string($cond['name'] ?? null) ? trim($cond['name']) : '';
        if ($name === '') {
            $this->remove($key);
            return;
        }
        $rules              = self::parse(is_string($cond['conds'] ?? null) ? $cond['conds'] : '');
        $this->rules[$key]  = $rules;
        $this->conds[$key]  = [
            'name'  => $name,
            'conds' => self::toString($rules),
            'expr'  => is_string($cond['expr'] ?? null) ? $cond['expr'] : '',
            'clear' => (bool) ($cond['clear'] ?? true),
        ];
    }

    public function remove(string $key): void
    {
        unset($this->conds[$key], $this->rules[$key]);
    }

    /**
     * The rules as the editor shows them, one row per field and comparison: "==" as "=" of one value, the alternatives
     * of "=" together, the "<>" of one field following each other together (all of them must hold - the field is none of them).
     * @return list<array{field: string, op: string, values: list<string>}>
     */
    public function rows(string $key): array
    {
        $ret = [];
        foreach ($this->rules[$key] ?? [] as $rule) {
            $op   = $rule->getOperator();
            $row  = ['field' => $rule->getFields()[0], 'op' => ($op === '==') ? '=' : $op, 'values' => self::values($rule)];
            $last = array_key_last($ret);
            if (($row['op'] === '<>') && ($last !== null) && ($ret[$last]['op'] === '<>') && ($ret[$last]['field'] === $row['field'])) {
                $ret[$last]['values'] = array_merge($ret[$last]['values'], $row['values']);
            } else {
                $ret[] = $row;
            }
        }
        return $ret;
    }

    public static function isKey(string $key): bool
    {
        return (bool) preg_match(self::KEY_RE, $key);
    }

    /** With a letter in it, so PHP never turns the array key into an int. */
    public static function newKey(): string
    {
        do {
            $key = bin2hex(random_bytes(4));
        } while (ctype_digit($key));
        return $key;
    }

    /** key of the condition the field uses; '' = none or malformed */
    public static function keyOfField(?string $field_aditional): string
    {
        $key = self::decode($field_aditional)['showif'] ?? '';
        return (is_string($key) && self::isKey($key)) ? $key : '';
    }

    /** field.aditional with the condition set ('' removes it); other keys stay */
    public static function fieldJson(string $field_aditional, string $key): string
    {
        $data = self::decode($field_aditional);
        if ($key === '') {
            unset($data['showif']);
        } else {
            $data['showif'] = $key;
        }
        return self::encode($data);
    }

    /**
     * Which fields are hidden. A field with a condition is shown when the expression is empty or gives
     * something other than '' / '0', every rule holds and every controlling field that has a condition
     * is shown itself. Broken references fail towards showing: an unknown key, a rule on a field
     * the slice does not have, a controlling field in a cycle.
     * @param array<string, string> $assigned id of every field of the slice => key of its condition or ''
     * @param callable(string): list<string> $valuesOf field id => its values
     * @param callable(string): string $expand expression => its result
     * @param list<string> $numeric ids of the fields stored as numbers - their 0 is empty
     * @return array<string, int> id of a hidden field => LIVE|STATIC
     */
    public function hidden(array $assigned, callable $valuesOf, callable $expand, array $numeric = []): array
    {
        $state = [];   // field id => 0 shown | LIVE | STATIC | -1 being evaluated
        $eval  = function (string $fid) use (&$eval, &$state, $assigned, $valuesOf, $expand, $numeric): int {
            if (isset($state[$fid])) {
                return max(0, $state[$fid]);
            }
            $key  = $assigned[$fid] ?? '';
            $cond = $this->conds[$key] ?? null;
            if ($cond === null) {
                return $state[$fid] = 0;
            }
            if (($cond['expr'] !== '') && in_array(trim($expand($cond['expr'])), ['', '0'], true)) {
                return $state[$fid] = self::STATIC;
            }
            $state[$fid] = -1;
            $ret = 0;
            foreach ($this->rules[$key] as $rule) {
                $ctl = $rule->getFields()[0];
                if (!array_key_exists($ctl, $assigned)) {
                    continue;
                }
                if (($ret = max($ret, $eval($ctl))) === self::STATIC) {
                    break;
                }
                if (($ret === 0) && !$rule->matchesValues($valuesOf, $numeric)) {
                    $ret = self::LIVE;
                }
            }
            return $state[$fid] = $ret;
        };

        $ret = [];
        foreach ($assigned as $fid => $key) {
            $fid = (string) $fid;
            if (($key !== '') && ($s = $eval($fid))) {
                $ret[$fid] = $s;
            }
        }
        return $ret;
    }

    /**
     * hidden() over an item. A field's values come from $content; a field missing there (not in the form)
     * from $fallback (the stored item), else with $defaults from what a new item gets - the profile's fill,
     * else the field's default - the same values whether the form is being drawn or saved.
     * The expression sees what the form is drawn from: the stored item, for a new one the defaults. The user's
     * unsaved values would make it differ between drawing (a field left out) and saving (that field missing).
     * Values and results of expressions are computed once - several rules and fields share them.
     * @return array<string, int> id of a hidden field => LIVE|STATIC
     */
    public function hiddenFor(\AA_Fields $fields, \AA_Content $content, ?\AA_Content $fallback = null, bool $defaults = false): array
    {
        if (!$this->conds) {
            return [];
        }
        $default  = static function (\AA_Field $field): \AA_Value {
            $profile = \AA_Profile::getProfile(AuthGetUid(), $field->getSliceId());
            return $profile->getFormFieldValue($field->getId()) ?? $field->getDefault(true);
        };
        $values   = [];
        // '' is no value - as getFieldValues() reads the form in the browser
        $valuesOf = function (string $fid) use (&$values, $fields, $content, $fallback, $defaults, $default): array {
            if (isset($values[$fid])) {
                return $values[$fid];
            }
            if ($content->isSet($fid)) {
                $ret = $content->getValuesArray($fid);
                // a checkbox of an old form sends 'on', which its inserter stores as 1
                if (str_starts_with((string) $fields->getField($fid)?->getProperty('input_show_func'), 'chb')) {
                    $ret = array_map(static fn($v) => ($v === 'on') ? '1' : $v, $ret);
                }
            } elseif ($fallback?->isSet($fid)) {
                $ret = $fallback->getValuesArray($fid);
            } else {
                $ret = ($defaults && ($field = $fields->getField($fid))) ? $default($field)->getValues() : [];
            }
            return $values[$fid] = array_values(array_filter(array_map('strval', $ret), static fn(string $v): bool => $v !== ''));
        };
        $item    = null;
        $results = [];
        $expand  = function (string $expr) use (&$item, &$results, $fields, $content, $fallback, $defaults, $default): string {
            if (!$item) {
                $source = $fallback ?? $content;
                if ($defaults) {
                    $source = new \ItemContent();
                    foreach ($fields as $fid => $field) {
                        $source->setAaValue($fid, $default($field));
                        $source->setSliceID($field->getSliceId());
                    }
                }
                $item = GetItemFromContent($source);
            }
            return $results[$expr] ??= \AA::Un($expr, '', $item);
        };
        return $this->hidden(self::assigned($fields), $valuesOf, $expand, self::numeric($fields));
    }

    /**
     * Changes the content being saved from the admin form: a field hidden by a rule is stored empty (when its
     * condition clears), anything else hidden keeps its stored value - it is taken out of the content, as
     * a field with "Show" off is - and a new item gets the field's default. A protected field (password, 2FA)
     * or a column of the item table is never emptied. A field the user may not edit
     * (frozen, filled or hidden by the profile) is left alone.
     * @return list<string> ids of the fields hidden by a condition
     */
    public function apply(\ItemContent $content, \AA_Fields $fields, ?\AA_Content $old, bool $insert): array
    {
        $hidden   = $this->hiddenFor($fields, $content, $old, $insert);
        $assigned = $hidden ? self::assigned($fields) : [];
        foreach ($hidden as $fid => $how) {
            $field   = $fields->getField($fid);
            $profile = \AA_Profile::getProfile(AuthGetUid(), $field->getSliceId());
            // what the user may not edit stays as setFromForm() left it: the stored value, or the profile's
            if ($insert ? $profile->getFormFieldValue($fid) : !IsEditable($old?->isSet($fid) ? $old->getFlag($fid) : 0, $field, $profile)) {
                continue;
            }
            unset($content[$fid]);
            // never emptied: a password (its inserter would store '' as the password), a column of the item table ('' = 0: expired, trashed)
            if (($how === self::LIVE) && $this->conds[$assigned[$fid]]['clear'] && !$field->isProtected() && ($field->storageTable() !== 'item')) {
                // when editing, only a stored value needs emptying
                if ($insert || ($old && (implode('', $old->getValuesArray($fid)) !== ''))) {
                    $content[$fid] = new \AA_Value('');
                }
            } elseif ($insert) {
                $default = $field->getDefault(true);
                if (!$default->isEmpty()) {
                    $content[$fid] = $default;
                }
            }
        }
        return array_keys($hidden);
    }

    /**
     * What AA_ShowIfInit() (javascript/inputform.js) needs. A rule on a field the slice does not have is left
     * out - it holds, as in hidden().
     * @param array<string, int> $hidden the result of hiddenFor()
     * @return array{fields: array<string, list<array{fid: string, op: string, v: list<string>, n: bool}>>, hidden: list<string>, off: list<string>}
     *  fields = every field with a known condition => its rules (v = the compared values, n = a number field);
     *  hidden = fields LIVE; off = fields STATIC
     */
    public function formConfig(\AA_Fields $fields, array $hidden): array
    {
        $assigned = self::assigned($fields);
        $numeric  = self::numeric($fields);
        $ret      = ['fields' => [], 'hidden' => [], 'off' => []];
        foreach ($assigned as $fid => $key) {
            if (!isset($this->conds[$key])) {
                continue;
            }
            $ret['fields'][$fid] = [];
            foreach ($this->rules[$key] as $rule) {
                $ctl = $rule->getFields()[0];
                if (array_key_exists($ctl, $assigned)) {
                    $ret['fields'][$fid][] = ['fid' => $ctl, 'op' => $rule->getOperator(), 'v' => self::values($rule), 'n' => in_array($ctl, $numeric, true)];
                }
            }
        }
        foreach ($hidden as $fid => $how) {
            $ret[($how === self::STATIC) ? 'off' : 'hidden'][] = (string) $fid;
        }
        return $ret;
    }

    /**
     * The condition the editor (admin/se_showif.php) posted. A rule row without a field is skipped; the values
     * come from the checkboxes and from the text area, one per line. "is" becomes one "=" of the values joined
     * by OR - one value "==", the exact match -, "is not" one "<>" for each value; "begins with" and "contains"
     * take the first value.
     * @param array $post name, rule[i][field|op|values[]|text], expr, clear
     * @param array<string, string> $names id of a field of the slice => its name
     * @param string $key key of the edited condition, '' for a new one - its own name is no clash
     * @return array{0: ?array, 1: list<string>} the condition (null on error) and the errors, plain text
     */
    public function fromPost(array $post, array $names, string $key): array
    {
        $errors = [];
        $name   = is_string($post['name'] ?? null) ? trim($post['name']) : '';
        if ($name === '') {
            $errors[] = _m('Fill in the name of the condition.');
        }
        foreach ($this->conds as $k => $cond) {
            if (((string) $k !== $key) && (mb_strtolower($cond['name']) === mb_strtolower($name))) {
                $errors[] = _m('Another condition of this slice is called %1.', [$name]);
            }
        }
        $rules = [];
        foreach ((is_array($post['rule'] ?? null) ? $post['rule'] : []) as $row) {
            $field = is_array($row) && is_string($row['field'] ?? null) ? $row['field'] : '';
            if ($field === '') {
                continue;
            }
            $label  = $names[$field] ?? $field;
            $op     = $row['op'] ?? null;
            $values = self::postedValues($row);
            if (!isset($names[$field])) {
                $errors[] = _m('The field %1 is not in this slice.', [$label]);
            } elseif (!in_array($op, self::OPS, true)) {
                $errors[] = _m('Choose how to compare the field %1.', [$label]);
            } elseif (!$values && !in_array($op, ['ISNULL', 'NOTNULL'], true)) {
                $errors[] = _m('Fill in the values of the field %1.', [$label]);
            } elseif (($op === '=') && (($expression = QueryExpression::ofAlternatives($values)) === null)) {
                $errors[] = _m('A value of the field %1 contains both kinds of quotes.', [$label]);
            } elseif (in_array($op, ['BEGIN', 'CONTAIN'], true) && str_contains($values[0], '\\')) {
                // the d- form strips slashes of an unquoted value
                $errors[] = _m('The value of the field %1 must not contain a backslash.', [$label]);
            } else {
                $rules = array_merge($rules, match ($op) {
                    '='                 => [self::exact($values) ? new \AA_Condition([$field], '==', $values[0]) : new \AA_Condition([$field], '=', $expression)],
                    // quoted, so that a value starting with a quote survives; the SQL strips slashes
                    '<>'                => array_map(static fn(string $v): \AA_Condition => new \AA_Condition([$field], '<>', '"' . str_replace('\\', '\\\\', $v) . '"'), $values),
                    'BEGIN', 'CONTAIN'  => [new \AA_Condition([$field], $op, $values[0])],
                    default             => [new \AA_Condition([$field], $op, '1')],
                });
            }
        }
        $expr = is_string($post['expr'] ?? null) ? trim($post['expr']) : '';
        if (!$rules && ($expr === '')) {
            $errors[] = _m('Add a rule or an expression.');
        }
        return $errors ? [null, $errors] : [['name' => $name, 'conds' => self::toString($rules), 'expr' => $expr, 'clear' => isset($post['clear'])], []];
    }

    /**
     * Values of a posted rule row: the checked boxes (values[]) and the lines of the text area (text), trimmed, once.
     * @return list<string>
     */
    public static function postedValues(array $row): array
    {
        $lines  = is_string($row['text'] ?? null) ? preg_split('/\R/', $row['text']) : [];
        $values = array_merge(array_filter((array) ($row['values'] ?? []), 'is_string'), $lines);
        return array_values(array_unique(array_filter(array_map('trim', $values), static fn(string $v): bool => $v !== '')));
    }

    /** Stores the conditions into slice.aditional, keeping its other keys. */
    public function save(string $slice_id): void
    {
        $current = (string) DB_AA::select1('aditional', 'SELECT `aditional` FROM `slice`', [['id', $slice_id, 'l']]);
        \AA::Metabase()->doUpdate('slice', ['id' => $slice_id, 'aditional' => $this->toJson($current)]);
    }

    /** Removes the condition and takes it off every field of the slice that uses it, in one transaction. */
    public function delete(string $slice_id, string $key, \AA_Fields $fields): void
    {
        $this->remove($key);
        DB_AA::transaction(function () use ($slice_id, $key, $fields): void {
            $this->save($slice_id);
            foreach (array_keys(self::assigned($fields), $key, true) as $fid) {
                \AA::Metabase()->doUpdate('field', ['id' => $fid, 'slice_id' => $slice_id, 'aditional' => self::fieldJson((string) $fields->getField($fid)['aditional'], '')]);
            }
        });
    }

    /** @return array<string, string> id of every field => key of its condition or '' */
    public static function assigned(\AA_Fields $fields): array
    {
        $ret = [];
        foreach ($fields as $fid => $field) {
            // ArrayAccess, not getProperty(): fields defined in a slice have no such column
            $ret[(string) $fid] = self::keyOfField($field['aditional']);
        }
        return $ret;
    }

    /**
     * One value the d- form keeps as it is, unquoted: no backslash and no %22 around it, which reading
     * the d- form would strip or decode.
     * @param list<string> $values
     */
    private static function exact(array $values): bool
    {
        return (count($values) === 1) && !str_contains($values[0], '\\') && !(str_starts_with($values[0], '%22') && str_ends_with($values[0], '%22'));
    }

    /** @return list<string> ids of the fields stored as numbers, as the SQL of a condition sees them */
    private static function numeric(\AA_Fields $fields): array
    {
        $ret = [];
        foreach ($fields as $fid => $field) {
            $column = $field->storageColumn();
            if (($field->storageTable() === 'item') ? (\AA::Metabase()->getFieldType('item', $column) === 'int') : ($column === 'number')) {
                $ret[] = (string) $fid;
            }
        }
        return $ret;
    }

    /**
     * The rules of a d- string the browser evaluates alike: one field, an operator of OPS, "=" only with
     * alternatives joined by OR. Anything else is left out - a broken rule never hides a field.
     * @return list<\AA_Condition>
     */
    private static function parse(string $conds): array
    {
        if (!str_starts_with(trim($conds), 'd-')) {
            return [];
        }
        $set = new \AA_Set();
        $set->_parseViewConds($conds);
        return array_values(array_filter($set->getConditions(), static fn(\AA_Condition $rule): bool => (count($rule->getFields()) === 1)
            && preg_match(self::FIELD_RE, $rule->getFields()[0])
            && in_array($rule->getOperator(), self::OPS, true)
            && (self::values($rule) !== null)));
    }

    /** @param list<\AA_Condition> $rules */
    private static function toString(array $rules): string
    {
        $set = new \AA_Set();
        foreach ($rules as $rule) {
            $set->addCondition($rule);
        }
        return $set->getCondsAsString();
    }

    /** @return ?list<string> what the rule compares with: none for ISNULL / NOTNULL; null when "=" is more than OR */
    private static function values(\AA_Condition $rule): ?array
    {
        return in_array($rule->getOperator(), ['ISNULL', 'NOTNULL'], true) ? [] : $rule->comparedValues();
    }

    /** @return array<string, mixed> the JSON object, [] for anything else */
    private static function decode(?string $json): array
    {
        $data = json_decode((string) $json, true);
        return (is_array($data) && !array_is_list($data)) ? $data : [];
    }

    private static function encode(array $data): string
    {
        return $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';
    }
}
