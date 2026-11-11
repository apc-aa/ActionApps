<?php

declare(strict_types=1);

namespace AA\Search;

use AA\IO\DB\DB_AA;

/**
 * The value of a search condition read as an expression - "News" OR "Events", Bio -GMO, (a OR b) AND NOT c -
 * one tree for both uses: the SQL of the condition (AA_Dbcondition) and the decision over values already loaded
 * (AA_Condition::matchesValues()), so the two cannot read it differently.
 * Grammar, by precedence: OR of AND of [NOT] (a word, a phrase, or an expression in parentheses).
 */
final class QueryExpression
{
    /** the SQL comparison of a word by the operator of the condition: [operator, before the word, after it] */
    private const SQL = [
        '='     => ['=', '', ''],
        'LIKE'  => ['LIKE', '%', '%'],
        'LLIKE' => ['LIKE', '%', ''],
        'RLIKE' => ['LIKE', '', '%'],
        'XLIKE' => ['LIKE', '', ''],
    ];

    /**
     * @param array $tree ['word', string] | ['or'|'and', list<array>] | ['not', array] | ['group', array] - group keeps
     *                    the parentheses the user wrote, as the SQL repeats them
     */
    private function __construct(private readonly array $tree) {}

    /** @throws \InvalidArgumentException on a syntax error - SQL then takes every item */
    public static function parse(string $text): self
    {
        $tokens = QueryLexer::lex(trim($text));
        $i      = 0;
        $tree   = self::orTerm($tokens, $i);
        if ($tokens[$i][0] !== QueryLexer::END) {
            throw new \InvalidArgumentException("Unexpected '{$tokens[$i][1]}' in the search value $text");
        }
        return new self($tree);
    }

    /** null on a syntax error */
    public static function tryParse(string $text): ?self
    {
        try {
            return self::parse($text);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The expression of the values joined by OR - each a phrase, so spaces, OR and parentheses inside it are text;
     * a backslash doubled, as the SQL and AA_Condition strip slashes of the value.
     * @param list<string> $values
     * @return ?string null for no value, or a value with both kinds of quotes, which a phrase cannot hold
     */
    public static function ofAlternatives(array $values): ?string
    {
        $phrases = [];
        foreach ($values as $value) {
            $quote = str_contains($value, '"') ? "'" : '"';
            if (str_contains($value, $quote)) {
                return null;
            }
            $phrases[] = $quote . str_replace('\\', '\\\\', $value) . $quote;
        }
        return $phrases ? implode(' OR ', $phrases) : null;
    }

    /** @return ?list<string> the words when the expression only joins them by OR ("News" OR "Events"), else null */
    public function alternatives(): ?array
    {
        return match ($this->tree[0]) {
            'word'  => [$this->tree[1]],
            'or'    => (array_column($this->tree[1], 0) === array_fill(0, count($this->tree[1]), 'word')) ? array_column($this->tree[1], 1) : null,
            default => null,
        };
    }

    /**
     * The SQL condition on the column, each word compared by the operator: "=" exactly, LIKE anywhere,
     * RLIKE at the start, LLIKE at the end, XLIKE as written (% and _ of the word are wildcards); other
     * operators compare as LIKE.
     */
    public function toSql(string $column, string $operator): string
    {
        [$op, $pre, $post] = self::SQL[strtoupper($operator)] ?? self::SQL['LIKE'];
        // the escaping DB_AA uses for a literal; the words keep % and _ as wildcards of LIKE
        return self::sqlOf($this->tree, DB_AA::quoteIdentifier($column) . "$op '$pre", "$post'");
    }

    /** @param callable(string): bool $equals whether the value compared matches the word */
    public function matches(callable $equals): bool
    {
        return self::holds($this->tree, $equals);
    }

    /**
     * Whether the value matches the word as the SQL of toSql() compares it by a LIKE operator: % and _ are wildcards,
     * a backslash makes the next character plain; case does not count, as in the _ci collations.
     */
    public static function likeMatches(string $value, string $word, string $operator): bool
    {
        [, $pre, $post] = self::SQL[strtoupper($operator)] ?? self::SQL['LIKE'];
        $regex = '';
        $chars = mb_str_split($pre . $word . $post);
        for ($i = 0, $n = count($chars); $i < $n; ++$i) {
            if (($chars[$i] === '\\') && ($i + 1 < $n)) {
                $regex .= preg_quote($chars[++$i], '/');
            } else {
                $regex .= match ($chars[$i]) {
                    '%'     => '.*',
                    '_'     => '.',
                    default => preg_quote($chars[$i], '/'),
                };
            }
        }
        // D: a final newline is a character to LIKE, $ alone would skip it
        return (bool) preg_match('/^' . $regex . '$/Disu', $value);
    }

    private static function sqlOf(array $node, string $before, string $after): string
    {
        switch ($node[0]) {
            case 'word':
                return $before . addslashes($node[1]) . $after;
            case 'or':
            case 'and':
                return implode(" $node[0] ", array_map(static fn(array $child): string => self::sqlOf($child, $before, $after), $node[1]));
            case 'not':
                return 'not (' . self::sqlOf($node[1], $before, $after) . ')';
        }
        return '(' . self::sqlOf($node[1], $before, $after) . ')';     // group
    }

    private static function holds(array $node, callable $equals): bool
    {
        switch ($node[0]) {
            case 'word':
                return $equals($node[1]);
            case 'or':
                foreach ($node[1] as $child) {
                    if (self::holds($child, $equals)) {
                        return true;
                    }
                }
                return false;
            case 'and':
                foreach ($node[1] as $child) {
                    if (!self::holds($child, $equals)) {
                        return false;
                    }
                }
                return true;
            case 'not':
                return !self::holds($node[1], $equals);
        }
        return self::holds($node[1], $equals);                        // group
    }

    private static function orTerm(array $tokens, int &$i): array
    {
        $nodes = [self::andTerm($tokens, $i)];
        while ($tokens[$i][0] === QueryLexer::OR) {
            ++$i;
            $nodes[] = self::andTerm($tokens, $i);
        }
        return (count($nodes) > 1) ? ['or', $nodes] : $nodes[0];
    }

    private static function andTerm(array $tokens, int &$i): array
    {
        $nodes = [self::factor($tokens, $i)];
        while ($tokens[$i][0] === QueryLexer::AND) {
            ++$i;
            $nodes[] = self::factor($tokens, $i);
        }
        return (count($nodes) > 1) ? ['and', $nodes] : $nodes[0];
    }

    /** NOT applies to a word or a parenthesized expression, never to another NOT */
    private static function factor(array $tokens, int &$i): array
    {
        $not = ($tokens[$i][0] === QueryLexer::NOT);
        $i  += (int) $not;
        [$type, $value] = $tokens[$i];
        if ($type === QueryLexer::WORD) {
            ++$i;
            $node = ['word', $value];
        } elseif ($type === QueryLexer::OPEN) {
            ++$i;
            $node = ['group', self::orTerm($tokens, $i)];
            if ($tokens[$i][0] !== QueryLexer::CLOSE) {
                throw new \InvalidArgumentException('Missing ) in the search value');
            }
            ++$i;
        } else {
            throw new \InvalidArgumentException("Unexpected '$value' in the search value");
        }
        return $not ? ['not', $node] : $node;
    }
}
