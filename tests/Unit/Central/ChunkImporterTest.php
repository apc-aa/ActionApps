<?php

declare(strict_types=1);

/**
 * The chunk mode of Central Copy Slice (design balicek-v1.md § 10.1, C4.4 part 6): a chunk is imported only
 * into its module - the tables of the module's type, every row of the module (F4), items only into a slice
 * prepared here, content only of its items, constant groups new or of the module, installation columns and
 * foreign references cleaned, a taken AUTO_INCREMENT number replaced. No DB: FakeChunkTarget answers what
 * the importer asks, RecordingMetabase records the inserts.
 */

namespace Tests\Unit\Central;

use AA\Central\ChunkImporter;
use AA\Central\DefinitionStore;
use AA_Definition_Module_Chunk;
use AA_Definition_Module_Slice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Fixtures\FakeChunkTarget;
use Tests\Unit\Central\Fixtures\RecordingMetabase;

require_once __DIR__ . '/fixtures/ChunkFixtures.php';
require_once __DIR__ . '/../fixtures/util_shims.php';       // recursive_array_replace() of Copy Slice

final class ChunkImporterTest extends TestCase
{
    /** the module the chunks are for - a slice prepared on the target */
    private const SLICE    = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    /** another slice of the target */
    private const OTHER    = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SITE     = 'cccccccccccccccccccccccccccccccc';
    private const ALERTS   = 'dddddddddddddddddddddddddddddddd';
    /** the template module of the source AA */
    private const TEMPLATE = '0123456789abcdef0123456789abcdef';
    private const ITEM_A   = '1111111111111111111111111111111a';
    private const ITEM_B   = '2222222222222222222222222222222b';
    private const ITEM_C   = '3333333333333333333333333333333c';
    private const REFUSED  = 'Err: refused, not of this module or invalid - is the sending Central up to date?: ';

    private RecordingMetabase $metabase;
    private FakeChunkTarget $target;

    protected function setUp(): void
    {
        $this->metabase = new RecordingMetabase();
        $this->target   = new FakeChunkTarget([self::SLICE => 'S', self::OTHER => 'S', self::SITE => 'W', self::ALERTS => 'Alerts']);
    }

    /** @param array<int|string, mixed> $rows @return list<string> */
    private function import(string $table, array $rows, string $module = self::SLICE): array
    {
        return (new ChunkImporter($this->target, $this->metabase))->run($module, [$table => $rows]);
    }

    private static function id(string $digit): string
    {
        return str_repeat($digit, 32);
    }

    // --- allowlist -----------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function foreignTables(): array
    {
        return array_map(static fn(string $t): array => [$t], array_combine($t = ['users', 'toexecute', 'central_conf', 'object_text', 'perms', 'relation', 'no_such_table'], $t));
    }

    #[DataProvider('foreignTables')]
    public function test_a_table_outside_the_allowlist_is_refused(string $table): void
    {
        self::assertSame(["Err: refused, not a table Central copies: 1 $table ([x]); "], $this->import($table, ['x' => ['id' => 'x', 'slice_id' => self::SLICE]]));
        self::assertSame([], $this->metabase->inserted);
    }

    public function test_a_table_of_another_module_type_is_refused(): void
    {
        self::assertSame(['Err: refused, not a table of a module of type S: 1 site_spot ([7]); '], $this->import('site_spot', [['id' => 7, 'site_id' => self::SLICE]]));
        self::assertSame(['Err: refused, not a table of a module of type W: 1 field ([headline........,' . self::SITE . ']); '], $this->import('field', [['id' => 'headline........', 'slice_id' => self::SITE]], self::SITE));
        self::assertSame(['Err: refused, not a table of a module of type Alerts: 1 email ([3]); '], $this->import('email', [['id' => 3, 'owner_module_id' => self::ALERTS]], self::ALERTS), 'the alerts chunk sends no e-mails');
        $this->target->modules = [];
        self::assertSame(['Err: refused, not a table of a module of type P: 1 module ([' . self::SLICE . ']); '], $this->import('module', [['id' => self::SLICE, 'type' => 'P']]));
        self::assertSame([], $this->metabase->inserted);
    }

