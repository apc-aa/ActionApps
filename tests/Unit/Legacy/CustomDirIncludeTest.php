<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * The per-install files under include/custom/<AA_CUSTOM_DIR>/ are optional: an install sets
 * AA_CUSTOM_DIR for one of them (say its import sources) and has none of the others.
 *
 * Neither include/stringexpand.php nor include/locsess.php can be loaded on its own, and the
 * constant would leak into every later test, so the `if (defined('AA_CUSTOM_DIR'))` statement is
 * extracted from each real file (with the $CUSTOM_INC_FILES map before it in locsess.php) and run
 * with the constant and __DIR__ swapped for variables.
 */
final class CustomDirIncludeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aa-custom-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/custom/mysite', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/custom/mysite/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir . '/custom/mysite');
        rmdir($this->dir . '/custom');
        rmdir($this->dir);
        unset($GLOBALS['aa_test_custom_loaded']);
    }

    /** The `if (defined('AA_CUSTOM_DIR')) { … }` statement of an include/ file and the `$CUSTOM_INC_FILES = …;` it reads, as source */
    private static function extract(string $file): string
    {
        $tokens = token_get_all((string) file_get_contents(__DIR__ . '/../../../include/' . $file));
        $start  = null;
        $depth  = 0;
        $out    = '';
        foreach ($tokens as $i => $token) {
            if (is_null($start)) {
                $next = array_values(array_filter(
                    array_slice($tokens, $i + 1, 6),
                    static fn($t) => !is_array($t) || $t[0] !== T_WHITESPACE,
                ));
                $map   = is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$CUSTOM_INC_FILES' && ($next[0] ?? '') === '=';
                $guard = is_array($token) && $token[0] === T_IF && ($next[1][1] ?? '') === 'defined' && ($next[3][1] ?? '') === "'AA_CUSTOM_DIR'";
                if ($map || $guard) {
                    $start = $i;
                } else {
                    continue;
                }
            }
            $text = is_array($token) ? $token[1] : $token;
            $out .= $text;
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                self::assertStringContainsString("defined('AA_CUSTOM_DIR')", $out, "include/$file: the map must lie right before the include");
                return $out;
            }
        }
        self::fail("no AA_CUSTOM_DIR include found in include/$file");
    }

    /**
     * Runs the statement as if AA_CUSTOM_DIR were 'mysite' and the file lived in $this->dir
     *
     * @return list<string> the warnings it raised
     */
    private function runStatement(string $file, string $class_name = ''): array
    {
        $code = strtr(self::extract($file), [
            "defined('AA_CUSTOM_DIR')" => 'true',
            'AA_CUSTOM_DIR'            => '$aa_custom_dir',
            '__DIR__'                  => '$aa_dir',
        ]);
        $statement = eval('return static function (string $aa_dir, string $aa_custom_dir, string $class_name): void {
            ' . $code . '
        };');

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $statement($this->dir, 'mysite', $class_name);
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    public function test_stringexpand_missingCustomFile_isSkippedWithoutWarning(): void
    {
        self::assertSame([], $this->runStatement('stringexpand.php'));
    }

    public function test_stringexpand_presentCustomFile_isIncluded(): void
    {
        file_put_contents($this->dir . '/custom/mysite/stringexpand.php', '<?php $GLOBALS["aa_test_custom_loaded"][] = "stringexpand";');
        $GLOBALS['aa_test_custom_loaded'] = [];

        self::assertSame([], $this->runStatement('stringexpand.php'));
        self::assertSame(['stringexpand'], $GLOBALS['aa_test_custom_loaded']);
    }

    public function test_locsess_missingCustomResponder_isSkippedWithoutWarning(): void
    {
        self::assertSame([], $this->runStatement('locsess.php', 'AA_Responder_Something'));
    }

    public function test_locsess_presentCustomResponder_isIncludedForResponderClassesOnly(): void
    {
        file_put_contents($this->dir . '/custom/mysite/responder.php', '<?php $GLOBALS["aa_test_custom_loaded"][] = "responder";');
        $GLOBALS['aa_test_custom_loaded'] = [];

        self::assertSame([], $this->runStatement('locsess.php', 'AA_Other_Class'));
        self::assertSame([], $GLOBALS['aa_test_custom_loaded']);

        self::assertSame([], $this->runStatement('locsess.php', 'AA_Responder_Something'));
        self::assertSame(['responder'], $GLOBALS['aa_test_custom_loaded']);
    }
}
