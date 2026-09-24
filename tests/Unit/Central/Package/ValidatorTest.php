<?php

declare(strict_types=1);

/**
 * Validator and SecurityLint, C4.4 part 2 (design balicek-v1.md § 3.1 step 9, § 3.3, § 6): the written
 * example package read by Reader passes; each mutation of it gives exactly the expected findings.
 */

namespace {
    // AA_Module_Site::stateFileName() pulls include/constants.php; getClassProperties() of the object
    // classes needs include/util.php, whose chain reaches include/locsess.php (as CollectorObjectsTest)
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

    use AA\Central\Package\FindingList;
    use AA\Central\Package\LintFinding;
    use AA\Central\Package\Model\ConstantGroup;
    use AA\Central\Package\Model\Module;
    use AA\Central\Package\Model\Package;
    use AA\Central\Package\Model\RawBytes;
    use AA\Central\Package\Reader;
    use AA\Central\Package\Schema;
    use AA\Central\Package\SecurityLint;
    use AA\Central\Package\ValidationResult;
    use AA\Central\Package\Validator;
    use AA_Module_Site;
    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ValidatorTest extends TestCase
    {
        private const SITE   = '1a2b3c4d5e6f708192a3b4c5d6e7f801';
        private const SLICE  = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
        private const ALERTS = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
        private const ALIAS  = '99aa88bb77cc66dd55ee44ff33aa22bb';
        private const SET    = 'cc11bb22cc33dd44ee55ff6677889900';
        private const C2     = 'bb11bb22cc33dd44ee55ff6677889900';
        private const OTHER  = 'ffffffffffffffffffffffffffffffff';   // no module of the package

        private const W = 'site-module[' . self::SITE . ']';
        private const S = 'slice-module[' . self::SLICE . ']';
        private const A = 'alerts-module[' . self::ALERTS . ']';
        private const G = 'constant-group[ct_rubriky]';

        private const ALERTS2 = '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b';
        private const A2      = 'alerts-module[' . self::ALERTS2 . ']';

        /** what the example gives: a site without perm_mode */
        private const BASELINE = ['W perm-mode ' . self::W . '/settings/perm_mode'];

        private static ?Package $example = null;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../../include/util.php';
            require_once __DIR__ . '/../../../../modules/site/sitetree.php';
            class_exists(AA_Module_Site::class);
        }

        private static function example(): Package
        {
            return self::$example ??= (new Reader())->read((string) file_get_contents(__DIR__ . '/fixtures/aa-app-example.written.xml'))->package;
        }

        /** @return list<string> "<level> <code> <path>", sorted */
        private static function findings(Package $package): array
        {
            $list = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", (new Validator())->validate($package)->findings);
            sort($list);
            return $list;
        }

        // ---------------------------------------------------------------- mutation helpers

        /** a readonly model object with some constructor arguments changed */
        private static function with(object $o, array $changes): object
        {
            return new ($o::class)(...array_merge(get_object_vars($o), $changes));
        }

        /** @param Closure(Module): Module $fn */
        private static function module(Package $p, string $id, Closure $fn): Package
        {
            return self::with($p, ['modules' => array_map(static fn(Module $m): Module => ($m->id === $id) ? $fn($m) : $m, $p->modules)]);
        }

        /** @param Closure(list<array<string, mixed>>): list<array<string, mixed>> $fn */
        private static function rows(Package $p, string $id, string $element, Closure $fn): Package
        {
            return self::module($p, $id, static fn(Module $m): Module => self::with($m, ['rows' => array_merge($m->rows, [$element => $fn($m->rows[$element] ?? [])])]));
        }

        /** the $i-th row of an element with some values changed */
        private static function cell(Package $p, string $id, string $element, int $i, array $values): Package
        {
            return self::rows($p, $id, $element, static function (array $rows) use ($i, $values): array {
                $rows[$i] = $values + $rows[$i];
                return $rows;
            });
        }

        /** @param Closure(array<string, mixed>): array<string, mixed> $fn on the canonical spot tree */
        private static function tree(Package $p, Closure $fn): Package
        {
            return self::module($p, self::SITE, static fn(Module $m): Module => self::with($m, ['spotTree' => $fn($m->spotTree)]));
        }

        private static function setting(Package $p, string $id, string $name, mixed $value): Package
        {
            return self::module($p, $id, static fn(Module $m): Module => self::with($m, ['settings' => [$name => $value] + ($m->settings ?? [])]));
        }

