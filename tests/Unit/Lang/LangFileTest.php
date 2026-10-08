<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use AA\Lang\LangFile;
use PHPUnit\Framework\TestCase;

final class LangFileTest extends TestCase
{
    private LangFile $lf;
    private string $dir;

    protected function setUp(): void
    {
        $this->lf = new LangFile();
        $this->dir = sys_get_temp_dir() . '/langfile_' . getmypid();
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function entry(string $msg, string $tr, array $comments = []): array
    {
        return ['message' => $msg, 'translation' => $tr, 'comments' => $comments];
    }

    // --- write + read round trip ---

    public function test_round_trip_simple(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $this->lf->write($path, 'xx', [
            $this->entry('Hello', 'Ahoj', ['./a.php, row 5']),
            $this->entry('Untranslated', ''),
        ]);

        $this->assertSame(['Hello' => 'Ahoj', 'Untranslated' => ''], $this->lf->read($path));
    }

    public function test_round_trip_tricky_characters(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $tricky = [
            'multi' . "\n" . 'line message' => "více\nřádkový",
            'say "quotes" and $vars' => 'uvozovky "x" a $promenna',
            'back\\slash' => 'zpětné\\lomítko',
            '100\% done %1' => 'hotovo 100\% — %1',
            "tab\there" => "tabulátor\t",
        ];
        $entries = [];
        foreach ($tricky as $msg => $tr) {
            $entries[] = $this->entry((string) $msg, $tr);
        }
        $this->lf->write($path, 'xx', $entries);

        $this->assertSame($tricky, $this->lf->read($path));
    }

    public function test_written_file_lints_and_returns_array(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $this->lf->write($path, 'xx', [$this->entry("a\n\nb", "c\nd")], ['old msg' => 'old tr']);

        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
        $this->assertSame(0, $rc, implode("\n", $output));

        $map = $this->lf->read($path);
        $this->assertSame('old tr', $map['old msg']);     // unused block kept
        $this->assertSame("c\nd", $map["a\n\nb"]);
    }

    public function test_header_lines_for_known_lang(): void
    {
        $path = $this->dir . '/cz-utf8_test_lang.php';
        $this->lf->write($path, 'cz-utf8', [$this->entry('Hello', 'Ahoj')]);
        $src = file_get_contents($path);

        $this->assertStringContainsString('$mgettext_lang = "cz-utf8";', $src);
        $this->assertStringContainsString("defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_czech_ci');", $src);
        $this->assertStringContainsString("setlocale(LC_ALL, 'cs_CZ.utf8', 'cs_CZ');", $src);
    }

    public function test_derived_file_is_marked_generated(): void
    {
        $path = $this->dir . '/cz_test_lang.php';
        $this->lf->write($path, 'cz', [$this->entry('Hello', 'Ahoj')], [], 'cz-utf8');
        $src = file_get_contents($path);

        $this->assertStringContainsString('DERIVED from the cz-utf8 files', $src);
        $this->assertStringContainsString('DO NOT EDIT', $src);
    }

    // --- reading the legacy assignment format ---

    public function test_read_legacy_format(): void
    {
        $path = $this->dir . '/legacy_lang.php';
        file_put_contents($path, "<?php\n\$mgettext_lang = \"xx\";\n\$_m[\"Hello\"]\n = \"Ahoj\";\n\n\$_m[\"Bye\"]\n = \"\";\n");

        $this->assertSame(['Hello' => 'Ahoj', 'Bye' => ''], $this->lf->read($path));
    }

    public function test_read_missing_file_returns_empty(): void
    {
        $this->assertSame([], $this->lf->read($this->dir . '/none.php'));
    }

    // --- charset conversion for derived 1B files ---

    public function test_convert_values_to_windows1250(): void
    {
        $lossy = [];
        $converted = LangFile::convertValues(['key' => 'Příliš žluťoučký kůň'], 'windows-1250', $lossy);

        $this->assertSame([], $lossy);
        $this->assertSame('Příliš žluťoučký kůň', iconv('windows-1250', 'UTF-8', $converted['key']));
    }

    public function test_convert_reports_lossy_values(): void
    {
        $lossy = [];
        $converted = LangFile::convertValues(['k' => 'em—dash €'], 'iso-8859-1', $lossy);

        $this->assertArrayHasKey('k', $lossy);
        $this->assertNotSame('', $converted['k']);  // transliterated, not dropped
    }

    public function test_to_utf8_seeds_from_one_byte_charset(): void
    {
        $cp1250 = iconv('UTF-8', 'windows-1250', 'žluťoučký');
        $this->assertSame(['k' => 'žluťoučký'], LangFile::toUtf8(['k' => $cp1250], 'windows-1250'));
        $this->assertSame(['k' => 'utf'], LangFile::toUtf8(['k' => 'utf'], 'utf-8'));
    }

    // --- string literal helper ---

    public function test_php_string_literal_multiline(): void
    {
        $this->assertSame('"a\n"' . "\n   ." . '"b"', LangFile::phpStringLiteral("a\nb"));
        $this->assertSame('"plain"', LangFile::phpStringLiteral('plain'));
    }

    public function test_php_string_literal_ascii_mode_escapes_high_bytes(): void
    {
        $cp1250 = iconv('UTF-8', 'windows-1250', 'Přidat');
        $literal = LangFile::phpStringLiteral($cp1250, true);
        $this->assertSame('"P\xF8idat"', $literal);
        $this->assertSame('"utf škrt"', LangFile::phpStringLiteral('utf škrt', false));
    }

    // --- unused messages are commented out but survive regenerations ---

    public function test_unused_block_is_commented_out_but_readable(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $unused = [
            'gone message' => 'pryč',
            "multi\nline gone" => "více\nřádků pryč",
            'tricky "q" $v' => 'baf \\',
        ];
        $this->lf->write($path, 'xx', [$this->entry('Hello', 'Ahoj')], $unused);

        // not loaded at runtime (include sees only active entries) …
        $runtime_view = (static fn() => include $path)();
        $this->assertSame(['Hello' => 'Ahoj'], $runtime_view);
        // … but read() revives the commented block for the generator
        $read = $this->lf->read($path);
        foreach ($unused + ['Hello' => 'Ahoj'] as $message => $translation) {
            $this->assertSame($translation, $read[$message] ?? null, "message [$message]");
        }
        // and a second write/read cycle keeps it intact
        $this->lf->write($path . '2', 'xx', [$this->entry('Hello', 'Ahoj')], $unused);
        $this->assertSame($this->lf->read($path), $this->lf->read($path . '2'));
    }

    public function test_unused_block_survives_carriage_return_and_php_close_tag(): void
    {
        // either one would end a // comment line and leave the file unparsable
        $path = $this->dir . '/xx_test_lang.php';
        $unused = ['cr gone' => "a\rb", 'crlf gone' => "a\r\nb", 'tag gone' => 'echo ?> here'];
        $this->lf->write($path, 'xx', [$this->entry('Hello', 'Ahoj'), $this->entry('tag ?> active', 'x ?> y')], $unused);

        $this->assertSame(['Hello' => 'Ahoj', 'tag ?> active' => 'x ?> y'], (static fn() => include $path)());
        $read = $this->lf->read($path);
        foreach ($unused as $message => $translation) {
            $this->assertSame($translation, $read[$message] ?? null, "message [$message]");
        }
    }

    // --- runtime-translated (_mdelayed) messages stay active ---

    public function test_runtime_block_is_active_at_runtime(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $this->lf->write($path, 'xx', [$this->entry('Hello', 'Ahoj')], [], '', ['ADMINISTRATOR' => 'Správce']);

        $runtime_view = (static fn() => include $path)();
        $this->assertSame('Správce', $runtime_view['ADMINISTRATOR']);
        $this->assertStringContainsString('Runtime-translated messages', (string) file_get_contents($path));
    }

    // --- one-byte derived files are encoding-proof pure ASCII ---

    public function test_derived_one_byte_file_is_pure_ascii(): void
    {
        $path = $this->dir . '/cz_test_lang.php';
        $value_cp1250 = iconv('UTF-8', 'windows-1250', 'žluťoučký kůň');
        $this->lf->write(
            $path,
            'cz',
            [$this->entry('yellowish horse', $value_cp1250)],
            ['old' => iconv('UTF-8', 'windows-1250', 'starý')],
            'cz-utf8',
        );

        $src = (string) file_get_contents($path);
        $this->assertDoesNotMatchRegularExpression('/[\x80-\xFF]/', $src, 'one-byte file must be pure ASCII');

        // escapes decode back to the exact windows-1250 bytes
        $runtime_view = (static fn() => include $path)();
        $this->assertSame($value_cp1250, $runtime_view['yellowish horse']);
        $this->assertSame('starý', iconv('windows-1250', 'UTF-8', $this->lf->read($path)['old']));
    }

    public function test_utf8_master_keeps_readable_diacritics(): void
    {
        $path = $this->dir . '/cz-utf8_test_lang.php';
        $this->lf->write($path, 'cz-utf8', [$this->entry('Hello', 'žluťoučký')]);
        $this->assertStringContainsString('žluťoučký', (string) file_get_contents($path));
    }

    // --- AI-translation markers (trailing "// ai …" comments) ---

    public function test_marks_round_trip_active_runtime_and_unused(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $marks = [
            'Hello' => 'ai 2026-06',
            "multi\nline" => 'ai 2026-06',
            'gone message' => 'ai 2025-12',
            'ADMINISTRATOR' => 'ai 2026-06',
        ];
        $this->lf->write(
            $path,
            'xx',
            [$this->entry('Hello', 'Ahoj'), $this->entry("multi\nline", "více\nřádků"), $this->entry('Plain', 'Prostý')],
            ['gone message' => 'pryč'],
            '',
            ['ADMINISTRATOR' => 'Správce'],
            $marks,
        );

        $read_marks = LangFile::readMarks($path);
        ksort($marks);
        ksort($read_marks);
        $this->assertSame($marks, $read_marks);
        // regeneration cycle is byte-identical for multiline/unused/runtime marked pairs too
        $first = (string) file_get_contents($path);
        $this->lf->write(
            $path,
            'xx',
            [$this->entry('Hello', 'Ahoj'), $this->entry("multi\nline", "více\nřádků"), $this->entry('Plain', 'Prostý')],
            ['gone message' => 'pryč'],
            '',
            ['ADMINISTRATOR' => 'Správce'],
            LangFile::readMarks($path),
        );
        $this->assertSame($first, (string) file_get_contents($path));
        // marks are comments only — runtime loading and read() are unaffected
        $this->assertSame('Ahoj', $this->lf->read($path)['Hello']);
        $runtime_view = (static fn() => include $path)();
        $this->assertSame('Ahoj', $runtime_view['Hello']);
        // unmarked pair has no marker
        $this->assertArrayNotHasKey('Plain', LangFile::readMarks($path));
    }

    public function test_marks_survive_rewrite_and_deletion_is_permanent(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $entries = [$this->entry('Hello', 'Ahoj'), $this->entry('Bye', 'Pa')];
        $this->lf->write($path, 'xx', $entries, [], '', [], ['Hello' => 'ai 2026-06', 'Bye' => 'ai 2026-06']);

        // regeneration cycle: read marks back and write again — byte-identical
        $first = (string) file_get_contents($path);
        $this->lf->write($path, 'xx', $entries, [], '', [], LangFile::readMarks($path));
        $this->assertSame($first, (string) file_get_contents($path));

        // a human deletes one marker — the next cycle must not bring it back
        file_put_contents($path, str_replace('"Bye" => "Pa", // ai 2026-06', '"Bye" => "Pa",', $first));
        $this->lf->write($path, 'xx', $entries, [], '', [], LangFile::readMarks($path));
        $marks = LangFile::readMarks($path);
        $this->assertSame(['Hello' => 'ai 2026-06'], $marks);
    }

    public function test_marks_in_one_byte_ascii_file(): void
    {
        $path = $this->dir . '/cz_test_lang.php';
        $value_cp1250 = iconv('UTF-8', 'windows-1250', 'žluťoučký');
        $this->lf->write($path, 'cz', [$this->entry('yellowish', $value_cp1250)], [], 'cz-utf8', [], ['yellowish' => 'ai 2026-06']);

        $src = (string) file_get_contents($path);
        $this->assertDoesNotMatchRegularExpression('/[\x80-\xFF]/', $src);
        $this->assertSame(['yellowish' => 'ai 2026-06'], LangFile::readMarks($path));
    }

    public function test_key_starting_with_ai_is_not_a_false_marker(): void
    {
        $path = $this->dir . '/xx_test_lang.php';
        $this->lf->write($path, 'xx', [$this->entry('Hello', 'Ahoj')], ['ai something' => 'překlad']);
        $this->assertSame([], LangFile::readMarks($path));
    }
}
