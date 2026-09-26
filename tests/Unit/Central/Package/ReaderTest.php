<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\PackageRejected;
use AA\Central\Package\Reader;
use AA\Central\Package\ReadResult;
use AA\Central\Package\Schema;
use AA\Central\Package\ValueCodec;
use AA\Central\Package\Writer;
use AA\Central\Package\XmlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WriterTest.php';     // its example models (tests have no autoloader)

/** Reader: safe parsing of an untrusted package, pruning by version, XSD validation and the canonical
 *  model - and the round trip model -> Writer -> Reader -> model. */
final class ReaderTest extends TestCase
{
    private const SITE   = '1a2b3c4d5e6f708192a3b4c5d6e7f801';
    private const SLICE  = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const ALERTS = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
    private const OBJECT = 'cc11bb22cc33dd44ee55ff6677889900';
    private const ITEM   = 'c148d685bfdc21e9f8731037539e8b90';

    private const SOURCE = ['url' => 'https://actionapps.org/aadev/', 'aa-version' => '2026.9', 'aa-build' => 'd2a1efe 2026-09-24 00:30', 'site' => self::SITE, 'exported' => '2026-09-24T10:00:00Z'];

    private static function read(string $xml): ReadResult
    {
        return (new Reader())->read($xml);
    }

    private static function xml(Package $package): string
    {
        return (new Writer())->toXml($package)->xml;
    }

    /** a model as plain data, so assertSame() compares it like `===` (types, key order) */
    private static function dump(mixed $value): mixed
    {
        return match (true) {
            $value instanceof RawBytes => ['RawBytes' => $value->bytes],
            is_object($value)          => [$value::class => array_map(self::dump(...), get_object_vars($value))],
            is_array($value)           => array_map(self::dump(...), $value),
            default                    => $value,
        };
    }

    /** model -> XML -> Reader -> model -> XML gives the same XML, and the read model is a fixed point */
    private static function assertRoundTrip(Package $package): ReadResult
    {
        $xml  = self::xml($package);
        $read = self::read($xml);
        self::assertSame($xml, self::xml($read->package), 'Writer(Reader(Writer(model))) = Writer(model)');
        self::assertSame(self::dump($read->package), self::dump(self::read(self::xml($read->package))->package), 'the canonical form reads back unchanged');
        return $read;
    }

    /** @param array<string, list<array<string, mixed>>> $rows */
    private static function slicePackage(array $rows = [], ?array $settings = null, array $objects = [], array $items = []): Package
    {
        return new Package('T', self::SOURCE, [new Module(Module::SLICE, self::SLICE, $rows + ['module' => [['name' => 'M']], 'slice' => [['name' => 'S']]], $settings, $objects, items: $items)], withItems: $items !== []);
    }

    /** A row in the canonical form: key attributes (defaults filled in), then every column this AA
     *  carries - the writer lists them all in meta/tables, so a missing one is the empty value.
     *  @param array<string, string|RawBytes|null> $values */
    private static function row(string $element, array $values): array
    {
        $schema = new Schema();
        $row    = [];
        foreach ($schema->rowAttributes($element) as $column => $key) {
            $row[$column] = $values[$column] ?? $key[2] ?? self::fail("$element.$column is required");
        }
        foreach ($schema->rowColumns($element) as $column => $type) {
            $row[$column] = array_key_exists($column, $values) ? $values[$column] : ValueCodec::emptyValue($type);
        }
        self::assertSame([], array_diff_key($values, $row), 'test data: no such column');
        return $row;
    }

    /** A hand-written package: a slice with $body after its <slice> row (settings, field, view, email,
     *  profile, object, item in this order), meta/tables listing view `name odd listlen`. */
    private static function doc(string $body = '', string $format = '1.0', string $prolog = '', string $meta = '', string $after = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n$prolog" . '<aa-app xmlns="' . Schema::NS . "\" format=\"$format\">\n"
            . '  <meta><name>T</name><source url="https://example.org/" aa-version="2026.9" exported="2026-09-24T10:00:00Z"/>'
            . '<contents items="true"/><tables><table name="view" columns="name odd listlen"/></tables>' . "$meta</meta>\n"
            . '  <slice-module id="' . self::SLICE . "\"><module><name>M</name></module><slice><name>S</name></slice>$body</slice-module>\n"
            . "$after</aa-app>\n";
    }

    private static function assertRejected(string $xml, string $message, ?Reader $reader = null): PackageRejected
    {
        try {
            ($reader ?? new Reader())->read($xml);
        } catch (PackageRejected $e) {
            // a message starting with / is a regular expression
            str_starts_with($message, '/') ? self::assertMatchesRegularExpression($message, $e->getMessage()) : self::assertStringContainsString($message, $e->getMessage());
            return $e;
        }
        self::fail("Not rejected, expected: $message");
    }

    /** @return list<array{string, string, string, string}> [path, level, code, ref] */
    private static function findings(ReadResult $result): array
    {
        return array_map(static fn(array $f): array => [$f[0], $f[1]->level, $f[1]->code, $f[1]->ref], $result->findings);
    }

