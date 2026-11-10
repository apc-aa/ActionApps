<?php

declare(strict_types=1);

namespace AA\Form;

use AA\IO\DB\DB_AA;

/**
 * Named display conditions of a slice: a field of the admin item form is shown only while its condition holds.
 * A condition is an array {name: string, all: list<{field: string, op: string, values: list<string>}>, expr: string, clear: bool}
 * stored under "showif" in slice.aditional (JSON); a field picks one by key under "showif" in field.aditional.
 * It is not a permission - it only tidies the form.
 */
final class ShowConditions
{
    public const OPS    = ['in', 'notin', 'filled', 'empty'];
    /** hidden by the rules - the browser may show it again */
    public const LIVE   = 1;
    /** hidden by the expression - until the form is opened again */
    public const STATIC = 2;

    private const KEY_RE    = '/^[0-9a-f]{8}$/';
    private const FIELD_RE  = '/^[A-Za-z0-9_.]{16}$/';
    /** operators that compare with values */
    private const VALUE_OPS = ['in', 'notin'];

    /** @var array<string, array> */
    private array $conds = [];

    /** Reads slice.aditional; anything malformed is left out, so a broken condition never hides a field. */
    public static function fromJson(?string $slice_aditional): self
    {
        $ret  = new self();
        $list = self::decode($slice_aditional)['showif'] ?? null;
        if (is_array($list)) {
            foreach ($list as $key => $cond) {
                $key = (string) $key;
                if (self::isKey($key) && is_array($cond) && ($cond = self::normalize($cond))) {
                    $ret->conds[$key] = $cond;
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

    public function set(string $key, array $cond): void
    {
        $this->conds[$key] = $cond;
    }

    public function remove(string $key): void
    {
        unset($this->conds[$key]);
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

    /**
     * Values compare as strings, exactly. "filled" means truthy (not '' and not '0'), so a checkbox reads
     * the same from the form ('on') and from the database ('1' / '0').
     * @param array{op: string, values: list<string>} $rule
     * @param array<int|string|float|null> $values
     */
    public static function ruleMatches(array $rule, array $values): bool
    {
        $values = array_map('strval', $values);
        return match ($rule['op']) {
            'in'     => (bool) array_intersect($values, $rule['values']),
            'notin'  => !array_intersect($values, $rule['values']),
            'filled' => self::filled($values),
            'empty'  => !self::filled($values),
            default  => true,
        };
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
     * @return array<string, int> id of a hidden field => LIVE|STATIC
     */
    public function hidden(array $assigned, callable $valuesOf, callable $expand): array
    {
        $state = [];   // field id => 0 shown | LIVE | STATIC | -1 being evaluated
        $eval  = function (string $fid) use (&$eval, &$state, $assigned, $valuesOf, $expand): int {
            if (isset($state[$fid])) {
                return max(0, $state[$fid]);
            }
            $cond = $this->conds[$assigned[$fid] ?? ''] ?? null;
            if ($cond === null) {
                return $state[$fid] = 0;
            }
            if (($cond['expr'] !== '') && !self::filled([trim($expand($cond['expr']))])) {
                return $state[$fid] = self::STATIC;
            }
            $state[$fid] = -1;
            $ret = 0;
            foreach ($cond['all'] as $rule) {
                if (!array_key_exists($rule['field'], $assigned)) {
                    continue;
                }
                if (($ret = max($ret, $eval($rule['field']))) === self::STATIC) {
                    break;
                }
                if (($ret === 0) && !self::ruleMatches($rule, $valuesOf($rule['field']))) {
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
     * from $fallback (the stored item), else with $defaults from the field's default including the profile -
     * the same values whether the form is being drawn or saved.
     * Values and results of expressions are computed once - several rules and fields share them.
     * @return array<string, int> id of a hidden field => LIVE|STATIC
     */
    public function hiddenFor(\AA_Fields $fields, \AA_Content $content, ?\AA_Content $fallback = null, bool $defaults = false): array
    {
        if (!$this->conds) {
            return [];
        }
        $values   = [];
        $valuesOf = function (string $fid) use (&$values, $fields, $content, $fallback, $defaults): array {
            if (isset($values[$fid])) {
                return $values[$fid];
            }
            if ($content->isSet($fid)) {
                return $values[$fid] = $content->getValuesArray($fid);
            }
            if ($fallback?->isSet($fid)) {
                return $values[$fid] = $fallback->getValuesArray($fid);
            }
            return $values[$fid] = ($defaults && ($field = $fields->getField($fid))) ? $field->getDefault(true)->getValues() : [];
        };
        $item    = null;
        $results = [];
        $expand  = function (string $expr) use (&$item, &$results, $content, $fallback): string {
            $item ??= GetItemFromContent(new \ItemContent(array_replace($fallback?->getContent() ?? [], $content->getContent())));
            return $results[$expr] ??= \AA::Un($expr, '', $item);
        };
        return $this->hidden(self::assigned($fields), $valuesOf, $expand);
    }

    /**
     * Changes the content being saved from the admin form: a field hidden by a rule is stored empty (when its
     * condition clears), anything else hidden keeps its stored value - it is taken out of the content, as
     * a field with "Show" off is - and a new item gets the field's default. A protected field (password, 2FA)
     * is never emptied: its inserter would store the empty value as the password. A field the user may not edit
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
            if (($how === self::LIVE) && $this->conds[$assigned[$fid]]['clear'] && !$field->isProtected()) {
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
     * @return array{fields: array<string, list<array{fid: string, op: string, v: list<string>}>>, hidden: list<string>, off: list<string>}
     *  fields = every field with a known condition => its rules; hidden = fields LIVE; off = fields STATIC
     */
    public function formConfig(\AA_Fields $fields, array $hidden): array
    {
        $assigned = self::assigned($fields);
        $ret      = ['fields' => [], 'hidden' => [], 'off' => []];
        foreach ($assigned as $fid => $key) {
            if (($key === '') || !($cond = $this->conds[$key] ?? null)) {
                continue;
            }
            $rules = array_filter($cond['all'], static fn(array $rule): bool => array_key_exists($rule['field'], $assigned));
            $ret['fields'][$fid] = array_values(array_map(static fn(array $rule): array => ['fid' => $rule['field'], 'op' => $rule['op'], 'v' => $rule['values']], $rules));
        }
        foreach ($hidden as $fid => $how) {
            $ret[($how === self::STATIC) ? 'off' : 'hidden'][] = (string) $fid;
        }
        return $ret;
    }

    /**
     * The condition the editor (admin/se_showif.php) posted. A rule row without a field is skipped; the values
     * come from the checkboxes and from the text area, one per line.
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
        $all = [];
        foreach ((is_array($post['rule'] ?? null) ? $post['rule'] : []) as $row) {
            $field = is_array($row) && is_string($row['field'] ?? null) ? $row['field'] : '';
            if ($field === '') {
                continue;
            }
            $label = $names[$field] ?? $field;
            $op    = $row['op'] ?? null;
            if (!isset($names[$field])) {
                $errors[] = _m('The field %1 is not in this slice.', [$label]);
            } elseif (!in_array($op, self::OPS, true)) {
                $errors[] = _m('Choose how to compare the field %1.', [$label]);
            } elseif (!($rule = self::rule($field, $op, self::postedValues($row)))) {
                $errors[] = _m('Fill in the values of the field %1.', [$label]);
            } else {
                $all[] = $rule;
            }
        }
        $expr = is_string($post['expr'] ?? null) ? trim($post['expr']) : '';
        if (!$all && ($expr === '')) {
            $errors[] = _m('Add a rule or an expression.');
        }
        return $errors ? [null, $errors] : [['name' => $name, 'all' => $all, 'expr' => $expr, 'clear' => isset($post['clear'])], []];
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

    /** @param list<string> $values */
    private static function filled(array $values): bool
    {
        foreach ($values as $v) {
            if (($v !== '') && ($v !== '0')) {
                return true;
            }
        }
        return false;
    }

    /** the condition with only what it may hold, or null when it has no name */
    private static function normalize(array $cond): ?array
    {
        $name = is_string($cond['name'] ?? null) ? trim($cond['name']) : '';
        if ($name === '') {
            return null;
        }
        $all = [];
        foreach ((array) ($cond['all'] ?? []) as $rule) {
            if (is_array($rule) && is_string($rule['field'] ?? null) && preg_match(self::FIELD_RE, $rule['field']) && is_string($rule['op'] ?? null)
                && ($rule = self::rule($rule['field'], $rule['op'], array_values(array_map('strval', array_filter((array) ($rule['values'] ?? []), 'is_scalar')))))) {
                $all[] = $rule;
            }
        }
        return [
            'name'  => $name,
            'all'   => $all,
            'expr'  => is_string($cond['expr'] ?? null) ? $cond['expr'] : '',
            'clear' => (bool) ($cond['clear'] ?? true),
        ];
    }

    /**
     * @param list<string> $values
     * @return ?array the rule, null for an unknown operator or a comparison with no values
     */
    private static function rule(string $field, string $op, array $values): ?array
    {
        if (!in_array($op, self::OPS, true)) {
            return null;
        }
        $compares = in_array($op, self::VALUE_OPS, true);
        return ($compares && !$values) ? null : ['field' => $field, 'op' => $op, 'values' => $compares ? $values : []];
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