        private static function object(Package $p, string $id, int $k, Closure $fn): Package
        {
            return self::module($p, $id, static function (Module $m) use ($k, $fn): Module {
                $objects     = $m->objects;
                $objects[$k] = $fn($objects[$k]);
                return self::with($m, ['objects' => $objects]);
            });
        }

        private static function items(Package $p, array $items, bool $flag = true): Package
        {
            return self::with(self::module($p, self::SLICE, static fn(Module $m): Module => self::with($m, ['items' => $items])), ['withItems' => $flag]);
        }

        private static function item(string $id, string $value = 'x'): array
        {
            return ['id' => $id, 'content' => ['headline........' => [0 => ['value' => $value, 'flag' => 0]]]];
        }

        // ---------------------------------------------------------------- tests

        public function test_the_example_is_importable(): void
        {
            $result = (new Validator())->validate(self::example());
            self::assertTrue($result->importable());
            $expected = self::BASELINE;
            sort($expected);
            self::assertSame($expected, self::findings(self::example()));
        }

        /** @param Closure(Package): Package $mutate @param list<string> $expected findings added to BASELINE */
        #[DataProvider('mutations')]
        public function test_mutation(Closure $mutate, array $expected): void
        {
            $found = self::findings($mutate(self::example()));
            sort($expected);
            self::assertSame($expected, array_values(array_diff($found, self::BASELINE)));
        }

