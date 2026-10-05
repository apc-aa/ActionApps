<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA\Security\SignedExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * filler.php runs what a form sends back in ret_code/ok_url. Signed by AA it may be any expression;
 * unsigned it comes from whoever sent the request, so only text, aliases and the harmless forms run.
 */
final class SignedExpressionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        if (!defined('AA_ID')) {
            define('AA_ID', '0123456789abcdef0123456789abcdef');
        }
    }

    /** @return callable(string): bool */
    private static function fields(): callable
    {
        return static fn(string $id): bool => in_array($id, ['headline........', 'text...........1'], true);
    }

    #[DataProvider('expressions')]
    public function test_sign_verifiesBackToTheExpression(string $expression): void
    {
        $token = SignedExpression::sign($expression);

        self::assertSame($expression, SignedExpression::verify($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $token, 'the AA JS library appends it to the query unencoded');
    }

    public static function expressions(): array
    {
        return [
            'ajax default' => ['{alias:headline........:f_h:, }'],
            'any command'  => ['{include:include/config.php3:readfile}'],
            'binary'       => ["\xFF\xFE+/="],
            'empty'        => [''],
        ];
    }

    public function test_verify_refusesAChangedOrForeignValue(): void
    {
        $token = SignedExpression::sign('{headline........}');
        [$prefix, $encoded, $mac] = explode('.', $token);
        $other = rtrim(strtr(base64_encode('{include:x:readfile}'), '+/', '-_'), '=');

        self::assertNull(SignedExpression::verify("$prefix.$other.$mac"), 'another expression under the same signature');
        self::assertNull(SignedExpression::verify("$prefix.$encoded." . str_repeat('0', 64)));
        self::assertNull(SignedExpression::verify(base64_encode('{headline........}')), 'the older unsigned ret_code_enc');
        self::assertNull(SignedExpression::verify('aa1.x'));
    }

    #[DataProvider('harmless')]
    public function test_isHarmless_runsTextAliasesAndTheFormsAaGenerated(string $expression): void
    {
        self::assertTrue(SignedExpression::isHarmless($expression, self::fields()));
    }

    public static function harmless(): array
    {
        return [
            'text'                => ['Thank you for your submission.'],
            'aliases'             => ['_#FIRSTNAM _#LASTNAME '],
            'ajax default'        => ['{alias:headline........:f_h:, }'],
            'related item add'    => ['{headline........}'],
            'quoted text'         => ['{- <div class="ok">Sent. Thank you!</div> }'],
            'alias in braces'     => ['?id={_#N1_ID___}'],
            'nested'              => ['{-Thanks, {headline........}}'],
            'text with brackets'  => ['aa[n1_x][headline........]'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_isHarmless_refusesAnyOtherExpression(string $expression): void
    {
        self::assertFalse(SignedExpression::isHarmless($expression, self::fields()));
    }

    public static function dangerous(): array
    {
        return [
            'readfile'                      => ['{include:../include/config.php3:readfile}'],
            'command named like a field'    => ['{include.........}'],
            'unknown field'                 => ['{password........}'],
            'command inside a quoted text'  => ['{-a {include:x:readfile} b}'],
            'harmless part makes a command' => ['{include{-x}:x:readfile}'],
            'refused name joins the text'   => ['{include.........}-x}'],
            'alias function with a command' => ['{alias:headline........:f_t:{include:x}}'],
            'inline alias'                  => ['{(x)}'],
        ];
    }
}
