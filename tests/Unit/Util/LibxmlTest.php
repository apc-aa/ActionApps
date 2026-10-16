<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Libxml;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The settings run() changes are global to the process: every test puts back what it found */
final class LibxmlTest extends TestCase
{
    private bool $internal = false;

    /** @var ?callable */
    private $loader = null;

    protected function setUp(): void
    {
        $this->internal = libxml_use_internal_errors(false);
        $this->loader   = libxml_get_external_entity_loader();
        libxml_clear_errors();
    }

    protected function tearDown(): void
    {
        libxml_set_external_entity_loader($this->loader);
        libxml_use_internal_errors($this->internal);
    }

    public function test_run_returnsAndRestores(): void
    {
        $loader = static fn(): ?string => null;
        libxml_set_external_entity_loader($loader);

        $inside = Libxml::run(static fn(): array => [libxml_use_internal_errors(), libxml_get_external_entity_loader()]);

        self::assertTrue($inside[0], 'errors collected inside');
        self::assertNotSame($loader, $inside[1]);
        self::assertFalse(libxml_use_internal_errors());
        self::assertSame($loader, libxml_get_external_entity_loader());
    }

    public function test_run_restoresAfterAnException(): void
    {
        $thrown = null;
        try {
            Libxml::run(static function (): void {
                (new DOMDocument())->loadXML('<a>');
                throw new \LogicException('inside');
            });
        } catch (\LogicException $e) {
            $thrown = $e;
        }

        self::assertSame('inside', $thrown->getMessage());
        self::assertFalse(libxml_use_internal_errors());
        self::assertSame($this->loader, libxml_get_external_entity_loader());
        self::assertSame([], libxml_get_errors(), 'the errors of $fn are cleared');
    }

    // even with the flags that load and expand entities, nothing outside the document is read
    public function test_run_externalEntityNotLoaded(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'libxml-');
        file_put_contents((string) $secret, 'SECRET-MARKER');
        try {
            $text = Libxml::run(static function () use ($secret): string {
                $dom = new DOMDocument();
                $dom->loadXML('<!DOCTYPE a [<!ENTITY x SYSTEM "file://' . $secret . '">]><a>&x;</a>', LIBXML_NOENT | LIBXML_DTDLOAD);
                return (string) $dom->textContent;
            });
        } finally {
            unlink((string) $secret);
        }