        /** @return array<string, array{Closure(Package): Package, list<string>}> */
        public static function mutations(): array
        {
            $W = self::W;
            $S = self::S;
            $A = self::A;
            $G  = self::G;
            $A2 = self::A2;
            return [
                // ---- structure: the rows of a module type, the tables it may have
                'slice row missing'          => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'slice', static fn(): array => []), ["E module-rows $S/slice"]],
                'site row missing'           => [static fn(Package $p): Package => self::rows($p, self::SITE, 'site', static fn(): array => []), ["E module-rows $W/site"]],
                'two module rows'            => [static fn(Package $p): Package => self::rows($p, self::SITE, 'module', static fn(array $r): array => [$r[0], $r[0]]), ["E module-rows $W/module"]],
                'no alerts collection'       => [static fn(Package $p): Package => self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['rows' => array_diff_key($m->rows, array_flip(['alerts_collection', 'alerts_collection_filter', 'alerts_collection_howoften']))])), ["W module-rows $A/alerts_collection"]],
                'two constant_slice rows'    => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [new ConstantGroup('ct_rubriky', self::SLICE, ['constant_slice' => [[], []]] + $p->constantGroups[0]->rows)]]), ["E module-rows $G/constant_slice"]],
                'site_spot in a slice'       => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'site_spot', static fn(): array => [['spot_id' => '1', 'content' => 'x']]), ["E table-not-allowed $S/site_spot"]],
                'view in a constant group'   => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [new ConstantGroup('ct_rubriky', self::SLICE, ['view' => [['id' => '99']]] + $p->constantGroups[0]->rows)]]), ["E table-not-allowed $G/view"]],
                'spot tree in a slice'       => [static fn(Package $p): Package => self::module($p, self::SLICE, static fn(Module $m): Module => self::with($m, ['spotTree' => ['start_id' => '1', 'spots' => []]])), ["E table-not-allowed $S/spot-tree"]],
                'settings of alerts'         => [static fn(Package $p): Package => self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['settings' => ['x' => '1']])), ["E table-not-allowed $A/settings"]],
                'items of alerts'            => [static fn(Package $p): Package => self::with(self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['items' => [self::item(self::OTHER)]])), ['withItems' => true]), ["E table-not-allowed $A/item"]],
                // ---- ids: shape and uniqueness (long ids share one map, § 7.1)
                'upper case module id'       => [static fn(Package $p): Package => self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['id' => strtoupper(self::ALERTS)])), ['E invalid-id alerts-module[' . strtoupper(self::ALERTS) . ']']],
                'field id not 16 chars'      => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 0, ['id' => 'headline']), ["E invalid-id $S/field[headline]"]],
                'e-mail id 0-padded'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['id' => '05']), ["E invalid-id $S/email[05]"]],
                'spot id no number'          => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site_spot', 1, ['spot_id' => '2a']), ["E invalid-id $W/site_spot[2a]", "W spot-unused $W/site_spot[2a]"]],
                'collection id of 4'         => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['id' => 'AbC1']), ["E invalid-id $A/alerts_collection[AbC1]", "E dangling-ref $A/alerts_collection_filter[AbC12,3,0]", "E dangling-ref $A/alerts_collection_howoften[AbC12,daily]"]],
                'field twice'                => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'field', static fn(array $r): array => [...$r, $r[2]]), ["E duplicate-id $S/field[headline........]"]],
                'view twice'                 => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'view', static fn(array $r): array => [...$r, $r[0]]), ["E duplicate-id $S/view[12]"]],
                'e-mail id of another module' => [static fn(Package $p): Package => self::rows($p, self::ALERTS, 'email', static fn(array $r): array => [...$r, ['id' => '5'] + $r[0]]), ["E duplicate-id $A/email[5]"]],
                'alerts filter twice'        => [static fn(Package $p): Package => self::rows($p, self::ALERTS, 'alerts_filter', static fn(array $r): array => [...$r, $r[0]]), ["E duplicate-id $A/alerts_filter[3]"]],
                'filter shared, same rows'   => [static fn(Package $p): Package => self::alerts2($p, []), []],
                'filter shared, other rows'  => [static fn(Package $p): Package => self::alerts2($p, ['description' => 'Jiný']), ["E duplicate-id $A2/alerts_filter[3]"]],
                'site spot twice'            => [static fn(Package $p): Package => self::rows($p, self::SITE, 'site_spot', static fn(array $r): array => [...$r, $r[1]]), ["E duplicate-id $W/site_spot[2]"]],
                'uses twice'                 => [static fn(Package $p): Package => self::rows($p, self::SITE, 'uses', static fn(array $r): array => [...$r, $r[0]]), ["E duplicate-id $W/uses[" . self::SLICE . ']']],
                'group twice'                => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [...$p->constantGroups, new ConstantGroup('ct_rubriky')]]), ["E duplicate-id $G"]],
                'constant id = object id'    => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [self::constants($p, 1, ['id' => self::SET, 'ancestors' => ''])]]), ["E duplicate-id $G/constant[" . self::SET . ']']],
                'subobject id = module id'   => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                    $o['props']['sort'][0]['id'] = self::SITE;
                    return $o;
                }), ["E duplicate-id $S/object[" . self::SET . ']/sort[0]']],
                'object id = settings id'    => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => ['id' => string2id('AA_Modulesettings_Slice' . self::SLICE)] + $o), ['E duplicate-id ' . self::S . '/settings']],
                'group name with a space'    => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [...$p->constantGroups, new ConstantGroup('grp ', self::SLICE)]]), ['E group-name constant-group[grp ]']],
                'group name, leading space'  => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [...$p->constantGroups, new ConstantGroup(' grp', self::SLICE)]]), ['E group-name constant-group[ grp]']],
                'item id = module id'        => [static fn(Package $p): Package => self::items($p, [self::item(self::SLICE)]), ["E duplicate-id $S/item[" . self::SLICE . ']']],
                // ---- references inside the package
                'uses outside'               => [static fn(Package $p): Package => self::cell($p, self::SITE, 'uses', 0, ['destination_id' => self::OTHER]), ["E dangling-ref $W/uses[" . self::OTHER . ']']],
                'group owner is a site'      => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [new ConstantGroup('ct_rubriky', self::SITE, $p->constantGroups[0]->rows)]]), ["E dangling-ref $G@owner"]],
                'reader slice outside'       => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['slice_id' => self::SITE]), ["E reader-outside $A/alerts_collection[AbC12]/slice_id"]],
                'collection filter unknown'  => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection_filter', 0, ['filterid' => '4']), ["E dangling-ref $A/alerts_collection_filter[AbC12,4,0]"]],
                'howoften of no collection'  => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection_howoften', 0, ['collectionid' => 'XyZ98']), ["E dangling-ref $A/alerts_collection_howoften[XyZ98,daily]"]],
                'ancestor of another group'  => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [self::constants($p, 1, ['ancestors' => self::SET])]]), ["W dangling-ref $G/constant[" . self::C2 . ']/ancestors']],
                // ---- objects: allowlisted classes, their properties, subobjects where the property holds them
                'class off the allowlist'    => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => ['class' => 'AA_Form'] + $o), ["E object $W/object[" . self::ALIAS . ']']],
                'unknown property'           => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => ['props' => ['evil' => 'x'] + $o['props']] + $o), ["E object $W/object[" . self::ALIAS . ']/evil']],
                'subobject of another class' => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                    $o['props']['conds'][0]['class'] = 'AA_Sortorder';
                    return $o;
                }), ["E object $S/object[" . self::SET . ']/conds[0]']],
                'scalar in object property'  => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                    $o['props']['sort'] = self::C2;
                    return $o;
                }), ["E object $S/object[" . self::SET . ']/sort[0]']],
                'unknown setting'            => [static fn(Package $p): Package => self::setting($p, self::SLICE, 'foo', '1'), ["E object $S/settings/foo"]],
                'object props no array'      => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => ['props' => 'x'] + $o), ["E object $W/object", "E object $W/object[" . self::ALIAS . ']/0']],
                'dropped site settings'      => [static fn(Package $p): Package => self::setting(self::setting(self::setting($p, self::SITE, 'ga_id', 'G-1'), self::SITE, 'alternate_url', 'x.cz'), self::SITE, 'app_version', '1'), ["W settings-dropped $W/settings/alternate_url", "W settings-dropped $W/settings/app_version", "W settings-dropped $W/settings/ga_id"]],
                // ---- site tree
                'spot not an array'          => [static fn(Package $p): Package => self::tree($p, static fn(array $t): array => ['spots' => ['x']] + $t), ["E spot-tree $W/spot-tree"]],
                'spot without parent'        => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][1]['p'] = '9';
                    return $t;
                }), ["E spot-tree $W/spot-tree"]],
                'content of no spot'         => [static fn(Package $p): Package => self::rows($p, self::SITE, 'site_spot', static fn(array $r): array => [...$r, ['spot_id' => '7', 'content' => 'x']]), ["W spot-unused $W/site_spot[7]"]],
                'spot name with markup'      => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][1]['n'] = '<img src=x onerror=alert(1)>';
                    return $t;
                }), ["W spot-markup $W/spot-tree/spot[2]@name"]],
                'spot variable with markup'  => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][0]['v'] = ['<b>' => '<b>'];
                    $t['spots'][2]['c'] = ['<b>' => 'rubrika'];
                    return $t;
                }), ["W spot-markup $W/spot-tree/spot[1]/var[#0]", "W spot-markup $W/spot-tree/spot[3]/cond[#0]@var"]],
                'spot condition comparison'  => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][2]['c'] = ['x' => '{if:{x}:<:3:a:b} a < b'];
                    return $t;
                }), []],
                'spot condition with markup' => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][2]['c'] = ['x' => '<script>'];
                    return $t;
                }), ["W spot-markup $W/spot-tree/spot[3]/cond[#0]"]],
                // ---- structured references leaving the package (§ 8.4 W)
                'slice.vid outside'          => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['vid' => '99']), ["W view-outside $S/slice[#0]/vid"]],
                'input_view outside'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'profile', 0, ['value' => '99']), ["W view-outside $S/profile[#0]/value"]],
                'filter view outside'        => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_filter', 0, ['vid' => '99']), ["W view-outside $A/alerts_filter[3]/vid"]],
                'welcome e-mail outside'     => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_alert' => '8']), ["W email-outside $A/alerts_collection[AbC12]/emailid_alert"]],
                'mlxctrl outside'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['mlxctrl' => self::OTHER]), ["W module-outside $S/slice[#0]/mlxctrl"]],
                'translate_slice outside'    => [static fn(Package $p): Package => self::setting($p, self::SITE, 'translate_slice', self::OTHER), ["W module-outside $W/settings/translate_slice[0]"]],
                // ---- values
                'lang_file .php3'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['lang_file' => 'cz-utf8_news_lang.php3']), []],
                'lang_file of another type'  => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['lang_file' => 'cz-utf8_site_lang.php']), ["E lang-file $S/module[#0]/lang_file"]],
                'lang_file unknown'          => [static fn(Package $p): Package => self::cell($p, self::SITE, 'module', 0, ['lang_file' => '../../x_site_lang.php']), ["E lang-file $W/module[#0]/lang_file"]],
                'lang_file empty'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['lang_file' => '']), ["E lang-file $S/module[#0]/lang_file"]],
                'slice.lang_file of a site'  => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['lang_file' => 'cz-utf8_site_lang.php']), ["W lang-file-replaced $S/slice[#0]/lang_file"]],
                'slice.lang_file .php3'      => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['lang_file' => 'cz-utf8_news_lang.php3']), []],
                'group name of 17 bytes'     => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [...$p->constantGroups, new ConstantGroup('čččččččččč')]]), ['E group-name constant-group[čččččččččč]']],
                'group name with colon'      => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [...$p->constantGroups, new ConstantGroup('a:b')]]), ['E group-name constant-group[a:b]']],
                'core group in the package'  => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [...$p->constantGroups, new ConstantGroup('lt_languages')]]), ['E group-name constant-group[lt_languages]']],
                'widget group missing'       => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 1, ['input_show_func' => 'sel:ct_jine:']), ["W group-missing $S/field[category.......1]/input_show_func"]],
                'widget core group'          => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 1, ['input_show_func' => 'sel:lt_languages:']), []],
                'widget relation'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 1, ['input_show_func' => 'mse:#sLiCe-' . self::SLICE . ':']), []],
                'control file'               => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site', 0, ['flag' => '0', 'state_file' => 'apc.php']), ["E state-file $W/site[#0]/state_file"]],
                'control file of flag 1'     => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site', 0, ['state_file' => 'apc.php']), []],
                'home path without router'   => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site', 0, ['flag' => '0']), []],
                'int out of range'           => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['input_pri' => '70000']), ["E int-range $S/field[headline........]/input_pri"]],
                'negative unsigned'          => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 1, ['listlen' => '-1']), ["E int-range $S/view[13]/listlen"]],
                'tinyint'                    => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [new ConstantGroup('ct_rubriky', self::SLICE, ['constant_slice' => [['hierarch' => '128']]] + $p->constantGroups[0]->rows)]]), ["E int-range $G/constant_slice[#0]/hierarch"]],
                'profile property unknown'   => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'profile', static fn(array $r): array => [...$r, ['property' => 'owner', 'selector' => '', 'value' => '1']]), ["W profile-property $S/profile[#1]/property"]],
                'anonymous posting'          => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['permit_anonymous_post' => '1', 'permit_anonymous_edit' => '3']), ["W permission $S/slice[#0]/permit_anonymous_edit", "W permission $S/slice[#0]/permit_anonymous_post"]],
                'permissions NULL'           => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['permit_anonymous_post' => null, 'permit_anonymous_edit' => null, 'fileman_access' => null]), []],
                'fileman and auth'           => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['fileman_access' => 'EDITOR', 'auth_field_group' => 'headline........']), ["W permission $S/slice[#0]/auth_field_group", "W permission $S/slice[#0]/fileman_access"]],
                'item pseudo field'          => [static fn(Package $p): Package => self::items($p, [['id' => self::OTHER, 'content' => ['posted_by.......' => [0 => ['value' => 'uid', 'flag' => 0]], 'u_slice_id......' => [0 => ['value' => self::SLICE, 'flag' => 0]]]]]), ["E item-key $S/item[" . self::OTHER . ']/posted_by.......', "E item-key $S/item[" . self::OTHER . ']/u_slice_id......']],
                'item of no field'           => [static fn(Package $p): Package => self::items($p, [['id' => self::OTHER, 'content' => ['nofield........1' => [0 => ['value' => 'x', 'flag' => 0]], 'status_code.....' => [0 => ['value' => '1', 'flag' => 0]]]]]), ["W item-field $S/item[" . self::OTHER . ']/nofield........1']],
                // ---- items (§ 4, Q-H1 decision: only publish = all forbids them)
                'items without the flag'     => [static fn(Package $p): Package => self::items($p, [self::item(self::OTHER)], false), ["E items $S/item"]],
                'items of a published app'   => [static fn(Package $p): Package => self::with(self::items($p, [self::item(self::OTHER)]), ['publish' => 'all']), ['E items-published meta/publish']],
                'item flag, publish all'     => [static fn(Package $p): Package => self::with($p, ['withItems' => true, 'publish' => 'all']), ['E items-published meta/publish']],
                'items, publish users'       => [static fn(Package $p): Package => self::with(self::items($p, [self::item(self::OTHER)]), ['publish' => 'users']), []],
                'publish all, no items'      => [static fn(Package $p): Package => self::with($p, ['publish' => 'all']), []],
                // ---- security lint (§ 3.3): every text of the package
                'include in a view'          => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['odd' => '{include:../../etc/passwd:readfile}']), ["S include $S/view[12]/odd"]],
                'server in a spot'           => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site_spot', 0, ['content' => 'a {server:DB_PASSWORD} b']), ["S server $W/site_spot[1]/content"]],
                'credentials in an alias'    => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => ['props' => ['code' => '{credentials:x}'] + $o['props']] + $o), ["S credentials $W/object[" . self::ALIAS . ']/code[0]']],
                'slice_pwd in an e-mail'     => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['body' => '{view.php?vid=12&slice_pwd=abc}']), ["S slice-pwd $S/email[5]/body"]],
                'router home is code'        => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site', 0, ['state_file' => '{include:http://intranet/}']), ["S include $W/site[#0]/state_file"]],
                'spot condition is code'     => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][2]['c'] = ['{server:HTTP_HOST}' => 'x'];
                    return $t;
                }), ["S server $W/spot-tree/spot[3]/cond[#0]@var"]],
                'page404_code'               => [static fn(Package $p): Package => self::setting($p, self::SITE, 'page404_code', '{file2text:http://x/a.pdf}'), ["S file2text $W/settings/page404_code[0]"]],
                'subobject value'            => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                    $o['props']['conds'][0]['props']['value'] = '{server:REMOTE_ADDR}';
                    return $o;
                }), ["S server $S/object[" . self::SET . ']/conds[0]/value[0]']],
                'constant'                   => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [self::constants($p, 0, ['description' => '{include:x}'])]]), ["S include $G/constant[aa11bb22cc33dd44ee55ff6677889900]/description"]],
                'item value'                 => [static fn(Package $p): Package => self::items($p, [self::item(self::OTHER, '{server:SERVER_ADDR}')]), ["S server $S/item[" . self::OTHER . ']/headline........[0]']],
                'raw bytes are code too'     => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['odd' => new RawBytes("\xE9{server:X}")]), ["S server $S/view[12]/odd"]],
                'attachment URLs'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => 'https#://a.org/x.pdf:ftp#://b.org/y.png']), ["S email-attachments $S/email[5]/attachments"]],
                'attachment JSON'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '{"a.pdf":"https://a.org/a.pdf"}']), ["S email-attachments $S/email[5]/attachments"]],
                'attachment of a field'      => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '{file............}']), ["S email-attachments $S/email[5]/attachments"]],
                'attachment of an alias'     => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '["_#FILE_URL", "{file...........1}"]']), ["S email-attachments $S/email[5]/attachments"]],
                'attachment local file'      => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '/var/www/aa/include/config.php']), ["W email-attachments-dropped $S/email[5]/attachments"]],
                'attachment file: in a list' => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '["https://a.org/a.pdf","{file............}","file:///etc/passwd"]']), ["W email-attachments-dropped $S/email[5]/attachments"]],
                'attachment computed'        => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '{concat:/etc:/passwd}']), ["W email-attachments-dropped $S/email[5]/attachments"]],
                'attachment computed in list' => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '/x.pdf:{file............}x']), ["W email-attachments-dropped $S/email[5]/attachments"]],
                'attachment include'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '{include:http://x/}']), ["S include $S/email[5]/attachments", "W email-attachments-dropped $S/email[5]/attachments"]],
                // ---- alias functions: f_u calls a PHP callable, f_z fetches like {fileinfo}
                'f_u in a field'             => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias1_func' => 'f_u:phpinfo']), ["S f_u $S/field[headline........]/alias1_func"]],
                'f_z in a field'             => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias2_func' => ' F_Z:size']), ["S f_z $S/field[headline........]/alias2_func"]],
            ];
        }

        /** a second alerts module sharing filter 3 (with $filter changed) */
        private static function alerts2(Package $p, array $filter): Package
        {
            $rows = [
                'module'                     => [['name' => 'Druhé', 'lang_file' => 'cz-utf8_alerts_lang.php', 'flag' => '0']],
                'alerts_collection'          => [['id' => 'XyZ98', 'emailid_welcome' => '0', 'emailid_alert' => '0', 'slice_id' => self::SLICE]],
                'alerts_collection_filter'   => [['collectionid' => 'XyZ98', 'filterid' => '3', 'myindex' => '0']],
                'alerts_collection_howoften' => [['collectionid' => 'XyZ98', 'howoften' => 'daily']],
                'alerts_filter'              => [$filter + self::alertsModule($p)->rows['alerts_filter'][0]],
            ];
            return self::with($p, ['modules' => [...$p->modules, new Module(Module::ALERTS, self::ALERTS2, $rows)]]);
        }

        private static function alertsModule(Package $p): Module
        {
            return array_values(array_filter($p->modules, static fn(Module $m): bool => $m->id === self::ALERTS))[0];
        }

        /** the one group of the example with constant $k changed */
        private static function constants(Package $p, int $k, array $values): ConstantGroup
        {
            $group                     = $p->constantGroups[0];
            $rows                      = $group->rows;
            $rows['constant'][$k]      = $values + $rows['constant'][$k];
            return new ConstantGroup($group->name, $group->owner, $rows);
        }

        public function test_perm_mode(): void
        {
            $mode = static fn(string $v): array => array_values(preg_grep('/perm-mode/', self::findings(self::setting(self::example(), self::SITE, 'perm_mode', $v))));
            self::assertSame([], $mode('1'));
            self::assertSame([], $mode('2'));
            self::assertSame(['W perm-mode ' . self::W . '/settings/perm_mode'], $mode('3'));
        }

        public function test_security_findings_need_a_confirmation_but_do_not_block(): void
        {
            $package = self::cell(self::example(), self::SLICE, 'view', 0, ['odd' => '{server:X}']);
            $result  = (new Validator())->validate($package);
            self::assertTrue($result->importable());
            self::assertSame(['{server:X}'], array_map(static fn(array $f): string => $f[1]->ref, $result->ofLevel(LintFinding::SECURITY)));
            self::assertFalse((new Validator())->validate(self::rows($package, self::SLICE, 'slice', static fn(): array => []))->importable());
        }

        public function test_findings_are_capped_per_code_and_sorted_by_danger(): void
        {
            $odd    = str_repeat('{img:x.jpg}', 1100) . '{include:/x}';
            $result = (new Validator())->validate(self::cell(self::example(), self::SLICE, 'view', 0, ['odd' => $odd]));
            $codes  = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code}", $result->findings);
            self::assertSame('S include', $codes[0], 'S first, whatever comes before it in the texts');
            self::assertCount(FindingList::MAX_PER_CODE + 1, array_keys($codes, 'I img', true));
            $findings = $result->findings;
            self::assertSame('+1050 more (img)', end($findings)[1]->ref);
            $levels = array_map(static fn(array $f): string => $f[1]->level, $result->findings);
            self::assertSame(['S', 'W', 'I'], array_values(array_unique($levels)));
        }

        /** permit_anonymous_* and fileman_access are nullable without a default: SQL NULL reads as null, which is no switch */
        public function test_permission_null_as_read(): void
        {
            $xml   = (string) file_get_contents(__DIR__ . '/fixtures/aa-app-example.written.xml');
            $xml   = str_replace('<type>S</type>', '<type>S</type><permit_anonymous_post null="true"/><permit_anonymous_edit null="true"/><fileman_access null="true"/>', $xml, $n);
            $read  = (new Reader())->read($xml)->package;
            $slice = array_values(array_filter($read->modules, static fn(Module $m): bool => $m->id === self::SLICE))[0];
            self::assertSame(1, $n);
            self::assertNull($slice->rows['slice'][0]['permit_anonymous_post']);
            self::assertSame(self::BASELINE, self::findings($read));
        }

        public function test_an_unreadable_object_is_no_exception_text(): void
        {
            $found = (new Validator())->validate(self::object(self::example(), self::SITE, 0, static fn(array $o): array => ['props' => 'x'] + $o))->ofLevel(LintFinding::ERROR);
            $refs  = array_column(array_map(static fn(array $f): array => [$f[0], $f[1]->ref], $found), 1, 0);
            self::assertSame('object cannot be read (TypeError)', $refs[self::W . '/object']);
        }

        public function test_result_levels(): void
        {
            $e      = new LintFinding(LintFinding::ERROR, 'x', '', 0);
            $w      = new LintFinding(LintFinding::WARNING, 'y', '', 0);
            $result = new ValidationResult([['a', $w], ['b', $e]]);
            self::assertFalse($result->importable());
            self::assertSame([['b', $e]], $result->ofLevel(LintFinding::ERROR));
            self::assertTrue((new ValidationResult([['a', $w]]))->importable());
        }

        /** @param list<string> $expected "<code> <ref> @<offset>" */
        #[DataProvider('texts')]
        public function test_security_lint_of_a_text(string $text, array $expected): void
        {
            $found = array_map(static fn(LintFinding $f): string => "$f->level $f->code $f->ref @$f->offset", (new SecurityLint(new Schema()))->lint($text));
            self::assertSame($expected, $found);
        }

        /** @return array<string, array{string, list<string>}> */
        public static function texts(): array
        {
            return [
                'plain'                => ['{view:12} {item:x:_#HEADLINE}', []],
                'include, any type'    => ['a{include:x.html}', ['S include {include:x.html} @1']],
                'include readfile'     => ['{include:../../etc/passwd:readfile}', ['S include {include:../../etc/passwd:readfile} @0']],
                'include upper case'   => ['{INCLUDE:http://10.0.0.1/}', ['S include {INCLUDE:http://10.0.0.1/} @0']],
                'include(…) form'      => ['{include(/x)}', ['S include {include(/x)} @0']],
                'computed argument'    => ['{include:{var:x}:site}', ['S include {include: @0']],
                'another command'      => ['{includes:x} {serverx:y}', []],
                'server'               => ['{server:REMOTE_ADDR}', ['S server {server:REMOTE_ADDR} @0']],
                'credentials'          => ['{Credentials:pwd:' . self::SLICE . '}', ['S credentials {Credentials:pwd:' . self::SLICE . '} @0']],
                'file2text'            => ['{file2text:/x.pdf}', ['S file2text {file2text:/x.pdf} @0']],
                'slice_pwd'            => ['<a href="view.php?vid=3&slice_pwd=tajne">', ['S slice-pwd slice_pwd=tajne @24']],
                'slice_pwd in a name'  => ['my_slice_pwd=1', []],
                'two, by offset'       => ['{server:A}{include:b}', ['S server {server:A} @0', 'S include {include:b} @10']],
                'user'                 => ['{user} {user:password}', ['I user {user} @0', 'S user {user:password} @7']],
                'user of a field'      => ['{user:headline........}{USER( password )}', ['I user {user:headline........} @0', 'S user {USER( password )} @23']],
                'user computed'        => ['{user:{qs:f}}{user:_#FIELD___}', ['S user {user: @0', 'S user {user:_#FIELD___} @13']],
                'img relative, field'  => ['{img:x.jpg:100}{img:{file............}:100}{img:_#FOTO____}', ['I img {img:x.jpg:100} @0', 'I img {img: @15', 'I img {img:_#FOTO____} @43']],
                'img remote'           => ['{img:http://10.0.0.1/a.jpg}{img://evil/x}{img:file#:///etc/passwd}', ['S img {img:http://10.0.0.1/a.jpg} @0', 'S img {img://evil/x} @27', 'S img {img:file#:///etc/passwd} @41']],
                'img computed'         => ['{img:{concat:a:b}}{img:x_#FOTO____}', ['S img {img: @0', 'S img {img:x_#FOTO____} @18']],
                'placeholders'         => ['{_#1:x}{_#P2(x)}{_#loop:x}{_#ALIAS___}{_#name:y}', ['S placeholder-command {_#1: @0', 'S placeholder-command {_#P2( @7', 'S placeholder-command {_#loop: @16', 'S placeholder-command {_#name: @38']],
                'json braces'          => ['["\\u007binclude:x\\u007D"]', ['S json-brace \\u007b @2', 'S json-brace \\u007D @17']],
                'reads and writes'     => ['{cookie:x}{xuser:y}{mail:a}{mailform:b}{mailtemplate:5}', ['S cookie {cookie:x} @0', 'S xuser {xuser:y} @10', 'S mail {mail:a} @19', 'S mailform {mailform:b} @27', 'S mailtemplate {mailtemplate:5} @39']],
                'remote fetches'       => ['{rss2html:u}{fileinfo:u:size}{filelink:u}{linkcheck:u}{img:u}', ['S rss2html {rss2html:u} @0', 'S fileinfo {fileinfo:u:size} @12', 'S filelink {filelink:u} @29', 'S linkcheck {linkcheck:u} @41', 'I img {img:u} @54']],
                'more commands'        => ['{datovka:x}{gethostbyaddr:x}{internal:x}{newitem:x}{updateitem:x}{ensureitem:x}{redirect:x}{header:x}{changepwdsendmail:x}',
                    ['S datovka {datovka:x} @0', 'S gethostbyaddr {gethostbyaddr:x} @11', 'S internal {internal:x} @28', 'S newitem {newitem:x} @40', 'S updateitem {updateitem:x} @51', 'S ensureitem {ensureitem:x} @65', 'S redirect {redirect:x} @79', 'S header {header:x} @91', 'S changepwdsendmail {changepwdsendmail:x} @101']],
                'added by review'      => ['{recompute:a}{userinfo:u}{jwtencode:k}{setsecret:u}', ['S recompute {recompute:a} @0', 'S userinfo {userinfo:u} @13', 'S jwtencode {jwtencode:k} @25', 'S setsecret {setsecret:u} @38']],
                'info only'            => ['{debug}{version}{serverload}', ['I debug {debug} @0', 'I version {version} @7', 'I serverload {serverload} @16']],
                'not these'            => ['{imgtext:x}{mails:x}{users:x}', []],
                'expand, dequote, ( )' => ['{expand:x}{dequote:y}{( {view:3} )}', ['S expand {expand:x} @0', 'S dequote {dequote:y} @10']],
                'composed name'        => ['{{ifeq:1:1:include}:x} {inc{x}:y}', ['S composed-command {{ @0', 'S composed-command {inc{ @23']],
                'quote map byte'       => ["a\x1Eb", ["S quote-map \x1E @1"]],
                'alias f_u, f_z'       => ['{alias:headline........:f_u:fn} {alias::F_Z:size} {alias:x:f_h}', ['S f_u {alias:headline........:f_u @0', 'S f_z {alias::F_Z @32']],
            ];
        }
    }
}