    public function test_installation_state_and_profiles_of_users_are_skipped(): void
    {
        self::assertStringStartsWith('Skipped, installation state: 2 email_notify (', $this->import('email_notify', [['slice_id' => self::SLICE], ['slice_id' => self::SLICE]])[0]);
        self::assertStringStartsWith('Skipped, installation state: 1 rssfeeds (', $this->import('rssfeeds', [['slice_id' => self::SLICE]])[0]);
        self::assertSame(['OK: 1 profile ([3]); ', 'Skipped, profile of a user: 1 profile ([4]); '], $this->import('profile', [
            ['id' => 3, 'slice_id' => self::SLICE, 'uid' => '*', 'property' => 'input_view', 'value' => '12'],
            ['id' => 4, 'slice_id' => self::SLICE, 'uid' => 'jane', 'property' => 'hide', 'value' => '1'],
        ]));
        self::assertSame([['slice_id' => self::SLICE, 'uid' => '*', 'property' => 'input_view', 'value' => '12']], $this->metabase->rows('profile'), 'id renumbered - nobody refers to it');
    }

    // --- cleaning --------------------------------------------------------------------------------

    public function test_the_module_row_is_cleaned(): void
    {
        $this->target->modules = [];
        self::assertSame(['OK: 1 module ([' . self::SLICE . ']); '], $this->import('module', [[
            'id' => self::SLICE, 'name' => 'News', 'type' => 'S', 'lang_file' => 'cz_news_lang.php', 'flag' => 0, 'owner' => self::OTHER,
            'created_by' => 'someone', 'created_at' => 5, 'slice_url' => 'https://source/', 'deleted' => 1, 'priority' => 7, 'app_id' => self::OTHER, 'no_such' => 'x',
        ]]));
        self::assertSame([['id' => self::SLICE, 'name' => 'News', 'type' => 'S', 'lang_file' => 'cz_news_lang.php', 'flag' => 0,
            'deleted' => 0, 'created_by' => 'importer', 'created_at' => 1700000000]], $this->metabase->rows('module'));
    }

    public function test_the_slice_row_is_cleaned_its_password_replaced_and_mlxctrl_of_another_slice_cleared(): void
    {
        $report = $this->import('slice', [[
            'id' => self::SLICE, 'name' => 'News', 'vid' => 12, 'mlxctrl' => self::OTHER, 'owner' => self::OTHER, 'created_by' => 'x', 'created_at' => 5,
            'slice_url' => 'https://source/', 'deleted' => 1, 'fileman_dir' => 'source', 'reading_password' => 'secret', 'export_to_all' => 1,
        ]]);

        self::assertSame(['OK: 1 slice ([' . self::SLICE . ']); ', 'Note: slice of ' . self::SLICE . ': mlxctrl ' . self::OTHER . ' cleared - not of this module; '
            . 'slice ' . self::SLICE . ': a new random reading password - set it in the slice settings; '], $report);
        $row = $this->metabase->rows('slice')[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $row['reading_password']);
        unset($row['reading_password']);
        self::assertSame(['id' => self::SLICE, 'name' => 'News', 'vid' => 12, 'deleted' => 0, 'created_by' => 'importer', 'created_at' => 1700000000], $row);
    }

    public function test_mlxctrl_of_the_slice_itself_and_no_password_stay(): void
    {
        self::assertSame(['OK: 1 slice ([' . self::SLICE . ']); '], $this->import('slice', [['id' => self::SLICE, 'mlxctrl' => self::SLICE, 'reading_password' => '']]));
        self::assertSame(['id' => self::SLICE, 'mlxctrl' => self::SLICE, 'deleted' => 0, 'created_by' => 'importer', 'created_at' => 1700000000], $this->metabase->rows('slice')[0]);
    }

    public function test_the_references_of_a_collection_are_cleared(): void
    {
        $report = $this->import('alerts_collection', [['id' => 'AbC12', 'module_id' => self::ALERTS, 'slice_id' => self::OTHER, 'emailid_welcome' => 3, 'emailid_alert' => 0]], self::ALERTS);

        self::assertSame(['OK: 1 alerts_collection ([AbC12]); ', 'Note: alerts_collection of ' . self::ALERTS . ': slice_id ' . self::OTHER . ' cleared - not of this module; '
            . 'alerts_collection of ' . self::ALERTS . ': emailid_welcome 3 cleared - not of this module; '], $report);
        self::assertSame([['id' => 'AbC12', 'module_id' => self::ALERTS, 'emailid_alert' => 0]], $this->metabase->rows('alerts_collection'));
    }

    public function test_email_attachments_reading_local_files_are_cleared(): void
    {
        self::assertSame(['OK: 2 email ([3][4]); ', 'Note: email 3: attachments cleared (a local file or a computed value); '], $this->import('email', [
            ['id' => 3, 'owner_module_id' => self::SLICE, 'attachments' => '/etc/aa/config.php'],
            ['id' => 4, 'owner_module_id' => self::SLICE, 'attachments' => 'https://example.org/a.pdf'],
        ]));
        self::assertSame(['', 'https://example.org/a.pdf'], array_column($this->metabase->rows('email'), 'attachments'));
    }

