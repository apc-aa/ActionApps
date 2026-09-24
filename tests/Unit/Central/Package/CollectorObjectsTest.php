<?php

declare(strict_types=1);

/**
 * Collector, C4.3 part 4 (design balicek-v1.md § 5.4, § 6.3): the objects a module owns - module
 * settings, AA_Aliasfunc, AA_Set with its conditions and sort orders - and the site tree, over a
 * FakeSource of object_text/integer/float rows as AA_Object::save() writes them. The package then goes
 * through Writer (XSD-validated).
 */

namespace {
    // AA_Module_Site / REL_FLAG_MODULE_DEPEND pull include/constants.php; getClassProperties() of the
    // object classes and string2id() need include/util.php, whose chain reaches include/locsess.php
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
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

namespace Tests\Unit\Central\Package {

    use AA\Central\Package\CollectOptions;
    use AA\Central\Package\CollectResult;
    use AA\Central\Package\Collector;
    use AA\Central\Package\LintFinding;
    use AA\Central\Package\Model\ConstantGroup;
    use AA\Central\Package\Model\Module;
    use AA\Central\Package\Model\RawBytes;
    use AA\Central\Package\ObjectExport;
    use AA\Central\Package\ReferenceLint;
    use AA\Central\Package\Schema;
    use AA\Central\Package\SecurityLint;
    use AA\Central\Package\ValueCodec;
    use AA\Central\Package\Writer;
    use AA_Module_Site;
    use DOMDocument;
    use DOMXPath;
    use PHPUnit\Framework\TestCase;
    use sitetree;
    use Tests\Unit\Central\Package\Fixtures\FakeSource;

    require_once __DIR__ . '/fixtures/FakeSource.php';

    final class CollectorObjectsTest extends TestCase
    {
        private const SITE    = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const SLICE_A = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';   // in "uses"
        private const SLICE_B = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';   // outside the app
        private const SITE_2  = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';   // another site - outside
        private const SLICE_W = 'f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6';   // windows-1250

        private const ALIAS  = '11111111111111111111111111111111';
        private const SET    = '22222222222222222222222222222222';
        private const COND1  = '33333333333333333333333333333331';
        private const COND2  = '33333333333333333333333333333332';
        private const SORT1  = '44444444444444444444444444444441';
        private const GONE   = '55555555555555555555555555555555';   // a condition AA deleted
        private const TASK   = '66666666666666666666666666666666';
        private const NOTE   = '77777777777777777777777777777777';
        private const FORM   = '88888888888888888888888888888888';
        private const OLD    = '99999999999999999999999999999999';   // a settings object of an old id
        private const ALIAS2 = 'abababababababababababababababab';
        private const TASK2  = '66666666666666666666666666666667';
        private const DATOVKA = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

        private const TOKEN = 'secret-api-token-4711';

        private const SOURCE = ['url' => 'https://example.org/aa/', 'aa-version' => '2026.9', 'exported' => '2026-09-24T10:00:00Z'];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../../include/util.php';
            require_once __DIR__ . '/../../../../modules/site/sitetree.php';
            class_exists(AA_Module_Site::class);
        }

