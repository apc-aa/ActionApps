<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The slice settings pages change fields and constants or import slices, so each write may run only from the page's
 *  own form: a POST carrying its token, not sent by a page of another origin. The pages need a session
 *  and the permission backend, so the tests read their source. */
final class SliceSettingsWriteGateTest extends TestCase
{
    /** @return array<string, array{string, string, list<string>, int}>  page, its token constant, calls that write, places that send the token */
    public static function pages(): array
    {
        return [
            'fields'           => ['admin/se_fields.php', 'FIELDS_TOKEN', ['delete', 'doUpdate', 'storeAdminRows', 'repair4modules'], 2],
            'constants import' => ['admin/se_constant_import.php', 'CONSTANT_IMPORT_TOKEN', ['add_constant_group'], 1],
            'slice import'     => ['admin/sliceimp.php', 'SLICEIMP_TOKEN', ['getUploadedFile', 'sliceimp_xml_parse'], 1],
        ];
    }

    #[DataProvider('pages')]
    public function test_gate_needs_the_token_and_own_origin(string $file, string $const, array $writes, int $forms): void
    {
        $src = self::source($file);
        $this->assertSame(1, preg_match('~^const ' . $const . ' = \'aa-[a-z-]+\';$~m', $src), "$file: no $const");
        $this->assertSame(1, preg_match(
            '~\$may_write\s*=\s*!ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\)\s*&&\s*FormToken::checkPost\(' . $const . ',\s*\$token,\s*\$_SERVER\);~',
            $src,
        ), "$file: \$may_write is not the token and origin check");
        // the hidden field of a form, or the token a script posts
        $sent = substr_count($src, 'FormToken::field(' . $const . ')') + substr_count($src, 'FormToken::get(' . $const . ')');
        $this->assertSame($forms, $sent, "$file: a form without the token");
    }

    #[DataProvider('pages')]
    public function test_every_write_runs_under_the_gate(string $file, string $const, array $writes, int $forms): void
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

    /** The constants page shows the group also after a rejected change (the hierarchical editor reads its
     *  settings on the way), so instead of gating each write it drops the requested changes first. */
    public function test_constants_page_drops_the_changes_without_the_token(): void
    {
        $file = 'admin/se_constant.php';
        $this->test_gate_needs_the_token_and_own_origin($file, 'CONSTANT_TOKEN', [], 1);
        $src  = self::source($file);
        $drop = strpos($src, '$update    = $deleteGroup = false;');
        $this->assertNotFalse($drop, 'the changes are not dropped');
        $this->assertSame(1, preg_match('~if \(\(\$update \|\| \$deleteGroup \|\| \(\$hcalldata !== \'\'\) \|\| \(\$new_owner_id !== \'\'\)\) && !\$may_write\) \{[^}]*\$update    = \$deleteGroup = false;\s*\$hcalldata = \$new_owner_id = \'\';~', $src));
        foreach (['delete_constant_group(', 'hcUpdate()', 'doUpdate(', 'doInsert(', 'DB_AA::delete('] as $write) {
            $at = strpos($src, $write);
            $this->assertNotFalse($at, "$write not found");
            $this->assertGreaterThan($drop, $at, "$write runs before the changes are dropped");
        }
    }

    /** SliceEditProcess() also checks the permission, so the slice settings page always calls it and drops
     *  the changes first. The add-slice pages post here without add/update just to open the form - no token. */
    public function test_slice_settings_page_drops_the_changes_without_the_token(): void
    {
        $file = 'admin/slicedit.php';
        $this->test_gate_needs_the_token_and_own_origin($file, 'SLICEDIT_TOKEN', [], 1);
        $src = self::source($file);
        $this->assertSame(1, preg_match('~\$expired\s*=\s*\(\$add \|\| \$update \|\| !empty\(\$_POST\[\'aa\'\]\)\) && !\$may_write;~', $src), 'a change goes without the token');
        // AA_Slice::processModuleObject() saves the additional settings from $_POST['aa'] on its own
        $drop = preg_match('~if \(\$expired\) \{[^}]*unset\(\$_POST\[\'aa\'\]\);~', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        $this->assertNotFalse($drop, 'the additional settings are not dropped');
        $this->assertCount(1, self::calls(\PhpToken::tokenize($src), 'SliceEditProcess'));
        $call = (int) strpos($src, '$err = SliceEditProcess(');
        $this->assertGreaterThan($drop, $call, 'SliceEditProcess() runs before the changes are dropped');
        $this->assertStringContainsString(', $add && $may_write, $update && $may_write, ', substr($src, $call, (int) strpos($src, ';', $call) - $call), 'add/update run without the token');
        $this->assertStringContainsString('$redisplay  = ($has_errors || $expired) && ($add || $update);', $src, 'an expired form loses the typed values');
    }

    public function test_slice_import_takes_its_data_from_the_form_only(): void
    {
        $src = self::source('admin/sliceimp.php');
        foreach (['slice_def', 'conflicts_list', 'view_conflicts_list', 'data_conflicts_list'] as $var) {
            $this->assertStringContainsString("InputVars::stringPOST('$var')", $src);
        }
        $this->assertStringContainsString('[safe($res), strlen ($res)]', $src, 'the id from the request is printed escaped');
    }

    public function test_fields_page_scripts_post_their_actions(): void
    {
        // deleting a field and moving an alias are buttons of the page, not links to follow
        $this->assertStringNotContainsString('window.location', self::source('admin/se_fields.php'));
    }

    /** an expired form keeps the typed templates and says so on the page - the popup of $err is gone in seconds */
    public function test_fulltext_page_shows_the_expired_form_on_the_page(): void
    {
        $src      = self::source('admin/se_fulltext.php');
        $decision = strpos($src, '(!$update || !count($err) || $foreign)');
        $moved    = preg_match('~if \(isset\(\$err\[\'token\'\]\)\) \{\s*\$Msg = \$err\[\'token\'\];[^\n]*\s*unset\(\$err\[\'token\'\]\);~', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        $head     = strpos($src, '$apage->printHead($err, $Msg);');

        $this->assertNotFalse($decision, 'the typed templates are not kept');
        $this->assertNotFalse($moved, 'the message is not moved to the page');
        $this->assertNotFalse($head);
        $this->assertGreaterThan($decision, $moved, 'moved before $err decides which templates the form shows');
        $this->assertLessThan($head, $moved);
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
