<?php

declare(strict_types=1);

namespace AA\Form;

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

    private const KEY_RE   = '/^[0-9a-f]{8}$/';
    private const FIELD_RE = '/^[A-Za-z0-9_.]{16}$/';

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
                if (preg_match(self::KEY_RE, $key) && is_array($cond) && ($cond = self::normalize($cond))) {
                    $ret->conds[$key] = $cond;
                }
            }
        }
        return $ret;
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
        return (is_string($key) && preg_match(self::KEY_RE, $key)) ? $key : '';
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
            $state[$fid] = -1;
            $ret = 0;
            if (($cond['expr'] !== '') && !self::filled([trim($expand($cond['expr']))])) {
                $ret = self::STATIC;
            }
            foreach ($cond['all'] as $rule) {
                if ($ret === self::STATIC) {
                    break;
                }
                if (!array_key_exists($rule['field'], $assigned)) {
                    continue;
                }
                $ret = max($ret, $eval($rule['field']));
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
            if (!is_array($rule) || !is_string($rule['field'] ?? null) || !preg_match(self::FIELD_RE, $rule['field']) || !in_array($rule['op'] ?? null, self::OPS, true)) {
                continue;
            }
            $values = [];
            if (in_array($rule['op'], ['in', 'notin'], true)) {
                $values = array_values(array_map('strval', array_filter((array) ($rule['values'] ?? []), 'is_scalar')));
                if (!$values) {
                    continue;
                }
            }
            $all[] = ['field' => $rule['field'], 'op' => $rule['op'], 'values' => $values];
        }
        return [
            'name'  => $name,
            'all'   => $all,
            'expr'  => is_string($cond['expr'] ?? null) ? $cond['expr'] : '',
            'clear' => (bool) ($cond['clear'] ?? true),
        ];
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
