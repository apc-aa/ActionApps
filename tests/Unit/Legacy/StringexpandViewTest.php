<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA_Slice;
use AA_Stringexpand_View;
use AA_View;
use AA_Views;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * {view:<vid>:<ids>:<settings>}: the output of a view, and the queries it asks for it. The views are
 * given here, the database answers no item - a listing shows its noitem text then.
 */
final class StringexpandViewTest extends TestCase
{
    private const SLICE = 'cafe0000000000000000000000000001';
    private const LIST  = 41;
    private const PAGE  = 42;
    private const A     = 'a0000000000000000000000000000001';
    private const B     = 'b0000000000000000000000000000002';

    /** @var array{mixed, mixed, mixed, mixed} AA::$langnum, AA::$slice_id, apc_state and QueryIDsCount before the test - a view sets them */
    private array $saved = [null, null, null, null];

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/view.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function setUp(): void
    {
        RecordingDb::reset();
        // the items the statement names, in its order; the slice by its conditions holds B and A
        RecordingDb::$answer = static function (string $sql): array {
            preg_match_all('/0x([0-9a-f]{32})/', $sql, $m);
            $ids = array_values(array_unique(array_diff($m[1], [self::SLICE]))) ?: [self::B, self::A];

            return match (true) {
                str_starts_with($sql, 'SELECT slice_id FROM item') => [['slice_id' => pack_id(self::SLICE)]],
                str_contains($sql, 'item.id as itemid')           => array_map(static fn(string $id): array => ['itemid' => pack_id($id)], $ids),
                // as the primary key orders them
                str_starts_with($sql, 'SELECT * FROM item')       => array_map(static fn(string $id): array => ['id' => pack_id($id), 'short_id' => '0', 'slice_id' => pack_id(self::SLICE), 'status_code' => '1'], self::sorted($ids)),
                default                                           => [],
            };
        };
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $this->saved = [AA::$langnum, AA::$slice_id, $GLOBALS['apc_state'] ?? null, $GLOBALS['QueryIDsCount'] ?? null];
        $GLOBALS['apc_state']['router'] = 'test';   // the language of the page is set - no language file read
        AA::$langnum = [1];
        // a slice with no field: no alias, no setting
        new class (self::SLICE) extends AA_Slice {
            public function __construct(string $id)
            {
                $this->module_id = $id;
                self::$_modules[$id] = $this;   // what AA_Slice::getModule() returns
            }

            public function aliases($additional_aliases = null, $type = ''): ?array
            {
                return [];
            }

            public function getProperty($prop)
            {
                return null;
            }

            public function getFields($dynamic_fields = false): \AA_Fields
            {
                return new FakeFields($this->module_id, []);
            }
        };
        self::views();
    }

