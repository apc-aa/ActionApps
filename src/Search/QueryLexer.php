<?php

declare(strict_types=1);

namespace AA\Search;

/**
 * Splits the value of a search condition (=, LIKE, RLIKE, ...) into tokens: words, "phrases" and 'phrases',
 * AND (also +), OR, NOT (also - before a word) and parentheses (also [] and {}). Words next to each other
 * are joined by an implicit AND. A minus or an apostrophe inside a word belongs to it; a phrase without
 * its closing quote is left out.
 */
final class QueryLexer
{
    public const WORD  = 1;
    public const AND   = 2;
    public const OR    = 3;
    public const NOT   = 4;
    public const OPEN  = 5;
    public const CLOSE = 6;
    public const END   = 8;

    private const WHITE   = " \t";
    private const SPECIAL = "+-\"' \t(){}[]";

    /** @return list<array{int, string}> type and value of each token, the last one END */
    public static function lex(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $i      = strspn($input, self::WHITE);
        while ($i < $length) {
            $char = $input[$i];
            if (($char === '"') || ($char === "'")) {
                $end = strpos($input, $char, $i + 1);
                if ($end === false) {
                    break;                    // an unclosed phrase is no token
                }
                $tokens[] = [self::WORD, substr($input, $i + 1, $end - $i - 1)];
                $i        = $end + 1;
            } elseif (str_contains('+-({[)}]', $char)) {
                $tokens[] = [match ($char) {
                    '+' => self::AND, '-' => self::NOT, '(', '{', '[' => self::OPEN, default => self::CLOSE
                }, $char];
                ++$i;
            } else {
                $start = $i;
                while (($i < $length) && self::inWord($input, $i, $length)) {
                    ++$i;
                }
                $word     = substr($input, $start, $i - $start);
                $tokens[] = [match (strtolower($word)) {
                    'and' => self::AND, 'or' => self::OR, 'not' => self::NOT, default => self::WORD
                }, $word];
            }
            $i += strspn($input, self::WHITE, $i);
        }

        $ret = [];
        foreach ($tokens as $k => [$type, $value]) {
            $ret[] = [$type, $value];
            if (($type === self::WORD) && in_array($tokens[$k + 1][0] ?? 0, [self::WORD, self::OPEN, self::NOT], true)) {
                $ret[] = [self::AND, ''];
            }
        }
        $ret[] = [self::END, ''];
        return $ret;
    }

    /** a minus inside a word or at the end of the input, an apostrophe between letters, do not end the word */
    private static function inWord(string $input, int $i, int $length): bool
    {
        $char = $input[$i];
        if (!str_contains(self::SPECIAL, $char)) {
            return true;
        }
        if ($char === '-') {
            return ($i && !str_contains(self::SPECIAL, $input[$i - 1])) || ($i === $length - 1);
        }
        // the two neighbours are looked up as one string, as the original lexer did
        return ($char === "'") && $i && ($i !== $length - 1) && !str_contains(self::SPECIAL, $input[$i - 1] . $input[$i + 1]);
    }
}