        /** the rows AA_Object::save() writes: object_text for strings, object_integer for bool/int
         *  properties (AA_Property::storageType()); a list = a multi-value property, priority = its key
         *  @param array<string, mixed> $props @param list<string> $subobjects
         *  @return array{list<array<string, mixed>>, list<array<string, mixed>>} text rows, integer rows */
        private static function object(string $id, string $class, string $owner, array $props, array $subobjects = [], ?string $name = null, array $ints = [], array $floats = []): array
        {
            $text = [
                ['object_id' => $id, 'property' => 'aa_type', 'priority' => 0, 'value' => $class],
                ['object_id' => $id, 'property' => 'aa_owner', 'priority' => 0, 'value' => $owner],
            ];
            if ($name !== null) {
                $text[] = ['object_id' => $id, 'property' => 'aa_name', 'priority' => 0, 'value' => $name];
            }
            foreach ([...$props, 'aa_subobjects' => $subobjects] as $property => $values) {
                foreach (is_array($values) ? $values : [$values] as $priority => $value) {
                    $text[] = ['object_id' => $id, 'property' => $property, 'priority' => $priority, 'value' => $value];
                }
            }
            $int = $float = [];
            foreach ($ints as $property => $value) {
                $int[] = ['object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value];
            }
            foreach ($floats as $property => $value) {
                $float[] = ['object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value];
            }
            return [$text, $int, $float];
        }

        /** a site as the editor builds it: start, header, content branching on {x} into an option */
        private static function tree(): sitetree
        {
            $tree = unserialize('O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}', ['allowed_classes' => ['sitetree', 'spot']]);
            $tree->addInSequence(1, 'header');
            $tree->addInSequence(1, 'content');
            $tree->set('variables', 3, ['x' => 'x']);
            $tree->set('choices', 3, []);
            $tree->addChoice(3, 'article');
            $tree->set('conditions', 4, ['x' => '=:=:clanek']);
            return $tree;
        }

        private static function source(): FakeSource
        {
            $module = static fn(string $id, string $type, string $lang): array => ['id' => $id, 'type' => $type, 'name' => "Module $type", 'lang_file' => $lang, 'flag' => 0, 'deleted' => 0];
            $slice  = static fn(string $id, string $lang): array => ['id' => $id, 'name' => 'S', 'type' => 'S', 'vid' => 0, 'lang_file' => $lang];
            $objects = [
                // module settings: the derived id; ga_id and alternate_url are the source installation's
                self::object(string2id('AA_Modulesettings_Site' . self::SITE), 'AA_Modulesettings_Site', self::SITE, [
                    'web_languages'   => ['cz', 'en'],
                    'translate_slice' => self::SLICE_A,
                    'add_aliases'     => [self::SITE_2],
                    'page404'         => '2',
                    'page404_code'    => "<h1>Not found</h1>\r\n{view:20}",
                    'ga_id'           => 'UA-12345-1',
                    'alternate_url'   => ['www.source.example'],
                    'app_publish'     => 'all',     // R5 - meta carries it
                    'spamprotect'     => 'x',       // no property of the class (any more): AA_Object::load() ignores it
                ]),
                self::object(string2id('AA_Modulesettings_Slice' . self::SLICE_A), 'AA_Modulesettings_Slice', self::SLICE_A, [
                    'translations' => ['cz', 'en'],
                    'fieldslice'   => self::SLICE_B,
                    'historylog'   => null,          // NULL of object_text.value
                    'autofields'   => '0',           // also in object_integer - load() takes the later table
                ], ints: ['autofields' => 1], floats: ['historylog' => 9.5]),   // no float property: not read
                self::object(self::OLD, 'AA_Modulesettings_Slice', self::SLICE_A, ['translations' => ['de']]),
                self::object(self::ALIAS, 'AA_Aliasfunc', self::SITE, [
                    'alias'  => 'Hlavicka',
                    'code'   => '<header><h1>_#P1</h1>{constant:ct_alias:x:name}{form:' . self::FORM . '}</header>',
                    'desc'   => 'Page header',
                    'ussage' => ['{_:Hlavicka:Title}'],
                ], name: 'Hlavicka'),
                // conds stored out of order (priority decides), one of them deleted by AA
                self::object(self::SET, 'AA_Set', self::SLICE_A, [
                    'slices' => [self::SLICE_A],
                    // a deleted condition, an object of another class, a serialized state
                    'conds'  => [1 => self::COND2, 0 => self::COND1, 2 => self::GONE, 3 => self::TASK, 4 => 'O:12:"AA_Condition":0:{}'],
                    'sort'   => [self::SORT1],
                ], [self::COND1, self::COND2, self::SORT1]),
                self::object(self::COND1, 'AA_Condition', self::SLICE_A, ['fields' => ['category.......1'], 'operator' => '=', 'value' => 'zpravy']),
                self::object(self::COND2, 'AA_Condition', self::SLICE_A, ['fields' => ['headline........', 'abstract........'], 'operator' => 'LIKE', 'value' => '{ids:' . self::SLICE_B . '}']),
                // owned by another module - AA_Object::load() reads a subobject by its id alone
                self::object(self::SORT1, 'AA_Sortorder', self::SLICE_B, ['field' => 'publish_date....', 'desc' => '1']),
                // never carried: code on a schedule, tokens, forms
                self::object(self::TASK, 'AA_Plannedtask', self::SLICE_A, ['code' => '{mail:all}']),
                self::object(self::TASK2, 'AA_Plannedtask', self::SLICE_A, ['code' => '{mail:none}']),
                self::object(self::DATOVKA, 'Datovka', self::SLICE_A, ['password' => self::TOKEN]),
                self::object(self::NOTE, 'AA\Util\Note', self::SITE, ['text' => self::TOKEN]),
                self::object(self::FORM, 'AA_Form', self::SLICE_A, ['rows' => 'a:0:{}']),
                self::object(self::ALIAS2, 'AA_Aliasfunc', self::SLICE_W, ['alias' => 'Skola', 'code' => "\x8Akola", 'desc' => "a\x81b"]),
                self::object(string2id('AA_Modulesettings_Slice' . self::SLICE_W), 'AA_Modulesettings_Slice', self::SLICE_W, ['translations' => ['cz']]),
            ];
            return new FakeSource([
                'module'         => [
                    $module(self::SITE, 'W', 'cz-utf8_site_lang.php'),
                    $module(self::SLICE_A, 'S', 'cz-utf8_news_lang.php'),
                    $module(self::SLICE_B, 'S', 'cz-utf8_news_lang.php'),
                    $module(self::SITE_2, 'W', 'cz-utf8_site_lang.php'),
                    $module(self::SLICE_W, 'S', 'cz_news_lang.php'),
                ],
                'site'           => [['id' => self::SITE, 'state_file' => '/cz/home', 'structure' => serialize(self::tree()), 'flag' => 1]],
                'site_spot'      => [['id' => 1, 'site_id' => self::SITE, 'spot_id' => 1, 'content' => '<html></html>', 'flag' => 0]],
                'relation'       => [['source_id' => self::SITE, 'destination_id' => self::SLICE_A, 'flag' => 1]],
                'slice'          => [$slice(self::SLICE_A, 'cz-utf8_news_lang.php'), $slice(self::SLICE_B, 'cz-utf8_news_lang.php'), $slice(self::SLICE_W, 'cz_news_lang.php')],
                'view'           => [['id' => 20, 'slice_id' => self::SLICE_A, 'name' => 'List', 'type' => 'digest'], ['id' => 2, 'slice_id' => self::SLICE_A, 'name' => 'Two', 'type' => 'full']],
                'constant'       => [['id' => 'aa11bb22cc33dd44ee55ff6677889900', 'group_id' => 'ct_alias', 'name' => 'A', 'value' => 'a', 'pri' => 100]],
                'object_text'    => array_merge(...array_column($objects, 0)),
                'object_integer' => array_merge(...array_column($objects, 1)),
                'object_float'   => array_merge(...array_column($objects, 2)),
            ]);
        }

        private static function site(?FakeSource $source = null): CollectResult
        {
            return (new Collector($source ?? self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE));
        }

        private static function moduleOf(CollectResult $result, string $id): Module
        {
            foreach ($result->package->modules as $module) {
                if ($module->id === $id) {
                    return $module;
                }
            }
            self::fail("no module $id");
        }

        /** @return list<string> "level code ref @ path" */
        private static function findings(CollectResult $result): array
        {
            return array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} {$f[1]->ref} @ $f[0]", $result->findings);
        }

        private static function xpath(CollectResult $result): DOMXPath
        {
            $dom = new DOMDocument();
            self::assertTrue($dom->loadXML((new Writer())->toXml($result->package)->xml));
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('a', Schema::NS);
            return $xpath;
        }

        // --- module settings -------------------------------------------------------------------------

        public function test_site_settings_without_the_properties_of_the_source_installation(): void
        {
            $settings = self::moduleOf(self::site(), self::SITE)->settings;

            $this->assertSame([
                'translate_slice' => self::SLICE_A,
                'add_aliases'     => [self::SITE_2],
                'web_languages'   => ['cz', 'en'],
                'page404'         => '2',
                'page404_code'    => "<h1>Not found</h1>\r\n{view:20}",
            ], $settings);
        }

        public function test_slice_settings_from_all_object_tables(): void
        {
            $settings = self::moduleOf(self::site(), self::SLICE_A)->settings;

            // autofields lives in object_integer; a NULL value is '' (Model\Module: properties are never NULL)
            $this->assertSame(['translations' => ['cz', 'en'], 'autofields' => 1, 'fieldslice' => self::SLICE_B, 'historylog' => ''], $settings);
        }

        public function test_a_module_without_settings_has_none(): void
        {
            $source = self::source();
            $source->tables['object_text'] = array_values(array_filter($source->tables['object_text'], static fn(array $r): bool => $r['object_id'] !== string2id('AA_Modulesettings_Site' . self::SITE)));

            $this->assertNull(self::moduleOf(self::site($source), self::SITE)->settings);
        }

        // --- objects ---------------------------------------------------------------------------------

        public function test_an_alias_of_the_site(): void
        {
            $objects = self::moduleOf(self::site(), self::SITE)->objects;

            $this->assertSame([[
                'id'    => self::ALIAS,
                'class' => 'AA_Aliasfunc',
                'name'  => 'Hlavicka',
                'props' => [
                    'alias'  => 'Hlavicka',
                    'code'   => '<header><h1>_#P1</h1>{constant:ct_alias:x:name}{form:' . self::FORM . '}</header>',
                    'desc'   => 'Page header',
                    'ussage' => ['{_:Hlavicka:Title}'],
                ],
            ]], $objects);
        }

        public function test_a_set_carries_its_conditions_and_sort_orders_nested(): void
        {
            $objects = self::moduleOf(self::site(), self::SLICE_A)->objects;

            $this->assertSame([[
                'id'    => self::SET,
                'class' => 'AA_Set',
                'props' => [
                    'conds'  => [
                        ['id' => self::COND1, 'class' => 'AA_Condition', 'props' => ['fields' => ['category.......1'], 'operator' => '=', 'value' => 'zpravy']],
                        ['id' => self::COND2, 'class' => 'AA_Condition', 'props' => ['fields' => ['headline........', 'abstract........'], 'operator' => 'LIKE', 'value' => '{ids:' . self::SLICE_B . '}']],
                    ],
                    'sort'   => [['id' => self::SORT1, 'class' => 'AA_Sortorder', 'props' => ['field' => 'publish_date....', 'desc' => '1']]],
                    'slices' => [self::SLICE_A],
                ],
            ]], $objects);
        }

        public function test_objects_outside_the_allowlist_are_left_out_with_their_class_only(): void
        {
            $result   = self::site();
            $findings = self::findings($result);
            $slice    = 'slice-module[' . self::SLICE_A . ']';

            // one finding per class, with the count; a settings object AA does not read (not the derived id) too
            $this->assertEqualsCanonicalizing([
                "I object-dropped AA_Form (1) @ $slice/object",
                "I object-dropped AA_Modulesettings_Slice (1) @ $slice/object",
                "I object-dropped AA_Plannedtask (2) @ $slice/object",
                "I object-dropped Datovka (1) @ $slice/object",
                'I object-dropped AA\Util\Note (1) @ site-module[' . self::SITE . ']/object',
            ], array_values(array_filter($findings, static fn(string $f): bool => str_contains($f, ObjectExport::OBJECT_DROPPED))));
            $this->assertStringNotContainsString(self::TOKEN, serialize($result));
            // the set loses a deleted condition (AA_Object::load() would give an empty one), an object of
            // another class and a serialized state - the finding names the id, never the value
            $conds = "$slice/object[" . self::SET . ']/conds[2]';
            $this->assertEqualsCanonicalizing(['W subobject ' . self::GONE . " @ $conds", 'W subobject ' . self::TASK . " @ $conds", "W subobject  @ $conds"], array_values(array_filter($findings, static fn(string $f): bool => str_contains($f, ObjectExport::SUBOBJECT))));
        }

        public function test_disallowed_objects_are_never_read(): void
        {
            $source = self::source();
            self::site($source);

            $read = [];
            foreach ($source->log as [$call, $args]) {
                if (($call === 'objectRows') && ($args[1] !== []) && !array_diff($args[1], ['aa_type', 'aa_subobjects'])) {
                    continue;       // the class, nothing else
                }
                array_push($read, ...($call === 'objectRows') ? $args[0] : []);
            }
            // the settings, the alias / set, their subobjects of the right class - one owned elsewhere too
            $this->assertNotContains(self::DATOVKA, $read);
            $this->assertNotContains(self::TASK, $read);        // referenced by the set as a condition
            $this->assertContains(self::SORT1, $read);
        }

        public function test_the_settings_are_read_by_their_derived_id(): void
        {
            $source = self::source();
            foreach ($source->tables['object_text'] as $i => $row) {
                if (($row['object_id'] === string2id('AA_Modulesettings_Site' . self::SITE)) && ($row['property'] === 'aa_owner')) {
                    $source->tables['object_text'][$i]['value'] = self::SITE_2;     // AA_Module::_getModuleObjectProperty() does not care
                }
            }

            $this->assertSame(['cz', 'en'], self::moduleOf(self::site($source), self::SITE)->settings['web_languages']);
        }

        /** A new property of the settings classes may hold a secret or a state of the installation (as
         *  ga_id, alternate_url): it fails here until someone decides whether it travels (ObjectExport) */
        public function test_the_properties_of_the_settings_classes_are_reviewed(): void
        {
            $this->assertSame(
                ['translate_slice', 'add_aliases', 'web_languages', 'page404', 'page404_code', 'sitemap_alias', 'perm_alias', 'loginpage_code', 'perm_mode', 'ga_id', 'alternate_url'],
                array_keys(\AA_Modulesettings_Site::getClassProperties()),
            );
            $this->assertSame(
                ['translations', 'autofields', 'fieldslice', 'historylog', 'nocheck_pending', 'nocheck_expired'],
                array_keys(\AA_Modulesettings_Slice::getClassProperties()),
            );
        }

        public function test_object_texts_are_linted_and_their_module_ids_closed_over(): void
        {
            $result   = self::site();
            $findings = self::findings($result);
            $site     = 'site-module[' . self::SITE . ']';
            $slice    = 'slice-module[' . self::SLICE_A . ']';

            $this->assertContains('W ' . ReferenceLint::FORM . " {form: @ $site/object[" . self::ALIAS . ']/code[0]', $findings);
            $this->assertContains('W ' . ReferenceLint::MODULE_OUTSIDE . ' ' . self::SITE_2 . " @ $site/settings/add_aliases[0]", $findings);
            $this->assertContains('W ' . ReferenceLint::MODULE_OUTSIDE . ' ' . self::SLICE_B . " @ $slice/settings/fieldslice[0]", $findings);
            $this->assertContains('W ' . ReferenceLint::MODULE_OUTSIDE . ' ' . self::SLICE_B . " @ $slice/object[" . self::SET . ']/conds[1]/value[0]', $findings);
            $this->assertEqualsCanonicalizing([self::SITE_2, self::SLICE_B], array_keys($result->outside));
            $this->assertSame(["$site/settings/add_aliases[0]"], $result->outside[self::SITE_2]);
            $this->assertEqualsCanonicalizing(["$slice/settings/fieldslice[0]", "$slice/object[" . self::SET . ']/conds[1]/value[0]'], $result->outside[self::SLICE_B]);
            // in the app: translate_slice, AA_Set.slices; view 20 of slice A
            $this->assertSame([], array_filter($findings, static fn(string $f): bool => str_contains($f, 'translate_slice') || str_contains($f, '/slices[') || str_contains($f, 'view-outside')));
            // only properties that are code are linted - page404 = 2 is an enum value, not view 2
            $this->assertSame([], array_filter($findings, static fn(string $f): bool => str_contains($f, '/page404[')));
        }

        public function test_a_group_named_in_an_alias_travels(): void
        {
            $groups = array_map(static fn(ConstantGroup $g): string => $g->name, self::site()->package->constantGroups);

            $this->assertSame(['ct_alias'], $groups);
        }

        public function test_object_texts_are_converted_from_the_module_charset(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_W], new CollectOptions(self::SOURCE));
            $alias  = self::moduleOf($result, self::SLICE_W)->objects[0];