        self::assertStringNotContainsString('SECRET-MARKER', $text);
    }

    public function test_errors_withoutWarnings(): void
    {
        $errors = Libxml::run(static function (): array {
            (new DOMDocument())->loadXML('<a xmlns="relative"/>');     // a warning only
            $warnings = Libxml::errors();
            (new DOMDocument())->loadXML('<a>');
            return [$warnings, Libxml::errors()];
        });

        self::assertSame([], $errors[0]);
        self::assertNotSame([], $errors[1]);
    }

    public function test_describe_linesCountAndCut(): void
    {
        $text = Libxml::run(static function (): array {
            (new DOMDocument())->loadXML("<a>\n<x:b/>\n<y:c/>\n</a>");     // not fatal, both reported
            return [Libxml::describe(1, 10), Libxml::describe(5, 500)];
        });

        self::assertMatchesRegularExpression('/^line 2: .{10}…$/u', $text[0]);
        self::assertStringContainsString('; line 3: ', $text[1]);
        self::assertSame('', Libxml::describe(5, 500), 'none outside run()');
    }

    public function test_declaredEncoding(): void
    {
        self::assertNull(Libxml::declaredEncoding('<a/>'));
        self::assertNull(Libxml::declaredEncoding('<?xml version="1.0"?><a/>'));
        self::assertSame('utf-8', Libxml::declaredEncoding("\xEF\xBB\xBF<?xml version='1.0' encoding = 'utf-8'?><a/>"));
        self::assertSame('UTF-7', Libxml::declaredEncoding('<?xml version="1.0" encoding="UTF-7"?>'));
        self::assertNull(Libxml::declaredEncoding('<a/><?xml version="1.0" encoding="UTF-7"?>'), 'only a declaration at the start counts');
    }

    /** @return array<string, array{?string, bool}> */
    public static function encodings(): array
    {
        return ['none' => [null, true], 'utf-8' => ['utf-8', true], 'utf-16' => ['UTF-16', false], 'utf8, not the exact name' => ['utf8', false]];
    }

    #[DataProvider('encodings')]
    public function test_isUtf8(?string $encoding, bool $utf8): void
    {
        self::assertSame($utf8, Libxml::isUtf8($encoding));
    }

    public function test_cut_keepsCharactersWhole(): void
    {
        self::assertSame('short', Libxml::cut('short', 10));
        self::assertSame('Žl…', Libxml::cut('Žluťoučký', 3));
    }

    public function test_load_commentsCdataAndBom(): void
    {
        $dom = Libxml::load("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?><!-- <!DOCTYPE x> --><a><![CDATA[<!ENTITY b>]]>č</a>");

        self::assertSame('<!ENTITY b>č', $dom->documentElement?->textContent);
    }

    /** @return array<string, array{string, string}> each row refused by its own check, told apart by the message */
    public static function refusedByLoad(): array
    {
        $laughs = '<!DOCTYPE l [<!ENTITY a "aaaaaaaaaa"><!ENTITY e "&a;&a;&a;&a;&a;&a;&a;&a;">]><l>&e;</l>';
        return [
            'empty'                  => ['', 'The answer is empty'],
            'blank'                  => [" \n", 'The answer is empty'],
            'invalid utf-8'          => ["<a>\xE8</a>", 'is not UTF-8 text'],
            'nul'                    => ["<a>\0</a>", 'is not UTF-8 text'],
            'utf-16'                 => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><a/>', 'UTF-16'), 'is not UTF-8 text'],
            'ebcdic hides its <!'    => [(string) iconv('UTF-8', 'IBM500', '<?xml version="1.0" encoding="IBM500"?>' . $laughs), 'is not UTF-8 text'],
            'billion laughs'         => [$laughs, 'or other markup declarations'],
            'external entity'        => ['<!DOCTYPE a [<!ENTITY e SYSTEM "file:///etc/passwd">]><a>&e;</a>', 'or other markup declarations'],
            'html doctype'           => ["<!doctype html>\n<html/>", 'or other markup declarations'],
            'doctype after comments' => ['<?xml version="1.0"?><!-- a --><?pi <!-- ?>' . "\n" . '<!DOCTYPE a><a>--></a>', 'or other markup declarations'],
            'declaration after root' => ['<a><!ELEMENT a ANY></a>', 'is not well-formed XML'],
            'declared utf-7'         => ['<?xml version="1.0" encoding="UTF-7"?>+ADw-a/+AD4-', 'declares encoding "UTF-7"'],
            'declared latin 2'       => ['<?xml version="1.0" encoding="ISO-8859-2"?><a/>', 'declares encoding "ISO-8859-2"'],
            'not well-formed'        => ['<a><b></a>', 'is not well-formed XML: line 1'],
            'undeclared entity'      => ['<a>&e;</a>', 'is not well-formed XML'],
        ];
    }

    #[DataProvider('refusedByLoad')]
    public function test_load_refuses(string $xml, string $message): void
    {
        try {
            Libxml::load($xml, 'The answer');
            self::fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame($this->loader, libxml_get_external_entity_loader());
    }

    /** @return array<string, array{string, string}> what libxml read, past the byte checks */
    public static function refusedByParse(): array
    {
        return [
            'doctype'       => ['<!DOCTYPE a><a/>', 'must not have a DOCTYPE (DTD, entities)'],
            'ebcdic dtd'    => [(string) iconv('UTF-8', 'IBM500', '<?xml version="1.0" encoding="IBM500"?><!DOCTYPE a [<!ENTITY e "x">]><a>&e;</a>'), 'must not have a DOCTYPE (DTD, entities)'],
            'latin 2'       => ['<?xml version="1.0" encoding="ISO-8859-2"?><a/>', 'is in encoding "ISO-8859-2"'],
        ];
    }

    // the DOM step on its own: the byte checks of load() would refuse these first
    #[DataProvider('refusedByParse')]
    public function test_parse_refusesWhatLibxmlRead(string $xml, string $message): void
    {
        try {
            (new \ReflectionMethod(Libxml::class, 'parse'))->invoke(null, $xml, 'The answer');
            self::fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
