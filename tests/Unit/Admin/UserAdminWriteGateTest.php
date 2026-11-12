<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The user and role pages change accounts and rights, so each write may run only from the page's own
 *  form: a POST carrying its token, not sent by a page of another origin. The pages need a session and
 *  the permission backend, so the tests read their source. */
final class UserAdminWriteGateTest extends TestCase
{
    /** @return array<string, array{string, string, list<string>}>  page, its token constant, calls (or included files) that write */
    public static function pages(): array
    {
        return [
            'users'  => ['admin/um_uedit.php', 'UM_USER_TOKEN', ['delUser', 'ProcessUser']],
            'groups' => ['admin/um_gedit.php', 'UM_GROUP_TOKEN', ['delGroup', '/include/um_gedit.php']],
            'roles'  => ['admin/se_users.php', 'SE_USERS_TOKEN', ['ChangeRole']],
        ];
    }

    #[DataProvider('pages')]
    public function test_gate_needs_the_token_and_own_origin(string $file, string $const, array $writes): void
    {
        $src = self::source($file);
        $this->assertSame(1, preg_match('~^const ' . $const . ' = \'aa-[a-z-]+\';$~m', $src), "$file: no $const");
        $this->assertSame(1, preg_match(
            '~\$may_write\s*=\s*!ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\)\s*&&\s*FormToken::checkPost\(' . $const . ',\s*\$token,\s*\$_SERVER\);~',
            $src,
        ), "$file: \$may_write is not the token and origin check");
        $this->assertStringContainsString('FormToken::field(' . $const . ')', $src, "$file: a form without the token");
    }

    #[DataProvider('pages')]
    public function test_every_write_runs_under_the_gate(string $file, string $const, array $writes): void
    {
        $src = self::source($file);
        foreach ($writes as $function) {
            $calls = self::calls($src, $function);
            $this->assertNotEmpty($calls, "$function() not found in $file");
            foreach ($calls as $index) {
                $this->assertStringContainsString('$may_write', self::enclosingConditions($src, $index), "$function() in $file runs without the form token");
            }
        }
    }

    private static function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }

    /** @return list<int>  token indexes of the calls of $function (its definition skipped), or of the string naming a file */
    private static function calls(string $src, string $function): array
    {
        $tokens = \PhpToken::tokenize($src);
        $found  = [];
        foreach ($tokens as $i => $token) {
            if (str_ends_with($function, '.php')) {
                if ($token->is(T_CONSTANT_ENCAPSED_STRING) && str_contains($token->text, $function)) {
                    $found[] = $i;
                }
            } elseif ($token->is(T_STRING) && strcasecmp($token->text, $function) === 0
                && ($tokens[self::skip($tokens, $i, 1)]->text ?? '') === '('
                && !$tokens[self::skip($tokens, $i, -1)]->is(T_FUNCTION)) {
                $found[] = $i;
            }
        }
        return $found;
    }

    /** Conditions of all if/elseif blocks around the token, innermost first */
    private static function enclosingConditions(string $src, int $index): string
    {
        $tokens = \PhpToken::tokenize($src);
        $conds  = [];
        $depth  = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($tokens[$i]->text === '}') {
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
                    $conds[] = implode('', array_map(static fn(\PhpToken $t): string => $t->text, array_slice($tokens, $open, $close - $open + 1)));
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
