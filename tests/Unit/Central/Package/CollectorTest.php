<?php

declare(strict_types=1);

namespace {
    // AA_Module_Site::stateFileName() and REL_FLAG_MODULE_DEPEND pull include/constants.php, which needs these
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
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
    use AA\Central\Package\ExportLint;
    use AA\Central\Package\LintFinding;
    use AA\Central\Package\Model\ConstantGroup;
    use AA\Central\Package\Model\Module;
    use AA\Central\Package\Model\RawBytes;
    use AA\Central\Package\ReferenceLint;
    use AA\Central\Package\Schema;
    use AA\Central\Package\SecurityLint;
    use AA\Central\Package\ValueCodec;
    use AA\Central\Package\Writer;
    use AA_Module_Site;
    use DOMDocument;
    use DOMXPath;
    use InvalidArgumentException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\FakeSource;

    require_once __DIR__ . '/fixtures/FakeSource.php';

    final class CollectorTest extends TestCase
    {
        private const SITE    = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const SLICE_A = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';   // in "uses"
        private const SLICE_B = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';   // only referenced from a spot
        private const ALERTS  = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';
        private const POLL    = 'e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5';
        private const SLICE_W = 'f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6';   // windows-1250
        private const SLICE_J = 'a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7';   // EUC-JP
        private const DELETED = 'a8a8a8a8a8a8a8a8a8a8a8a8a8a8a8a8';
        private const GONE    = '0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f';   // no such module
        private const ITEM    = '0123456789abcdef0123456789abcdef';
        private const ITEM2   = '1123456789abcdef0123456789abcdef';   // in the trash
        private const C1      = 'aa11bb22cc33dd44ee55ff6677889900';
        private const C2      = 'bb11bb22cc33dd44ee55ff6677889900';

        private const SOURCE = ['url' => 'https://example.org/aa/', 'aa-version' => '2026.9', 'exported' => '2026-09-24T10:00:00Z'];

        private const SPOT = 'site-module[' . self::SITE . ']/site_spot[1]/content';

        /** site.structure as the stock Site Template has it (src/Optimize/RedefineSiteTemplates.php), with spot 2 */
        private const TREE = 'O:8:"sitetree":2:{s:4:"tree";a:2:{'
            . 'i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:2:{i:0;s:1:"1";i:1;s:1:"2";}s:2:"ch";N;s:1:"f";i:0;}'
            . 'i:2;O:4:"spot":8:{s:2:"id";s:1:"2";s:1:"n";s:4:"text";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";N;s:2:"ch";N;s:1:"f";i:0;}'
            . '}s:8:"start_id";s:1:"1";}';

        public static function setUpBeforeClass(): void
        {
            class_exists(AA_Module_Site::class);   // include/slice.class.php brings include/constants.php
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../../include/util.php';      // string2id() for ObjectExport
        }

        private static function module(string $id, string $type, string $name, string $lang_file, int $deleted = 0): array
        {
            return ['id' => $id, 'type' => $type, 'name' => $name, 'lang_file' => $lang_file, 'flag' => 0, 'deleted' => $deleted,
                'owner' => '99999999999999999999999999999999', 'created_by' => 'uid-17', 'created_at' => 1700000000,
                'slice_url' => 'https://source.example/x.shtml', 'priority' => 5, 'app_id' => null];
        }

        private static function slice(string $id, string $name, array $more = []): array
        {
            return $more + ['id' => $id, 'name' => $name, 'type' => 'S', 'vid' => 0, 'mlxctrl' => null, 'lang_file' => 'cz-utf8_news_lang.php',
                'owner' => '99999999999999999999999999999999', 'created_by' => 'uid-17', 'created_at' => 1700000000,
                'slice_url' => 'https://source.example/', 'deleted' => 0, 'fileman_dir' => 'files', 'reading_password' => 'secret',
                'export_to_all' => 1, 'auth_field_group' => '', 'mailman_field_lists' => '', 'fulltext_format' => '<h1>_#HEADLINE</h1>'];
        }

        private static function field(string $slice, string $id, string $isf, string $name = 'Field', string $insert = ''): array
        {
            return ['id' => $id, 'slice_id' => $slice, 'type' => rtrim($id, '.0123456789'), 'name' => $name, 'input_pri' => 100,
                'input_show_func' => $isf, 'input_insert_func' => $insert];
        }

        private static function constant(string $id, string $group, string $name, int $pri, string $ancestors = ''): array
        {
            return ['id' => $id, 'group_id' => $group, 'name' => $name, 'value' => strtolower($name), 'class' => '', 'pri' => $pri,
                'ancestors' => $ancestors, 'description' => '', 'short_id' => $pri];
        }

        private static function group(string $group, string $slice): array
        {
            return ['group_id' => $group, 'slice_id' => $slice, 'propagate' => 1, 'levelcount' => 1, 'horizontal' => 0, 'hidevalue' => 0, 'hierarch' => 0];
        }

