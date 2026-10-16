<?php

declare(strict_types=1);

/**
 * The cases the lexer of the code editor is tested on (tests/js/aa-syntax-cases.json) say what the
 * expander does with them - comments vanish, text braces and {} stay, ":" splits as ParamExplode()
 * splits. Here the real expander confirms it, so the editor cannot drift from it unnoticed.
 */

namespace {
    // include/util.php reaches include/locsess.php; none of the cases touches the database
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
    use PHPUnit\Framework\TestCase;

    #[RunClassInSeparateProcess]
    final class StringexpandSyntaxCasesTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/util.php';
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
}
