<?php

declare(strict_types=1);

namespace AA\Search;

use AA_Operators;

/**
 * Numbered conditions kept in one text column - the conditions of a view after its third (view.conds).
 * A condition has its number for good: a call redefines its value by it (cmd[]=c-5-value), so the number
 * is stored with the condition and removing another one does not move it. Unlike the d- form of a URL the
 * stored text holds also a condition with an empty value - one that waits for the value of the call.
 *
 * One condition a line: number, fields divided by commas (the condition holds when any of them matches),
 * operator and value, divided by tabs. The bytes of the value are kept as they came - an admin page may
 * be in another charset than UTF-8.
 */
final class StoredConditions
{
    /** view.cond1cond is varchar(255) - a value of a later condition is no longer */
    public const VALUE_MAX = 255;

    /** a field id, also of related items: headline........@relation.......1, name@<slice>/relation.......2+ */
    private const FIELD_RE = '~^[A-Za-z0-9_.@/+]{1,100}$~D';

    /** @var array<int, array{fields: list<string>, operator: string, value: string}> by their numbers, ascending */
    private array $conditions;

    /** @param array<int, array{fields: list<string>, operator: string, value: string}> $conditions */
    private function __construct(array $conditions)
    {
        ksort($conditions);
        $this->conditions = $conditions;
    }

    /** @param int $first the lowest number a condition of the text may have - a lower one is not read */
    public static function fromText(string $stored, int $first = 1): self
    {
        $conditions = [];
        foreach (($stored === '') ? [] : (preg_split('/\r?\n/', $stored) ?: []) as $line) {
            [$number, $fields, $operator, $value] = array_pad(explode("\t", $line, 4), 4, '');
            self::add($conditions, $first, $number, explode(',', $fields), $operator, $value);
        }
        return new self($conditions);
    }

    /**
     * The conditions as the form posts them (ConditionsInput): number => [fields => [...], operator, value].
     * What is no condition - no field chosen, an unknown operator, a number below $first - is left out,
     * so a row emptied in the form is a row removed.
     */
    public static function fromForm(mixed $posted, int $first): self
    {
        $conditions = [];
        foreach (is_array($posted) ? $posted : [] as $number => $row) {
            if (is_array($row)) {
                self::add($conditions, $first, (string) $number, is_array($row['fields'] ?? null) ? $row['fields'] : [], is_string($row['operator'] ?? null) ? $row['operator'] : '', is_string($row['value'] ?? null) ? $row['value'] : '');
            }
        }
        return new self($conditions);
    }

    /**
     * @param array<int, array{fields: list<string>, operator: string, value: string}> $conditions
     * @param array<mixed>                                                               $fields
     */
    private static function add(array &$conditions, int $first, string $number, array $fields, string $operator, string $value): void
    {
        $fields = array_values(array_unique(array_filter($fields, static fn(mixed $field): bool => is_string($field) && (preg_match(self::FIELD_RE, $field) === 1))));
        if (!ctype_digit($number) || ((int) $number < max(1, $first)) || !$fields || !isset(AA_Operators::getEnum()[1][$operator])) {
            return;
        }
        // a tab or a line break would be read back as the end of the value
        $conditions[(int) $number] = ['fields' => $fields, 'operator' => $operator, 'value' => substr(strtr($value, "\t\r\n", '   '), 0, self::VALUE_MAX)];
    }

    public function toText(): string
    {
        $lines = [];
        foreach ($this->conditions as $number => $condition) {
            $lines[] = implode("\t", [$number, implode(',', $condition['fields']), $condition['operator'], $condition['value']]);
        }
        return implode("\n", $lines);
    }

    /** @return array<int, array{fields: list<string>, operator: string, value: string}> by their numbers, ascending */
    public function all(): array
    {
        return $this->conditions;
    }

    /**
     * The conditions as AA_Set::addCondsFromArray() takes them, in the order of their numbers. One left
     * without a value is still here: the reader of the array drops it, as it drops such a condition 1 - 3.
     * @param array<int|string, mixed> $values the values a call gives by the numbers; '' or none - the stored one
     * @return list<array<string, mixed>>
     */
    public function toConds(array $values = []): array
    {
        $conds = [];
        foreach ($this->conditions as $number => $condition) {
            $given   = $values[$number] ?? '';
            $conds[] = ['operator' => $condition['operator'], 'value' => (is_scalar($given) && (strlen((string) $given) > 0)) ? (string) $given : $condition['value']]
                + array_fill_keys($condition['fields'], 1);
        }
        return $conds;
    }
}