        /** a value list of GetItemContent() */
        private static function v(mixed ...$values): array
        {
            return array_map(static fn($value): array => ['value' => $value, 'flag' => 0], $values);
        }

        /** an item as GetItemContent() returns it - the item table columns as fields, the pseudo fields */
        private static function item(string $id, string $slice, string $status, array $content): array
        {
            return ['id' => $id, 'content' => $content + [
                'u_slice_id......' => [['value' => $slice]],
                'unpacked_id.....' => [['value' => $id]],
                'id..............' => self::v($id),
                'short_id........' => self::v('17'),
                'slice_id........' => self::v($slice),
                'status_code.....' => self::v($status),
                'post_date.......' => self::v('1700000000'),
                'publish_date....' => self::v('1700000000'),
                'expiry_date.....' => self::v('2000000000'),
                'highlight.......' => self::v('0'),
                'posted_by.......' => self::v('uid-17'),
                'edited_by.......' => self::v('uid-18'),
                'last_edit.......' => self::v('1700000100'),
                'display_count...' => self::v('4711'),
                'flags...........' => self::v('0'),
                'disc_count......' => self::v('3'),
                'disc_app........' => self::v('2'),
                'externally_fed..' => self::v('source'),
                'moved2active....' => self::v('0'),
            ]];
        }

