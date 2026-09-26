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
    use AA\Central\Package\Model\Batch;
    use AA\Central\Package\Model\ConstantGroup;
    use AA\Central\Package\Model\Module;
    use AA\Central\Package\Model\RawBytes;
    use AA\Central\Package\Reader;
    use AA\Central\Package\ReferenceLint;
    use AA\Central\Package\Schema;
    use AA\Central\Package\SecurityLint;
    use AA\Central\Package\Validator;
    use AA\Central\Package\ValueCodec;
    use AA\Central\Package\Writer;
    use AA\Central\Package\XmlGuard;
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
        private const ITEM3   = '2123456789abcdef0123456789abcdef';
        private const ITEM_W  = '3123456789abcdef0123456789abcdef';
        private const OWNER   = '99999999999999999999999999999999';
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
                'owner' => self::OWNER, 'created_by' => 'uid-17', 'created_at' => 1700000000,
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
                'slice_owner' => [['id' => self::OWNER, 'name' => 'Ekologický institut', 'email' => 'office@source.example']],
            ], [
                self::SLICE_A => [
                    self::item(self::ITEM, self::SLICE_A, '1', [
                        'headline........' => [['value' => 'First', 'flag' => 1]],
                        'number..........' => self::v(null),
                        'category.......1' => self::v('news', 'home'),
                        'password.......1' => self::v('$2y$10$hash'),
                        'secret..........' => self::v('JBSWY3DPEHPK3PXP'),     // 2FA - GetItemContent() leaves it out
                    ]),
                    self::item(self::ITEM3, self::SLICE_A, '1', ['headline........' => self::v('Third')]),
                    self::item(self::ITEM2, self::SLICE_A, '3', ['headline........' => self::v('Trashed')]),
                ],
                self::SLICE_W => [self::item(self::ITEM_W, self::SLICE_W, '1', ['headline........' => self::v("\xC8esko")])],
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

        /** the preview stores only the fingerprint of the XML; the write collects again and compares -
         *  the rows may come in another order the second time (no ORDER BY) */
        public function test_the_same_selection_gives_the_same_xml(): void
        {
            $source   = self::source();
            $reversed = new FakeSource(array_map('array_reverse', $source->tables), array_map('array_reverse', $source->items));
            $options  = new CollectOptions(self::SOURCE, CollectOptions::TRANSFER);
            $xml      = static fn(FakeSource $s): string => (new Writer())->toXml((new Collector($s))->fromSite(self::SITE, $options)->package)->xml;

            $this->assertSame($xml($source), $xml($reversed));
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

        /** a const view lists the constants of the group in view.parameter (view.php) */
        public function test_a_group_only_a_const_view_lists_travels(): void
        {
            $source                   = self::source();
            $source->tables['view'][] = ['id' => 12, 'slice_id' => self::SLICE_A, 'name' => 'Rubriky', 'type' => 'const', 'parameter' => 'ct_unused', 'odd' => '_#NAME###_'];

            $result = (new Collector($source))->fromSite(self::SITE, new CollectOptions(self::SOURCE));

            $this->assertSame(self::SLICE_A, self::groupOf($result, 'ct_unused')?->owner);
            $this->assertSame([], array_filter(self::findings($result), static fn(string $f): bool => str_contains($f, '/view[12]')));
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
            $result = (new Collector(self::source()))->fromModules([self::SLICE_W], new CollectOptions(self::SOURCE));
            $slice  = self::moduleOf($result, self::SLICE_W);
            $batch  = (new Collector(self::source()))->itemBatch(self::SLICE_W, '', self::transfer());

            $this->assertSame('Školy', $result->package->name);
            $this->assertSame('Školy', $slice->rows['slice'][0]['name']);
            $this->assertSame('Číslo', $slice->rows['field'][0]['name']);
            $this->assertSame('Česko', $batch->package->modules[0]->items[0]['content']['headline........'][0]['value']);
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

        // --- items: batches of a transfer --------------------------------------------------------------

        private static function transfer(bool $all_bins = false): CollectOptions
        {
            return new CollectOptions(self::SOURCE, mode: CollectOptions::TRANSFER, allBins: $all_bins);
        }

        /** the one slice of a batch */
        private static function batchSlice(CollectResult $result): Module
        {
            self::assertCount(1, $result->package->modules);
            return $result->package->modules[0];
        }

        /** a source with one slice of these items, ids from $first on
         *  @param list<string> $headlines */
        private static function bigSlice(array $headlines, int $first = 1): FakeSource
        {
            $source = self::source();
            $source->items[self::SLICE_B] = [];
            foreach ($headlines as $i => $headline) {
                $id                             = sprintf('%032x', $first + $i);
                $source->items[self::SLICE_B][] = self::item($id, self::SLICE_B, '1', ['headline........' => self::v($headline)]);
            }
            return $source;
        }

        public function test_definitions_carry_no_items(): void
        {
            $source = self::source();
            $result = (new Collector($source))->fromSite(self::SITE, self::transfer(true));

            $this->assertFalse($result->package->withItems);
            $this->assertNull($result->package->batch);
            $this->assertSame([], self::moduleOf($result, self::SLICE_A)->items);
            $this->assertSame([], array_values(array_intersect(['itemIds', 'item'], array_column($source->log, 0))));
        }

        public function test_a_batch_is_one_slice_with_its_items_only(): void
        {
            $result = (new Collector(self::source()))->itemBatch(strtoupper(self::SLICE_A), '', self::transfer());
            $slice  = self::batchSlice($result);

            $this->assertSame([Module::SLICE, self::SLICE_A, []], [$slice->type, $slice->id, $slice->rows]);
            $this->assertSame([null, [], null, []], [$slice->settings, $slice->objects, $slice->spotTree, $slice->transfer]);
            $this->assertSame([], $result->package->constantGroups);
            $this->assertTrue($result->package->withItems);
            $this->assertSame('Articles', $result->package->name);
            $this->assertSame([self::SLICE_A], $result->checkPermissions);
            $this->assertSame([self::ITEM, self::ITEM3], array_column($slice->items, 'id'));      // the active bin, by id
            $this->assertEquals(new Batch('', self::ITEM3, true), $result->package->batch);
            $this->assertFalse($result->failed());
        }

        public function test_a_batch_continues_after_the_cursor(): void
        {
            $result = (new Collector(self::source()))->itemBatch(self::SLICE_A, self::ITEM, self::transfer());

            $this->assertSame([self::ITEM3], array_column(self::batchSlice($result)->items, 'id'));
            $this->assertEquals(new Batch(self::ITEM, self::ITEM3, true), $result->package->batch);
        }

        public function test_a_batch_of_all_bins(): void
        {
            $result = (new Collector(self::source()))->itemBatch(self::SLICE_A, self::ITEM, self::transfer(true));

            $this->assertSame([self::ITEM2, self::ITEM3], array_column(self::batchSlice($result)->items, 'id'));
        }

        public function test_the_batch_after_the_last_item_is_empty_and_finished(): void
        {
            $result = (new Collector(self::source()))->itemBatch(self::SLICE_A, self::ITEM3, self::transfer());

            $this->assertSame([], self::batchSlice($result)->items);
            $this->assertEquals(new Batch(self::ITEM3, self::ITEM3, true), $result->package->batch);
            $this->assertFalse($result->failed());
        }

        public function test_a_batch_takes_at_most_MAX_ITEMS(): void
        {
            $source = self::bigSlice(array_fill(0, CollectOptions::MAX_ITEMS + 1, 'x'));
            $first  = (new Collector($source))->itemBatch(self::SLICE_B, '', self::transfer());

            $this->assertCount(CollectOptions::MAX_ITEMS, self::batchSlice($first)->items);
            $last = sprintf('%032x', CollectOptions::MAX_ITEMS);
            $this->assertEquals(new Batch('', $last, false), $first->package->batch);
            $this->assertContains(['itemIds', [self::SLICE_B, false, '', CollectOptions::MAX_ITEMS + 1]], $source->log);   // one more tells "not finished"

            $second = (new Collector($source))->itemBatch(self::SLICE_B, $last, self::transfer());
            $this->assertSame([sprintf('%032x', CollectOptions::MAX_ITEMS + 1)], array_column(self::batchSlice($second)->items, 'id'));
            $this->assertTrue($second->package->batch->finished);
        }

        public function test_exactly_MAX_ITEMS_are_finished(): void
        {
            $result = (new Collector(self::bigSlice(array_fill(0, CollectOptions::MAX_ITEMS, 'x'))))->itemBatch(self::SLICE_B, '', self::transfer());

            $this->assertCount(CollectOptions::MAX_ITEMS, self::batchSlice($result)->items);
            $this->assertTrue($result->package->batch->finished);
        }

        public function test_a_batch_stops_before_MAX_BATCH_BYTES(): void
        {
            $big    = str_repeat('x', (int) (CollectOptions::MAX_BATCH_BYTES * 0.4));
            $result = (new Collector(self::bigSlice([$big, $big, $big])))->itemBatch(self::SLICE_B, '', self::transfer());

            $this->assertSame([sprintf('%032x', 1), sprintf('%032x', 2)], array_column(self::batchSlice($result)->items, 'id'));
            $this->assertEquals(new Batch('', sprintf('%032x', 2), false), $result->package->batch);
            $this->assertFalse($result->failed());
        }

        public function test_an_item_over_MAX_BATCH_BYTES_is_an_error_with_its_id(): void
        {
            $id     = sprintf('%032x', 2);
            $source = self::bigSlice(['small', str_repeat('x', CollectOptions::MAX_BATCH_BYTES)]);

            $first = (new Collector($source))->itemBatch(self::SLICE_B, '', self::transfer());
            $this->assertEquals(new Batch('', sprintf('%032x', 1), false), $first->package->batch);     // the batch before it goes
            $this->assertFalse($first->failed());

            $result = (new Collector($source))->itemBatch(self::SLICE_B, sprintf('%032x', 1), self::transfer());
            $this->assertTrue($result->failed());
            $this->assertSame(['E ' . Collector::ITEM_TOO_BIG . " $id @ slice-module[" . self::SLICE_B . "]/item[$id]"], self::findings($result));
            $this->assertSame([], self::batchSlice($result)->items);                                      // never cut
            $this->assertEquals(new Batch(sprintf('%032x', 1), sprintf('%032x', 1), false), $result->package->batch);
        }

        public function test_the_byte_limit_keeps_a_batch_within_the_reader_limits(): void
        {
            // a <v> is an element, up to 3 nodes and 4 attributes (f, i, flag, enc|crlf)
            $values = intdiv(CollectOptions::MAX_BATCH_BYTES, Collector::VALUE_MARKUP);
            $this->assertLessThan(XmlGuard::MAX_ELEMENTS - 2 * CollectOptions::MAX_ITEMS - 1000, $values);
            $this->assertLessThan(XmlGuard::MAX_ATTRIBUTES - 1000, 4 * $values + CollectOptions::MAX_ITEMS);
            $this->assertLessThan(XmlGuard::MAX_NODES - 10000, 3 * $values + 3 * CollectOptions::MAX_ITEMS);
            // the limit counts the values as written (escaped, CDATA, base64); meta and <item> tags on top
            $this->assertLessThan(XmlGuard::MAX_BYTES, CollectOptions::MAX_BATCH_BYTES + 200 * CollectOptions::MAX_ITEMS + 100_000);
        }

        /** @return array<string, array{string}> */
        public static function growingValues(): array
        {
            return [
                'escaped > (4 bytes each)'  => [str_repeat('>', 300_000)],
                'CDATA split ]]> (x4)'      => [str_repeat('<]]>', 75_000)],
                'base64 of control chars'   => [str_repeat("\x01\x02\x03", 300_000)],
            ];
        }

        #[DataProvider('growingValues')]
        public function test_the_byte_limit_counts_the_value_as_written(string $value): void
        {
            $source = self::bigSlice(array_fill(0, 20, $value));
            $result = (new Collector($source))->itemBatch(self::SLICE_B, '', self::transfer());
            $xml    = (new Writer())->toXml($result->package)->xml;

            $this->assertCount(3, self::batchSlice($result)->items);        // 3 x ~1.2 MB written, not 13 x 300 kB
            $this->assertLessThan(CollectOptions::MAX_BATCH_BYTES + 100_000, strlen($xml));
            $this->assertCount(3, (new Reader())->read($xml)->package->modules[0]->items);
        }

        public function test_items_are_read_in_chunks_until_the_byte_limit(): void
        {
            $source = self::bigSlice(array_fill(0, CollectOptions::MAX_ITEMS, 'x'));
            (new Collector($source))->itemBatch(self::SLICE_B, '', self::transfer());
            $reads = array_values(array_filter($source->log, static fn(array $l): bool => $l[0] === 'item'));
            $this->assertCount(CollectOptions::MAX_ITEMS / Collector::ITEM_CHUNK, $reads);
            $this->assertSame([sprintf('%032x', 1)], array_slice($reads[0][1], 0, 1));    // in id order

            $big    = str_repeat('x', (int) (CollectOptions::MAX_BATCH_BYTES * 0.4));
            $source = self::bigSlice(array_fill(0, 2 * Collector::ITEM_CHUNK, $big));
            (new Collector($source))->itemBatch(self::SLICE_B, '', self::transfer());
            $this->assertCount(1, array_filter($source->log, static fn(array $l): bool => $l[0] === 'item'));
        }

        public function test_an_item_deleted_meanwhile_is_skipped_and_no_cursor(): void
        {
            $source           = self::source();
            $source->vanished = [self::ITEM3];
            $result           = (new Collector($source))->itemBatch(self::SLICE_A, '', self::transfer());

            $this->assertSame([self::ITEM], array_column(self::batchSlice($result)->items, 'id'));
            $this->assertEquals(new Batch('', self::ITEM, true), $result->package->batch);
            $this->assertTrue((new Validator())->validate($result->package, true)->importable());

            $source->vanished = [self::ITEM, self::ITEM3];
            $result           = (new Collector($source))->itemBatch(self::SLICE_A, '', self::transfer());
            $this->assertEquals(new Batch('', '', true), $result->package->batch);
            $this->assertTrue((new Validator())->validate($result->package, true)->importable());
        }

        public function test_findings_never_quote_passwords_or_secrets(): void
        {
            $source                                 = self::source();
            $source->tables['slice'][2]['reading_password'] = "pw\x81x";
            $source->items[self::SLICE_W][0]['content'] += ['password.......1' => self::v("ab\x81cd"), 'secret..........' => self::v("s\x81t"), 'text...........1' => self::v("t\x81u")];
            $findings = [
                ...(new Collector($source))->fromModules([self::SLICE_W], self::transfer())->findings,
                ...(new Collector($source))->itemBatch(self::SLICE_W, '', self::transfer())->findings,
            ];
            $refs = [];
            foreach ($findings as [$path, $f]) {
                $refs[preg_replace('/.*\//', '', $path)] = [$f->ref, $f->offset];
            }

            $this->assertSame(['', 2], $refs['reading-password']);
            $this->assertSame(['', 2], $refs['password.......1[0]']);
            $this->assertSame(['', 1], $refs['secret..........[0]']);
            $this->assertSame(["\x81u", 1], $refs['text...........1[0]']);     // other values say where
        }

        public function test_a_batch_carries_2fa_secrets_and_authors_never_the_state_of_the_source(): void
        {
            $source = self::source();
            $items  = self::batchSlice((new Collector($source))->itemBatch(self::SLICE_A, '', self::transfer()))->items;
            $this->assertSame([true], array_values(array_unique(array_column(array_filter($source->log, static fn(array $l): bool => $l[0] === 'item'), 2))));

            $this->assertSame([
                'headline........' => [['value' => 'First', 'flag' => 1]],
                'number..........' => [['value' => '', 'flag' => 0]],        // NULL => '' (Model\Module)
                'category.......1' => self::v('news', 'home'),
                'password.......1' => self::v('$2y$10$hash'),                 // readers must log in after a transfer
                'secret..........' => self::v('JBSWY3DPEHPK3PXP'),            // and with their second factor
                'status_code.....' => self::v('1'),
                'post_date.......' => self::v('1700000000'),
                'publish_date....' => self::v('1700000000'),
                'expiry_date.....' => self::v('2000000000'),
                'highlight.......' => self::v('0'),
                'posted_by.......' => self::v('uid-17'),                      // as text - no user of the target
                'edited_by.......' => self::v('uid-18'),
                'last_edit.......' => self::v('1700000100'),
                'flags...........' => self::v('0'),
                'moved2active....' => self::v('0'),
            ], $items[0]['content']);   // no short_id, counters or feeding of the source AA
        }

        public function test_a_batch_is_a_package_the_reader_reads(): void
        {
            $result = (new Collector(self::source()))->itemBatch(self::SLICE_A, '', self::transfer());
            $xml    = (new Writer())->toXml($result->package)->xml;      // XSD-validated
            $read   = (new Reader())->read($xml);

            $this->assertSame([], $read->findings);
            $this->assertEquals($result->package->batch, $read->package->batch);
            $this->assertSame([[Module::SLICE, self::SLICE_A, []]], array_map(static fn(Module $m): array => [$m->type, $m->id, $m->rows], $read->package->modules));
            $this->assertSame(array_column(self::batchSlice($result)->items, 'id'), array_column($read->package->modules[0]->items, 'id'));
            $this->assertSame('JBSWY3DPEHPK3PXP', $read->package->modules[0]->items[0]['content']['secret..........'][0]['value']);
        }

        public function test_item_batches_belong_to_a_transfer(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new Collector(self::source()))->itemBatch(self::SLICE_A, '', new CollectOptions(self::SOURCE));
        }

        /** @return array<string, array{string, string}> */
        public static function wrongBatches(): array
        {
            return [
                'a site'           => [self::SITE, ''],
                'a deleted slice'  => [self::DELETED, ''],
                'no such module'   => [self::GONE, ''],
                'a cursor of 31'   => [self::SLICE_A, substr(self::ITEM, 1)],
                'a cursor of text' => [self::SLICE_A, "' OR 1=1 --                     "],
            ];
        }

        #[DataProvider('wrongBatches')]
        public function test_a_wrong_batch_is_rejected(string $slice, string $after): void
        {
            try {
                (new Collector(self::source()))->itemBatch($slice, $after, self::transfer());
                $this->fail('no exception');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
                if ($after !== '') {
                    $this->assertStringNotContainsString($after, $e->getMessage());     // no input echoed
                }
            }
        }

        /** @return array<string, array{string}> */
        public static function published(): array
        {
            return ['users' => ['users'], 'all' => ['all']];
        }

        #[DataProvider('published')]
        public function test_a_transfer_is_never_published(string $publish): void
        {
            $this->assertSame('no', (new CollectOptions(self::SOURCE, mode: CollectOptions::TRANSFER, publish: 'no'))->publish);
            $this->expectException(InvalidArgumentException::class);
            new CollectOptions(self::SOURCE, mode: CollectOptions::TRANSFER, publish: $publish);
        }

        // --- transfer: state of the installation ---------------------------------------------------

        public function test_a_transfer_carries_the_owner_and_the_reading_password(): void
        {
            $result = (new Collector(self::source()))->fromSite(self::SITE, self::transfer());
            $owner  = ['owner' => ['name' => 'Ekologický institut', 'email' => 'office@source.example']];

            $this->assertSame($owner, self::moduleOf($result, self::SITE)->transfer);
            $this->assertSame($owner + ['reading-password' => 'secret'], self::moduleOf($result, self::SLICE_A)->transfer);
            $this->assertSame($owner, self::moduleOf($result, self::ALERTS)->transfer);
            $this->assertArrayNotHasKey('reading_password', self::moduleOf($result, self::SLICE_A)->rows['slice'][0]);
            $this->assertArrayNotHasKey('owner', self::moduleOf($result, self::SLICE_A)->rows['module'][0]);

            $read = (new Reader())->read((new Writer())->toXml($result->package)->xml);     // XSD-validated both ways
            $this->assertSame([], $read->findings);
            $this->assertSame($owner + ['reading-password' => 'secret'], self::moduleOf(new CollectResult($read->package), self::SLICE_A)->transfer);
        }

        /** the settings fields' values are the content of the slice id (admin/slicefieldsedit.php) */
        public function test_a_transfer_carries_the_values_of_the_slice_settings_fields(): void
        {
            $source                   = self::source();
            $source->tables['content'] = [
                ['item_id' => self::SLICE_A, 'field_id' => '_category.......', 'number' => 1, 'text' => 'red', 'flag' => 64],
                ['item_id' => self::SLICE_A, 'field_id' => '_category.......', 'number' => 0, 'text' => 'blue', 'flag' => 64],
                ['item_id' => self::SLICE_A, 'field_id' => '_count..........', 'number' => 5, 'text' => null, 'flag' => 0],
                ['item_id' => self::SLICE_A, 'field_id' => 'headline........', 'number' => 0, 'text' => 'no setting', 'flag' => 64],
                ['item_id' => self::ITEM2, 'field_id' => '_category.......', 'number' => 0, 'text' => 'of an item', 'flag' => 64],
            ];
            $values = ['_category.......' => [['value' => 'blue', 'flag' => 64], ['value' => 'red', 'flag' => 64]], '_count..........' => [['value' => '5', 'flag' => 0]]];

            $result = (new Collector($source))->fromModules([self::SLICE_A], self::transfer());

            $this->assertSame($values, self::moduleOf($result, self::SLICE_A)->transfer['setting-values']);
            $read = (new Reader())->read((new Writer())->toXml($result->package)->xml);
            $this->assertSame($values, self::moduleOf(new CollectResult($read->package), self::SLICE_A)->transfer['setting-values']);
            $this->assertArrayNotHasKey('setting-values', self::moduleOf((new Collector($source))->fromModules([self::SLICE_A], new CollectOptions(self::SOURCE)), self::SLICE_A)->transfer, 'an install: defaults and "fill settings"');
        }

        /** keyed as GetItemContent() keys them: a translation by its number, a duplicate of the first text left out;
         *  their texts are templates - linted as any definition */
        public function test_setting_values_are_keyed_as_get_item_content_keys_them(): void
        {
            $source                   = self::source();
            $source->tables['content'] = [
                ['item_id' => self::SLICE_A, 'field_id' => '_title..........', 'number' => 1000002, 'text' => 'Titre {view:20}', 'flag' => 64],
                ['item_id' => self::SLICE_A, 'field_id' => '_title..........', 'number' => 0, 'text' => 'Title', 'flag' => 0],
                ['item_id' => self::SLICE_A, 'field_id' => '_title..........', 'number' => 3, 'text' => 'Title', 'flag' => 64],
                ['item_id' => self::SLICE_A, 'field_id' => '_count..........', 'number' => 7, 'text' => '', 'flag' => 64 | 1],
                ['item_id' => self::SLICE_A, 'field_id' => '_count..........', 'number' => 8, 'text' => '', 'flag' => 1],
            ];

            $result = (new Collector($source))->fromModules([self::SLICE_A], self::transfer());

            $this->assertSame([
                '_count..........' => [['value' => '', 'flag' => 64 | 1], ['value' => '8', 'flag' => 1]],
                '_title..........' => [0 => ['value' => 'Title', 'flag' => 64], 1000002 => ['value' => 'Titre {view:20}', 'flag' => 64]],
            ], self::moduleOf($result, self::SLICE_A)->transfer['setting-values']);
            $at = 'slice-module[' . self::SLICE_A . ']/setting-values/_title..........[1000002]';
            $this->assertNotSame([], array_filter(self::findings($result), static fn(string $f): bool => str_ends_with($f, "@ $at")), 'a view outside the app');
        }

        public function test_an_owner_of_another_charset_is_converted_and_a_missing_one_left_out(): void
        {
            $source                                   = self::source();
            $source->tables['slice_owner'][0]['name'] = "\x8Akola";
            $source->tables['module'][0]['owner']     = '';           // the site has none
            $result                                   = (new Collector($source))->fromModules([self::SLICE_W], self::transfer());

            $this->assertSame(['owner' => ['name' => 'Škola', 'email' => 'office@source.example'], 'reading-password' => 'secret'], self::moduleOf($result, self::SLICE_W)->transfer);
            $this->assertSame([], self::moduleOf((new Collector($source))->fromSite(self::SITE, self::transfer()), self::SITE)->transfer);
        }

        public function test_an_install_carries_no_state_of_the_installation(): void
        {
            $result = (new Collector(self::source()))->fromSite(self::SITE, new CollectOptions(self::SOURCE));

            foreach ($result->package->modules as $module) {
                $this->assertSame([], $module->transfer, $module->id);
            }
            $xpath = new DOMXPath(self::xml($result));
            $xpath->registerNamespace('a', Schema::NS);
            $this->assertSame(0, $xpath->query('//a:owner|//a:reading-password|//a:alternate-url|//a:reading_password|//a:item')->length);
        }

        // --- readers' login names ------------------------------------------------------------------

        private static function readers(): FakeSource
        {
            $source                             = self::source();
            $source->tables['slice'][0]['type'] = 'ReaderManagement';
            $source->items[self::SLICE_A][]     = self::item('4123456789abcdef0123456789abcdef', self::SLICE_A, '1', ['headline........' => self::v('first')]);
            return $source;
        }

        public function test_readerLogins_are_the_login_names_only(): void
        {
            $collector = new Collector(self::readers());

            $this->assertSame([['First', 'Third', 'first'], []], $collector->readerLogins([self::SLICE_A], false));
            $this->assertSame([['First', 'Third', 'Trashed', 'first'], []], $collector->readerLogins([self::SLICE_A], true));
        }

        public function test_readerLogins_of_another_charset_are_utf8(): void
        {
            $source                             = self::readers();
            $source->tables['slice'][2]['type'] = 'ReaderManagement';

            $source->items[self::SLICE_W][] = self::item('5123456789abcdef0123456789abcdef', self::SLICE_W, '1', ['headline........' => self::v("x\x81")]);

            [$logins, $findings] = (new Collector($source))->readerLogins([self::SLICE_W], false);
            $this->assertSame(['Česko'], $logins);                   // valid UTF-8 only
            $this->assertCount(1, $findings);                        // the other one is no silent loss
            $this->assertSame(['slice-module[' . self::SLICE_W . ']/item', LintFinding::ERROR, Collector::LOGIN_CHARSET, '1'], [$findings[0][0], $findings[0][1]->level, $findings[0][1]->code, $findings[0][1]->ref]);
        }

        /** @return array<string, array{string}> */
        public static function noReaderSlices(): array
        {
            return ['a slice of articles' => [self::SLICE_B], 'a site' => [self::SITE], 'a deleted module' => [self::DELETED], 'no module' => [self::GONE]];
        }

        #[DataProvider('noReaderSlices')]
        public function test_readerLogins_refuse_other_modules(string $id): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new Collector(self::readers()))->readerLogins([self::SLICE_A, $id], false);
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