            $this->assertSame('Škola', $alias['props']['code']);
            $this->assertEquals(new RawBytes("a\x81b"), $alias['props']['desc']);
            $this->assertContains('W ' . ValueCodec::INVALID_BYTES . " \x81b @ slice-module[" . self::SLICE_W . ']/object[' . self::ALIAS2 . ']/desc[0]', self::findings($result));
            $this->assertSame('base64', self::xpath($result)->query('//a:object/a:p[@n="desc"]/@enc')->item(0)?->nodeValue);
        }

        // --- the site tree ---------------------------------------------------------------------------

        public function test_the_site_tree_in_canonical_form(): void
        {
            $tree = self::moduleOf(self::site(), self::SITE)->spotTree;

            $this->assertSame(self::tree()->toArray(), $tree);
            $this->assertSame(['4'], $tree['spots'][2]['ch']);      // spot 3 branches into the option 4,
            $this->assertSame(['4'], $tree['spots'][3]['po']);      // which has its own id in positions
        }

        /** a condition variable in braces is expanded (spot::conditionMatches(), AA::Un()) - code of the app */
        public function test_spot_conditions_are_linted(): void
        {
            $tree = self::tree();
            $tree->set('conditions', 4, ['x' => '=:=:clanek', '{ids:' . self::SLICE_B . ':d-x}' => '=:=:1', 'y' => 'vid={x}']);
            $source                                 = self::source();
            $source->tables['site'][0]['structure'] = serialize($tree);
            $result                                 = self::site($source);
            $spot                                   = 'site-module[' . self::SITE . ']/spot-tree/spot[4]';

            $this->assertContains('W ' . ReferenceLint::MODULE_OUTSIDE . ' ' . self::SLICE_B . " @ $spot/cond[#1]@var", self::findings($result));
            $this->assertContains('W ' . ReferenceLint::COMPUTED . " vid={ @ $spot/cond[#2]", self::findings($result));
            $this->assertContains("$spot/cond[#1]@var", $result->outside[self::SLICE_B]);
            $this->assertSame([], array_filter(self::findings($result), static fn(string $f): bool => str_contains($f, "$spot/cond[#0]")));
        }

