<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/setup.php is a page without login that writes the permission system, so the tests read its source:
 *  nothing it does may run before the config switch is checked. */
final class SetupTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/setup.php');
    }

    /** Offset of the first call of $function in the code (comments and strings skipped) */
    private static function firstCall(string $function): ?int
    {
        $tokens = \PhpToken::tokenize(self::source());
        foreach ($tokens as $i => $token) {
            if ($token->is(T_STRING) && strcasecmp($token->text, $function) === 0) {
                $j = $i + 1;
                while (isset($tokens[$j]) && $tokens[$j]->is(T_WHITESPACE)) {
                    $j++;
                }
                if (isset($tokens[$j]) && $tokens[$j]->text === '(') {
                    return $token->pos;
                }
            }
        }
        return null;
    }

    private static function gate(): int
    {
        $found = preg_match("/if \\(!defined\\('AA_SETUP_ENABLED'\\) \\|\\| AA_SETUP_ENABLED !== true\\) \\{\\s*http_response_code\\(404\\);\\s*exit;\\s*\\}/", self::source(), $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, 'setup.php must answer 404 unless AA_SETUP_ENABLED is true');
        return $m[0][1];
    }

    public function test_gate_comes_right_after_the_config(): void
    {
        $config = strpos(self::source(), 'include/config.php";');
        $this->assertNotFalse($config);
        $this->assertGreaterThan($config, self::gate(), 'the switch lives in config.php, so the gate needs it loaded');
    }

    public function test_nothing_runs_before_the_gate(): void
    {
        $gate = self::gate();
        foreach (['pageOpen', 'getDB', 'getObjectsPerms', 'addPermObject', 'addUser', 'addPerm', 'HtmlStart'] as $function) {
            $at = self::firstCall($function);
            $this->assertNotNull($at, "$function() is expected in setup.php");
            $this->assertGreaterThan($gate, $at, "$function() must not run before the gate");
        }
    }

    public function test_recover_deletes_no_permission(): void
    {
        $this->assertNull(self::firstCall('delPerm'), 'an unknown principal may be a real account the backend failed to describe');
    }

    public function test_passwords_are_posted(): void
    {
        $this->assertStringContainsString('<form method="post" action="setup.php">', self::source(), 'the account form must not put passwords into the URL');
    }
}
