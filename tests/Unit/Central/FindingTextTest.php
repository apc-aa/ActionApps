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
            'field'             => ["$S/field[headline........]/alias1_func", 'Slice "News" › field "Title" (headline........) › alias1_func'],
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
        foreach ([...\AA\Central\Package\SecurityLint::COMMANDS, ...\AA\Central\Package\SecurityLint::ALIAS_FUNCTIONS, 'header', 'user', 'img', 'changepwdsendmail'] as $code) {
            self::assertNotNull(FindingText::explain(new LintFinding(LintFinding::SECURITY, $code, '', 0)), $code);
        }
        self::assertNull(FindingText::explain(new LintFinding(LintFinding::WARNING, 'header', '', 0)));
    }
}