        /** the site tree admin prints spot names, variables and conditions unescaped - as the import (SecurityLint) */
        public function test_markup_in_spots_is_reported(): void
        {
            $tree = self::tree();
            $tree->set('name', 2, '<b>Home</b>');
            $tree->set('conditions', 4, ['x' => '{if:{x}:<:3:a:b}']);
            $source                                 = self::source();
            $source->tables['site'][0]['structure'] = serialize($tree);
            $spots = array_values(array_filter(self::findings(self::site($source)), static fn(string $f): bool => str_contains($f, SecurityLint::SPOT_MARKUP)));

            $this->assertSame(['W ' . SecurityLint::SPOT_MARKUP . ' <b>Home</b> @ site-module[' . self::SITE . ']/spot-tree/spot[2]@name'], $spots);
        }

        /** rows of removed spots (the editor keeps them unless it cleans the tree): the import could give a
         *  new spot their id (new_id()) and so their content - unless {sitespot:N} still includes them */
        public function test_spots_outside_the_tree_are_left_out(): void
        {
            $source = self::source();
            $row    = static fn(int $spot_id, string $content): array => ['id' => 100 + $spot_id, 'site_id' => self::SITE, 'spot_id' => $spot_id, 'content' => $content, 'flag' => 0];
            $source->tables['site_spot'] = [$row(1, '<html>{sitespot:9}</html>'), $row(2, 'header'), $row(4, 'article'), $row(7, 'removed'), $row(8, 'removed too'), $row(9, 'included')];
            $result = self::site($source);

            $this->assertSame([1, 2, 4, 9], array_column(self::moduleOf($result, self::SITE)->rows['site_spot'], 'spot_id'));
            $this->assertContains('I ' . Collector::SPOT_UNUSED . ' 2 @ site-module[' . self::SITE . ']/site_spot', self::findings($result));
        }

