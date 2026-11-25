<?php

declare(strict_types=1);

namespace AA\Search;

use AA_Operators;

/**
 * Named conditions kept in one text column - the conditions of a view (view.conds; its conditions 1 - 3 with
 * one field stay in their older columns: fromColumns(), toColumns()).
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

    /**
     * @param array<int|string, array{fields: list<string>, operator: string, value: string}> $conditions
     * @param int                                                                              $unread     rows of a form with a field chosen that are no condition
     */
    private function __construct(array $conditions, private readonly int $unread = 0)
    {
        $this->conditions = $conditions;
    }

    /**
     * The rows of the form that had a field chosen and were not read - the field or the operator is not
     * allowed. A script of the form cannot make one; an older record shown in the form can (fromColumns()
     * takes it as it is), and saving without it would drop a condition in silence.
     */
    public function unread(): int
    {
        return $this->unread;
    }

    public static function fromText(string $stored): self
    {
        $conditions = [];
        foreach (($stored === '') ? [] : (preg_split('/\r?\n/', $stored) ?: []) as $line) {
            [$name, $fields, $operator, $value] = array_pad(explode("\t", $line, 4), 4, '');
            if (self::isName($name) && ($condition = self::condition(explode(',', $fields), $operator, $value))) {
                $conditions[$name] = $condition;
            }
        }
        return new self($conditions);
    }

    /**
     * The conditions of a record that keeps its first ones in columns of their own (a view: cond1field,
     * cond1op, cond1cond, ...) and the others in a text. Filled columns are the condition of their number,
     * taken as they are - they hold only one field. A number whose columns are empty may be in the text.
     * @param array<int, array{string, string, string}> $columns number => [field, operator, value]
     */
    public static function fromColumns(array $columns, string $text): self
    {
        $stored     = self::fromText($text)->conditions;
        $conditions = [];
        foreach ($columns as $number => [$field, $operator, $value]) {
            if (($field !== '') && ($operator !== '')) {
                $conditions[$number] = ['fields' => [$field], 'operator' => $operator, 'value' => $value];
            } elseif (isset($stored[$number])) {
                $conditions[$number] = $stored[$number];
            }
            unset($stored[$number]);
        }
        return new self($conditions + $stored);
    }

    /**
     * What is stored where in a record with such columns: a condition numbered 1 - $numbers with one
     * field goes to the columns of its number when it fits them; the others are for the text.
     * @param array{field: int, operator: int, value: int} $limits the bytes the columns hold
     * @return array{array<int, array{string, string, string}>, self} number => [field, operator, value] ('' - no condition), the conditions of the text
     */
    public function toColumns(int $numbers, array $limits): array
    {
        $rest    = $this->conditions;
        $columns = [];
        for ($number = 1; $number <= $numbers; $number++) {
            $condition        = $rest[$number] ?? null;
            $columns[$number] = ['', '', ''];
            if ($condition && (count($condition['fields']) === 1) && (strlen($condition['fields'][0]) <= $limits['field']) && (strlen($condition['operator']) <= $limits['operator']) && (strlen($condition['value']) <= $limits['value'])) {
                $columns[$number] = [$condition['fields'][0], $condition['operator'], $condition['value']];
                unset($rest[$number]);
            }
        }
        return [$columns, new self($rest)];
    }

    /**
     * The conditions as the form posts them (ConditionsInput): rows of [name, fields => [...], operator, value],
     * in the order of the form. What is no condition - no field chosen, an unknown operator - is left out, so
     * a row emptied in the form is a row removed (unread() counts those left out with a field chosen). A row
     * whose name is not allowed (also one of $reserved) or belongs to a row above gets the number after the
     * highest one, as a new row of the form does, so no condition is lost for its name.
     * @param list<int|string> $reserved names no condition may have
     */
    public static function fromForm(mixed $posted, array $reserved = []): self
    {
        $rows   = [];
        $unread = 0;
        $taken  = array_fill_keys($reserved, true);
        foreach (is_array($posted) ? $posted : [] as $key => $row) {
            $fields    = (is_array($row) && is_array($row['fields'] ?? null)) ? $row['fields'] : [];
            $condition = is_array($row) ? self::condition($fields, is_string($row['operator'] ?? null) ? $row['operator'] : '', is_string($row['value'] ?? null) ? $row['value'] : '') : null;
            if (!$condition) {
                $unread += (array_filter($fields, static fn(mixed $field): bool => $field !== '') ? 1 : 0);
                continue;
            }
            $name = is_string($row['name'] ?? null) ? strtolower(trim($row['name'])) : (string) $key;
            if (self::isName($name) && !isset($taken[$name])) {
                $taken[$name] = true;
                $rows[]       = [$name, $condition];
            } else {
                $rows[] = [null, $condition];
            }
        }
        $conditions = [];
        foreach ($rows as [$name, $condition]) {
            if ($name === null) {
                $name = max([0, ...array_filter(array_keys($taken), 'is_int')]) + 1;
                if (strlen((string) $name) > self::NAME_MAXLENGTH) {   // no name any more: the lowest free number
                    $name = 1;
                    while (isset($taken[$name])) {
                        $name++;
                    }
                }
                $taken[$name] = true;
            }
            $conditions[$name] = $condition;
        }
        return new self($conditions, $unread);
    }

    /** a number from 1 up, written plainly (4, not 04), or small letters and digits */
    private static function isName(string $name): bool
    {
        if (preg_match('~^' . self::NAME_PATTERN . '$~D', $name) !== 1) {
            return false;
        }
        return !ctype_digit($name) || (((string) (int) $name === $name) && ((int) $name >= 1));
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