        /** a web with slice A and an alerts module in "uses"; slice B only referenced from its spot */
        private static function source(): FakeSource
        {
            $spot = '<html>{view:10}{view:20}{view.php?vid=30}{ids:' . self::SLICE_B . ':d-x}{modulefield:' . self::GONE . ':x}'
                . '{form:abc}{constant:ct_text:x:name}{constant:ct_b:x:name}</html>';
            return new FakeSource([
                'module'   => [
                    // .php3: most lang_file values of production aadb.module
                    self::module(self::SITE, 'W', 'Test web', 'cz-utf8_site_lang.php3'),
                    self::module(self::SLICE_A, 'S', 'Articles', 'cz-utf8_news_lang.php3'),
                    self::module(self::SLICE_B, 'S', 'Other', 'cz-utf8_news_lang.php'),
                    self::module(self::ALERTS, 'Alerts', 'Alerts', 'cz-utf8_alerts_lang.php'),
                    self::module(self::POLL, 'P', 'Poll', 'en_polls_lang.php'),
                    self::module(self::SLICE_W, 'S', "\x8Akoly", 'cz_news_lang.php'),
                    self::module(self::SLICE_J, 'S', "\xA4\xA2", 'ja_news_lang.php'),
                    self::module(self::DELETED, 'S', 'Old', 'cz-utf8_news_lang.php', 1),
                ],
                'site'      => [['id' => self::SITE, 'state_file' => '/cz/home', 'structure' => self::TREE, 'flag' => 1]],
                'site_spot' => [
                    ['id' => 101, 'site_id' => self::SITE, 'spot_id' => 1, 'content' => $spot, 'flag' => 0],
                    ['id' => 102, 'site_id' => self::SITE, 'spot_id' => 2, 'content' => '{mailtemplate:5}', 'flag' => 1],
                ],
                'relation'  => [
                    ['source_id' => self::SITE, 'destination_id' => self::SLICE_A, 'flag' => 1],
                    ['source_id' => self::SITE, 'destination_id' => self::ALERTS, 'flag' => 1],
                    ['source_id' => self::SITE, 'destination_id' => self::POLL, 'flag' => 1],
                    ['source_id' => self::SITE, 'destination_id' => self::GONE, 'flag' => 1],
                    ['source_id' => self::SITE, 'destination_id' => self::DELETED, 'flag' => 1],
                    ['source_id' => self::SITE, 'destination_id' => self::SLICE_B, 'flag' => 2],   // not "uses modules"
                ],
                'slice'     => [
                    // auth_field_group names a FIELD (include/auth.php:81), not a group
                    self::slice(self::SLICE_A, 'Articles', ['vid' => 11, 'auth_field_group' => 'category.......3', 'lang_file' => 'cz-utf8_news_lang.php3']),
                    self::slice(self::SLICE_B, 'Other'),
                    self::slice(self::SLICE_W, "\x8Akoly", ['lang_file' => 'cz_news_lang.php', 'fulltext_format' => "a\x81b", 'odd_row_format' => "\xC3\x81"]),
                    self::slice(self::SLICE_J, "\xA4\xA2", ['lang_file' => 'ja_news_lang.php']),
                ],
                'field'     => [
                    self::field(self::SLICE_A, 'headline........', 'fld:'),
                    self::field(self::SLICE_A, 'category.......1', 'sel:ct_foreign:'),
                    self::field(self::SLICE_A, 'category.......2', 'sel:lt_languages:'),     // core group
                    self::field(self::SLICE_A, 'category.......3', 'mch:ct_auth:3:'),
                    self::field(self::SLICE_A, 'category.......4', 'sel:ct_nowhere:'),       // no such group
                    self::field(self::SLICE_A, 'relation.......1', 'mse:#sLiCe-' . self::SLICE_A . ':'),
                    self::field(self::SLICE_A, 'password.......1', 'pwd:', 'Password', 'pwd:'),
                    self::field(self::SLICE_B, 'category.......1', 'sel:ct_b:'),
                    self::field(self::SLICE_W, 'headline........', 'fld:', "\xC8\xEDslo"),
                    self::field(self::SLICE_W, 'category.......1', 'TPR:5:40:ct_w'),
                    self::field(self::SLICE_W, 'category.......2', "sel:ct_\xE8:"),         // a windows-1250 group name
                ],
                'view'      => [
                    ['id' => 10, 'slice_id' => self::SLICE_A, 'name' => 'List', 'type' => 'digest', 'odd' => '<a href="{view.php?vid=11&cmd[11]=x-11-_#ITEM_ID_}">_#HEADLINE</a>'],
                    ['id' => 11, 'slice_id' => self::SLICE_A, 'name' => 'Detail', 'type' => 'full', 'odd' => '{constant:ct_text:{category.......1}:name}'],
                    ['id' => 20, 'slice_id' => self::SLICE_B, 'name' => 'Other list', 'type' => 'digest', 'odd' => 'x'],
                ],
                'email'     => [
                    ['id' => 5, 'owner_module_id' => self::SLICE_A, 'description' => 'Notify', 'subject' => 'New', 'body' => 'x',
                        'header_from' => 'office@source.example', 'reply_to' => '{_#E_MAIL__}', 'attachments' => 'files/terms.pdf'],
                    ['id' => 7, 'owner_module_id' => self::ALERTS, 'description' => 'Welcome', 'subject' => 'Hi', 'body' => 'y'],
                ],
                'profile'   => [
                    ['id' => 1, 'slice_id' => self::SLICE_A, 'uid' => '*', 'property' => 'input_view', 'selector' => '', 'value' => '11'],
                    ['id' => 2, 'slice_id' => self::SLICE_A, 'uid' => 'u123', 'property' => 'listlen', 'selector' => '', 'value' => '50'],
                ],
                'alerts_collection'          => [['id' => 'AbC12', 'module_id' => self::ALERTS, 'emailid_welcome' => 7, 'emailid_alert' => null, 'slice_id' => self::SLICE_A]],
                'alerts_collection_filter'   => [['collectionid' => 'AbC12', 'filterid' => 3, 'myindex' => 0]],
                'alerts_collection_howoften' => [['collectionid' => 'AbC12', 'howoften' => 'daily', 'last' => 1700000000]],
                'alerts_filter'              => [
                    ['id' => 3, 'vid' => 10, 'conds' => '', 'description' => 'All'],
                    ['id' => 4, 'vid' => 20, 'conds' => '', 'description' => 'Not ours'],
                ],
                'constant_slice' => [
                    ['group_id' => 'ct_foreign', 'slice_id' => self::SLICE_B, 'propagate' => 1, 'levelcount' => 2, 'horizontal' => 0, 'hidevalue' => 0, 'hierarch' => 1],
                    self::group('ct_auth', self::SLICE_A),
                    self::group('ct_unused', self::SLICE_A),
                    self::group('ct_b', self::SLICE_B),
                    self::group('ct_w', self::SLICE_W),
                    self::group("ct_\xE8", self::SLICE_W),
                ],
                'constant' => [
                    self::constant(self::C1, 'ct_foreign', 'News', 100),
                    self::constant(self::C2, 'ct_foreign', 'Home', 110, self::C1),
                    self::constant('cc11bb22cc33dd44ee55ff6677889900', 'ct_auth', 'Editors', 100),
                    self::constant('dd11bb22cc33dd44ee55ff6677889900', 'ct_text', 'Text', 100),
                    self::constant('ee11bb22cc33dd44ee55ff6677889900', 'ct_w', "\x9Akola", 100),
                    self::constant('ff11bb22cc33dd44ee55ff6677889900', 'ct_unused', 'Unused', 100),
                    self::constant('ab11bb22cc33dd44ee55ff6677889900', 'ct_b', 'B', 100),
                    self::constant('ac11bb22cc33dd44ee55ff6677889900', "ct_\xE8", "\xE8", 100),
                ],
            ], [
                self::SLICE_A => [
                    self::item(self::ITEM, self::SLICE_A, '1', [
                        'headline........' => [['value' => 'First', 'flag' => 1]],
                        'number..........' => self::v(null),
                        'category.......1' => self::v('news', 'home'),
                        'password.......1' => self::v('$2y$10$hash'),
                    ]),
                    self::item(self::ITEM2, self::SLICE_A, '3', ['headline........' => self::v('Trashed')]),
                ],
                self::SLICE_W => [self::item(self::ITEM, self::SLICE_W, '1', ['headline........' => self::v("\xC8esko")])],
            ]);
        }