        public function test_no_structure_is_no_tree(): void
        {
            $source                                 = self::source();
            $source->tables['site'][0]['structure'] = '';     // AA builds the tree from spot 1 - and so will the import
            $result                                 = self::site($source);

            $this->assertNull(self::moduleOf($result, self::SITE)->spotTree);
            $this->assertSame([], array_filter(self::findings($result), static fn(string $f): bool => str_contains($f, Collector::SPOT_TREE)));
        }

        public function test_a_structure_that_is_no_tree_fails_the_export(): void
        {
            foreach (['O:8:"stdClass":0:{}', 'a:0:{}', 'garbage', 'O:8:"sitetree":0:{}'] as $structure) {
                $source                                 = self::source();
                $source->tables['site'][0]['structure'] = $structure;
                $result                                 = self::site($source);

                $this->assertNull(self::moduleOf($result, self::SITE)->spotTree, $structure);
                $this->assertContains('E ' . Collector::SPOT_TREE . ' structure @ site-module[' . self::SITE . ']/spot-tree', self::findings($result), $structure);
            }
        }

        public function test_a_spot_text_the_package_cannot_carry_is_reported_as_hex(): void
        {
            $tree = self::tree();
            $tree->set('name', 2, "a\x01b");
            $source                                 = self::source();
            $source->tables['site'][0]['structure'] = serialize($tree);
            $result                                 = self::site($source);

            $this->assertNull(self::moduleOf($result, self::SITE)->spotTree);
            $this->assertContains('E ' . Collector::SPOT_TREE . ' 610162 @ site-module[' . self::SITE . ']/spot-tree', self::findings($result));
        }

