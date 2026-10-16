<?php

declare(strict_types=1);

/**
 * The cases the lexer of the code editor is tested on (tests/js/aa-syntax-cases.json) say what the
 * expander does with them - comments vanish, text braces and {} stay, ":" splits as ParamExplode()
 * splits. Here the real expander confirms it, so the editor cannot drift from it unnoticed.
 */

namespace Tests\Unit\Legacy;

use AA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandSyntaxCasesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../../js/aa-syntax-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $ret   = [];
        foreach ($cases['scan'] as $case) {
            if (isset($case['output'])) {
                $ret[$case['input']] = [$case['input'], $case['output']];
            }
        }
        return $ret;
    }

    #[DataProvider('cases')]
    public function test_unalias_expandsTheCaseAsTheEditorReadsIt(string $input, string $output): void
    {
        self::assertSame($output, AA::Un($input));
    }
}