        private static function moduleOf(CollectResult $result, string $id): ?Module
        {
            foreach ($result->package->modules as $module) {
                if ($module->id === $id) {
                    return $module;
                }
            }
            return null;
        }

        private static function groupOf(CollectResult $result, string $name): ?ConstantGroup
        {
            foreach ($result->package->constantGroups as $group) {
                if ($group->name === $name) {
                    return $group;
                }
            }
            return null;
        }

        /** @return list<string> "level code ref @ path" */
        private static function findings(CollectResult $result, ?string $level = null): array
        {
            $findings = array_filter($result->findings, static fn(array $f): bool => ($level === null) || ($f[1]->level === $level));
            return array_values(array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} {$f[1]->ref} @ $f[0]", $findings));
        }

        /** the XML Writer makes of the package, validated against the XSD (Writer throws otherwise) */
        private static function xml(CollectResult $result): DOMDocument
        {
            $dom = new DOMDocument();
            self::assertTrue($dom->loadXML((new Writer())->toXml($result->package)->xml));
            return $dom;
        }

        // --- the app boundary ----------------------------------------------------------------------

        public function test_fromSite_takes_the_site_and_its_uses_modules_of_the_v1_types(): void
        {
            $result = (new Collector(self::source()))->fromSite(strtoupper(self::SITE), new CollectOptions(self::SOURCE));

            $ids = array_map(static fn(Module $m): string => "$m->type $m->id", $result->package->modules);
            sort($ids);
            $this->assertSame(['alerts-module ' . self::ALERTS, 'site-module ' . self::SITE, 'slice-module ' . self::SLICE_A], $ids);
            $this->assertSame([['destination_id' => self::SLICE_A], ['destination_id' => self::ALERTS]], self::moduleOf($result, self::SITE)->rows['uses']);
            $this->assertSame('Test web', $result->package->name);
            $this->assertSame(self::SOURCE + ['site' => self::SITE], $result->package->source);
            $findings = self::findings($result);
            $this->assertContains('W ' . Collector::MODULE_TYPE . ' ' . self::POLL . ' @ site-module[' . self::SITE . ']/uses[' . self::POLL . ']', $findings);
            $this->assertContains('I ' . Collector::MODULE_MISSING . ' ' . self::GONE . ' @ site-module[' . self::SITE . ']/uses[' . self::GONE . ']', $findings);
            $this->assertContains('I ' . Collector::MODULE_MISSING . ' ' . self::DELETED . ' @ site-module[' . self::SITE . ']/uses[' . self::DELETED . ']', $findings);
        }

        public function test_the_caller_checks_the_app_modules_and_the_owners_of_foreign_groups(): void
        {
            $result = (new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE));

            $this->assertEqualsCanonicalizing([self::SITE, self::SLICE_A, self::ALERTS, self::SLICE_B], $result->checkPermissions);
        }

        public function test_fromSite_lints_references_outside_the_app_and_reports_their_modules(): void
        {
            $result = (new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE));