    /** the views of the database: a listing sorted by the headline, and a static page */
    private static function views(): void
    {
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, [
            self::LIST => new AA_View(self::LIST, self::view(self::LIST, 'list', ['odd' => '<li>{id..............}</li>', 'noitem_msg' => 'none', 'listlen' => '10', 'order1' => 'headline........', 'o1_direction' => '0'])),
            self::PAGE => new AA_View(self::PAGE, self::view(self::PAGE, 'static', ['odd' => '<p>a page</p>'])),
        ]);
    }

    protected function tearDown(): void
    {
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, []);
        $modules = new ReflectionProperty(\AA_Module::class, '_modules');
        $modules->setValue(null, array_diff_key($modules->getValue(), [self::SLICE => true]));
        [AA::$langnum, AA::$slice_id, $GLOBALS['apc_state'], $GLOBALS['QueryIDsCount']] = $this->saved;
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        AA::Contentcache()->clear();
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @param array<string, string> $fields */
    private static function view(int $vid, string $type, array $fields): array
    {
        return $fields + ['id' => (string) $vid, 'slice_id' => pack_id(self::SLICE), 'type' => $type, 'deleted' => '0', 'flag' => '0', 'lang_file' => 'cz_news_lang.php3', 'odd' => '', 'noitem_msg' => ''];
    }

    /** @return list<string> the statements asked, the time of the request left out */
    private static function asked(): array
    {
        return array_map(static fn(string $sql): string => (string) preg_replace("/'\\d{10}'/", "'NOW'", $sql), RecordingDb::$log);
    }

    /** @return array<string, array{string, string, ?string, string, list<string>}> vid, ids, settings, the output, the statements */
    public static function calls(): array
    {
        $bins     = "item.status_code=1 AND item.publish_date <= 'NOW' AND item.expiry_date > 'NOW'";
        $inOrder  = 'item.id IN (0xb0000000000000000000000000000002,0xa0000000000000000000000000000001)';
        $byIds    = 'ORDER BY  field(item.id,0xb0000000000000000000000000000002,0xa0000000000000000000000000000001)';
        $both     = '<li>' . self::B . '</li><li>' . self::A . '</li>';
        $content2 = ["SELECT * FROM item WHERE  $inOrder ", 'SELECT * FROM content WHERE item_id IN (0xa0000000000000000000000000000001,0xb0000000000000000000000000000002)  ORDER BY item_id, number'];

        return [
            'static'                       => [(string) self::PAGE, '', null, '<p>a page</p>', []],
            'static, ids and settings'     => [(string) self::PAGE, self::A, 'listlen-1', '<p>a page</p>', []],
            'listing by its conditions'    => [(string) self::LIST, '', null, $both, ['SELECT DISTINCT item.id as itemid  FROM item  WHERE item.slice_id = 0xcafe0000000000000000000000000001 AND   ' . $bins . '   -- AA slice: ' . self::SLICE, ...$content2]],
            // {view:N:} - no ids is the whole slice
            'listing, ids empty'           => [(string) self::LIST, '', '', $both, ['SELECT DISTINCT item.id as itemid  FROM item  WHERE item.slice_id = 0xcafe0000000000000000000000000001 AND   ' . $bins . '   -- AA slice: ' . self::SLICE, ...$content2]],
            'listing of ids'               => [(string) self::LIST, self::B . '-' . self::A, null, $both, [
                'SELECT slice_id FROM item WHERE item.id = 0xb0000000000000000000000000000002 LIMIT  1',
                "SELECT DISTINCT item.id as itemid  FROM item  WHERE item.slice_id = 0xcafe0000000000000000000000000001 AND   $inOrder  AND   $bins   $byIds -- AA slice: " . self::SLICE,
                ...$content2,
            ]],
            'listing of ids, in their order' => [(string) self::LIST, self::B . '-' . self::A, 'listlen-1,sort-AAnoSORT', '<li>' . self::B . '</li>', [
                "SELECT DISTINCT item.id as itemid  FROM item  WHERE   $inOrder  AND   $bins   $byIds -- AA slice: ",
                'SELECT * FROM item WHERE  item.id = 0xb0000000000000000000000000000002 ',
                'SELECT * FROM content WHERE item_id = 0xb0000000000000000000000000000002  ORDER BY item_id, number',
            ]],
            'no such view'                 => ['77', self::A, null, '', ["SELECT view.*, module.deleted, module.lang_file FROM view, module WHERE module.id = view.slice_id AND view.id = '77' LIMIT  1"]],
        ];
    }

    /** $show called quiet about the parameters a view leaves out */
    private static function quiet(\Closure $show): string
    {
        set_error_handler(static fn(int $no): bool => in_array($no, [E_WARNING, E_NOTICE, E_DEPRECATED], true));
        try {
            return $show();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * {view} and AA_Stringexpand_View::output() give the same output and ask the same statements
     * @param list<string> $asked
     */
    #[DataProvider('calls')]
    public function test_view_sameOutputAsView(string $vid, string $ids, ?string $settings, string $output, array $asked): void
    {
        self::assertSame($output, self::quiet(static fn(): string => (new AA_Stringexpand_View([]))->expand($vid, $ids, $settings)));
        self::assertSame($asked, self::asked());
        RecordingDb::$log = [];
        self::views();
        self::assertSame($output, self::quiet(static fn(): string => AA_Stringexpand_View::output($vid, $ids, $settings)));
        self::assertSame($asked, self::asked());
    }

    // {datovkabulk:view}: the ids alone, in their order, whatever the view sorts by
    public function test_view_idsKeepGateOrder(): void
    {
        $output = self::quiet(static fn(): string => AA_Stringexpand_View::output(self::LIST, self::B . '-' . self::A, 'listlen-5,sort-headline........-', true));

        self::assertSame('<li>' . self::B . '</li><li>' . self::A . '</li>', $output);
        self::assertStringContainsString('ORDER BY  field(item.id,0xb0000000000000000000000000000002,0xa0000000000000000000000000000001)', self::asked()[0]);
        self::assertStringNotContainsString('item.slice_id', self::asked()[0], 'by the ids, not by the slice');
    }

    // all the ids, whatever listlen the view has, the template gives, or a scroller pages by
    public function test_view_allIdsWhateverTheListlen(): void
    {
        $ids                              = array_map(static fn(int $n): string => sprintf('d%031d', $n), range(1, 30));
        $GLOBALS['apc_state']['xpage']    = '2';
        $output                           = self::quiet(static fn(): string => AA_Stringexpand_View::output(self::LIST, implode('-', $ids), 'listlen-5,from-3,page-2', true));

        self::assertSame(implode('', array_map(static fn(string $id): string => "<li>$id</li>", $ids)), $output);
    }

    // {view:N:} lists the whole slice; a listing of the gate shows nothing
    public function test_view_emptyIdsEmptyListing(): void
    {
        self::assertSame('none', self::quiet(static fn(): string => AA_Stringexpand_View::output(self::LIST, '', 'listlen-5', true)));
        self::assertSame([], RecordingDb::$log, 'no item read');
    }

    public function test_view_listingWithoutIdsEmpty(): void
    {
        self::assertSame('none', self::quiet(static fn(): string => AA_Stringexpand_View::output(self::LIST, null, null, true)));
        self::assertSame('<p>a page</p>', self::quiet(static fn(): string => AA_Stringexpand_View::output(self::PAGE, null, null, true)), 'a static view takes no ids');
        self::assertSame([], RecordingDb::$log, 'no item read');
    }
}
