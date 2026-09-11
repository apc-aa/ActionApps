<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\InputVars;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the typed input-validation boundary. The `id` type is intentionally
 * not exercised here: it calls the global is_long_id() from include/zids.php,
 * which is not autoloadable — it belongs to a zids characterization test that
 * loads that legacy file. Everything below is fully isolated (superglobals only).
 */
final class InputVarsTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
    }

    // --- int ---

    public function test_intPOST_casts_numeric_string_to_int(): void
    {
        $_POST['n'] = '42';
        $this->assertSame(42, InputVars::intPOST('n', true));
    }

    public function test_intPOST_required_rejects_non_numeric(): void
    {
        $_POST['n'] = 'abc';
        $this->expectException(RuntimeException::class);
        InputVars::intPOST('n', true);
    }

    public function test_intPOST_optional_skips_validation_and_casts(): void
    {
        // not-required short-circuits validation: invalid value is cast, not rejected
        $_POST['n'] = 'abc';
        $this->assertSame(0, InputVars::intPOST('n', false));
    }

    public function test_intPOST_required_rejects_float_like(): void
    {
        // (int)$value == $value is false for '4.5' → rejected when required
        $_POST['n'] = '4.5';
        $this->expectException(RuntimeException::class);
        InputVars::intPOST('n', true);
    }

    // --- bool ---

    public function test_boolPOST_accepts_zero_and_one(): void
    {
        $_POST['t'] = '1';
        $_POST['f'] = '0';
        $this->assertTrue(InputVars::boolPOST('t', true));
        $this->assertFalse(InputVars::boolPOST('f', true));
    }

    public function test_boolPOST_required_rejects_other_values(): void
    {
        $_POST['b'] = 'yes';
        $this->expectException(RuntimeException::class);
        InputVars::boolPOST('b', true);
    }

    // --- string ---

    public function test_stringPOST_returns_value(): void
    {
        $_POST['s'] = 'hello';
        $this->assertSame('hello', InputVars::stringPOST('s', true));
    }

    public function test_stringPOST_required_rejects_blank(): void
    {
        $_POST['s'] = '   ';
        $this->expectException(RuntimeException::class);
        InputVars::stringPOST('s', true);
    }

    public function test_stringSlashedPOST_addslashes_deprecated_quoted(): void
    {
        $_POST['s'] = "O'Brien \"x\"";
        $this->assertSame("O\\'Brien \\\"x\\\"", InputVars::stringSlashedPOST('s', true));
    }

    // --- array ---

    public function test_arrayPOST_returns_array(): void
    {
        $_POST['a'] = ['x', 'y'];
        $this->assertSame(['x', 'y'], InputVars::arrayPOST('a', true));
    }

    public function test_arrayPOST_required_rejects_scalar(): void
    {
        $_POST['a'] = 'not-an-array';
        $this->expectException(RuntimeException::class);
        InputVars::arrayPOST('a', true);
    }

    // --- GET + precedence ---

    public function test_intGET_reads_from_get(): void
    {
        $_GET['n'] = '7';
        $this->assertSame(7, InputVars::intGET('n', true));
    }

    public function test_intGETPOST_prefers_get_over_post(): void
    {
        $_GET['n']  = '5';
        $_POST['n'] = '9';
        $this->assertSame(5, InputVars::intGETPOST('n', true));
    }

    public function test_intGETPOST_falls_back_to_post(): void
    {
        $_POST['n'] = '9';
        $this->assertSame(9, InputVars::intGETPOST('n', true));
    }

    // --- exception message shape ---

    public function test_required_failure_message_names_var_and_type(): void
    {
        $_POST['email'] = '';
        $this->expectExceptionMessageMatches('#email/string#');
        InputVars::stringPOST('email', true);
    }

    // --- Addslashes/Stripslashes helpers (recursive, pure) ---

    public function test_AddslashesArray_recurses_into_nested_arrays(): void
    {
        $in  = ['a' => "O'B", 'b' => ['c' => 'say "hi"']];
        $out = InputVars::AddslashesArray($in);
        $this->assertSame(['a' => "O\\'B", 'b' => ['c' => 'say \\"hi\\"']], $out);
    }

    public function test_Stripslashes_is_inverse_of_Addslashes(): void
    {
        $in = ['a' => "O'B", 'b' => ['c' => 'say "hi"']];
        $this->assertSame($in, InputVars::StripslashesArray(InputVars::AddslashesArray($in)));
    }
}