        public function test_spot_names_are_converted_from_the_site_charset(): void
        {
            $tree = self::tree();
            $tree->set('name', 2, "Z\xE1hlav\xED");
            $source                                 = self::source();
            $source->tables['module'][0]['lang_file'] = 'cz_site_lang.php';
            $source->tables['site'][0]['structure'] = serialize($tree);

            $this->assertSame('Záhlaví', self::moduleOf(self::site($source), self::SITE)->spotTree['spots'][1]['n']);
        }

        // --- the package -----------------------------------------------------------------------------

        public function test_the_package_with_objects_and_tree_is_valid(): void
        {
            $xpath = self::xpath(self::site());

            $this->assertSame('AA_Modulesettings_Site', $xpath->query('//a:site-module/a:settings/@class')->item(0)?->nodeValue);
            $this->assertSame('AA_Modulesettings_Slice', $xpath->query('//a:slice-module/a:settings/@class')->item(0)?->nodeValue);
            $this->assertSame(0, $xpath->query('//a:p[@n="ga_id" or @n="alternate_url" or @n="app_publish"]')->length);
            $this->assertSame(['conds', 'conds', 'sort'], array_map(static fn($n): string => $n->nodeValue, iterator_to_array($xpath->query('//a:object[@class="AA_Set"]/a:object/@n'))));
            $this->assertSame('1', $xpath->query('//a:object[@id="' . self::COND2 . '"]/@i')->item(0)?->nodeValue);
            $this->assertSame(4, $xpath->query('//a:spot-tree/a:spot')->length);
            $this->assertSame('4', $xpath->query('//a:spot[@id="4"]/a:positions')->item(0)?->nodeValue);
            $this->assertSame(2, $xpath->query('//a:object[not(parent::a:object)]')->length);   // the alias and the set
        }
    }
}
