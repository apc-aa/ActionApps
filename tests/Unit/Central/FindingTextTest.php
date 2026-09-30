<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Central;

use AA\Central\FindingText;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../include/mgettext.php';

final class FindingTextTest extends TestCase
{
    private const SITE  = 'f56240712d3755e060bcb9e63323ab51';
    private const SLICE = 'aa11bb22cc33dd44ee55ff6677889900';
    private const ITEM  = '0123456789abcdef0123456789abcdef';

    private static function package(): Package
    {
        return new Package('App', [], [
            new Module(
                Module::SITE,
                self::SITE,
                ['module' => [['name' => 'Our web']], 'site_spot' => [['spot_id' => '5', 'content' => 'x']]],
                spotTree: ['start_id' => 1, 'spots' => [['id' => 1, 'n' => 'start'], ['id' => 5, 'n' => 'Header']]],
            ),
            new Module(
                Module::SLICE,
                self::SLICE,
                ['module' => [['name' => 'News']], 'field' => [['id' => 'headline........', 'name' => 'Title']], 'view' => [['id' => '12', 'name' => 'List']]],
                objects: [['id' => 'abc', 'class' => 'AA_Aliasfunc', 'name' => '_#MYALIAS', 'props' => []]],
                items: [['id' => self::ITEM, 'content' => ['headline........' => [['value' => 'Hello']]]]],
            ),
        ], [new ConstantGroup('colors', '', ['constant' => [['id' => 'c1', 'name' => 'Red']]])]);
    }

    /** @return array<string, array{string, string}> */
    public static function places(): array
    {
        $W = 'site-module[' . self::SITE . ']';
        $S = 'slice-module[' . self::SLICE . ']';
        return [
            'site spot content' => ["$W/site_spot[5]/content", 'Site "Our web" › spot "Header" (5) › content'],
            'spot tree name'    => ["$W/spot-tree/spot[5]@name", 'Site "Our web" › spot "Header" (5) › name'],
            'spot condition'    => ["$W/spot-tree/spot[1]/cond[#0]@var", 'Site "Our web" › spot "start" (1) › condition › variable'],
            'view'              => ["$S/view[12]/odd", 'Slice "News" › view "List" (12) › odd'],
            'field'             => ["$S/field[headline........]/alias1_func", 'Slice "News" › field "Title" (headline........) › alias 1'],
            'slice row'         => ["$S/slice[#0]/fulltext_format", 'Slice "News" › fulltext_format'],
            'object'            => ["$S/object[abc]/code[0]", 'Slice "News" › object "_#MYALIAS" (abc) › code'],
            'item'              => ["$S/item[" . self::ITEM . ']/headline........[0]', 'Slice "News" › item "Hello" › headline........'],
            'constant'          => ['constant-group[colors]/constant[c1]/description', 'constant group "colors" › constant "Red" (c1) › description'],
            'unknown module'    => ['slice-module[' . self::ITEM . ']/view[3]/odd', 'Slice › view (3) › odd'],
            'not a package'     => ['', ''],
            'meta'              => ['meta/contents', 'meta › contents'],
        ];
    }

    #[DataProvider('places')]
    public function test_a_place_is_named_by_the_package(string $path, string $place): void
    {
        self::assertSame($place, (new FindingText(self::package()))->place($path));
    }

    public function test_without_a_package_the_kinds_and_short_keys_stay(): void
    {
        self::assertSame('Site › spot (5) › content', (new FindingText())->place('site-module[' . self::SITE . ']/site_spot[5]/content'));
    }

    public function test_every_security_code_of_a_command_is_explained(): void
    {
        foreach ([...\AA\Central\Package\SecurityLint::COMMANDS, ...\AA\Central\Package\SecurityLint::ALIAS_FUNCTIONS, 'header', 'user', 'img', 'changepwdsendmail', 'domain-kept'] as $code) {
            self::assertNotNull(FindingText::explain(new LintFinding(LintFinding::SECURITY, $code, '', 0)), $code);
        }
    }