    public function test_a_control_file_of_the_source_site_is_dropped_the_router_home_kept(): void
    {
        self::assertSame(['OK: 1 site ([' . self::SITE . ']); ', 'Note: site ' . self::SITE . ': control file state_file dropped; '], $this->import('site', [['id' => self::SITE, 'state_file' => 'mysite.php', 'flag' => 0, 'structure' => 'x']], self::SITE));
        $this->import('site', [['id' => self::SITE, 'state_file' => '/cz/home', 'flag' => 1]], self::SITE);
        self::assertSame([['id' => self::SITE, 'flag' => 0, 'structure' => 'x'], ['id' => self::SITE, 'state_file' => '/cz/home', 'flag' => 1]], $this->metabase->rows('site'));
    }

    public function test_the_howoften_row_starts_now(): void
    {
        $this->target->collections = ['AbC12' => self::ALERTS];

        self::assertSame(['OK: 1 alerts_collection_howoften ([AbC12,daily]); '], $this->import('alerts_collection_howoften', [['collectionid' => 'AbC12', 'howoften' => 'daily', 'last' => 5]], self::ALERTS));
        self::assertSame([['collectionid' => 'AbC12', 'howoften' => 'daily', 'last' => 1700000000]], $this->metabase->rows('alerts_collection_howoften'));
    }

    // --- AUTO_INCREMENT --------------------------------------------------------------------------

    public function test_a_taken_number_is_inserted_again_without_it(): void
    {
        $this->metabase->taken = ['view' => [13]];

        $report = $this->import('view', [['id' => 12, 'slice_id' => self::SLICE, 'name' => 'A'], ['id' => 13, 'slice_id' => self::SLICE, 'name' => 'B'], ['id' => 12, 'slice_id' => self::SLICE, 'name' => 'C']]);

        self::assertSame(['OK: 3 view ([12][13][12]); ', 'Note: view id 13 taken here → 1000 (references not rewritten); view id 12 taken here → 1001 (references not rewritten); '], $report, 'the chunk\'s own collision too');
        self::assertSame([12, 13, null, 12, null], array_map(static fn(array $r): ?int => $r['id'] ?? null, $this->metabase->rows('view')));
    }

    public function test_a_taken_short_id_is_replaced_a_failed_row_reported(): void
    {
        $this->metabase->taken = ['item' => [5]];

        self::assertSame(['OK: 1 item ([' . self::ITEM_A . ']); ', 'Note: item short_id 5 taken here → 1000 (references not rewritten); '], $this->import('item', [['id' => self::ITEM_A, 'short_id' => 5, 'slice_id' => self::SLICE]]));
        self::assertSame(['Err: 1 field ([t,' . self::SLICE . ']); '], $this->import('field', [['id' => 't', 'slice_id' => self::SLICE, 'name' => 'bad']]), 'today\'s Err line');
    }

    // --- F4: every row of the chunk's module ----------------------------------------------------

    public function test_a_row_of_another_module_or_with_a_non_scalar_value_is_refused(): void
    {
        self::assertSame(['OK: 1 field ([headline........,' . self::SLICE . ']); ', self::REFUSED . '3 field ([text............,' . self::OTHER . '][abstract........,][x,' . self::SLICE . ']); '], $this->import('field', [
            ['id' => 'headline........', 'slice_id' => self::SLICE],
            ['id' => 'text............', 'slice_id' => self::OTHER],
            ['id' => 'abstract........'],
            ['id' => 'x', 'slice_id' => self::SLICE, 'name' => ['array']],
        ]));
        self::assertSame(['headline........'], array_column($this->metabase->rows('field'), 'id'));
    }

    /** an older Central names the template module (and replaces the ids only in the rows) */
    public function test_a_chunk_of_an_older_central_is_refused(): void
    {
        $rows = [['id' => 'a', 'slice_id' => self::SLICE]];

        self::assertSame(['Err: refused, module ' . self::TEMPLATE . ' does not exist here - is the sending Central up to date?: 1 field ([a,' . self::SLICE . ']); '], $this->import('field', $rows, self::TEMPLATE));
        $this->target->modules[self::TEMPLATE] = 'S';   // a copy within the AA
        self::assertSame([self::REFUSED . '1 field ([a,' . self::SLICE . ']); '], $this->import('field', $rows, self::TEMPLATE));
        self::assertSame(['Err: refused, module x does not exist here - is the sending Central up to date?: 1 field ([a,x]); '], $this->import('field', [['id' => 'a', 'slice_id' => 'x']], 'x'));
        self::assertSame([], $this->metabase->inserted);
    }

