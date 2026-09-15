<?php

declare(strict_types=1);

namespace AA\Lang;

/**
 * Extracts translatable messages — first string-literal arguments of _m() calls —
 * from PHP source files. Token-based replacement of the 2003 character-state-machine
 * in misc/mgettext/xmgettext.php (which decoded literals via eval()).
 *
 * Rules (same as the old tool):
 *  - only constant string literals are allowed in _m(); concatenation with "." is OK
 *  - variables/expressions in the first argument produce a warning, nothing is extracted
 *  - _mdelayed() is intentionally NOT extracted (runtime-computed messages)
 */
class MsgExtractor
{
    /**
     * @param string $code     PHP source code
     * @param string $filename used in warnings and message locations
     * @return array{messages: array<string, int[]>, warnings: string[]}
     *               messages: message text => list of rows where it appears
     */
    public function extractFromSource(string $code, string $filename): array
    {
        $messages = [];
        $warnings = [];
        $tokens = \PhpToken::tokenize($code);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $j = $this->mCallParenAt($tokens, $i);
            if ($j === null) {
                continue;
            }

            $message = '';
            $bad = false;
            $row = $tokens[$i]->line;
            $k = $j + 1;
            // parse: STRING ( . STRING )* followed by "," or ")"
            while (true) {
                $k = $this->nextSignificant($tokens, $k);
                if ($k === null) {
                    $bad = true;
                    break;
                }
                $tok = $tokens[$k];
                if ($tok->is(T_CONSTANT_ENCAPSED_STRING)) {
                    $message .= self::decodeStringLiteral($tok->text);
                    $row = $tok->line;
                    $k = $this->nextSignificant($tokens, $k + 1);
                    if ($k === null) {
                        $bad = true;
                        break;
                    }
                    $next = $tokens[$k]->text;
                    if ($next === '.') {
                        $k++;
                        continue;
                    }
                    if ($next === ',' || $next === ')') {
                        break; // complete first argument
                    }
                    $bad = true;
                    break;
                }
                if ($tok->is([T_VARIABLE, '"', T_START_HEREDOC, T_ENCAPSED_AND_WHITESPACE])) {
                    $warnings[] = "$filename, row {$tok->line}: using variable in _m is not allowed";
                } else {
                    $warnings[] = "$filename, row {$tok->line}: bad syntax after _m (";
                }
                $bad = true;
                break;
            }

            if (!$bad && $message !== '') {
                $messages[$message][] = $row;
            }
            if ($k !== null) {
                $i = $k; // continue after the first argument
            }
        }

        return ['messages' => $messages, 'warnings' => $warnings];
    }

    /**
     * @param string[] $files paths relative to $base_dir
     * @return array{messages: array<string, array<string, int[]>>, warnings: string[]}
     *               messages: message => file => rows
     */
    public function extractFromFiles(string $base_dir, array $files): array
    {
        $messages = [];
        $warnings = [];
        foreach ($files as $file) {
            $code = file_get_contents(rtrim($base_dir, '/') . '/' . $file);
            if ($code === false) {
                $warnings[] = "$file: cannot read file";
                continue;
            }
            $one = $this->extractFromSource($code, $file);
            foreach ($one['messages'] as $message => $rows) {
                $messages[$message][$file] = $rows;
            }
            $warnings = array_merge($warnings, $one['warnings']);
        }
        return ['messages' => $messages, 'warnings' => $warnings];
    }

    /** Quick check whether source contains a real _m( call (used for scan-coverage check) */
    public function sourceUsesM(string $code): bool
    {
        if (!str_contains($code, '_m')) {
            return false;
        }
        $tokens = \PhpToken::tokenize($code);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($this->mCallParenAt($tokens, $i) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * If the token at $i is an `_m` identifier that opens a call of the global
     * _m() function (next significant token is "("), returns the index of that
     * "(". Otherwise null. Skips "function _m(" declarations and method-style
     * dispatches ("$x->_m(", "$x?->_m(", "Foo::_m(") — those are unrelated methods.
     *
     * @param \PhpToken[] $tokens
     */
    private function mCallParenAt(array $tokens, int $i): ?int
    {
        if (!$tokens[$i]->is(T_STRING) || $tokens[$i]->text !== '_m') {
            return null;
        }
        $j = $this->nextSignificant($tokens, $i + 1);
        if ($j === null || $tokens[$j]->text !== '(') {
            return null;
        }
        $p = $this->prevSignificant($tokens, $i - 1);
        if ($p !== null && $tokens[$p]->is([T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            return null;
        }
        return $j;
    }

    /**
     * Decodes a PHP string literal (including the surrounding quotes) to its value,
     * following PHP escape-sequence semantics. Replaces the old eval() approach.
     */
    public static function decodeStringLiteral(string $raw): string
    {
        $quote = $raw[0];
        $body = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        // double-quoted string
        return preg_replace_callback(
            '/\\\\(n|t|r|v|e|f|\\\\|\\$|"|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
            static function (array $m): string {
                $esc = $m[1];
                static $map = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
                if (isset($map[$esc])) {
                    return $map[$esc];
                }
                if ($esc[0] === 'x') {
                    return chr((int) hexdec(substr($esc, 1)));
                }
                if ($esc[0] === 'u') {
                    return mb_chr((int) hexdec(substr($esc, 2, -1)), 'UTF-8');
                }
                return chr((int) octdec($esc));
            },
            $body,
        );
    }

    /** @param \PhpToken[] $tokens */
    private function nextSignificant(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for (; $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }
        return null;
    }

    /** @param \PhpToken[] $tokens */
    private function prevSignificant(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }
        return null;
    }
}