            $this->assertEqualsCanonicalizing([
                'W ' . Collector::MODULE_TYPE . ' ' . self::POLL . ' @ site-module[' . self::SITE . ']/uses[' . self::POLL . ']',
                'W ' . SecurityLint::ATTACHMENTS_DROPPED . ' files/terms.pdf @ slice-module[' . self::SLICE_A . ']/email[5]/attachments',
                'W ' . ReferenceLint::VIEW_OUTSIDE . ' 20 @ ' . self::SPOT,
                'W ' . ReferenceLint::VIEW_OUTSIDE . ' 30 @ ' . self::SPOT,                  // no such view: no module to report
                'W ' . ReferenceLint::MODULE_OUTSIDE . ' ' . self::SLICE_B . ' @ ' . self::SPOT,
                'W ' . ReferenceLint::MODULE_OUTSIDE . ' ' . self::GONE . ' @ ' . self::SPOT,     // no such module: not in $outside
                'W ' . ReferenceLint::FORM . ' {form: @ ' . self::SPOT,
                'W ' . Collector::GROUP_MISSING . ' ct_nowhere @ slice-module[' . self::SLICE_A . ']/field[category.......4]/input_show_func',
            ], self::findings($result, LintFinding::WARNING));
            // slice B is not pulled in - the author has to decide about it
            $this->assertNull(self::moduleOf($result, self::SLICE_B));
            $this->assertSame([self::SLICE_B => [self::SPOT]], $result->outside);
            $this->assertSame([], self::findings($result, LintFinding::ERROR));
            $this->assertFalse($result->failed());
        }

        public function test_references_of_app_views_emails_and_groups_are_no_findings(): void
        {
            $refs = array_map(static fn(array $f): string => $f[1]->ref, (new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE))->findings);

            foreach (['10', '11', '5', '7', 'ct_text', 'ct_foreign', 'ct_auth', 'ct_b', self::SLICE_A] as $in_app) {
                $this->assertNotContains($in_app, $refs);
            }
        }

        public function test_an_email_of_a_module_outside_the_app_is_in_the_closure(): void
        {
            $source = self::source();
            $source->tables['alerts_collection'][0]['emailid_alert'] = 5;    // the e-mail of slice A
            $result = (new Collector($source))->fromModules([self::ALERTS], new CollectOptions(self::SOURCE));

            $path = 'alerts-module[' . self::ALERTS . ']/alerts_collection[AbC12]/emailid_alert';
            $this->assertContains('W ' . ReferenceLint::EMAIL_OUTSIDE . " 5 @ $path", self::findings($result));
            $this->assertContains($path, $result->outside[self::SLICE_A]);
        }

        public function test_a_published_app_turns_warnings_into_errors(): void
        {
            $collector = new Collector(self::source());
            $draft     = $collector->fromSite(self::SITE, new CollectOptions(self::SOURCE));
            $published = $collector->fromSite(self::SITE, new CollectOptions(self::SOURCE, publish: 'users'));

            $this->assertSame([], self::findings($published, LintFinding::WARNING));
            $this->assertCount(count(self::findings($draft, LintFinding::WARNING)), self::findings($published, LintFinding::ERROR));
            $this->assertSame(self::findings($draft, LintFinding::INFO), self::findings($published, LintFinding::INFO));
            $this->assertTrue($published->failed());
            $this->assertSame('users', $published->package->publish);
        }

        public function test_fromModules_takes_only_the_given_modules(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, name: 'My app', version: '1.0'));

            $this->assertSame([self::SLICE_A], array_map(static fn(Module $m): string => $m->id, $result->package->modules));
            $this->assertSame(['My app', '1.0'], [$result->package->name, $result->package->version]);
            $this->assertArrayNotHasKey('site', $result->package->source);
            // the alerts module reading slice A is not in the app - an info for the author
            $this->assertContains('I ' . ExportLint::ALERTS_OUTSIDE . ' ' . self::ALERTS . ' @ slice-module[' . self::SLICE_A . ']', self::findings($result));
            self::xml($result);
        }

        public function test_an_alerts_module_reading_a_slice_outside_the_app_fails_the_export(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::ALERTS], new CollectOptions(self::SOURCE));

            $this->assertContains('E ' . ExportLint::READER_OUTSIDE . ' ' . self::SLICE_A . ' @ alerts-module[' . self::ALERTS . ']/alerts_collection[AbC12]/slice_id', self::findings($result));
            $this->assertTrue($result->failed());
            $this->assertArrayHasKey(self::SLICE_A, $result->outside);
        }

        /** @return array<string, array{list<string>, string}> */
        public static function wrongModules(): array
        {
            return [
                'a site'          => [[self::SITE], 'fromModules'],
                'a poll'          => [[self::POLL], 'fromModules'],
                'a deleted slice' => [[self::SLICE_A, self::DELETED], 'fromModules'],
                'no such id'      => [[self::GONE], 'fromModules'],
                'not an id'       => [['abc'], 'fromModules'],
                'nothing'         => [[], 'fromModules'],
                'site: a slice'   => [[self::SLICE_A], 'fromSite'],
            ];
        }

        #[DataProvider('wrongModules')]
        public function test_wrong_modules_are_rejected(array $ids, string $method): void
        {
            $this->expectException(InvalidArgumentException::class);
            $collector = new Collector(self::source());
            $method === 'fromSite' ? $collector->fromSite($ids[0], new CollectOptions(self::SOURCE)) : $collector->fromModules($ids, new CollectOptions(self::SOURCE));
        }

        public function test_a_deleted_site_is_rejected(): void
        {
            $source                              = self::source();
            $source->tables['module'][0]['deleted'] = 1;
            $this->expectException(InvalidArgumentException::class);
            (new Collector($source))->fromSite(self::SITE, new CollectOptions(self::SOURCE));
        }

        public function test_an_unknown_publish_value_is_rejected(): void
        {
            $this->expectException(InvalidArgumentException::class);
            new CollectOptions(self::SOURCE, publish: 'everyone');
        }

        // --- rows ------------------------------------------------------------------------------------

        public function test_rows_carry_only_package_columns(): void
        {
            $result = (new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE));
            $slice  = self::moduleOf($result, self::SLICE_A);

            $this->assertSame([['name' => 'Articles', 'lang_file' => 'cz-utf8_news_lang.php', 'flag' => 0]], $slice->rows['module']);
            foreach (['id', 'owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'fileman_dir', 'reading_password', 'export_to_all'] as $scrubbed) {
                $this->assertArrayNotHasKey($scrubbed, $slice->rows['slice'][0], $scrubbed);
            }
            $this->assertSame(11, $slice->rows['slice'][0]['vid']);
            $this->assertSame('cz-utf8_news_lang.php', $slice->rows['slice'][0]['lang_file']);     // the .php3 trimmed, as in module
            $this->assertSame('cz-utf8_site_lang.php', self::moduleOf($result, self::SITE)->rows['module'][0]['lang_file']);
            $this->assertSame([], array_filter(self::findings($result), static fn(string $f): bool => str_contains($f, 'lang-file')));
            $this->assertNull($slice->rows['slice'][0]['mlxctrl']);
            $this->assertSame([10, 11], array_column($slice->rows['view'], 'id'));
            $this->assertArrayNotHasKey('slice_id', $slice->rows['view'][0]);
            $this->assertSame([5], array_column($slice->rows['email'], 'id'));
            $this->assertArrayNotHasKey('owner_module_id', $slice->rows['email'][0]);
            // the default profile only - users of the source AA do not exist on the target
            $this->assertSame([['property' => 'input_view', 'selector' => '', 'value' => '11']], $slice->rows['profile']);

            $site = self::moduleOf($result, self::SITE);
            $this->assertSame([['state_file' => '/cz/home', 'flag' => 1]], $site->rows['site']);
            $this->assertSame([[1, 0], [2, 1]], array_map(static fn(array $r): array => [$r['spot_id'], $r['flag']], $site->rows['site_spot']));
            $this->assertArrayNotHasKey('id', $site->rows['site_spot'][0]);
            $this->assertSame(['start_id' => '1', 'spots' => [
                ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => null, 'c' => null, 'po' => ['1', '2'], 'ch' => null],
                ['id' => '2', 'n' => 'text', 'p' => '1', 'f' => 0, 'v' => null, 'c' => null, 'po' => null, 'ch' => null],
            ]], $site->spotTree);
            $this->assertNull($site->settings);      // objects: CollectorObjectsTest
            $this->assertSame([], $site->objects);
        }

        public function test_the_source_organisation_of_emails_is_reported(): void
        {
            $findings = self::findings((new Collector(self::source()))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE)));
            $email    = 'slice-module[' . self::SLICE_A . ']/email[5]';

            // as the import sees it (SecurityLint::attachments()): a local path is dropped there
            $this->assertContains('W ' . SecurityLint::ATTACHMENTS_DROPPED . " files/terms.pdf @ $email/attachments", $findings);
            $this->assertContains('I ' . ExportLint::EMAIL_ADDRESS . " office@source.example @ $email/header_from", $findings);
            $this->assertNotContains('I ' . ExportLint::EMAIL_ADDRESS . " {_#E_MAIL__} @ $email/reply_to", $findings);   // an expression
        }

        public function test_alerts_rows_take_the_filters_of_the_collection(): void
        {
            $alerts = self::moduleOf((new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE)), self::ALERTS);

            $this->assertSame([['id' => 'AbC12', 'emailid_welcome' => 7, 'emailid_alert' => null, 'slice_id' => self::SLICE_A]], $alerts->rows['alerts_collection']);
            $this->assertSame([['collectionid' => 'AbC12', 'filterid' => 3, 'myindex' => 0]], $alerts->rows['alerts_collection_filter']);
            $this->assertSame([['collectionid' => 'AbC12', 'howoften' => 'daily']], $alerts->rows['alerts_collection_howoften']);
            $this->assertSame([['id' => 3, 'vid' => 10, 'conds' => '', 'description' => 'All']], $alerts->rows['alerts_filter']);
            $this->assertSame([7], array_column($alerts->rows['email'], 'id'));
        }

        public function test_a_control_file_of_the_site_is_left_out(): void
        {
            $source                    = self::source();
            $source->tables['site'][0] = ['id' => self::SITE, 'state_file' => 'apc.php', 'structure' => '', 'flag' => 0];
            $result                    = (new Collector($source))->fromSite(self::SITE, new CollectOptions(self::SOURCE));

            $this->assertSame([['flag' => 0]], self::moduleOf($result, self::SITE)->rows['site']);
            $this->assertContains('W ' . Collector::STATE_FILE . ' apc.php @ site-module[' . self::SITE . ']/site[#0]/state_file', self::findings($result));
        }

        public function test_a_missing_slice_row_fails_the_export(): void
        {
            $source = self::source();
            unset($source->tables['slice'][0]);
            $result = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE));

            $this->assertContains('E ' . Collector::ROW_MISSING . ' slice @ slice-module[' . self::SLICE_A . ']/slice', self::findings($result));
            $this->assertTrue($result->failed());
        }

        // --- constant groups -------------------------------------------------------------------------

        public function test_groups_used_by_the_app_travel_foreign_ones_too(): void
        {
            $result = (new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE));

            $names = array_map(static fn(ConstantGroup $g): string => $g->name, $result->package->constantGroups);
            sort($names);
            // field widgets, {constant:} in texts; not the core, unused, missing or only slice B's groups
            $this->assertSame(['ct_auth', 'ct_b', 'ct_foreign', 'ct_text'], $names);

            $foreign = self::groupOf($result, 'ct_foreign');
            $this->assertSame(self::SLICE_A, $foreign->owner);   // owned outside the app: the first app slice using it
            $this->assertSame([['propagate' => 1, 'levelcount' => 2, 'horizontal' => 0, 'hidevalue' => 0, 'hierarch' => 1]], $foreign->rows['constant_slice']);
            $this->assertSame(
                ['id' => self::C2, 'name' => 'Home', 'value' => 'home', 'class' => '', 'pri' => 110, 'ancestors' => self::C1, 'description' => ''],
                $foreign->rows['constant'][1],
            );
            $this->assertSame(self::SLICE_A, self::groupOf($result, 'ct_b')->owner);   // foreign, used by the site only: the first app slice
            $this->assertSame(self::SLICE_A, self::groupOf($result, 'ct_auth')->owner);
            $this->assertSame('', self::groupOf($result, 'ct_text')->owner);      // unowned stays unowned
            $this->assertArrayNotHasKey('constant_slice', self::groupOf($result, 'ct_text')->rows);
        }

        public function test_a_missing_owner_of_a_group_is_a_warning(): void
        {
            $source                                        = self::source();
            $source->tables['constant_slice'][0]['slice_id'] = self::GONE;       // ct_foreign, owned by no module
            $result                                        = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE));

            $this->assertContains('W ' . Collector::OWNER_MISSING . ' ' . self::GONE . ' @ constant-group[ct_foreign]', self::findings($result));
            $this->assertSame([], array_filter(self::findings($result), static fn(string $f): bool => str_contains($f, 'lang-file')));
            $this->assertNotContains(self::GONE, $result->checkPermissions);
            $this->assertSame(self::SLICE_A, self::groupOf($result, 'ct_foreign')->owner);
            $this->assertSame('News', self::groupOf($result, 'ct_foreign')->rows['constant'][0]['name']);
        }

        public function test_a_foreign_owner_of_an_unknown_language_is_an_error(): void
        {
            $source                                  = self::source();
            $source->tables['module'][2]['lang_file'] = 'xx_news_lang.php';       // slice B owns ct_foreign
            $result                                  = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE));

            $this->assertContains('E ' . Collector::LANG_FILE . ' xx_news_lang.php @ constant-group[ct_foreign]', self::findings($result));
        }

        // --- charset ---------------------------------------------------------------------------------

        public function test_texts_of_a_windows_1250_module_are_converted_to_utf8(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_W], new CollectOptions(self::SOURCE, items: true));
            $slice  = self::moduleOf($result, self::SLICE_W);

            $this->assertSame('Školy', $result->package->name);
            $this->assertSame('Školy', $slice->rows['slice'][0]['name']);
            $this->assertSame('Číslo', $slice->rows['field'][0]['name']);
            $this->assertSame('Česko', $slice->items[0]['content']['headline........'][0]['value']);
            $this->assertSame('škola', self::groupOf($result, 'ct_w')->rows['constant'][0]['name']);   // tpr: the group is the third parameter
            // the group name is looked up as stored (windows-1250), the package has it in UTF-8
            $this->assertSame('č', self::groupOf($result, 'ct_č')?->rows['constant'][0]['name']);
        }

        public function test_bytes_the_charset_does_not_define_stay_raw_with_one_finding(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_W], new CollectOptions(self::SOURCE));
            $slice  = self::moduleOf($result, self::SLICE_W)->rows['slice'][0];
            $path   = 'slice-module[' . self::SLICE_W . ']/slice[#0]';

            $this->assertEquals([new RawBytes("a\x81b"), new RawBytes("\xC3\x81")], [$slice['fulltext_format'], $slice['odd_row_format']]);
            $findings = self::findings($result);
            $this->assertContains('W ' . ValueCodec::INVALID_BYTES . " \x81b @ $path/fulltext_format", $findings);       // at the byte that failed
            $this->assertContains('W ' . ValueCodec::INVALID_BYTES . " \x81 @ $path/odd_row_format", $findings);          // valid UTF-8, still lost
            // written as base64, both - "\xC3\x81" must never be read as the UTF-8 "Á"
            $odd = new DOMXPath(self::xml($result));
            $odd->registerNamespace('a', Schema::NS);
            $this->assertSame('base64', $odd->query('//a:slice/a:odd_row_format')->item(0)?->attributes?->getNamedItem('enc')?->nodeValue);
            // the writer finds the invalid one again - withWriter() reports it once
            $all = $result->withWriter((new Writer())->toXml($result->package));
            $this->assertCount(2, array_filter($all, static fn(array $f): bool => $f[1]->code === ValueCodec::INVALID_BYTES));
        }

        public function test_texts_of_an_euc_jp_module_are_converted(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_J], new CollectOptions(self::SOURCE));

            $this->assertSame('あ', self::moduleOf($result, self::SLICE_J)->rows['slice'][0]['name']);
            $this->assertSame([], self::findings($result));
        }

        public function test_a_lang_file_of_another_type_is_normalised(): void
        {
            $source                                  = self::source();
            $source->tables['module'][1]['lang_file'] = 'cz-utf8_site_lang.php';   // a site language for a slice
            $result                                  = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE));

            $this->assertContains('I ' . Collector::LANG_FILE_NORMALISED . ' cz-utf8_site_lang.php @ slice-module[' . self::SLICE_A . ']/module[#0]/lang_file', self::findings($result));
            $this->assertSame('cz-utf8_news_lang.php', self::moduleOf($result, self::SLICE_A)->rows['module'][0]['lang_file']);
            $this->assertFalse($result->failed());
        }

        public function test_an_unknown_language_fails_the_export(): void
        {
            $source                                  = self::source();
            $source->tables['module'][1]['lang_file'] = 'xx_news_lang.php';
            $result                                  = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE));

            $this->assertContains('E ' . Collector::LANG_FILE . ' xx_news_lang.php @ slice-module[' . self::SLICE_A . ']/module[#0]/lang_file', self::findings($result));
            $this->assertTrue($result->failed());
        }

        // --- items -----------------------------------------------------------------------------------

        public function test_items_only_on_request(): void
        {
            $source = self::source();
            $off    = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE));
            $this->assertFalse($off->package->withItems);
            $this->assertSame([], self::moduleOf($off, self::SLICE_A)->items);
            $this->assertNotContains('item', array_column($source->log, 0));

            $on    = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, items: true));
            $items = self::moduleOf($on, self::SLICE_A)->items;
            $this->assertTrue($on->package->withItems);
            $this->assertSame([self::ITEM], array_column($items, 'id'));      // the active bin only
            $this->assertSame([
                'headline........' => [['value' => 'First', 'flag' => 1]],
                'number..........' => [['value' => '', 'flag' => 0]],        // NULL => '' (Model\Module)
                'category.......1' => self::v('news', 'home'),
                'password.......1' => self::v('$2y$10$hash'),                 // readers must log in after a transfer
                'status_code.....' => self::v('1'),
                'post_date.......' => self::v('1700000000'),
                'publish_date....' => self::v('1700000000'),
                'expiry_date.....' => self::v('2000000000'),
                'highlight.......' => self::v('0'),
                'last_edit.......' => self::v('1700000100'),
                'flags...........' => self::v('0'),
                'moved2active....' => self::v('0'),
            ], $items[0]['content']);   // no users, counters or feeding of the source AA
            $this->assertCount(1, self::xml($on)->getElementsByTagName('item'));
        }

        public function test_items_of_all_bins_on_request(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, items: true, allBins: true));

            $this->assertSame([self::ITEM, self::ITEM2], array_column(self::moduleOf($result, self::SLICE_A)->items, 'id'));
        }

        public function test_more_items_than_the_limit_fail_the_export(): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, items: true, allBins: true, maxItems: 1));

            $this->assertCount(1, self::moduleOf($result, self::SLICE_A)->items);
            $this->assertContains('E ' . Collector::ITEMS_LIMIT . ' 1 @ slice-module[' . self::SLICE_A . ']/item', self::findings($result));
        }

        public function test_items_of_a_reader_management_slice_travel_like_any_other(): void
        {
            // moving a whole site between installations takes its readers too
            $source                             = self::source();
            $source->tables['slice'][0]['type'] = 'ReaderManagement';
            $result                             = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, items: true));

            $items = self::moduleOf($result, self::SLICE_A)->items;
            $this->assertSame([self::ITEM], array_column($items, 'id'));
            $this->assertSame(self::v('$2y$10$hash'), $items[0]['content']['password.......1']);
            $this->assertFalse($result->failed());
        }

        /** @return array<string, array{string}> */
        public static function notPublic(): array
        {
            return ['no' => ['no'], 'users (admins of the site only, Q-H1)' => ['users']];
        }

        #[DataProvider('notPublic')]
        public function test_items_may_travel_in_a_package_that_is_not_public(string $publish): void
        {
            $result = (new Collector(self::source()))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, items: true, publish: $publish));

            $this->assertSame([self::ITEM], array_column(self::moduleOf($result, self::SLICE_A)->items, 'id'));
            $this->assertSame([], array_filter(self::findings($result), static fn(string $f): bool => str_contains($f, Collector::ITEMS_PUBLISHED)));
        }

        public function test_a_public_app_carries_no_items(): void
        {
            $source = self::source();
            $result = (new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE, items: true, publish: 'all'));

            $this->assertContains('E ' . Collector::ITEMS_PUBLISHED . ' all @ meta/contents', self::findings($result));
            $this->assertSame([], self::moduleOf($result, self::SLICE_A)->items);
            $this->assertNotContains('item', array_column($source->log, 0));
        }

        public function test_the_collected_site_is_a_valid_package(): void
        {
            $dom = self::xml((new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE, description: 'A test app', publish: 'no')));

            $this->assertSame(1, $dom->getElementsByTagName('site-module')->length);
            $this->assertSame(4, $dom->getElementsByTagName('constant-group')->length);
            $this->assertSame(0, $dom->getElementsByTagName('reading_password')->length);
        }
    }
}