    // ---------------------------------------------------------------- round trip

    public function test_written_fixture_round_trips(): void
    {
        $xml  = (string) file_get_contents(__DIR__ . '/fixtures/aa-app-example.written.xml');
        $read = self::read($xml);
        $this->assertSame([], $read->findings);
        $this->assertSame(Writer::FORMAT, $read->format);
        $this->assertSame($xml, self::xml($read->package));
    }

    /** @return array<string, array{Package}> models in the loose forms Writer accepts */
    public static function models(): array
    {
        $site = static fn(array $spots): Package => new Package('T', self::SOURCE, [new Module(Module::SITE, self::SITE, ['module' => [[]], 'site' => [[]]], spotTree: ['start_id' => 1, 'spots' => $spots])]);
        return [
            'design example'         => [WriterTest::example()],
            'with items'             => [WriterTest::exampleWithItems()],
            'minimal'                => [new Package('M', ['url' => 'https://example.org/', 'aa-version' => '1', 'exported' => '2026-09-24T10:00:00Z'], [new Module(Module::ALERTS, self::ALERTS, ['module' => [[]]])])],
            'raw bytes'              => [self::slicePackage(['view' => [['id' => 1, 'odd' => new RawBytes("\xE8\xE1st")]]])],
            'invalid UTF-8 string'   => [self::slicePackage(['view' => [['id' => 1, 'odd' => "\xC3\x28"]]])],
            'ints, scalars, lists'   => [self::slicePackage(['view' => [['id' => '2', 'listlen' => 10], ['id' => 100, 'listlen' => '-3']], 'profile' => [['property' => 'b'], ['property' => 'a', 'value' => 0]]], ['one' => 'x', 'list' => ['a', 'b'], 'sparse' => [3 => 'c']])],
            'spot tree empty arrays' => [$site([
                ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => [], 'c' => null, 'po' => ['1', '10', '2'], 'ch' => []],
                ['id' => '10', 'n' => "a\"b\nc", 'p' => '1', 'f' => 2, 'v' => ['y' => 'y', 'x' => 'x'], 'c' => ['y' => '=:1', 'x' => 'REGEXP:^a']],
                ['id' => '2', 'n' => 'b', 'p' => '1', 'c' => [], 'po' => []],
            ])],
            'spot tree false'        => [$site([['id' => 1, 'n' => 'start', 'p' => 1, 'v' => false, 'c' => false, 'po' => false, 'ch' => false]])],
            'constant groups'        => [new Package('T', self::SOURCE, [new Module(Module::SLICE, self::SLICE, ['module' => [[]], 'slice' => [[]]])], [
                new ConstantGroup('g2', '', ['constant' => [['id' => str_repeat('c', 32), 'pri' => 20], ['id' => str_repeat('a', 32), 'pri' => 20, 'ancestors' => str_repeat('c', 32)]]]),
                new ConstantGroup('g1', self::SLICE, ['constant_slice' => [['hierarch' => 1]]]),
            ])],
        ];
    }

    #[DataProvider('models')]
    public function test_model_round_trips(Package $package): void
    {
        $this->assertSame([], self::assertRoundTrip($package)->findings);
    }

    #[DataProviderExternal(WriterTest::class, 'values')]
    public function test_value_reads_back(?string $value, string $how): void
    {
        $read     = self::assertRoundTrip(self::slicePackage(['view' => [['id' => 12, 'odd' => $value]]]));
        $expected = ($value !== null && !mb_check_encoding($value, 'UTF-8')) ? new RawBytes($value) : $value;
        $this->assertSame(self::dump($expected), self::dump($read->package->modules[0]->rows['view'][0]['odd']), $how);
    }

    /** base64 carries no charset: raw bytes that happen to be valid UTF-8 read back as text */
    public function test_raw_bytes_valid_as_utf8_read_back_as_text(): void
    {
        $read = self::read(self::xml(self::slicePackage(['view' => [['id' => 12, 'odd' => new RawBytes("\xC3\x81")]]])));
        $this->assertSame('Á', $read->package->modules[0]->rows['view'][0]['odd']);
    }

    /** @return array<string, array{?string, bool}> meta/publish as written, published */
    public static function publishValues(): array
    {
        return [
            'all'                 => ['all', true],
            'no'                  => ['no', false],
            'users (older AAs)'   => ['users', false],
            'none'                => [null, false],
        ];
    }

    /** publishing is yes or no; the three values of older packages still read */
    #[DataProvider('publishValues')]
    public function test_meta_publish_is_yes_or_no(?string $value, bool $published): void
    {
        $xml = self::xml(self::slicePackage());
        $xml = ($value === null) ? $xml : (string) preg_replace('~(<meta>\s*<name>[^<]*</name>)~', "$1<publish>$value</publish>", $xml, 1);

        $this->assertSame($published, self::read($xml)->package->published);
        $this->assertSame($published ? 1 : 0, substr_count(self::xml(self::read($xml)->package), '<publish>all</publish>'), 'written back: all, or nothing');
    }

    // ---------------------------------------------------------------- canonical form

    public function test_canonical_form(): void
    {
        $sub     = 'dd11bb22cc33dd44ee55ff6677889900';
        $package = new Package('T', ['exported' => '2026-09-24T10:00:00Z', 'url' => 'https://example.org/', 'aa-version' => '2026.9'], [new Module(Module::SLICE, self::SLICE, [
            'module'  => [['name' => 'M']],
            'slice'   => [['name' => 'S', 'mlxctrl' => null]],
            'view'    => [['id' => 12, 'listlen' => 10, 'odd' => "a\r\nb", 'row_delimiter' => '   ']],
            'profile' => [['property' => 'input_view', 'value' => 13]],
        ], ['translations' => ['cz', 'en'], 'page404' => 2], [
            ['id' => self::OBJECT, 'class' => 'AA_Set', 'props' => ['slices' => self::SLICE, 'conds' => [['id' => $sub, 'class' => 'AA_Condition', 'props' => ['value' => 'x']]]]],
        ], items: [
            ['id' => self::ITEM, 'content' => ['headline........' => [1 => ['value' => 'B', 'flag' => 64], 0 => ['value' => 'A']]]],
        ])], [new ConstantGroup('g', '', ['constant' => [['id' => str_repeat('a', 32), 'pri' => 5]]])], withItems: true);

        $expected = new Package('T', ['url' => 'https://example.org/', 'aa-version' => '2026.9', 'exported' => '2026-09-24T10:00:00Z'], [new Module(Module::SLICE, self::SLICE, [
            'module'  => [self::row('module', ['name' => 'M'])],
            'slice'   => [self::row('slice', ['name' => 'S', 'mlxctrl' => null])],
            'view'    => [self::row('view', ['id' => '12', 'listlen' => '10', 'odd' => "a\r\nb", 'row_delimiter' => '   '])],
            'profile' => [self::row('profile', ['property' => 'input_view', 'value' => '13'])],
        ], ['page404' => '2', 'translations' => ['cz', 'en']], [
            ['id' => self::OBJECT, 'class' => 'AA_Set', 'props' => ['conds' => [['id' => $sub, 'class' => 'AA_Condition', 'props' => ['value' => 'x']]], 'slices' => self::SLICE]],
        ], items: [
            ['id' => self::ITEM, 'content' => ['headline........' => [['value' => 'A', 'flag' => 0], ['value' => 'B', 'flag' => 64]]]],
        ])], [new ConstantGroup('g', '', ['constant' => [self::row('constant', ['id' => str_repeat('a', 32), 'pri' => '5'])]])], withItems: true);

        $this->assertSame(self::dump($expected), self::dump(self::assertRoundTrip($package)->package));
    }

    public function test_spot_tree_in_the_canonical_form_of_sitetree_to_array(): void
    {
        $tree = self::read(self::xml(self::models()['spot tree empty arrays'][0]))->package->modules[0]->spotTree;
        $this->assertSame(['start_id' => '1', 'spots' => [
            ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => [], 'c' => null, 'po' => ['1', '10', '2'], 'ch' => []],
            ['id' => '2', 'n' => 'b', 'p' => '1', 'f' => 0, 'v' => null, 'c' => [], 'po' => [], 'ch' => null],
            ['id' => '10', 'n' => "a\"b\nc", 'p' => '1', 'f' => 2, 'v' => ['y' => 'y', 'x' => 'x'], 'c' => ['y' => '=:1', 'x' => 'REGEXP:^a'], 'po' => null, 'ch' => null],
        ]], $tree);
    }

    /** a column meta/tables lists but the row leaves out = empty value; an unlisted one stays absent
     *  (the importing AA's default) */
    public function test_missing_columns_follow_meta_tables(): void
    {
        $read = self::read(self::doc('<view id="12"><name>x</name></view>'));
        $this->assertSame(['id' => '12', 'name' => 'x', 'odd' => '', 'listlen' => '0'], $read->package->modules[0]->rows['view'][0]);
        $this->assertSame(['name' => 'S'], $read->package->modules[0]->rows['slice'][0], 'slice is not in meta/tables');
    }

    public function test_the_design_example_reads(): void
    {
        $read  = self::read((string) file_get_contents(__DIR__ . '/fixtures/aa-app-example.xml'));
        $this->assertSame([], $read->findings);
        [$site, $slice] = $read->package->modules;
        $this->assertSame("<!DOCTYPE html>\r\n<html>{view:12::listlen-5}\r\n{view.php?vid=12&cmd[12]=x-12-{xid}}\r\n<script>if (a[b[0]]>1) {}</script>\r\n{_:Hlavicka:Central Test}\r\n</html>", $site->rows['site_spot'][0]['content'], 'crlf, CDATA split at ]]>');
        $this->assertSame(['spot_id' => '2', 'content' => '{item:{xid}:_#HEADLINE}', 'flag' => '0'], $site->rows['site_spot'][1], 'site_spot not in the abbreviated meta/tables');
        $this->assertNull($slice->rows['slice'][0]['mlxctrl']);
        $this->assertSame('', $slice->rows['view'][1]['field3'], 'listed, left out');
        $this->assertArrayNotHasKey('aditional', $slice->rows['view'][1], 'not listed');
        $this->assertSame(['translations' => ['cz', 'en']], $slice->settings);
    }

    /** @return array<string, array{string, string}> body, expected view.odd */
    public static function texts(): array
    {
        return [
            'CDATA split at ]]>'       => ['<view id="1"><odd><![CDATA[<x>]]]]><![CDATA[></x>]]></odd></view>', '<x>]]></x>'],
            'whitespace only'          => ['<view id="1"><odd>   </odd></view>', '   '],
            'newlines only'            => ["<view id=\"1\"><odd>\n\n</odd></view>", "\n\n"],
            'comment inside the value' => ['<view id="1"><odd>a<!-- note -->b</odd></view>', 'ab'],
            'crlf="1"'                 => ["<view id=\"1\"><odd crlf=\"1\">a\nb</odd></view>", "a\r\nb"],
            'CR as a character ref'    => ['<view id="1"><odd>a&#13;b</odd></view>', "a\rb"],
            'base64 control char'      => ['<view id="1"><odd enc="base64">' . base64_encode("\x07") . '</odd></view>', "\x07"],
        ];
    }

    #[DataProvider('texts')]
    public function test_text_is_read_as_written(string $body, string $expected): void
    {
        $this->assertSame($expected, self::read(self::doc($body))->package->modules[0]->rows['view'][0]['odd']);
    }

    public function test_whitespace_of_properties_and_item_values_is_kept(): void
    {
        $read = self::read(self::doc('<settings class="AA_Modulesettings_Slice"><p n="x">  </p></settings><item id="' . self::ITEM . '"><v f="headline........"> </v></item>'));
        $this->assertSame(['x' => '  '], $read->package->modules[0]->settings);
        $this->assertSame([' '], array_column($read->package->modules[0]->items[0]['content']['headline........'], 'value'));
    }

    public function test_numbers_are_canonical(): void
    {
        $read = self::read(self::doc('<view id=" 012 "><listlen>007</listlen><odd>007</odd></view><view id="+13"><listlen>-0</listlen></view>'));
        $this->assertSame([['12', '7', '007'], ['13', '0', '']], array_map(static fn(array $r): array => [$r['id'], $r['listlen'], $r['odd']], $read->package->modules[0]->rows['view']));
    }

    public function test_null_attribute_as_1(): void
    {
        $this->assertNull(self::read(self::doc('<view id="1"><odd null="1"/></view>'))->package->modules[0]->rows['view'][0]['odd']);
    }

    // ---------------------------------------------------------------- pruning

    public function test_unknown_column_is_always_pruned(): void
    {
        $read = self::read(self::doc('<view id="12"><name>x</name><colour_2030>red</colour_2030></view>', after: '<constant-group name="g"><constant id="' . str_repeat('a', 32) . '"><weight>3</weight></constant></constant-group>'));
        $this->assertSame([
            ['slice-module[' . self::SLICE . ']/view[12]/colour_2030', LintFinding::WARNING, Reader::UNKNOWN_COLUMN, 'colour_2030'],
            ['constant-group[g]/constant[' . str_repeat('a', 32) . ']/weight', LintFinding::WARNING, Reader::UNKNOWN_COLUMN, 'weight'],
        ], self::findings($read));
        $this->assertArrayNotHasKey('colour_2030', $read->package->modules[0]->rows['view'][0]);
    }

    /** @return array<string, array{string, string}> a column this AA knows, but a package never carries */
    public static function forbiddenColumns(): array
    {
        return [
            'installation column'   => ['<slice><name>S</name><reading_password>x</reading_password></slice>', 'reading_password'],
            'module column'         => ['<view id="1"><slice_id>' . self::SLICE . '</slice_id></view>', 'slice_id'],
            'AUTO_INCREMENT number' => ['<view id="1"><id>5</id></view>', "}id'"],
        ];
    }

    #[DataProvider('forbiddenColumns')]
    public function test_known_but_forbidden_column_is_rejected_not_pruned(string $body, string $column): void
    {
        foreach (['1.0', '1.1'] as $format) {
            $xml = str_contains($body, '<slice>')
                ? str_replace('<slice><name>S</name></slice>', $body, self::doc(format: $format))
                : self::doc($body, $format);
            $this->assertStringContainsString('schema', self::assertRejected($xml, $column)->getMessage(), $format);
        }
    }

    public function test_newer_minor_prunes_unknown_elements_attributes_and_classes(): void
    {
        $body = '<view id="12" colour="red"><name>x</name></view>'
            . '<object id="' . self::OBJECT . '" class="AA_Form"><p n="rows">x</p></object>'
            . '<object id="' . str_repeat('b', 32) . '" class="AA_Set" since="1.1"><p n="slices">' . self::SLICE . '</p>'
            . '<object id="' . str_repeat('d', 32) . '" class="AA_Future" n="conds"/><object id="' . str_repeat('e', 32) . '" class="AA_Aliasfunc" n="sort"/></object>'
            . '<form id="3"><name>F</name></form>';
        $read = self::read(self::doc($body, '1.7', meta: '<licence>GPL</licence>'));
        $m    = 'slice-module[' . self::SLICE . ']';
        $this->assertSame([
            ['aa-app', LintFinding::INFO, Reader::NEWER_FORMAT, '1.7'],
            ['meta/licence', LintFinding::WARNING, Reader::UNKNOWN_ELEMENT, 'licence'],
            ["$m/view[12]", LintFinding::WARNING, Reader::UNKNOWN_ATTRIBUTE, 'colour'],
            ["$m/object[" . self::OBJECT . ']', LintFinding::WARNING, Reader::UNKNOWN_CLASS, 'AA_Form'],
            ["$m/object[" . str_repeat('b', 32) . ']', LintFinding::WARNING, Reader::UNKNOWN_ATTRIBUTE, 'since'],
            ["$m/object[" . str_repeat('b', 32) . ']/object[' . str_repeat('d', 32) . ']', LintFinding::WARNING, Reader::UNKNOWN_CLASS, 'AA_Future'],
            ["$m/object[" . str_repeat('b', 32) . ']/object[' . str_repeat('e', 32) . ']', LintFinding::WARNING, Reader::UNKNOWN_CLASS, 'AA_Aliasfunc'],
            ["$m/form[3]", LintFinding::WARNING, Reader::UNKNOWN_ELEMENT, 'form'],
        ], self::findings($read));
        $module = $read->package->modules[0];
        $this->assertSame([['id' => str_repeat('b', 32), 'class' => 'AA_Set', 'props' => ['slices' => self::SLICE]]], $module->objects);
        $this->assertSame('12', $module->rows['view'][0]['id']);
    }

    public function test_newer_minor_prunes_inside_the_setting_values(): void
    {
        $body = str_replace('</slice>', '</slice><setting-values><v f="_email.........." colour="red">a@example.org</v><v f="_email.........." i="1000001" flag="64">b</v><w/></setting-values>', self::doc(format: '1.7'));
        $read = self::read($body);
        $at   = 'slice-module[' . self::SLICE . ']/setting-values';
        $this->assertSame([
            ['aa-app', LintFinding::INFO, Reader::NEWER_FORMAT, '1.7'],
            ["$at/v", LintFinding::WARNING, Reader::UNKNOWN_ATTRIBUTE, 'colour'],
            ["$at/w", LintFinding::WARNING, Reader::UNKNOWN_ELEMENT, 'w'],
        ], self::findings($read));
        $this->assertSame(
            ['_email..........' => [0 => ['value' => 'a@example.org', 'flag' => 0], 1000001 => ['value' => 'b', 'flag' => 64]]],
            $read->package->modules[0]->transfer['setting-values'],
        );
    }

    /** @return array<string, array{string, string, string}> body, meta, expected message */
    public static function unknownInThisVersion(): array
    {
        return [
            'element'        => ['<form id="3"/>', '', 'form'],
            'attribute'      => ['<view id="12" colour="red"/>', '', 'colour'],
            'top class'      => ['<object id="' . self::OBJECT . '" class="AA_Form"/>', '', 'AA_Form'],
            'nested class'   => ['<object id="' . self::OBJECT . '" class="AA_Set"><object id="' . str_repeat('d', 32) . '" class="AA_Aliasfunc" n="conds"/></object>', '', 'AA_Aliasfunc'],
            'meta element'   => ['', '<licence>GPL</licence>', 'licence'],
            'huge attribute' => ['<view id="12" x="' . str_repeat('a', 7_000_000) . '"/>', '', "'x'"],
        ];
    }

    #[DataProvider('unknownInThisVersion')]
    public function test_same_minor_leaves_unknown_things_to_the_xsd(string $body, string $meta, string $message): void
    {
        $this->assertStringContainsString('schema', self::assertRejected(self::doc($body, meta: $meta), $message)->getMessage());
    }

    public function test_signature_content_is_not_pruned(): void
    {
        $read = self::read(self::doc(format: '1.2', after: '<signature algo="ed25519"><x:sig xmlns:x="urn:x" x:k="1">abc</x:sig><future/></signature>'));
        $this->assertSame([['aa-app', LintFinding::INFO, Reader::NEWER_FORMAT, '1.2']], self::findings($read));
    }

    // ---------------------------------------------------------------- bad inputs

    /** @return array<string, array{string, string}> xml, expected message */
    public static function badInputs(): array
    {
        $laughs = '<!DOCTYPE aa-app [<!ENTITY a "haha">' . implode('', array_map(static fn(int $i): string => "<!ENTITY a$i \"" . str_repeat('&a' . ($i ? $i - 1 : '') . ';', 10) . '">', range(0, 9))) . ']>';
        return [
            'DOCTYPE, external DTD'     => [self::doc(prolog: "<!DOCTYPE aa-app SYSTEM \"http://127.0.0.1:9/x.dtd\">\n"), 'DOCTYPE'],
            'DOCTYPE, internal subset'  => [self::doc(prolog: "<!DOCTYPE aa-app [<!ELEMENT aa-app ANY>]>\n"), 'DOCTYPE'],
            'DOCTYPE after a comment'   => [self::doc(prolog: "<!-- first -->\n<!DOCTYPE aa-app>\n"), 'DOCTYPE'],
            'internal entity'           => [self::doc('<view id="1"><name>&e;</name></view>', prolog: "<!DOCTYPE aa-app [<!ENTITY e \"x\">]>\n"), 'DOCTYPE'],
            'external entity'           => [self::doc('<view id="1"><name>&e;</name></view>', prolog: "<!DOCTYPE aa-app [<!ENTITY e SYSTEM \"file:///etc/passwd\">]>\n"), 'DOCTYPE'],
            'billion laughs'            => [self::doc('<view id="1"><name>&a9;</name></view>', prolog: $laughs), 'DOCTYPE'],
            'markup declaration'        => [self::doc(after: '<signature><!ELEMENT x ANY></signature>'), 'DOCTYPE'],
            'undeclared entity'         => [self::doc('<view id="1"><name>&e;</name></view>'), 'not well-formed'],
            'XInclude'                  => [self::doc(meta: '<xi:include xmlns:xi="http://www.w3.org/2001/XInclude" href="file:///etc/passwd" parse="text"/>'), 'schema'],
            'XInclude, newer minor'     => [self::doc(format: '1.1', meta: '<xi:include xmlns:xi="http://www.w3.org/2001/XInclude" href="file:///etc/passwd" parse="text"/>'), 'schema'],
            'foreign attribute'         => [self::doc('<view id="1" xsi:type="row_slice" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>', '1.1'), 'xsi:type'],
            // the same local name twice: keyed by it, the namespaced one would hide behind the plain one
            'foreign attribute twin'    => [self::doc('<view id="1" xsi:type="row_slice" type="x" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>', '1.1'), 'xsi:type'],
            'xsi:type on the root'      => [str_replace('format="1.0"', 'format="1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="x"', self::doc()), 'xsi:type'],
            'long encoding declaration' => [str_replace('encoding="UTF-8"', str_repeat(' ', 3_000_000) . 'encoding="ISO-8859-1"', self::doc()), 'ISO-8859-1'],
            'UTF-7'                     => [str_replace(['encoding="UTF-8"', '<name>T</name>'], ['encoding="UTF-7"', '<name>+ADw-b+AD4-</name>'], self::doc()), 'UTF-7'],
            'items not announced'       => [str_replace('items="true"', 'items="false"', self::doc('<item id="' . self::ITEM . '"><v f="headline........">a</v></item>')), 'items in a package without items'],
            'index out of range'        => [self::doc('<item id="' . self::ITEM . '"><v f="headline........" i="99999999999999999999">a</v></item>'), 'out of range'],
            'flag out of range'         => [self::doc('<item id="' . self::ITEM . '"><v f="headline........" flag="-99999999999999999999">a</v></item>'), 'out of range'],
            'format with spaces'        => [self::doc(format: ' 1.0'), 'format " 1.0" is not MAJOR.MINOR'],
            'xml:space'                 => [self::doc('<view id="1" xml:space="preserve"/>'), 'xml:space'],
            'text before the root'      => ["x<aa-app/>", 'not well-formed'],
            'not well-formed'           => [str_replace('</aa-app>', '', self::doc()), 'not well-formed'],
            'unterminated start tag'    => [self::doc() . '<x a="1" b', 'not well-formed'],
            'unterminated end tag'      => [self::doc() . '</x', 'not well-formed'],
            'unterminated comment'      => [self::doc() . '<!-- x', 'not well-formed'],
            'empty'                     => ['', 'empty'],
            'non-UTF-8 declaration'     => [str_replace('encoding="UTF-8"', 'encoding="ISO-8859-2"', self::doc()), 'ISO-8859-2'],
            'invalid UTF-8'             => [str_replace('<name>T</name>', "<name>\xE8</name>", self::doc()), 'UTF-8'],
            'UTF-16'                    => [mb_convert_encoding(self::doc(), 'UTF-16'), 'UTF-8'],
            'major version 2'           => [self::doc(format: '2.0'), 'format 2.0'],
            'major version 0'           => [self::doc(format: '0.9'), 'format 0.9'],
            'no format'                 => [str_replace(' format="1.0"', '', self::doc()), 'format'],
            'format not a version'      => [self::doc(format: 'one'), 'format'],
            'minor with leading zero'   => [self::doc(format: '1.01'), 'schema'],
            'other root'                => ['<sliceexport version="1.1"/>', 'not an aa-app package'],
            'other namespace'           => [str_replace(Schema::NS, 'urn:x', self::doc()), 'not an aa-app package'],
            'view without id'           => [self::doc('<view><name>x</name></view>'), "'id' is required"],
            'object without class'      => [self::doc('<object id="' . self::OBJECT . '"/>'), "'class' is required"],
            'no module row'             => [str_replace('<slice-module', '<site-module id="' . self::SITE . '"><site/></site-module><slice-module', self::doc()), 'module'],
            'null with a text'          => [self::doc('<view id="1"><odd null="true">x</odd></view>'), 'view[1]/odd: null="true" with a text'],
            'broken base64'             => [self::doc('<view id="1"><odd enc="base64">%%%</odd></view>'), 'view[1]/odd: Broken base64'],
            'duplicate item value'      => [self::doc('<item id="' . self::ITEM . '"><v f="headline........">a</v><v f="headline........" i="0">b</v></item>'), 'headline........[0] twice'],
            'duplicate spot variable'   => [str_replace('<slice-module', '<site-module id="' . self::SITE . '"><module/><site/><spot-tree start="1"><spot id="1" name="s" parent="1"><var>x</var><var>x</var></spot></spot-tree></site-module><slice-module', self::doc()), 'variable x twice'],
            'duplicate spot condition'  => [str_replace('<slice-module', '<site-module id="' . self::SITE . '"><module/><site/><spot-tree start="1"><spot id="1" name="s" parent="1"><cond var="x">1</cond><cond var="x">2</cond></spot></spot-tree></site-module><slice-module', self::doc()), 'condition x twice'],
        ];
    }

    #[DataProvider('badInputs')]
    public function test_bad_input_is_rejected(string $xml, string $message): void
    {
        $e = self::assertRejected($xml, $message);
        $this->assertStringNotContainsString('root:', $e->getMessage(), 'nothing of /etc/passwd leaks');
    }

    public function test_size_limit(): void
    {
        $xml = self::doc(after: '<signature>' . str_repeat(' ', XmlGuard::MAX_BYTES) . '</signature>');
        self::assertRejected($xml, 'larger than ' . XmlGuard::MAX_BYTES . ' bytes');
        self::assertRejected(self::doc(), 'larger than 100 bytes', new Reader(guard: new XmlGuard(maxBytes: 100)));
    }

    public function test_element_limit(): void
    {
        $many = static fn(int $n): string => self::doc(after: '<signature>' . str_repeat('<x/>', $n) . '</signature>');
        self::read($many(XmlGuard::MAX_ELEMENTS - 13));    // doc() has 13 elements besides the signature content
        self::assertRejected($many(XmlGuard::MAX_ELEMENTS - 12), 'more than ' . XmlGuard::MAX_ELEMENTS . ' elements');
    }

    public function test_attribute_limit(): void
    {
        $tag = '<x ' . implode(' ', array_map(static fn(int $i): string => "a$i=\"\"", range(1, 50))) . '/>';
        self::assertRejected(self::doc(after: '<signature>' . str_repeat($tag, intdiv(XmlGuard::MAX_ATTRIBUTES, 50) + 1) . '</signature>'), 'more than ' . XmlGuard::MAX_ATTRIBUTES . ' attributes');
    }

    /** libxml allocates all attributes of a start tag before XMLReader returns it - counted on the bytes */
    public function test_attributes_of_one_tag_are_counted_before_libxml(): void
    {
        $attrs = static fn(int $n): string => implode(' ', array_map(static fn(int $i): string => "a$i =\n'$i'", range(1, $n)));
        self::read(self::doc(after: '<signature ' . $attrs(XmlGuard::MAX_TAG_ATTRIBUTES) . '/>'));
        $message = 'more than ' . XmlGuard::MAX_TAG_ATTRIBUTES . ' attributes in one tag';
        self::assertRejected(self::doc(after: '<signature ' . $attrs(XmlGuard::MAX_TAG_ATTRIBUTES + 1) . '/>'), $message);
        self::assertRejected(self::doc(after: '<signature q=">" ' . $attrs(XmlGuard::MAX_TAG_ATTRIBUTES) . '/>'), $message);
        self::assertRejected(self::doc(after: '<signature xmlns:p0="urn:p" ' . str_replace(' a', ' xmlns:a', $attrs(XmlGuard::MAX_TAG_ATTRIBUTES)) . '/>'), $message);
    }

    /** comments, text, CDATA are nodes of the DOM too: `a<!---->` x 1M = 8 MB, 356 MB of DOM */
    public function test_node_limit(): void
    {
        $many = static fn(int $n): string => self::doc(after: '<signature>' . str_repeat('a<!---->', $n) . '</signature>');
        self::read($many(intdiv(XmlGuard::MAX_NODES, 2) - 30));
        self::assertRejected($many(intdiv(XmlGuard::MAX_NODES, 2)), 'more than ' . XmlGuard::MAX_NODES . ' nodes');
    }

    /** <!DOCTYPE, <!ELEMENT in CDATA and comments are text: the byte scan skips them */
    public function test_declarations_in_cdata_and_comments_are_text(): void
    {
        $read = self::read(self::doc('<view id="1"><odd><![CDATA[<!DOCTYPE html><a href="x" b=\'>\'>]]></odd></view>', prolog: "<!-- <!DOCTYPE x> -->\n"));
        $this->assertSame('<!DOCTYPE html><a href="x" b=\'>\'>', $read->package->modules[0]->rows['view'][0]['odd']);
    }

    /** package text in a message is cut: a caller may log or show it */
    public function test_package_text_in_messages_is_bounded(): void
    {
        $long = str_repeat('x', 3_000_000);
        $site = '<site-module id="' . self::SITE . "\"><module/><site/><spot-tree start=\"1\"><spot id=\"1\" name=\"s\" parent=\"1\"><var>$long</var><var>$long</var></spot></spot-tree></site-module>";
        foreach ([
            str_replace('<slice-module', "$site<slice-module", self::doc()),
            self::doc("<view id=\"1\"><listlen>$long</listlen></view>"),
            self::doc("<view id=\"1\" $long=\"1\"/>", '1.0'),
            self::doc("<view id=\"1\" xmlns:$long=\"urn:x\" $long:a=\"1\"/>", '1.1'),
            self::doc("<settings class=\"AA_Modulesettings_Slice\"><p n=\"$long\">a</p><p n=\"$long\">b</p></settings>"),
        ] as $i => $xml) {
            $this->assertLessThan(2000, strlen(self::assertRejected($xml, '')->getMessage()), "case $i");
        }
    }

    /** editors add schema hints to the root: dropped, nothing is loaded from them */
    public function test_schema_location_on_the_root_is_dropped(): void
    {
        $xml  = str_replace('format="1.0"', 'format="1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="' . Schema::NS . ' http://127.0.0.1:9/x.xsd" xsi:noNamespaceSchemaLocation="x.xsd"', self::doc());
        $this->assertSame([
            ['aa-app', LintFinding::INFO, Reader::SCHEMA_LOCATION, 'xsi:schemaLocation'],
            ['aa-app', LintFinding::INFO, Reader::SCHEMA_LOCATION, 'xsi:noNamespaceSchemaLocation'],
        ], self::findings(self::read($xml)));
    }

    public function test_empty_number_column_is_the_empty_value(): void
    {
        $row = self::read(self::doc('<view id="1"><listlen/><odd/></view>'))->package->modules[0]->rows['view'][0];
        $this->assertSame(['0', ''], [$row['listlen'], $row['odd']]);
    }

    public function test_requires_without_version_is_null(): void
    {
        $this->assertNull(self::read(str_replace('<contents', '<requires/><contents', self::doc()))->package->requires);
    }

    public function test_reader_of_a_given_schema(): void
    {
        $this->assertSame('T', (new Reader(new Schema()))->read(self::doc())->package->name);
    }

    public function test_depth_limit(): void
    {
        // aa-app/signature = 2 levels
        $deep = static fn(int $levels): string => self::doc(after: '<signature>' . str_repeat('<x>', $levels) . str_repeat('</x>', $levels) . '</signature>');
        self::read($deep(XmlGuard::MAX_DEPTH - 2));
        self::assertRejected($deep(XmlGuard::MAX_DEPTH - 1), 'nested deeper than ' . XmlGuard::MAX_DEPTH);
    }

    public function test_comments_and_processing_instructions_may_precede_the_root(): void
    {
        $this->assertSame('T', self::read(self::doc(prolog: "<!-- a -->\n<?aa note?>\n  "))->package->name);
    }

    /** the reader leaves libxml as it found it - also when it rejects */
    public function test_libxml_state_is_restored(): void
    {
        $loader   = static fn(): ?string => null;
        $internal = libxml_use_internal_errors(false);
        libxml_set_external_entity_loader($loader);
        try {
            self::read(self::doc());
            self::assertRejected(self::doc(prolog: '<!DOCTYPE aa-app>'), 'DOCTYPE');
            self::assertRejected(self::doc('<view/>'), 'schema');
            $this->assertFalse(libxml_use_internal_errors(false));
            $this->assertSame($loader, libxml_get_external_entity_loader());
            $this->assertSame([], libxml_get_errors());
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($internal);
        }
    }

    public function test_reader_keeps_no_state_between_calls(): void
    {
        $reader = new Reader();
        $this->assertNotSame([], $reader->read(self::doc('<view id="12"><x_2030>1</x_2030></view>'))->findings);
        $read = $reader->read(self::doc('<view id="12"/>'));
        $this->assertSame([], $read->findings);
        $this->assertSame(['id' => '12', 'name' => '', 'odd' => '', 'listlen' => '0'], $read->package->modules[0]->rows['view'][0]);
    }
}
