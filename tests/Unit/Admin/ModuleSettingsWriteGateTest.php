<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The settings pages of the modules write the module and its own tables, so each write may run only from the
 *  page's own form: a POST carrying its token, not sent by a page of another origin. Opening the form of a new
 *  module (a GET from the add-module page) needs no token. The pages need a session and the permission
 *  backend, so the tests read their source. */
final class ModuleSettingsWriteGateTest extends TestCase
{
    /** @return array<string, array{string, string, list<string>, list<string>}>  page, its token constant, calls that write,
     *          lines that decide when a post is a change and that keep its values on an expired form */
    public static function pages(): array
    {
        return [
            'alerts' => ['modules/alerts/modedit.php', 'MODEDIT_ALERTS_TOKEN', ['CreateNewOwner', 'WriteModuleFields', 'update', 'doInsert'], [
                '$write     = $is_add ? $insert : $update;',
                '$expired   = $write && !$may_write;',
                '$redisplay     = $expired || count($err);',
                'if (!$redisplay && !$is_add && $module_id) {',
                'RenderModuleFieldsForm($source_id, $superadmin, $is_add, $redisplay, ',
            ]],
            'polls' => ['modules/polls/modedit.php', 'MODEDIT_POLLS_TOKEN', ['CreateNewOwner', 'WriteModuleFields', 'doReplace', 'transaction'], [
                '$write     = $is_add ? $insert : $update;',
                '$expired   = $write && !$may_write;',
                '$redisplay   = $expired || count($err);',
                'RenderModuleFieldsForm($source_id, $superadmin, (bool) $template[\'P\'], $redisplay, ',
            ]],
            'links' => ['modules/links/modedit.php', 'MODEDIT_LINKS_TOKEN', ['CreateNewOwner', 'WriteModuleFields', 'update', 'doInsert'], [
                '$write     = $is_add ? ($insert && $superadmin) : $update;',
                '$expired   = $write && !$may_write;',
                '$redisplay   = $expired || count($err);',
                'RenderModuleFieldsForm($source_id, $superadmin, (bool) $template[\'Links\'], $redisplay, ',
            ]],
            // the form to choose a module posts jump_id and edit only
            'jump' => ['modules/jump/modedit.php', 'MODEDIT_JUMP_TOKEN', ['transaction'], [
                '$expired   = $update && !$may_write;',
                "if (\$expired) {\n    // the typed values stay in the form\n} elseif (!\$jump_id) {",
            ]],
        ];
    }

    #[DataProvider('pages')]
    public function test_gate_needs_the_token_and_own_origin(string $file, string $const, array $writes, array $expired): void
    {
        $src = self::source($file);
        $this->assertSame(1, preg_match('~^const ' . $const . ' = \'aa-modedit-[a-z]+\';$~m', $src), "$file: no $const");
        $this->assertSame(1, preg_match(
            '~\$may_write\s*=\s*!ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\)\s*&&\s*FormToken::checkPost\(' . $const . ',\s*\$token,\s*\$_SERVER\);~',
            $src,
        ), "$file: \$may_write is not the token and origin check");
        $this->assertSame(1, substr_count($src, 'FormToken::field(' . $const . ')'), "$file: the form does not carry the token");
    }

    #[DataProvider('pages')]
    public function test_every_write_runs_under_the_gate(string $file, string $const, array $writes, array $expired): void
    {
        $tokens = \PhpToken::tokenize(self::source($file));
        foreach ($writes as $function) {
            $calls = self::calls($tokens, $function);
            $this->assertNotEmpty($calls, "$function() not found in $file");
            foreach ($calls as $index) {
                $this->assertMatchesRegularExpression('~(?:\(|&&)\s*\$may_write\s*(?:&&|\))~', self::enclosingConditions($tokens, $index), "$function() in $file runs without the form token");
            }
        }
    }

    /** only a save asks for the token, and a refused one says so on the page and keeps the typed values */
    #[DataProvider('pages')]
    public function test_expired_form_keeps_the_typed_values(string $file, string $const, array $writes, array $expired): void
    {
        $src = self::source($file);
        foreach ($expired as $line) {
            $this->assertStringContainsString($line, $src, "$file: the expired form is handled differently");
        }
        $this->assertSame(1, preg_match('~if \(\$expired\) \{\s*\$Msg = MsgErr\(_m\(\'The form has expired; reload the page and try again.\'\)\);~', $src, $m, PREG_OFFSET_CAPTURE), "$file: no message on the page");
        $this->assertLessThan(strpos($src, '$apage->printHead('), $m[0][1], "$file: the message comes after the page head");
    }

    private static function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }

    /** @param list<\PhpToken> $tokens
     *  @return list<int>  token indexes of the calls of $function (its definition skipped) */
    private static function calls(array $tokens, string $function): array
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_STRING) && strcasecmp($token->text, $function) === 0
                && ($tokens[self::skip($tokens, $i, 1)]->text ?? '') === '('
                && !$tokens[self::skip($tokens, $i, -1)]->is(T_FUNCTION)) {
                $found[] = $i;
            }
        }
        return $found;
    }

    /** Conditions of all if/elseif blocks around the token, innermost first. A call inside a condition
     *  counts with the part of the condition before it - && evaluates left to right.
     *  @param list<\PhpToken> $tokens */
    private static function enclosingConditions(array $tokens, int $index): string
    {
        $text   = static fn(int $from, int $to): string => implode('', array_map(static fn(\PhpToken $t): string => $t->text, array_slice($tokens, $from, $to - $from + 1)));
        $conds  = [];
        $depth  = 0;
        $parens = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($tokens[$i]->text === ')') {
                $parens++;
            } elseif ($tokens[$i]->text === '(') {
                if ($parens > 0) {
                    $parens--;
                } elseif ($tokens[self::skip($tokens, $i, -1)]->is([T_IF, T_ELSEIF])) {
                    $conds[] = $text($i, $index);
                }
            } elseif ($tokens[$i]->text === '}') {
                $depth++;
            } elseif (in_array($tokens[$i]->text, ['{', '${'], true)) {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                $close = self::skip($tokens, $i, -1);
                if ($tokens[$close]->text !== ')') {
                    continue;                      // do, else, function body
                }
                $open = self::matchingOpen($tokens, $close);
                if ($tokens[self::skip($tokens, $open, -1)]->is([T_IF, T_ELSEIF])) {
                    $conds[] = $text($open, $close);
                }
            }
        }
        return implode("\n", $conds);
    }

    /** @param list<\PhpToken> $tokens */
    private static function matchingOpen(array $tokens, int $close): int
    {
        $depth = 0;
        for ($i = $close; $i >= 0; $i--) {
            $depth += ($tokens[$i]->text === ')') ? 1 : (($tokens[$i]->text === '(') ? -1 : 0);
            if ($depth === 0) {
                return $i;
            }
        }
        return 0;
    }

    /** Index of the next token in $step direction that is not whitespace or a comment
     *  @param list<\PhpToken> $tokens */
    private static function skip(array $tokens, int $i, int $step): int
    {
        do {
            $i += $step;
        } while (isset($tokens[$i]) && $tokens[$i]->isIgnorable());
        return max(0, $i);
    }
}
