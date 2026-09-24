<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Schema;
use AA\Central\Package\ValueCodec;
use AA\Central\Package\Writer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    private const SITE   = '1a2b3c4d5e6f708192a3b4c5d6e7f801';
    private const SLICE  = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const ALERTS = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';

    /** writer output of example() - the golden file; after checking the diff regenerate it with
     *  `AA_WRITE_GOLDEN=1 vendor/bin/phpunit --filter test_example_equals_the_golden_file` */
    private const GOLDEN = __DIR__ . '/fixtures/aa-app-example.written.xml';

    private const SOURCE = ['url' => 'https://actionapps.org/aadev/', 'aa-version' => '2026.9', 'aa-build' => 'd2a1efe 2026-09-24 00:30', 'site' => self::SITE, 'exported' => '2026-09-24T10:00:00Z'];

    /** The package of design balicek-v1.md § 4 (fixtures/aa-app-example.xml) as a model - rows and
     *  properties deliberately NOT in the output order. */
    private static function example(): Package
    {
        $spot_content = "<!DOCTYPE html>\r\n<html>{view:12::listlen-5}\r\n{view.php?vid=12&cmd[12]=x-12-{xid}}\r\n<script>if (a[b[0]]>1) {}</script>\r\n{_:Hlavicka:Central Test}\r\n</html>";
        $site         = new Module(Module::SITE, self::SITE, [
            'module'    => [['name' => 'Central Test - web', 'lang_file' => 'cz-utf8_site_lang.php']],
            'site'      => [['state_file' => '/cz/home', 'flag' => 1]],
            'site_spot' => [
                ['spot_id' => 2, 'content' => '{item:{xid}:_#HEADLINE}', 'flag' => 0],
                ['spot_id' => 1, 'content' => $spot_content],
            ],
            'uses'      => [['destination_id' => self::SLICE]],
        ], settings: [
            'web_languages'   => ['cz'],
            'translate_slice' => self::SLICE,
            'page404'         => '2',
        ], objects: [
            ['id' => '99aa88bb77cc66dd55ee44ff33aa22bb', 'class' => 'AA_Aliasfunc', 'props' => ['code' => '<header><h1>_#P1</h1></header>', 'alias' => 'Hlavicka']],
        ], spotTree: [
            'start_id' => '1',
            'spots'    => [
                ['id' => '3', 'n' => 'rubrika', 'p' => '1', 'f' => 0, 'v' => null, 'c' => ['x' => 'rubrika'], 'po' => null, 'ch' => null],
                ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => ['x' => 'x'], 'c' => null, 'po' => ['1', '2'], 'ch' => ['3']],
                ['id' => '2', 'n' => 'clanek', 'p' => '1'],
            ],
        ], doc: 'Web appky. Spot 1 = kostra stránky.');

        $slice = new Module(Module::SLICE, self::SLICE, [
            'module'  => [['name' => 'Central Test - články', 'lang_file' => 'cz-utf8_news_lang.php']],
            'slice'   => [['name' => 'Central Test - články', 'type' => 'S', 'vid' => '12', 'mlxctrl' => null, 'fulltext_format' => '<h1>_#HEADLINE</h1>', 'fulltext_format_top' => '']],
            'field'   => [
                ['id' => 'category.......1', 'type' => 'category', 'name' => 'Rubrika', 'input_show_func' => 'sel:ct_rubriky:', 'input_pri' => 0],
                ['id' => '_email..........', 'type' => 'email', 'name' => 'E-mail redakce', 'input_help' => 'Kam chodí upozornění', 'input_default' => 'txt:redakce@example.org', 'required' => '1'],
                ['id' => 'headline........', 'alias1_func' => 'f_h:', 'alias1' => '_#HEADLINE', 'input_show_func' => 'fld:', 'input_pri' => 100, 'name' => 'Titulek', 'type' => 'headline'],
            ],
            'view'    => [
                ['id' => 13, 'name' => 'Detail', 'type' => 'full', 'odd' => '{constant:ct_rubriky:{category.......1}:name}'],
                ['id' => 12, 'name' => 'Seznam článků', 'type' => 'digest', 'odd' => '<li><a href="{view.php?vid=13&cmd[13]=x-13-_#ITEM_ID_}">_#HEADLINE</a></li>', 'field3' => 'clanky'],
            ],
            'email'   => [['id' => 5, 'description' => 'Upozornění redakci', 'subject' => 'Nový článek', 'body' => '{modulefield:' . self::SLICE . ':_email..........}']],
            'profile' => [['property' => 'input_view', 'selector' => '', 'value' => '13']],
        ], settings: ['translations' => [1 => 'en', 0 => 'cz']], objects: [[
            'id'    => 'cc11bb22cc33dd44ee55ff6677889900',
            'class' => 'AA_Set',
            'props' => [
                'sort'   => [['id' => 'ee11bb22cc33dd44ee55ff6677889900', 'class' => 'AA_Sortorder', 'props' => ['field' => 'publish_date....', 'desc' => '1']]],
                'slices' => self::SLICE,
                'conds'  => [['id' => 'dd11bb22cc33dd44ee55ff6677889900', 'class' => 'AA_Condition', 'props' => ['fields' => 'category.......1', 'operator' => '=', 'value' => 'zpravy']]],
            ],
        ]]);

        $alerts = new Module(Module::ALERTS, self::ALERTS, [
            'module'                     => [['name' => 'Upozornění']],
            'email'                      => [['id' => 7, 'description' => 'Vítejte']],
            'alerts_collection'          => [['id' => 'AbC12', 'emailid_welcome' => 7, 'emailid_alert' => 0, 'slice_id' => self::SLICE]],
            'alerts_collection_filter'   => [['collectionid' => 'AbC12', 'filterid' => 3, 'myindex' => 0]],
            'alerts_collection_howoften' => [['collectionid' => 'AbC12', 'howoften' => 'daily']],
            'alerts_filter'              => [['id' => 3, 'vid' => 12, 'conds' => '', 'description' => 'Všechny články']],
        ]);

        $group = new ConstantGroup('ct_rubriky', self::SLICE, [
            'constant_slice' => [['propagate' => 0, 'hierarch' => 1]],
            'constant'       => [
                ['id' => 'bb11bb22cc33dd44ee55ff6677889900', 'name' => 'Domácí', 'value' => 'domaci', 'pri' => 110, 'ancestors' => 'aa11bb22cc33dd44ee55ff6677889900'],
                ['id' => 'aa11bb22cc33dd44ee55ff6677889900', 'name' => 'Zprávy', 'value' => 'zpravy', 'pri' => 100, 'ancestors' => ''],
            ],
        ]);

        return new Package('Central Test', self::SOURCE, [$alerts, $slice, $site], [$group], description: 'Testovací appka Centralu - web, články, kategorie.', version: '0.1.0', publish: 'no', requires: '2026.9');
    }

    /** a package with one slice of the given rows */
    private static function slicePackage(array $rows, ?array $settings = null, array $objects = [], array $items = []): Package
    {
        return new Package('T', self::SOURCE, [new Module(Module::SLICE, self::SLICE, $rows + ['module' => [['name' => 'M']], 'slice' => [['name' => 'S']]], $settings, $objects, items: $items)], withItems: $items !== []);
    }

    private static function load(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('a', Schema::NS);
        return $xp;
    }

    /** Validates like the reader will (§ 3.1): schema as a string, no external entity may load.
     *  @return list<string> libxml errors, [] = valid */
    private static function validate(string $xml): array
    {
        $errors = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static fn() => null);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument();
            $ok  = $dom->loadXML($xml, LIBXML_NONET) && $dom->schemaValidateSource((string) file_get_contents(Schema::FILE));
            $out = array_map(static fn($e) => trim($e->message), libxml_get_errors());
            return $ok ? [] : ($out ?: ['invalid']);
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($errors);
        }
    }

    private static function xml(Package $package): string
    {
        return (new Writer())->toXml($package)->xml;
    }

    /** What the reader will do with a value element (§ 2.2); a missing column listed in meta/tables
     *  = the empty value ('' for a text) */
    private static function decode(?DOMElement $el): ?string
    {
        return $el === null ? ValueCodec::emptyValue('textValue') : ValueCodec::decode(
            $el->textContent,
            $el->getAttribute('enc') ?: ValueCodec::TEXT,
            $el->getAttribute('crlf') === 'true',
            $el->getAttribute('null') === 'true',
        );
    }

    private static function viewOdd(string $xml): ?DOMElement
    {
        $el = self::load($xml)->query('//a:view[@id="12"]/a:odd')->item(0);
        return $el instanceof DOMElement ? $el : null;
    }

    // ---------------------------------------------------------------- the design example

    public function test_example_equals_the_golden_file(): void
    {
        $xml = self::xml(self::example());
        if (getenv('AA_WRITE_GOLDEN')) {
            file_put_contents(self::GOLDEN, $xml);
            $this->markTestSkipped('golden file written');
        }
        $this->assertSame((string) file_get_contents(self::GOLDEN), $xml, 'Writer output changed - check the diff, then regenerate ' . basename(self::GOLDEN) . ' (see GOLDEN)');
    }

    public function test_example_is_valid(): void
    {
        $this->assertSame([], self::validate(self::xml(self::example())));
    }

    /** Every value of the design example (fixtures/aa-app-example.xml) and nothing else. Documented
     *  differences, not in this comparison: the example abbreviates meta/tables (the writer lists all
     *  columns of this AA), writes `<flag>0</flag>` of site_spot 2 (an empty value, § 2.2) and the
     *  default `i="0"` of web_languages / translations[0]. Formatting (blank lines, one-line alerts
     *  rows, order of properties, CDATA of texts without < & newline) differs as well - leaves are
     *  compared by path, not by position. */
    public function test_example_carries_the_values_of_the_design_example(): void
    {
        $leaves = static function (string $xml): array {
            $out = [];
            foreach (self::load($xml)->query('/a:aa-app/*[not(self::a:meta)]//*[not(*)] | /a:aa-app/a:meta/*[not(self::a:tables)]') as $el) {
                assert($el instanceof DOMElement);
                $path = '';
                for ($node = $el; $node instanceof DOMElement; $node = $node->parentNode) {
                    $attrs = [];
                    foreach ($node->attributes as $attr) {
                        $attrs[] = "$attr->name=$attr->value";
                    }
                    sort($attrs);
                    $path = $node->localName . '[' . implode(',', array_diff($attrs, ['i=0'])) . ']/' . $path;
                }
                $out[] = $path . ' => ' . var_export(self::decode($el), true);
            }
            sort($out);
            return $out;
        };
        $design = array_values(array_diff($leaves((string) file_get_contents(__DIR__ . '/fixtures/aa-app-example.xml')), [
            "aa-app[format=1.0]/site-module[id=" . self::SITE . "]/site_spot[spot_id=2]/flag[]/ => '0'",
        ]));
        $this->assertSame($design, $leaves(self::xml(self::example())));
    }

    public function test_meta_tables_list_every_carried_column_of_this_aa(): void
    {
        $schema = new Schema();
        $tables = [];
        foreach (self::load(self::xml(self::example()))->query('//a:meta/a:tables/a:table') as $table) {
            assert($table instanceof DOMElement);
            $tables[$table->getAttribute('name')] = $table->getAttribute('columns');
        }
        $this->assertSame(implode(' ', array_keys($schema->rowColumns('view'))), $tables['view']);
        $this->assertSame(implode(' ', array_keys($schema->rowColumns('constant'))), $tables['constant']);
        $this->assertArrayNotHasKey('uses', $tables, 'no column elements');
        $this->assertArrayNotHasKey('alerts_collection_filter', $tables);
    }

    // ---------------------------------------------------------------- value encoding (§ 2.2)

    /** @return array<string, array{string|null, string}> value => how it must be written */
    public static function values(): array
    {
        return [
            'plain text'              => ['Seznam článků', 'text'],
            'text with >'             => ['a > b ]]> c', 'text'],
            'markup'                  => ['<b>x</b>', 'cdata'],
            'ampersand'               => ['a &amp; b', 'cdata'],
            'newline'                 => ["line1\nline2\n", 'cdata'],
            'CDATA end'               => ["<p>]]></p>\n", 'cdata'],
            'CDATA end twice, at end' => ['<a>]]]]>]]>', 'cdata'],
            'CRLF'                    => ["<p>\r\n</p>\r\n", 'crlf'],
            'CRLF, no markup'         => ["a\r\nb", 'crlf'],
            'mixed line ends'         => ["a\r\nb\nc", 'base64'],
            'lone CR'                 => ["a\rb", 'base64'],
            'NUL byte'                => ["a\x00b", 'base64'],
            'control char'            => ["x\x1By", 'base64'],
            'U+FFFE'                  => ["x\u{FFFE}", 'base64'],
            'invalid UTF-8'           => ["P\xF8\xEDli\xB9", 'base64'],
            'tab'                     => ["a\tb", 'text'],
            'spaces only'             => ['   ', 'text'],
            'leading/trailing space'  => [" a\n ", 'cdata'],
            'zero as text'            => ['0', 'text'],
            'emoji'                   => ['🙂 ž', 'text'],
            'NULL'                    => [null, 'null'],
            'empty string'            => ['', 'omitted'],
        ];
    }

    #[DataProvider('values')]
    public function test_text_value_round_trip(?string $value, string $how): void
    {
        $xml = self::xml(self::slicePackage(['view' => [['id' => 12, 'odd' => $value]]]));
        $this->assertSame([], self::validate($xml));
        $el = self::viewOdd($xml);
        $this->assertSame($value ?? null, self::decode($el));

        $node = $el?->firstChild;
        match ($how) {
            'omitted' => $this->assertNull($el),
            'null'    => $this->assertSame('<odd null="true"/>', $el?->ownerDocument?->saveXML($el)),
            'base64'  => $this->assertSame('base64', $el?->getAttribute('enc')),
            'crlf'    => $this->assertSame(['true', XML_CDATA_SECTION_NODE], [$el?->getAttribute('crlf'), $node?->nodeType]),
            'cdata'   => $this->assertSame(XML_CDATA_SECTION_NODE, $node?->nodeType),
            'text'    => $this->assertSame([XML_TEXT_NODE, false, false], [$node?->nodeType, $el?->hasAttribute('enc'), $el?->hasAttribute('crlf')]),
        };
    }

    public function test_cdata_end_is_split(): void
    {
        $xml = self::xml(self::slicePackage(['view' => [['id' => 12, 'odd' => '<x>]]></x>']]]));
        $this->assertStringContainsString('<odd><![CDATA[<x>]]]]><![CDATA[></x>]]></odd>', $xml);
    }

    public function test_invalid_utf8_is_base64_with_a_finding(): void
    {
        $writer = new Writer();
        $result = $writer->toXml(self::slicePackage(['view' => [['id' => 12, 'odd' => "ok \xC3\x28 rest"]]]));
        $this->assertSame(base64_encode("ok \xC3\x28 rest"), self::viewOdd($result->xml)?->textContent);
        $this->assertSame(['slice-module[' . self::SLICE . ']/view[12]/odd'], array_column($result->findings, 0));
        $finding = $result->findings[0][1];
        $this->assertSame([LintFinding::WARNING, ValueCodec::INVALID_BYTES, 3], [$finding->level, $finding->code, $finding->offset]);
        $this->assertStringStartsWith("\xC3\x28", $finding->ref);

        $this->assertSame([], $writer->toXml(self::example())->findings, 'a writer keeps no state between calls');
    }

    public function test_invalid_byte_after_megabytes_of_utf8_is_found(): void
    {
        $value  = str_repeat('ž', 600_000) . "\xFF";
        $result = (new Writer())->toXml(self::slicePackage(['view' => [['id' => 12, 'odd' => $value]]]));
        $this->assertSame(['slice-module[' . self::SLICE . ']/view[12]/odd'], array_column($result->findings, 0));
        $this->assertSame(1_200_000, $result->findings[0][1]->offset);
        $this->assertSame($value, self::decode(self::viewOdd($result->xml)));
    }

    public function test_keyless_rows_have_distinct_paths(): void
    {
        $findings = (new Writer())->toXml(self::slicePackage(['profile' => [
            ['property' => 'input_view', 'value' => "\xFF1"],
            ['property' => 'input_view', 'value' => "\xFF2"],
        ]]))->findings;
        $this->assertSame([
            'slice-module[' . self::SLICE . ']/profile[#0]/value',
            'slice-module[' . self::SLICE . ']/profile[#1]/value',
        ], array_column($findings, 0));
    }

    public function test_control_chars_are_base64_without_a_finding(): void
    {
        $this->assertSame([], (new Writer())->toXml(self::slicePackage(['view' => [['id' => 12, 'odd' => "\x07"]]]))->findings);
    }

    /** @return array<string, array{string, int|string|null, string|null}> column, value, expected text (null = omitted) */
    public static function typedValues(): array
    {
        return [
            'int 0'            => ['listlen', 0, null],
            'int "0"'          => ['listlen', '0', null],
            'int ""'           => ['listlen', '', null],
            'int 10'           => ['listlen', 10, '10'],
            'int negative'     => ['listlen', '-1', '-1'],
            'int NULL'         => ['listlen', null, ''],
            'text "0"'         => ['name', '0', '0'],
            'text ""'          => ['name', '', null],
            'text 0 (int)'     => ['name', 0, '0'],
        ];
    }

    #[DataProvider('typedValues')]
    public function test_empty_value_of_the_type_is_omitted(string $column, int|string|null $value, ?string $text): void
    {
        $xml = self::xml(self::slicePackage(['view' => [['id' => 12, $column => $value]]]));
        $el  = self::load($xml)->query("//a:view/a:$column")->item(0);
        $this->assertSame($text, $el?->textContent);
        if ($value === null) {
            $this->assertSame('true', $el instanceof DOMElement ? $el->getAttribute('null') : '');
        }
    }

    public function test_object_properties_and_item_values_are_encoded_too(): void
    {
        $xml = self::xml(self::slicePackage([], ['translations' => ["a\r\nb", "\x01"]], [
            ['id' => 'cc11bb22cc33dd44ee55ff6677889900', 'class' => 'AA_Aliasfunc', 'name' => 'Alias', 'props' => ['code' => '<b>]]></b>', 'alias' => '']],
        ], [
            ['id' => 'c148d685bfdc21e9f8731037539e8b90', 'content' => ['headline........' => [['value' => "x\r\ny", 'flag' => 0]]]],
        ]));
        $this->assertSame([], self::validate($xml));
        $xp = self::load($xml);
        $el = static fn(string $q): ?DOMElement => ($n = $xp->query($q)->item(0)) instanceof DOMElement ? $n : null;
        $this->assertSame("a\r\nb", self::decode($el('//a:settings/a:p[@n="translations"][not(@i)]')));
        $this->assertSame("\x01", self::decode($el('//a:settings/a:p[@n="translations"][@i="1"]')));
        $this->assertSame('<b>]]></b>', self::decode($el('//a:object/a:p[@n="code"]')));
        $this->assertSame('<p n="alias"/>', $xp->document->saveXML($el('//a:object/a:p[@n="alias"]')), 'an empty property is kept');
        $this->assertSame('Alias', $el('//a:object')?->getAttribute('name'));
        $this->assertSame("x\r\ny", self::decode($el('//a:item/a:v')));
    }

    // ---------------------------------------------------------------- determinism (§ 2.5)

    public function test_output_is_byte_stable(): void
    {
        $this->assertSame(self::xml(self::example()), self::xml(self::example()));
    }

    /** the example with items - translations, multi-values */
    private static function exampleWithItems(): Package
    {
        $p       = self::example();
        $modules = array_map(static fn(Module $m): Module => $m->type !== Module::SLICE ? $m : new Module($m->type, $m->id, $m->rows, $m->settings, $m->objects, $m->spotTree, [
            ['id' => 'd148d685bfdc21e9f8731037539e8b90', 'content' => ['headline........' => [['value' => 'Druhý']]]],
            ['id' => 'c148d685bfdc21e9f8731037539e8b90', 'content' => [
                'headline........' => [0 => ['value' => 'První'], 1000000 => ['value' => 'cz'], 2000000 => ['value' => 'en']],
                'category.......1' => [['value' => 'zpravy'], ['value' => 'domaci', 'flag' => 64]],
            ]],
        ], $m->doc), $p->modules);
        return new Package($p->name, $p->source, $modules, $p->constantGroups, $p->description, $p->version, $p->publish, $p->requires, true, $p->doc);
    }

    public function test_output_does_not_depend_on_the_input_order(): void
    {
        $reverse = static function (array $list): array {
            return array_reverse($list, true);
        };
        $props = static function (array $list) use (&$props, $reverse): array {
            $out = [];
            foreach ($reverse($list) as $name => $values) {
                $out[$name] = is_array($values) ? array_map(static fn($v) => is_array($v) ? ['props' => $props($v['props'] ?? [])] + $reverse($v) : $v, $reverse($values)) : $values;
            }
            return $out;
        };
        $shuffle = static function (Package $p) use ($reverse, $props): Package {
            $modules = [];
            foreach (array_reverse($p->modules) as $m) {
                $rows    = array_map(static fn(array $rows): array => array_map($reverse, array_reverse($rows)), $reverse($m->rows));
                $objects = array_map(static fn(array $o): array => ['props' => $props($o['props'])] + $reverse($o), array_reverse($m->objects));
                $tree    = $m->spotTree === null ? null : ['spots' => array_reverse($m->spotTree['spots'])] + $m->spotTree;
                $items   = array_map(static fn(array $i): array => ['content' => array_map($reverse, $reverse($i['content']))] + $i, array_reverse($m->items));
                $modules[] = new Module($m->type, $m->id, $rows, $m->settings === null ? null : $props($m->settings), $objects, $tree, $items, $m->doc);
            }
            $groups = array_map(static fn(ConstantGroup $g): ConstantGroup => new ConstantGroup($g->name, $g->owner, array_map('array_reverse', $reverse($g->rows))), $p->constantGroups);
            return new Package($p->name, $reverse($p->source), $modules, $groups, $p->description, $p->version, $p->publish, $p->requires, $p->withItems, $p->doc);
        };
        $this->assertSame(self::xml(self::example()), self::xml($shuffle(self::example())));
        $this->assertSame(self::xml(self::exampleWithItems()), self::xml($shuffle(self::exampleWithItems())));
    }

    public function test_rows_are_ordered_by_key(): void
    {
        $xml = self::xml(self::slicePackage([
            'view'    => [['id' => 100], ['id' => 13], ['id' => 2]],
            'field'   => [['id' => 'text...........2'], ['id' => 'headline........'], ['id' => '_email..........']],
            'profile' => [['property' => 'ui_manager', 'selector' => ''], ['property' => 'input_view', 'selector' => 'b'], ['property' => 'input_view', 'selector' => 'a']],
        ]));
        $xp  = self::load($xml);
        $ids = static fn(string $q): array => array_map(static fn($n) => $n->nodeValue, iterator_to_array($xp->query($q)));
        $this->assertSame(['2', '13', '100'], $ids('//a:view/@id'), 'numerically');
        $this->assertSame(['_email..........', 'headline........', 'text...........2'], $ids('//a:field/@id'));
        $this->assertSame(['input_view', 'input_view', 'ui_manager'], $ids('//a:profile/a:property'));
        $this->assertSame(['input_view|a', 'input_view|b', 'ui_manager|'], array_map(
            static fn($n) => $n->textContent . '|' . ($n->nextSibling?->nextSibling?->textContent ?? ''),
            iterator_to_array($xp->query('//a:profile/a:property')),
        ));
    }

    public function test_constants_are_ordered_by_pri_then_id(): void
    {
        $xml = self::xml(new Package('T', self::SOURCE, [new Module(Module::SLICE, self::SLICE, ['module' => [[]], 'slice' => [[]]])], [
            new ConstantGroup('g', '', ['constant' => [
                ['id' => str_repeat('c', 32), 'pri' => 20],
                ['id' => str_repeat('b', 32), 'pri' => 100],
                ['id' => str_repeat('a', 32), 'pri' => 20],
            ]]),
        ]));
        $ids = array_map(static fn($n) => $n->nodeValue[0], iterator_to_array(self::load($xml)->query('//a:constant/@id')));
        $this->assertSame(['a', 'c', 'b'], $ids);
        $this->assertStringContainsString('<constant-group name="g">', $xml, 'empty owner is the default');
    }

    /** ids and texts that look like numbers (`9e00…` = 9, `100…` = 1e31) sort bytewise */
    public function test_ids_and_texts_are_ordered_bytewise(): void
    {
        $small = '9e' . str_repeat('0', 30);
        $big   = '1' . str_repeat('0', 31);
        $xml   = self::xml(new Package('T', self::SOURCE, [new Module(Module::SLICE, self::SLICE, ['module' => [[]], 'slice' => [[]], 'profile' => [
            ['property' => 'x', 'value' => '9'],
            ['property' => 'x', 'value' => '13'],
        ]])], [new ConstantGroup('g', '', ['constant' => [['id' => $small, 'pri' => 1], ['id' => $big, 'pri' => 1]]])]));
        $xp = self::load($xml);
        $this->assertSame([$big, $small], array_map(static fn($n) => $n->nodeValue, iterator_to_array($xp->query('//a:constant/@id'))));
        $this->assertSame(['13', '9'], array_map(static fn($n) => $n->nodeValue, iterator_to_array($xp->query('//a:profile/a:value'))));
    }

    // ---------------------------------------------------------------- structure

    public function test_minimal_package_is_valid(): void
    {
        $xml = self::xml(new Package('Min', ['url' => 'https://example.org/', 'aa-version' => '2026.9', 'exported' => '2026-09-24T10:00:00Z'], [
            new Module(Module::ALERTS, self::ALERTS, ['module' => [[]]]),
        ]));
        $this->assertSame([], self::validate($xml));
        $this->assertStringContainsString("<alerts-module id=\"" . self::ALERTS . "\">\n    <module/>\n  </alerts-module>", $xml);
        $this->assertStringContainsString('<contents items="false"/>', $xml);
        $this->assertStringEndsWith("  <signature/>\n</aa-app>\n", $xml);
        $this->assertStringNotContainsString("\r", $xml);
    }

    public function test_spot_tree_keeps_order_and_empty_arrays(): void
    {
        $site = new Module(Module::SITE, self::SITE, ['module' => [[]], 'site' => [[]]], spotTree: ['start_id' => '1', 'spots' => [
            '1' => ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => [], 'c' => null, 'po' => ['1', '10', '2'], 'ch' => []],
            '10' => ['id' => '10', 'n' => "a\"b\nc", 'p' => '1', 'f' => 2, 'v' => ['y' => 'y', 'x' => 'x'], 'c' => ['y' => '=:1', 'x' => 'REGEXP:^a'], 'po' => null, 'ch' => null],
            '2' => ['id' => '2', 'n' => 'b', 'p' => '1', 'f' => 0, 'v' => null, 'c' => [], 'po' => [], 'ch' => null],
        ]]);
        $xml = self::xml(new Package('T', self::SOURCE, [$site]));
        $this->assertSame([], self::validate($xml));
        $this->assertStringContainsString(<<<'XML'
                <spot-tree start="1">
                  <spot id="1" name="start" parent="1" empty="v ch">
                    <positions>1 10 2</positions>
                  </spot>
                  <spot id="2" name="b" parent="1" empty="c po"/>
                  <spot id="10" name="a&quot;b&#10;c" parent="1" flag="2">
                    <var>y</var>
                    <var>x</var>
                    <cond var="y">=:1</cond>
                    <cond var="x">REGEXP:^a</cond>
                  </spot>
                </spot-tree>
            XML, $xml);
    }

    public function test_items_keep_the_value_order(): void
    {
        $xml = self::xml(self::slicePackage([], items: [
            ['id' => 'd148d685bfdc21e9f8731037539e8b90', 'content' => ['headline........' => [['value' => 'B']]]],
            ['id' => 'c148d685bfdc21e9f8731037539e8b90', 'content' => [
                'status_code.....' => [['value' => 1, 'flag' => 0]],
                'category.......1' => [['value' => 'zpravy'], ['value' => 'domaci', 'flag' => 64]],
            ]],
        ]));
        $this->assertSame([], self::validate($xml));
        $this->assertStringContainsString(<<<'XML'
                <item id="c148d685bfdc21e9f8731037539e8b90">
                  <v f="category.......1">zpravy</v>
                  <v f="category.......1" i="1" flag="64">domaci</v>
                  <v f="status_code.....">1</v>
                </item>
                <item id="d148d685bfdc21e9f8731037539e8b90">
            XML, $xml);
        $this->assertStringContainsString('<contents items="true"/>', $xml);
    }

    /** GetItemContent() keys translations by their number (>= AA_Value::MAX_INDEX, include/util.php:1071) */
    public function test_item_translations_keep_their_number(): void
    {
        $xml = self::xml(self::slicePackage([], items: [['id' => 'c148d685bfdc21e9f8731037539e8b90', 'content' => [
            'headline........' => [2000000 => ['value' => 'en'], 1000000 => ['value' => 'cz'], 0 => ['value' => 'base']],
        ]]]));
        $this->assertSame([], self::validate($xml));
        $this->assertStringContainsString(<<<'XML'
                  <v f="headline........">base</v>
                  <v f="headline........" i="1000000">cz</v>
                  <v f="headline........" i="2000000">en</v>
            XML, $xml);
    }

    /** sitetree stores `false` for a missing array (addInSequence() passes $flag to positions) */
    public function test_false_in_the_spot_tree_counts_as_null(): void
    {
        $site = new Module(Module::SITE, self::SITE, ['module' => [[]], 'site' => [[]]], spotTree: ['start_id' => '1', 'spots' => [
            ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => false, 'c' => false, 'po' => false, 'ch' => false],
        ]]);
        $this->assertStringContainsString("<spot-tree start=\"1\">\n      <spot id=\"1\" name=\"start\" parent=\"1\"/>\n", self::xml(new Package('T', self::SOURCE, [$site])));
    }

    /** @return array<string, array{Package, string}> */
    public static function invalidModels(): array
    {
        $slice  = static fn(array $rows): Package => self::slicePackage($rows);
        $alerts = new Module(Module::ALERTS, self::ALERTS, ['module' => [[]]]);
        $site   = static fn(array $spot): Package => new Package('T', self::SOURCE, [new Module(Module::SITE, self::SITE, ['module' => [[]], 'site' => [[]]], spotTree: [
            'start_id' => '1', 'spots' => [$spot + ['id' => '1', 'n' => 'start', 'p' => '1']],
        ])]);
        return [
            'no module'                   => [new Package('T', self::SOURCE, []), 'site-module'],
            'column not carried'          => [$slice(['view' => [['id' => 1, 'slice_id' => self::SLICE]]]), 'view.slice_id'],
            'installation column'         => [$slice(['slice' => [['name' => 'S', 'reading_password' => 'x']]]), 'slice.reading_password'],
            'table of another module type' => [$slice(['site_spot' => [['spot_id' => 1]]]), 'site_spot'],
            'unknown table'               => [$slice(['discussion' => [[]]]), 'discussion'],
            'items without contents'      => [new Package('T', self::SOURCE, [new Module(Module::SLICE, self::SLICE, ['module' => [[]], 'slice' => [[]]], items: [['id' => self::SLICE, 'content' => []]])]), 'items'],
            'bad int'                     => [$slice(['view' => [['id' => 1, 'listlen' => 'ten']]]), 'listlen'],
            'duplicate view id'           => [$slice(['view' => [['id' => 1], ['id' => 1]]]), 'uniqueView'],
            'control char in meta'        => [new Package("T\x01", self::SOURCE, [new Module(Module::ALERTS, self::ALERTS, ['module' => [[]]])]), 'meta/name'],
            'unknown module type'         => [new Package('T', self::SOURCE, [new Module('poll-module', self::ALERTS, [])]), 'poll-module'],
            'XSD error with its line'     => [$slice(['view' => [['id' => 1, 'listlen' => 'ten']]]), 'line '],
            'array as a row value'        => [$slice(['view' => [['id' => 1, 'name' => ['x']]]]), 'column name must be a scalar'],
            'spot variable key != name'   => [$site(['v' => ['x' => 'y']]), "variable key 'x' differs"],
            'spot positions not an array' => [$site(['po' => '1 2']), 'po must be an array'],
            'spot positions nested'       => [$site(['po' => [['1']]]), 'po must be an array of scalars'],
            'item value NULL'             => [self::slicePackage([], items: [['id' => self::SLICE, 'content' => ['headline........' => [['value' => null]]]]]), 'cannot be NULL'],
            'property NULL'               => [self::slicePackage([], ['x' => [null]]), 'must be a scalar'],
            'source without url'          => [new Package('T', ['url' => ''] + self::SOURCE, [$alerts]), 'url is required'],
            'source without aa-version'   => [new Package('T', array_diff_key(self::SOURCE, ['aa-version' => 1]), [$alerts]), 'aa-version is required'],
            'unknown source attribute'    => [new Package('T', self::SOURCE + ['token' => 'x'], [$alerts]), 'token'],
        ];
    }

    #[DataProvider('invalidModels')]
    public function test_invalid_model_throws(Package $package, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        self::xml($package);
    }
}
