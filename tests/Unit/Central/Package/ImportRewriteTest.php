<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\IdMap;
use AA\Central\Package\ImportRewrite;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\ReferenceRewriter;
use PHPUnit\Framework\TestCase;

/** ImportRewrite: the one rewrite of the plan's checks and the writer, texts and RawBytes alike */
final class ImportRewriteTest extends TestCase
{
    private const OLD = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const NEW = 'abcdef0123456789abcdef0123456789';

    private static function rewrite(): ImportRewrite
    {
        return new ImportRewrite(new IdMap(modules: [self::OLD => self::NEW], views: [12 => 1234567], groups: ['ct' => 'ct_1'], collections: ['AbC12' => 'XyZ98']));
    }

    public function test_known_rewrites_long_ids_alerts_fields_and_groups(): void
    {
        self::assertSame('{ids:' . self::NEW . '} alerts1....XyZ98 {constant:ct_1:x} {view:12}', self::rewrite()->known('{ids:' . self::OLD . '} alerts1....AbC12 {constant:ct:x} {view:12}'));
    }

    public function test_the_group_of_a_widget_only_as_one(): void
    {
        self::assertSame('sel:ct_1:ct', self::rewrite()->known('sel:ct:ct', 'input_show_func'));
        self::assertSame('sel:ct:ct', self::rewrite()->known('sel:ct:ct'), 'no group column: no structured group');
        self::assertSame('fld:ct', self::rewrite()->known('fld:ct', 'input_show_func'), 'a widget without a group');
    }

    /** a const view lists the constants of the group in view.parameter (view.php) */
    public function test_the_group_of_a_const_view(): void
    {
        self::assertSame(' ct_1 ', self::rewrite()->known(' ct ', 'parameter'));
        self::assertSame('other', self::rewrite()->known('other', 'parameter'), 'a group outside the package stays');
        self::assertSame('parameter', ReferenceRewriter::groupColumn('view', ['type' => 'const', 'parameter' => 'ct']));
        self::assertNull(ReferenceRewriter::groupColumn('view', ['type' => 'list', 'parameter' => 'ct']), 'a list view: a search parameter');
        self::assertSame('input_show_func', ReferenceRewriter::groupColumn('field', []));
        self::assertSame('ct', ReferenceRewriter::rowGroup('view', ['type' => 'const', 'parameter' => ' ct']));
        self::assertNull(ReferenceRewriter::rowGroup('view', ['type' => 'const', 'parameter' => '']));
    }

    /** a template the export could not convert (windows-1250 bytes) still names the modules of the app */
    public function test_raw_bytes_are_rewritten_as_bytes(): void
    {
        $raw   = new RawBytes("\x8Akoda {ids:" . strtoupper(self::OLD) . "} \x9A {view:12} {constant:ct:x}");
        $known = self::rewrite()->known($raw);

        self::assertInstanceOf(RawBytes::class, $known);
        self::assertSame("\x8Akoda {ids:" . strtoupper(self::NEW) . "} \x9A {view:12} {constant:ct_1:x}", $known->bytes);
        $numeric = self::rewrite()->numeric($known);
        self::assertInstanceOf(RawBytes::class, $numeric);
        self::assertSame("\x8Akoda {ids:" . strtoupper(self::NEW) . "} \x9A {view:1234567} {constant:ct_1:x}", $numeric->bytes);
    }

    public function test_numeric_after_known(): void
    {
        self::assertSame('{view.php?vid=1234567&cmd[1234567]=x-1234567-a}', self::rewrite()->numeric(self::rewrite()->known('{view.php?vid=12&cmd[12]=x-12-a}')));
    }
}
