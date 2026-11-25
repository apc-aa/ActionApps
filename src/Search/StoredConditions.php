<?php

declare(strict_types=1);

namespace AA\Search;

use AA_Operators;

/**
 * Named conditions kept in one text column - the conditions of a view after its third (view.conds).
 * A condition has a name: a number, or a few small letters and digits. A call redefines its value by the name
 * (cmd[]=c-5-value, cmd[]=c-author-value), so the name is stored with the condition and removing another one
 * does not move it. Unlike the d- form of a URL the stored text holds also a condition with an empty value -
 * one that waits for the value of the call.
 *
 * One condition a line: name, fields divided by commas (the condition holds when any of them matches),
 * operator and value, divided by tabs. The bytes of the value are kept as they came - an admin page may
 * be in another charset than UTF-8.
 */
final class StoredConditions
{
    /** six characters: the name in capitals fits an alias (_#C_AUTHOR) */
    public const NAME_MAXLENGTH = 6;
    public const NAME_PATTERN   = '[a-z0-9]{1,6}';

    /** a field id, also of related items: headline........@relation.......1, name@<slice>/relation.......2+ */
    private const FIELD_RE = '~^[A-Za-z0-9_.@/+]{1,100}$~D';

    /** @var array<int|string, array{fields: list<string>, operator: string, value: string}> by their names, as stored */
    private array $conditions;

    /** @param array<int|string, array{fields: list<string>, operator: string, value: string}> $conditions */
    private function __construct(array $conditions)
    {
        $this->conditions = $conditions;
    }

    /** @param int $first the lowest number a condition of the text may have for its name - a lower one is not read */
    public static function fromText(string $stored, int $first = 1): self
    {
        $conditions = [];
        foreach (($stored === '') ? [] : (preg_split('/\r?\n/', $stored) ?: []) as $line) {
            [$name, $fields, $operator, $value] = array_pad(explode("\t", $line, 4), 4, '');
            if (self::isName($name, $first) && ($condition = self::condition(explode(',', $fields), $operator, $value))) {
                $conditions[$name] = $condition;
            }
        }
        return new self($conditions);
    }

    /**
     * The conditions as the form posts them (ConditionsInput): rows of [name, fields => [...], operator, value],
     * in the order of the form. What is no condition - no field chosen, an unknown operator - is left out, so
     * a row emptied in the form is a row removed. A row whose name is not allowed (also a number below $first,
     * or one of $reserved) or belongs to a row above gets the lowest free number, so no condition is lost for its name.
     * @param list<int|string> $reserved names no condition may have
     */
    public static function fromForm(mixed $posted, int $first, array $reserved = []): self
    {
        $rows  = [];
        $taken = array_fill_keys($reserved, true);
        foreach (is_array($posted) ? $posted : [] as $key => $row) {
            $condition = is_array($row) ? self::condition(is_array($row['fields'] ?? null) ? $row['fields'] : [], is_string($row['operator'] ?? null) ? $row['operator'] : '', is_string($row['value'] ?? null) ? $row['value'] : '') : null;
            if (!$condition) {
                continue;
            }
            $name = is_string($row['name'] ?? null) ? strtolower(trim($row['name'])) : (string) $key;
            if (self::isName($name, $first) && !isset($taken[$name])) {
                $taken[$name] = true;
                $rows[]       = [$name, $condition];
            } else {
                $rows[] = [null, $condition];
            }
        }
        $conditions = [];
        foreach ($rows as [$name, $condition]) {
            if ($name === null) {
                $name = max(1, $first);
                while (isset($taken[$name])) {
                    $name++;
                }
                $taken[$name] = true;
            }
            $conditions[$name] = $condition;
        }
        return new self($conditions);
    }

    /** a number from $first up, written plainly (4, not 04), or small letters and digits */
    private static function isName(string $name, int $first): bool
    {
        if (preg_match('~^' . self::NAME_PATTERN . '$~D', $name) !== 1) {
            return false;
        }
        return !ctype_digit($name) || (((string) (int) $name === $name) && ((int) $name >= max(1, $first)));
    }

    /**
     * @param array<mixed> $fields
     * @return ?array{fields: list<string>, operator: string, value: string} null - no condition
     */
    private static function condition(array $fields, string $operator, string $value): ?array
    {
        $fields = array_values(array_unique(array_filter($fields, static fn(mixed $field): bool => is_string($field) && (preg_match(self::FIELD_RE, $field) === 1))));
        if (!$fields || !isset(AA_Operators::getEnum()[1][$operator])) {
            return null;
        }
        // a tab or a line break would be read back as the end of the value
        return ['fields' => $fields, 'operator' => $operator, 'value' => strtr($value, "\t\r\n", '   ')];
    }

    public function toText(): string
    {
        $lines = [];
        foreach ($this->conditions as $name => $condition) {
            $lines[] = implode("\t", [$name, implode(',', $condition['fields']), $condition['operator'], $condition['value']]);
        }
        return implode("\n", $lines);
    }

    /** @return array<int|string, array{fields: list<string>, operator: string, value: string}> by their names, as stored */
    public function all(): array
    {
        return $this->conditions;
    }

    /**
     * The value each condition works with.
     * @param array<int|string, mixed> $given the values a call gives by the names, in any case of letters; '' or none - the stored one
     * @return array<int|string, string> by the names
     */
    public function values(array $given = []): array
    {
        $given  = array_change_key_case($given);
        $values = [];
        foreach ($this->conditions as $name => $condition) {
            $value         = $given[$name] ?? '';
            $values[$name] = (is_scalar($value) && (strlen((string) $value) > 0)) ? (string) $value : $condition['value'];
        }
        return $values;
    }

    /**
     * The conditions as AA_Set::addCondsFromArray() takes them, in the stored order. One left without
     * a value is still here: the reader of the array drops it, as it drops such a condition 1 - 3.
     * @param array<int|string, mixed> $given the values a call gives by the names (values())
     * @return list<array<string, mixed>>
     */
    public function toConds(array $given = []): array
    {
        $conds = [];
        foreach ($this->values($given) as $name => $value) {
            $conds[] = ['operator' => $this->conditions[$name]['operator'], 'value' => $value] + array_fill_keys($this->conditions[$name]['fields'], 1);
        }
        return $conds;
    }
}
