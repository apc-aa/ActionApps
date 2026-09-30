<?php

declare(strict_types=1);

/**
 * Validator and SecurityLint: the example package read by Reader passes; each mutation of it gives
 * exactly the expected findings.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\FindingList;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Batch;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\Reader;
use AA\Central\Package\ReferenceLint;
use AA\Central\Package\Schema;
use AA\Central\Package\SecurityLint;
use AA\Central\Package\ValidationResult;
use AA\Central\Package\Validator;
use AA\Central\Package\Writer;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class ValidatorTest extends TestCase
{
    use PackageMutations;

    private const OTHER   = 'ffffffffffffffffffffffffffffffff';   // no module of the package
    private const A2      = 'alerts-module[' . self::ALERTS2 . ']';

    /** what the example gives: a site without perm_mode */
    private const BASELINE = ['W perm-mode ' . self::W . '/settings/perm_mode'];

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
    }

    /** @return list<string> "<level> <code> <path>", sorted */
    private static function findings(Package $package, bool $transfer = false): array
    {
        $list = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", (new Validator())->validate($package, $transfer)->findings);
        sort($list);
        return $list;
    }

    // ---------------------------------------------------------------- tests

    public function test_the_example_is_importable(): void
    {
        $result = (new Validator())->validate(self::written());
        self::assertTrue($result->importable());
        $expected = self::BASELINE;
        sort($expected);
        self::assertSame($expected, self::findings(self::written()));
    }

    /** @param Closure(Package): Package $mutate @param list<string> $expected findings added to BASELINE */
    #[DataProvider('mutations')]
    public function test_mutation(Closure $mutate, array $expected): void
    {
        $found = self::findings($mutate(self::written()), true);      // the checks of a transfer: items allowed
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
            'items of alerts'            => [static fn(Package $p): Package => self::with(self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['items' => [self::item(self::OTHER)]])), ['withItems' => true]), ["E table-not-allowed $A/item", 'E items meta/contents']],
            // ---- ids: shape and uniqueness (long ids share one map)
            'upper case module id'       => [static fn(Package $p): Package => self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['id' => strtoupper(self::ALERTS)])), ['E invalid-id alerts-module[' . strtoupper(self::ALERTS) . ']']],
            'field id not 16 chars'      => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 0, ['id' => 'headline']), ["E invalid-id $S/field[headline]"]],
            'e-mail id 0-padded'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['id' => '05']), ["E invalid-id $S/email[05]"]],
            'spot id no number'          => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site_spot', 1, ['spot_id' => '2a']), ["E invalid-id $W/site_spot[2a]", "W spot-unused $W/site_spot[2a]"]],
            'collection id of 4'         => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['id' => 'AbC1']), ["E invalid-id $A/alerts_collection[AbC1]", "E dangling-ref $A/alerts_collection_filter[AbC12,3,0]", "E dangling-ref $A/alerts_collection_howoften[AbC12,daily]"]],
            'field twice'                => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'field', static fn(array $r): array => [...$r, $r[2]]), ["E duplicate-id $S/field[headline........]"]],
            'view twice'                 => [static fn(Package $p): Package => self::rows($p, self::SLICE, 'view', static fn(array $r): array => [...$r, $r[0]]), ["E duplicate-id $S/view[12]"]],
            'e-mail id of another module' => [static fn(Package $p): Package => self::rows($p, self::ALERTS, 'email', static fn(array $r): array => [...$r, ['id' => '5'] + $r[0]]), ["E duplicate-id $A/email[5]"]],
            'alerts filter twice'        => [static fn(Package $p): Package => self::rows($p, self::ALERTS, 'alerts_filter', static fn(array $r): array => [...$r, $r[0]]), ["E duplicate-id $A/alerts_filter[3]"]],
            'filter shared, same rows'   => [static fn(Package $p): Package => self::sharedFilter($p, []), []],
            'filter shared, other rows'  => [static fn(Package $p): Package => self::sharedFilter($p, ['description' => 'Jiný']), ["E duplicate-id $A2/alerts_filter[3]"]],
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
            'item id = module id'        => [static fn(Package $p): Package => self::items($p, [self::item(self::SLICE)]), ["E duplicate-id $S/item[" . self::SLICE . ']', 'E items meta/contents']],
            // ---- references inside the package
            'uses outside'               => [static fn(Package $p): Package => self::cell($p, self::SITE, 'uses', 0, ['destination_id' => self::OTHER]), ["E dangling-ref $W/uses[" . self::OTHER . ']']],
            'group owner is a site'      => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [new ConstantGroup('ct_rubriky', self::SITE, $p->constantGroups[0]->rows)]]), ["E dangling-ref $G@owner"]],
            'reader slice outside'       => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['slice_id' => self::SITE]), ["E reader-outside $A/alerts_collection[AbC12]/slice_id"]],
            'reader slice not packaged'  => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['slice_id' => self::OTHER]), ["E reader-outside $A/alerts_collection[AbC12]/slice_id"]],
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
            // ---- structured references leaving the package (W); Alerts: E - the new collection would mail a
            //      view or template of the target to readers the package brings
            'slice.vid outside'          => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['vid' => '99']), ["W view-outside $S/slice[#0]/vid"]],
            'input_view outside'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'profile', 0, ['value' => '99']), ["W view-outside $S/profile[#0]/value"]],
            'filter view outside'        => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_filter', 0, ['vid' => '99']), ["E view-outside $A/alerts_filter[3]/vid"]],
            'filter without a view'      => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_filter', 0, ['vid' => '0']), []],
            'alert e-mail outside'       => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_alert' => '8']), ["E email-outside $A/alerts_collection[AbC12]/emailid_alert"]],
            'welcome e-mail outside'     => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_welcome' => '8']), ["E email-outside $A/alerts_collection[AbC12]/emailid_welcome"]],
            'welcome e-mail of a slice'  => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_welcome' => '5']), []],
            'no welcome e-mail'          => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_welcome' => '0', 'emailid_alert' => null]), []],
            'mlxctrl outside'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['mlxctrl' => self::OTHER]), ["W module-outside $S/slice[#0]/mlxctrl"]],
            // ---- packed columns (AA_Metabase::getPacked()): a value no long id would be written as '' (include/varset.php)
            'mlxctrl no long id'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['mlxctrl' => 'cz']), ["E invalid-id $S/slice[#0]/mlxctrl"]],
            'mlxctrl two ids'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['mlxctrl' => self::SLICE . self::SLICE]), ["E invalid-id $S/slice[#0]/mlxctrl"]],
            'mlxctrl empty'              => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['mlxctrl' => '']), []],
            'ancestors, two ids'         => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [self::constants($p, 1, ['ancestors' => self::C2 . self::C2])]]), []],
            'ancestors no ids'           => [static fn(Package $p): Package => self::with($p, ['constantGroups' => [self::constants($p, 1, ['ancestors' => 'x' . self::C2])]]), ["E invalid-id $G/constant[" . self::C2 . ']/ancestors', "W dangling-ref $G/constant[" . self::C2 . ']/ancestors']],
            'reader slice no long id'    => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['slice_id' => 'abc']), ["E invalid-id $A/alerts_collection[AbC12]/slice_id", "E reader-outside $A/alerts_collection[AbC12]/slice_id"]],
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
            'const view group missing'   => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['type' => 'const', 'parameter' => 'ct_jine']), ["W group-missing $S/view[12]/parameter"]],
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
            'item pseudo field'          => [static fn(Package $p): Package => self::items($p, [['id' => self::OTHER, 'content' => ['posted_by.......' => [0 => ['value' => 'uid', 'flag' => 0]], 'u_slice_id......' => [0 => ['value' => self::SLICE, 'flag' => 0]], 'status_code.....' => [0 => ['value' => '2', 'flag' => 0]]]]]), ["E item-key $S/item[" . self::OTHER . ']/u_slice_id......', 'E items meta/contents']],     // posted_by in an install: test_the_authors_of_items_travel_only_in_a_transfer
            'item of no field'           => [static fn(Package $p): Package => self::items($p, [['id' => self::OTHER, 'content' => ['nofield........1' => [0 => ['value' => 'x', 'flag' => 0]], 'status_code.....' => [0 => ['value' => '1', 'flag' => 0]]]]]), ["W item-field $S/item[" . self::OTHER . ']/nofield........1', 'E items meta/contents']],
            'item without a status'      => [static fn(Package $p): Package => self::items($p, [['id' => self::OTHER, 'content' => ['headline........' => [0 => ['value' => 'x', 'flag' => 0]]]]]), ["W item-status $S/item[" . self::OTHER . ']/status_code.....', 'E items meta/contents']],
            'item in no bin'             => [static fn(Package $p): Package => self::items($p, [['id' => self::OTHER, 'content' => ['status_code.....' => [0 => ['value' => '4', 'flag' => 1]]]]]), ["W item-status $S/item[" . self::OTHER . ']/status_code.....', 'E items meta/contents']],
            // ---- items (a transfer): never published
            'items without the flag'     => [static fn(Package $p): Package => self::with(self::items($p, [self::item(self::OTHER)]), ['withItems' => false]), ["E items $S/item", 'E items meta/contents']],
            'items of a published app'   => [static fn(Package $p): Package => self::with(self::items($p, [self::item(self::OTHER)]), ['published' => true]), ['E items-published meta/publish', 'E items meta/contents']],
            'item flag, published'       => [static fn(Package $p): Package => self::with($p, ['withItems' => true, 'published' => true]), ['E items-published meta/publish', 'E items meta/contents']],
            'state, published'           => [static fn(Package $p): Package => self::with(self::module($p, self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['reading-password' => 'x']])), ['published' => true]), ['E items-published meta/publish']],
            'published, no items'        => [static fn(Package $p): Package => self::with($p, ['published' => true]), []],
            // ---- security lint: every text of the package
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
            'item value'                 => [static fn(Package $p): Package => self::items($p, [self::item(self::OTHER, '{server:SERVER_ADDR}')]), ["S server $S/item[" . self::OTHER . ']/headline........[0]', 'E items meta/contents']],
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
            // ---- alias functions: f_u calls a usr_* function of the target (I), anything else is S; f_z fetches like {fileinfo}
            'f_u in a field'             => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias1_func' => 'f_u:phpinfo']), ["S f_u $S/field[headline........]/alias1_func"]],
            'f_u usr_ in a field'        => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias1_func' => 'F_U:usr_print_uid:_#HEADLINE']), ["I f_u $S/field[headline........]/alias1_func"]],
            'f_u usr_ alone in a field'  => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias1_func' => 'f_u:usr_cz_date']), ["I f_u $S/field[headline........]/alias1_func"]],
            'f_u usr_ computed in a field' => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias1_func' => 'f_u:usr_x_#HEADLINE']), ["S f_u $S/field[headline........]/alias1_func"]],
            'f_u USR_ in a field'        => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias1_func' => 'f_u:USR_print_uid']), ["S f_u $S/field[headline........]/alias1_func"]],
            'f_z in a field'             => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 2, ['alias2_func' => ' F_Z:size']), ["S f_z $S/field[headline........]/alias2_func"]],
        ];
    }

    /** a second alerts module sharing filter 3 (with $filter changed) */
    private static function sharedFilter(Package $p, array $filter): Package
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

    // ---------------------------------------------------------------- transfer: state of the installation

    /** the example with the state of the installation a transfer carries */
    private static function transferred(): Package
    {
        $owner = ['owner' => ['name' => 'Ekologický institut', 'email' => 'office@example.org']];
        $p     = self::module(self::written(), self::SITE, static fn(Module $m): Module => self::with($m, ['transfer' => $owner + ['alternate-url' => ['example.org', 'www.example.org']]]));
        $p     = self::module($p, self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => $owner + ['reading-password' => 'tajne',
            'setting-values' => ['_email..........' => [['value' => 'redakce@example.org', 'flag' => 64]]]]]));
        return self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['transfer' => $owner]));
    }

    public function test_only_a_transfer_may_carry_the_state_of_the_installation(): void
    {
        $expected = ['E transfer-only ' . self::W . '/owner', 'E transfer-only ' . self::W . '/alternate-url', 'E transfer-only ' . self::S . '/owner',
            'E transfer-only ' . self::S . '/reading-password', 'E transfer-only ' . self::A . '/owner'];      // settings values: an install shows them
        sort($expected);
        self::assertSame($expected, array_values(array_diff(self::findings(self::transferred()), self::BASELINE)));
        self::assertSame(self::BASELINE, self::findings(self::transferred(), true));
    }

    public function test_the_xsd_takes_the_state_the_validator_refuses_it_in_an_install(): void
    {
        $read = (new Reader())->read((new Writer())->toXml(self::transferred())->xml);

        self::assertSame([], $read->findings);
        self::assertFalse((new Validator())->validate($read->package)->importable());
        self::assertTrue((new Validator())->validate($read->package, true)->importable());
    }

    /** a setting value names a settings field of the slice; its text is a template, checked as any other */
    public function test_setting_values_of_a_transfer_are_checked(): void
    {
        $values  = ['_email..........' => [['value' => '{server:X}', 'flag' => 64]], '_nowhere........' => [['value' => 'x', 'flag' => 64]]];
        $package = self::module(self::transferred(), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['setting-values' => $values] + $m->transfer]));

        self::assertSame(
            ['E setting-field ' . self::S . '/setting-values/_nowhere........', 'S server ' . self::S . '/setting-values/_email..........[0]'],
            array_values(array_diff(self::findings($package, true), self::BASELINE)),
        );
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function misplacedState(): array
    {
        return [
            'reading password of a site' => [self::SITE, ['reading-password' => 'x']],
            'domains of a slice'         => [self::SLICE, ['alternate-url' => ['example.org']]],
            'domains of alerts'          => [self::ALERTS, ['alternate-url' => ['example.org']]],
            'owner without its e-mail'   => [self::SLICE, ['owner' => ['name' => 'x']]],
            'an unknown part'            => [self::SLICE, ['password' => 'x']],
            'setting values of a site'   => [self::SITE, ['setting-values' => ['_email..........' => [['value' => 'x', 'flag' => 0]]]]],
            'a value of no settings field' => [self::SLICE, ['setting-values' => ['headline........' => [['value' => 'x', 'flag' => 0]]]]],
            'no setting value'           => [self::SLICE, ['setting-values' => ['_email..........' => []]]],
        ];
    }

    /** @param array<string, mixed> $transfer */
    #[DataProvider('misplacedState')]
    public function test_misplaced_state_is_an_error_in_a_transfer_too(string $id, array $transfer): void
    {
        $package = self::module(self::written(), $id, static fn(Module $m): Module => self::with($m, ['transfer' => $transfer]));

        self::assertFalse((new Validator())->validate($package, true)->importable());
    }

    // ---------------------------------------------------------------- item batches

    private const I1 = '000000000000000000000000000000a1';
    private const I2 = '000000000000000000000000000000a2';

    /** a batch of the example's slice: I1, I2 after nothing */
    private static function batch(?Batch $batch = null, ?array $items = null): Package
    {
        return new Package(
            'Články',
            self::written()->source,
            [new Module(Module::SLICE, self::SLICE, [], items: $items ?? [self::item(self::I1), self::item(self::I2)])],
            withItems: true,
            batch: $batch ?? new Batch('', self::I2, false),
        );
    }

    public function test_a_batch_is_valid_without_the_slice_definition(): void
    {
        self::assertSame([], self::findings(self::batch(), true));
        self::assertSame([], self::findings(self::batch(new Batch(self::I2, self::I2, true), []), true));
    }

    public function test_the_authors_of_items_travel_only_in_a_transfer(): void
    {
        $author = ['posted_by.......' => [['value' => 'uid-17', 'flag' => 0]], 'edited_by.......' => [['value' => 'uid-18', 'flag' => 0]]];
        $items  = [['id' => self::I1, 'content' => $author + self::item(self::I1)['content']], self::item(self::I2)];
        $at     = self::S . '/item[' . self::I1 . ']/';

        self::assertSame([], self::findings(self::batch(null, $items), true));
        self::assertSame(["E item-key {$at}edited_by.......", "E item-key {$at}posted_by.......", 'E transfer-only meta/batch'], self::findings(self::batch(null, $items)));
        self::assertSame(["E item-key {$at}edited_by.......", "E item-key {$at}posted_by.......", 'E items meta/contents'], array_values(array_diff(self::findings(self::items(self::written(), $items)), self::BASELINE)));
    }

    /** @return array<string, array{Closure(): Package}> */
    public static function installProbes(): array
    {
        $hash = static fn(string $id): array => ['id' => $id, 'content' => ['password........' => [['value' => '$2y$10$hash', 'flag' => 0]], 'secret..........' => [['value' => 'JBSWY3DP', 'flag' => 0]]] + self::item($id)['content']];
        return [
            'items with a hash and a secret' => [static fn(): Package => self::items(self::written(), [$hash(self::OTHER)])],
            'the item flag alone'            => [static fn(): Package => self::with(self::written(), ['withItems' => true])],
            'items without the flag'         => [static fn(): Package => self::with(self::items(self::written(), [self::item(self::OTHER)]), ['withItems' => false])],
            'a batch'                        => [static fn(): Package => self::batch()],
            'a batch of readers'             => [static fn(): Package => self::batch(new Batch('', self::I1, true), [$hash(self::I1)])],
        ];
    }

    /** @param Closure(): Package $package */
    #[DataProvider('installProbes')]
    public function test_an_install_takes_no_items(Closure $package): void
    {
        self::assertFalse((new Validator())->validate($package())->importable());
    }

    public function test_a_published_batch_is_an_error(): void
    {
        self::assertSame(['E items-published meta/publish'], self::findings(self::with(self::batch(), ['published' => true]), true));
    }

    /** @return array<string, array{Closure(): Package, list<string>}> */
    public static function badBatches(): array
    {
        $S = self::S;
        return [
            'the slice row'            => [static fn(): Package => self::rows(self::batch(), self::SLICE, 'slice', static fn(): array => [['name' => 'x']]), ["E batch $S"]],
            'settings'                 => [static fn(): Package => self::module(self::batch(), self::SLICE, static fn(Module $m): Module => self::with($m, ['settings' => []])), ["E batch $S"]],
            'the owner'                => [static fn(): Package => self::module(self::batch(), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['owner' => ['name' => 'x', 'email' => 'y']]])), ["E batch $S"]],
            'two slices'               => [static fn(): Package => self::with(self::batch(), ['modules' => [...self::batch()->modules, new Module(Module::SLICE, self::OTHER, [])]]), ['E batch aa-app']],
            'a site'                   => [static fn(): Package => self::with(self::batch(), ['modules' => [new Module(Module::SITE, self::SITE, [])]]), ['E batch aa-app']],
            'a constant group'         => [static fn(): Package => self::with(self::batch(), ['constantGroups' => self::written()->constantGroups]), ['E batch aa-app']],
            'no item flag'             => [static fn(): Package => self::with(self::batch(), ['withItems' => false]), ["E items $S/item"]],
            'an item at the cursor'    => [static fn(): Package => self::batch(new Batch(self::I1, self::I2, false)), ["E batch $S/item[" . self::I1 . ']']],
            'an item after next'       => [static fn(): Package => self::batch(new Batch('', self::I1, false)), ["E batch $S/item[" . self::I2 . ']']],
            'next is no item of it'    => [static fn(): Package => self::batch(new Batch('', self::OTHER, false)), ['E batch meta/batch']],
            'empty, next moved'        => [static fn(): Package => self::batch(new Batch('', self::I1, true), []), ['E batch meta/batch']],
            'a cursor of no id'        => [static fn(): Package => self::batch(new Batch('x', self::I2, false)), ['E batch meta/batch']],
            'a short_id'               => [static fn(): Package => self::batch(null, [self::item(self::I1), ['id' => self::I2, 'content' => ['short_id........' => [['value' => '7', 'flag' => 0]]] + self::item(self::I2)['content']]]), ["E item-key $S/item[" . self::I2 . ']/short_id........']],
        ];
    }

    /** @param Closure(): Package $package @param list<string> $expected */
    #[DataProvider('badBatches')]
    public function test_a_bad_batch(Closure $package, array $expected): void
    {
        self::assertSame($expected, self::findings($package(), true));
    }

    public function test_perm_mode(): void
    {
        $mode = static fn(string $v): array => array_values(preg_grep('/perm-mode/', self::findings(self::setting(self::written(), self::SITE, 'perm_mode', $v))));
        self::assertSame([], $mode('1'));
        self::assertSame([], $mode('2'));
        self::assertSame(['W perm-mode ' . self::W . '/settings/perm_mode'], $mode('3'));
    }

    public function test_security_findings_need_a_confirmation_but_do_not_block(): void
    {
        $package = self::cell(self::written(), self::SLICE, 'view', 0, ['odd' => '{server:X}']);
        $result  = (new Validator())->validate($package);
        self::assertTrue($result->importable());
        self::assertSame(['{server:X}'], array_map(static fn(array $f): string => $f[1]->ref, $result->ofLevel(LintFinding::SECURITY)));
        self::assertFalse((new Validator())->validate(self::rows($package, self::SLICE, 'slice', static fn(): array => []))->importable());
    }

    public function test_findings_are_capped_per_code_and_sorted_by_danger(): void
    {
        $odd    = str_repeat('{img:x.jpg}', 1100) . '{include:/x}';
        $result = (new Validator())->validate(self::cell(self::written(), self::SLICE, 'view', 0, ['odd' => $odd]));
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
        $found = (new Validator())->validate(self::object(self::written(), self::SITE, 0, static fn(array $o): array => ['props' => 'x'] + $o))->ofLevel(LintFinding::ERROR);
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

    /** a regex that hits a PCRE limit (here thousands of nested braces) is an S finding, not an exception
     *  out of Validator::validate(); the limit is lowered so the test does not depend on the stack size */
    public function test_security_lint_failing_regex_is_a_finding(): void
    {
        $ini = ['pcre.jit' => ini_get('pcre.jit'), 'pcre.recursion_limit' => ini_get('pcre.recursion_limit')];
        ini_set('pcre.jit', '0');
        ini_set('pcre.recursion_limit', '1000');
        try {
            $found = (new SecurityLint(new Schema()))->lint('{alias:' . str_repeat('{', 5000) . str_repeat('}', 5000) . ':f_z}');
        } finally {
            foreach ($ini as $k => $v) {
                ini_set($k, (string) $v);
            }
        }
        self::assertSame('S lint-failed regular expression limit @0', "{$found[0]->level} {$found[0]->code} {$found[0]->ref} @{$found[0]->offset}");
        self::assertCount(1, array_filter($found, static fn(LintFinding $f): bool => $f->code === SecurityLint::LINT_FAILED));
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
            'header harmless'      => ['{header:404}{header:json}{header:XCTO:nosniff}{HEADER(filename:a.csv)}', ['I header {header:404} @0', 'I header {header:json} @12', 'I header {header:XCTO:nosniff} @25', 'I header {HEADER(filename:a.csv)} @46']],
            'header of the browser' => ['{header:CSP:default-src *}{header:CORS:*}{header:RT:x}{header:NEL:y}{header:{x}}', ['S header {header:CSP:default-src *} @0', 'S header {header:CORS:*} @26', 'S header {header:RT:x} @41', 'S header {header:NEL:y} @54', 'S header {header: @68']],
            'added by review'      => ['{recompute:a}{userinfo:u}{jwtencode:k}{setsecret:u}', ['S recompute {recompute:a} @0', 'S userinfo {userinfo:u} @13', 'S jwtencode {jwtencode:k} @25', 'S setsecret {setsecret:u} @38']],
            'info only'            => ['{debug}{version}{serverload}', ['I debug {debug} @0', 'I version {version} @7', 'I serverload {serverload} @16']],
            'not these'            => ['{imgtext:x}{mails:x}{users:x}', []],
            'expand, dequote, ( )' => ['{expand:x}{dequote:y}{( {view:3} )}', ['S expand {expand:x} @0', 'S dequote {dequote:y} @10']],
            'composed name'        => ['{{ifeq:1:1:include}:x} {inc{x}:y}', ['S composed-command {{ @0', 'S composed-command {inc{ @23']],
            'quote map byte'       => ["a\x1Eb", ["S quote-map \x1E @1"]],
            'alias f_u, f_z'       => ['{alias:headline........:f_u:fn} {alias::F_Z:size} {alias:x:f_h}', ['S f_u {alias:headline........:f_u @0', 'S f_z {alias::F_Z @32']],
            'alias f_u usr_'       => ['{alias:headline........:f_u:usr_print_uid}{alias::F_U:usr_cz_date:j.n.Y}{alias::f_u:usr_{x}}{alias::f_u:usr_x#:y}{alias::f_z:usr_x}',
                ['I f_u {alias:headline........:f_u @0', 'I f_u {alias::F_U @42', 'S f_u {alias::f_u @72', 'S f_u {alias::f_u @92', 'S f_z {alias::f_z @113']],
            'alias literal f_c'    => ['{alias:x:f_c:a}{alias::F_H}{alias:{x}:f_h}', []],
            'alias item functions' => ['{alias:x:i_s}{alias:x:l_b:1}{alias:x:L_G}{alias:x:l_o}{alias:x:l_p}', []],
            'alias field 200 pieces' => ['{alias:' . str_repeat('a{b}', 100) . ':f_z:http://evil/}', ['S f_z ' . substr('{alias:' . str_repeat('a{b}', 100) . ':f_z', 0, ReferenceLint::MAX_REF) . ' @0']],
            'alias field 201+ pieces' => ['{alias:' . str_repeat('a{b}', 101) . ':f_z:http://evil/}', ['S alias-computed ' . substr('{alias:' . str_repeat('a{b}', 101), 0, ReferenceLint::MAX_REF) . ' @0']],
            'alias f_z glued'      => ['{alias::f_zhttp://10.0.0.1/}', ['S f_z {alias::f_z @0']],
            'alias computed field' => ['{alias:{concat:head:line}:f_z:size}', ['S f_z {alias:{concat:head:line}:f_z @0']],
            'alias computed fnc'   => ['{alias:x:f_{y}:a}{alias:x:{concat:f_:z}:a}{alias:x:_#FNC}{alias:x:' . "\x1E" . '}{alias:{x}:{y}}',
                ['S alias-computed {alias:x: @0', 'S alias-computed {alias:x: @17', 'S alias-computed {alias:x: @42', 'S alias-computed {alias:x: @57', "S quote-map \x1E @66", 'S alias-computed {alias:{x}: @68']],
        ];
    }
}