    /** @return array<string, array{string}> */
    public static function codes(): array
    {
        $codes = ['alerts-field-kept', 'alerts-filter-kept', 'alerts-outside', 'bare-number', 'batch', 'computed', 'count-differs', 'dangling-ref', 'domain-dropped', 'duplicate-id',
            'edited-during-transfer', 'email-address', 'email-attachments-dropped', 'email-exists', 'email-outside', 'email-unmatched', 'field-differs', 'field-type-differs', 'form',
            'glued-id', 'group-kept', 'group-left-out', 'group-missing', 'group-name', 'group-name-exhausted', 'group-other', 'group-outside', 'group-redirected', 'group-renamed',
            'group-unpublished', 'charset', 'charset-broken', 'into-existing', 'into-source', 'int-range', 'invalid-id', 'item-field', 'item-id-exists', 'item-id-in-use', 'item-key',
            'items', 'items-published', 'item-status', 'item-too-big', 'lang-file', 'lang-file-normalised', 'lang-file-replaced', 'login-charset', 'may-be-too-long', 'module-missing',
            'module-outside', 'module-rows', 'module-type', 'no-transaction', 'object', 'object-dropped', 'object-exists', 'object-unmatched', 'owner-missing', 'owner-unknown',
            'permission', 'perm-mode', 'preselect-dropped', 'preselected-by-role', 'preselected-reader-slice', 'profile-property', 'reader-accounts', 'reader-admin', 'reader-duplicate',
            'reader-multivalue', 'reader-outside', 'reader-perms', 'reader-taken', 'reader-2fa', 'relation-dangling', 'relation-target-differs', 'row-missing', 'setting-default-skipped',
            'setting-field', 'settings-dropped', 'slice-state-kept', 'slice-type-differs', 'source-count-unknown', 'spot-markup', 'spot-tree', 'spot-unused', 'state-file', 'subobject',
            'table-not-allowed', 'target-slice', 'too-long', 'transfer-only', 'unique-field', 'unknown-form', 'view-differs', 'view-exists', 'view-outside', 'view-unmatched',
            'export-failed', 'export-denied', 'invalid-bytes', 'unknown-column', 'unknown-element', 'unknown-attribute', 'unknown-class', 'newer-format', 'schema-location',
            'spot-markup-stripped', 'no-spot-tree', 'owner-invalid', 'setting-not-number', 'reader-left-out', 'group-owner-left-out', 'debug', 'version', 'serverload', 'header', 'user', 'img'];
        return array_combine($codes, array_map(static fn(string $c): array => [$c], $codes));
    }

    #[DataProvider('codes')]
    public function test_every_other_code_is_explained_in_words(string $code): void
    {
        foreach ([LintFinding::ERROR, LintFinding::WARNING, LintFinding::INFO] as $level) {
            $explained = FindingText::explain(new LintFinding($level, $code, '', 0));
            self::assertNotNull($explained, $code);
            self::assertNotSame('', $explained['what']);
            self::assertNotSame('', $explained['todo']);
        }
    }

    public function test_a_code_of_two_levels_says_what_each_means(): void
    {
        foreach (['view-outside', 'state-file'] as $code) {
            self::assertNotSame(
                FindingText::explain(new LintFinding(LintFinding::ERROR, $code, '', 0))['todo'] ?? null,
                FindingText::explain(new LintFinding(LintFinding::WARNING, $code, '', 0))['todo'] ?? null,
                $code,
            );
        }
    }

    public function test_a_setting_value_is_named(): void
    {
        $post = 'slice-module[' . self::SLICE . ']/slice[#0]/permit_anonymous_post';
        self::assertSame('Hold bin (2)', FindingText::found(new LintFinding(LintFinding::WARNING, 'permission', '2', 0), $post));
        self::assertSame('not set', FindingText::found(new LintFinding(LintFinding::WARNING, 'perm-mode', '', 0), 'x/settings/perm_mode'));
        self::assertSame('{include:…', FindingText::found(new LintFinding(LintFinding::SECURITY, 'include', '{include:', 0), 'x'));
        self::assertSame('Slice "News" › Allow anonymous posting of items', (new FindingText(self::package()))->place($post));
    }
}