    // --- items -----------------------------------------------------------------------------------

    public function test_only_items_into_the_prepared_slice_pass(): void
    {
        self::assertSame(['OK: 1 item ([' . self::ITEM_A . ']); ', self::REFUSED . '1 item ([' . self::ITEM_B . ']); '], $this->import('item', [
            ['id' => self::ITEM_A, 'short_id' => 5, 'slice_id' => self::SLICE, 'status_code' => 1],
            ['id' => self::ITEM_B, 'short_id' => 6, 'slice_id' => self::OTHER, 'status_code' => 1],
        ]));
        self::assertSame([['id' => self::ITEM_A, 'short_id' => 5, 'slice_id' => self::SLICE, 'status_code' => 1]], $this->metabase->rows('item'));
        self::assertSame(['Err: refused, not a table of a module of type W: 1 item ([' . self::ITEM_A . ']); '], $this->import('item', [['id' => self::ITEM_A, 'slice_id' => self::SITE]], self::SITE));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function itemRows(): array
    {
        return ['content' => ['content', ['field_id' => 'headline........', 'text' => 'x']], 'discussion' => ['discussion', ['subject' => 'x']]];
    }

    #[DataProvider('itemRows')]
    public function test_rows_of_an_item_of_another_slice_are_refused(string $table, array $row): void
    {
        $this->target->items = [self::ITEM_A => self::SLICE, self::ITEM_B => self::OTHER];
        $rows = array_map(static fn(string $item, int $i): array => ['id' => "d$i", 'item_id' => $item] + $row, [self::ITEM_A, self::ITEM_B, self::ITEM_C, 'x'], [0, 1, 2, 3]);

        $id = ($table === 'content') ? static fn(string $d): string => '' : static fn(string $d): string => $d;
        self::assertSame(["OK: 1 $table ([{$id('d0')}]); ", self::REFUSED . "3 $table ([{$id('d1')}][{$id('d2')}][{$id('d3')}]); "], $this->import($table, $rows));
        self::assertSame([self::ITEM_A], array_column($this->metabase->rows($table), 'item_id'));
    }

    public function test_rows_of_a_collection_of_another_module_are_refused(): void
    {
        $this->target->collections = ['AbC12' => self::ALERTS, 'Zz999' => self::OTHER];

        self::assertSame(['OK: 1 alerts_collection_filter ([AbC12,3]); ', self::REFUSED . '1 alerts_collection_filter ([Zz999,4]); '], $this->import('alerts_collection_filter', [
            ['collectionid' => 'AbC12', 'filterid' => 3],
            ['collectionid' => 'Zz999', 'filterid' => 4],
        ], self::ALERTS));
    }

    // --- constant groups -------------------------------------------------------------------------

    public function test_a_new_group_is_created_and_owned_by_the_module(): void
    {
        self::assertSame(['OK: 2 constant ([' . self::id('1') . '][' . self::id('2') . ']); ', 'Note: constant group news_cat now owned by ' . self::SLICE . '; '], $this->import('constant', [
            ['id' => self::id('1'), 'group_id' => 'lt_groupNames', 'name' => 'news_cat', 'value' => 'news_cat', 'short_id' => 77],
            ['id' => self::id('2'), 'group_id' => 'news_cat', 'name' => 'Sport', 'value' => 'sport'],
        ]));
        self::assertSame(['news_cat'], $this->target->askedGroups, 'asked once per chunk, before any insert');
        self::assertArrayNotHasKey('short_id', $this->metabase->rows('constant')[0], 'the numbering of this AA');
        self::assertSame([['group_id' => 'news_cat', 'slice_id' => self::SLICE]], $this->metabase->rows('constant_slice'));
    }

    public function test_a_group_of_the_module_takes_more_constants(): void
    {
        $this->target->groups = ['news_cat' => self::SLICE];

        self::assertSame(['OK: 1 constant ([' . self::id('2') . ']); '], $this->import('constant', [['id' => self::id('2'), 'group_id' => 'news_cat', 'name' => 'Sport']]));
        self::assertSame([], $this->metabase->rows('constant_slice'), 'owned already');
    }

    /** @return array<string, array{string}> */
    public static function foreignOwners(): array
    {
        return ['another slice' => [self::OTHER], 'no owner' => ['']];
    }

    #[DataProvider('foreignOwners')]
    public function test_an_existing_foreign_group_is_refused(string $owner): void
    {
        $this->target->groups = ['news_cat' => $owner];

        self::assertSame(['OK: 1 constant ([' . self::id('4') . ']); ', self::REFUSED . '2 constant ([' . self::id('1') . '][' . self::id('2') . ']); ', 'Note: constant group fresh now owned by ' . self::SLICE . '; '], $this->import('constant', [
            ['id' => self::id('1'), 'group_id' => 'lt_groupNames', 'name' => 'news_cat', 'value' => 'news_cat'],
            ['id' => self::id('2'), 'group_id' => 'news_cat', 'name' => 'Sport'],
            ['id' => self::id('4'), 'group_id' => 'fresh', 'name' => 'New'],
        ]));
        self::assertSame([self::REFUSED . '1 constant_slice ([news_cat]); '], $this->import('constant_slice', [['group_id' => 'news_cat', 'slice_id' => self::SLICE]]));
        self::assertSame(['OK: 1 constant_slice ([mine]); '], $this->import('constant_slice', [['group_id' => 'mine', 'slice_id' => self::SLICE, 'propagate' => 1]]));
        self::assertSame([['group_id' => 'fresh', 'slice_id' => self::SLICE], ['group_id' => 'mine', 'slice_id' => self::SLICE, 'propagate' => 1]], $this->metabase->rows('constant_slice'));
    }

    /** group_id is varbinary: `Foo` and `foo` are two groups; a name longer than the column would be cut into another */
    public function test_group_names_are_exact_bytes_of_the_column_length(): void
    {
        $this->target->groups = ['foo' => self::OTHER, str_repeat('g', 16) => self::OTHER];

        self::assertSame(['OK: 1 constant ([' . self::id('1') . ']); ', self::REFUSED . '2 constant ([' . self::id('2') . '][' . self::id('3') . ']); ', 'Note: constant group Foo now owned by ' . self::SLICE . '; '], $this->import('constant', [
            ['id' => self::id('1'), 'group_id' => 'Foo', 'name' => 'A'],
            ['id' => self::id('2'), 'group_id' => 'foo', 'name' => 'B'],
            ['id' => self::id('3'), 'group_id' => str_repeat('g', 16) . 'x', 'name' => 'C'],
        ]));
        self::assertSame(['Foo', 'foo'], $this->target->askedGroups, 'the 17-byte name is not even asked');
    }

    // --- Copy Slice: chunks of a definition --------------------------------------------------------

    public function test_the_chunks_name_the_destination_module(): void
    {
        $module = static fn(array $chunks): string => (new \ReflectionProperty(AA_Definition_Module_Chunk::class, 'module_id'))->getValue($chunks[0]);

        self::assertSame(self::SLICE, $module(DefinitionStore::chunks(self::TEMPLATE, ['field' => [['id' => 'a']]], [self::TEMPLATE => self::SLICE])));
        self::assertSame(self::TEMPLATE, $module(DefinitionStore::chunks(self::TEMPLATE, ['field' => [['id' => 'a']]], null)));
        self::assertSame([], (new ChunkImporter($this->target, $this->metabase))->run(self::SLICE, ['field' => []]), 'an empty chunk reports nothing');
    }

    /** a template AA answering Get_Module_Defs with rows of another slice of this AA - the local import
     *  writes only into the module Copy Slice created */
    public function test_the_local_import_writes_only_into_the_new_module(): void
    {
        $property = new \ReflectionProperty(\AA::class, 'metabase');
        $saved    = $property->getValue();
        $property->setValue(null, $this->metabase);
        try {
            $def = new AA_Definition_Module_Slice();
            (new \ReflectionProperty($def, 'module_id'))->setValue($def, self::TEMPLATE);
            (new \ReflectionProperty($def, 'data'))->setValue($def, [
                'module' => [self::TEMPLATE => ['id' => self::TEMPLATE, 'name' => 'Copy', 'type' => 'S']],
                'field'  => [['id' => 'headline........', 'slice_id' => self::OTHER]],
                'item'   => [['id' => self::ITEM_A, 'slice_id' => self::OTHER]],
            ]);
            $report = $def->moduleImport([self::TEMPLATE => self::SLICE], $this->target);
        } finally {
            $property->setValue(null, $saved);
        }

        self::assertSame(['OK: 1 module ([' . self::SLICE . ']); ', self::REFUSED . '1 field ([headline........,' . self::OTHER . ']); ', self::REFUSED . '1 item ([' . self::ITEM_A . ']); '], $report);
        self::assertSame(['module'], array_column($this->metabase->inserted, 0), 'nothing into ' . self::OTHER);
    }
}
