<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\SdsList;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsRegistry;
use AA\Optimize\Finding;
use AA_Field;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Registry\Fixtures\RecordingSync;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Optimize\Fixtures\RecordingIsdsCheck;

/**
 * CheckIsdsRegistry: the items of paired slices without a data box or with another one than the
 * register finds by IČO - counts only, in the terms of the page's filters, never item names, which
 * the State Check mail would carry. The items come from RecordingSync, the boxes from the 31 real
 * ones of the SdsList fixture.
 */
final class CheckIsdsRegistryTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Isds/Registry/fixtures/seznam_ds_ovm-sample.xml';

    private const SLICE  = '8d8d8d8d8d8d8d8d8d8d8d8d8d8d8d8d';
    private const SLICE2 = '9e9e9e9e9e9e9e9e9e9e9e9e9e9e9e9e';
    private const SLICE3 = 'afafafafafafafafafafafafafafafaf';

    private const NAME = 'headline........';
    private const ICO  = 'text...........1';
    private const DS   = 'text...........2';

    /** names of items the findings must never carry */
    private const ITEM_NAMES = ['Brno', 'Liberec', 'Cerhenice', 'Adamov', 'Nikde', 'Praha'];

    private const STATUS_LABELS = ['New', 'Changed', 'Not found', 'Several candidates'];

    /** @var list<Box> */
    private static array $boxes = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../Isds/Registry/fixtures/RecordingSync.php';
        require_once __DIR__ . '/fixtures/RecordingIsdsCheck.php';
        self::$boxes = iterator_to_array(SdsList::fromXml(self::FIXTURE), false);
    }

    public function test_extendedOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_EXTENDED], CheckIsdsRegistry::types());
    }

    public function test_noPairedSlice_ok(): void
    {
        self::assertSame([Finding::OK], self::severities((new RecordingIsdsCheck())->findings()));
    }

    // a slice in the trash keeps its settings until it is purged
    public function test_slicesInTheTrashSkipped(): void
    {
        $check = self::check([self::SLICE => self::needsWork(), self::SLICE2 => self::allFine()]);
        $check->trashed = [self::SLICE];

        $findings = $check->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('Paired slices whose items have the data boxes of the register: 1', $findings[0]->title);
        $check->trashed = [self::SLICE, self::SLICE2];
        self::assertSame('No slice pairs its items with the register of data boxes', $check->findings()[0]->title);
    }

    public function test_registerMissing_infoWithThePagesOfThePairedSlices(): void
    {
        $check = self::check([self::SLICE => self::needsWork(), self::SLICE2 => self::allFine()]);
        $check->register = null;

        $findings = $check->findings();

        self::assertSame([Finding::INFO], self::severities($findings));
        self::assertSame('register', $findings[0]->key);
        self::assertCount(2, $findings[0]->links);
        self::assertStringContainsString('se_isds_registry.php?change_id=' . self::SLICE, (string) array_values($findings[0]->links)[0]);
        self::assertSame(0, $check->registerReads, 'nothing to compare with');
    }

    // two paired slices of one name: each link says which one it opens
    public function test_registerMissing_linksToSlicesOfOneNameTellThemApart(): void
    {
        $check = self::check([self::SLICE => self::allFine(), self::SLICE2 => self::allFine(), self::SLICE3 => self::allFine()]);
        $check->names    = [self::SLICE => 'Obce', self::SLICE2 => 'Obce', self::SLICE3 => 'Kraje'];
        $check->register = null;

        $links = $check->findings()[0]->links;

        self::assertSame(['Data boxes of Obce (8d8d8d8d)', 'Data boxes of Obce (9e9e9e9e)', 'Data boxes of Kraje'], array_keys($links));
        self::assertStringContainsString('change_id=' . self::SLICE2, $links['Data boxes of Obce (9e9e9e9e)']);
    }

    // the register's path is not known, or PHP may not look into its directory
    public function test_registerDirectoryUnusable_warningNamesIt(): void
    {
        $check = self::check([self::SLICE => self::needsWork()]);
        $check->register = new \RuntimeException('PHP cannot look into the directory of the register of data boxes /srv/aa/img_upload/abc');

        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('register-dir', $findings[0]->key);
        self::assertStringContainsString('/srv/aa/img_upload/abc', $findings[0]->evidence[0][_m('Problem')]);
        self::assertSame(0, $check->registerReads);
    }

    public function test_registerOld_infoAndTheCountsStill(): void
    {
        $check = self::check([self::SLICE => self::needsWork()]);
        $check->register = ['mtime' => (int) mktime(10, 0, 0, 10, 16, 2026), 'size' => 17000000];
        $check->now      = (int) mktime(10, 0, 0, 10, 23, 2026);
        self::assertSame([Finding::WARNING], self::severities($check->findings()), 'not older than 7 days yet');

        $check->now = (int) mktime(10, 1, 0, 10, 23, 2026);
        $findings   = $check->findings();

        self::assertSame([Finding::INFO, Finding::WARNING], self::severities($findings));
        self::assertSame('The downloaded register of data boxes is older than 7 days', $findings[0]->title);
        self::assertSame([[_m('Downloaded') => '2026-10-16 10:00']], $findings[0]->evidence);
    }

    public function test_registerUnreadable_warning(): void
    {
        $check = self::check([self::SLICE => self::needsWork()]);
        $check->boxes = new \UnexpectedValueException('Not the list <b>of boxes</b>');

        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('register-unreadable', $findings[0]->key);
        self::assertSame(safe('Not the list <b>of boxes</b>'), $findings[0]->evidence[0][_m('Error')]);
        self::assertStringContainsString('downloading it again', $findings[0]->advice, 'a damaged copy is replaced');

        $check->boxes = new \RuntimeException('Cannot read the list of data boxes /srv/aa/x.xml');
        self::assertStringContainsString('check the directory and the permissions', $check->findings()[0]->advice);
    }

    public function test_settingsNoLongerFit_warningNamesTheField(): void
    {
        $check = self::check([self::SLICE => self::needsWork(), self::SLICE2 => self::allFine()]);
        $check->syncs[self::SLICE2]->fields = self::fields(without: [self::DS]);   // the box field deleted since

        $findings = $check->findings();

        self::assertSame([Finding::WARNING, Finding::WARNING], self::severities($findings));
        self::assertSame('fit:' . self::SLICE2, $findings[1]->key);
        self::assertStringContainsString(self::DS, $findings[1]->evidence[0][_m('Problem')]);
        self::assertSame(1, $check->registerReads, 'read once for all slices');
    }

    public function test_counts_warningWithoutItemNames(): void
    {
        $check = self::check([self::SLICE => self::needsWork()]);
        $check->names = [self::SLICE => 'Území <b>ČR</b>'];

        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        $finding = $findings[0];
        // without a box: Brno (new), Adamov (ambiguous), Nikde (unmatched); another box: Liberec
        self::assertSame(4, $finding->count);
        self::assertSame('Slice "Území <b>ČR</b>": items without a data box: 3, items with another box than the register by IČO: 1', $finding->title);
        // as many as the filters of the page show; the same rows are not counted
        self::assertSame([array_combine(['Slice', ...self::STATUS_LABELS], [safe('Území <b>ČR</b>'), '1', '1', '2', '1'])], $finding->evidence);
        self::assertSame(self::SLICE, $finding->key);
        self::assertSame('Území <b>ČR</b>', $finding->place);
        self::assertStringContainsString('se_isds_registry.php?change_id=' . self::SLICE, (string) array_values($finding->links)[0]);
        $all = serialize($finding);
        foreach (self::ITEM_NAMES as $item) {
            self::assertStringNotContainsString($item, $all);
        }
    }

    public function test_allFine_ok(): void
    {
        $findings = self::check([self::SLICE => self::allFine(), self::SLICE2 => self::allFine()])->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('Paired slices whose items have the data boxes of the register: 2', $findings[0]->title);
    }

    // a box found by name is a suggestion a person checks, no fault of the item
    public function test_anotherBoxFoundByName_noWarning(): void
    {
        $byName = [sprintf('a%031d', 7) => self::item('Praha', '', 'abcdefg')] + self::allFine();

        $fine  = self::check([self::SLICE => $byName])->findings();
        $other = self::check([self::SLICE => [sprintf('a%031d', 1) => self::item('Brno', '44992785')] + $byName])->findings();

        self::assertSame([Finding::OK], self::severities($fine));
        self::assertSame([Finding::WARNING], self::severities($other));
        self::assertSame(1, $other[0]->count, 'Brno only');
        self::assertStringEndsWith('items without a data box: 1, items with another box than the register by IČO: 0', $other[0]->title);
        self::assertSame(['1', '1', '1', '0'], array_values(array_slice($other[0]->evidence[0], 1)), 'Praha among Changed, as the page filter shows it');
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, array<string, mixed>> $slices slice id => item id => content */
    private static function check(array $slices): RecordingIsdsCheck
    {
        $check = new RecordingIsdsCheck();
        $check->boxes = self::$boxes;
        foreach ($slices as $slice => $items) {
            $config = Config::fromValues($slice, ['name_field' => self::NAME, 'ico_field' => self::ICO, 'ds_field' => self::DS]);
            $sync   = new RecordingSync($config);
            $sync->fields  = self::fields();
            $sync->content = $items;
            $check->configs[]       = $config;
            $check->syncs[$slice]   = $sync;
        }
        return $check;
    }

    /** @return array<string, array<string, mixed>> */
    private static function needsWork(): array
    {
        return [
            sprintf('a%031d', 1) => self::item('Brno', '44992785'),                // new
            sprintf('a%031d', 2) => self::item('Liberec', '00262978', 'x2pbqzq'),  // changed
            sprintf('a%031d', 3) => self::item('Cerhenice', '00235300', 'eerak7a'), // same
            sprintf('a%031d', 4) => self::item('Adamov'),                          // ambiguous
            sprintf('a%031d', 5) => self::item('Nikde'),                           // unmatched
            sprintf('a%031d', 6) => self::item('Nikde', '', 'abcdefg'),            // unmatched, has a box
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function allFine(): array
    {
        return [
            sprintf('a%031d', 3) => self::item('Cerhenice', '00235300', 'eerak7a'),
            sprintf('a%031d', 6) => self::item('Nikde', '', 'abcdefg'),
        ];
    }

    /** @param list<string> $without */
    private static function fields(array $without = []): FakeFields
    {
        $fields = [];
        foreach (array_diff([self::NAME, self::ICO, self::DS], $without) as $id) {
            // no slice id: AA_Field::isPasswordCopy() would load the slice
            $fields[] = new AA_Field(['id' => $id, 'name' => $id, 'slice_id' => '', 'in_item_tbl' => '', 'text_stored' => 1, 'input_show_func' => 'fld', 'input_insert_func' => 'qte', 'content_edit' => 0, 'html_default' => 0, 'multiple' => 0]);
        }
        return new FakeFields(self::SLICE, $fields);
    }

    /** @return array<string, list<array{value: string, flag: int}>> */
    private static function item(string $name, string $ico = '', string $ds = ''): array
    {
        $values = [self::NAME => $name, self::ICO => $ico, self::DS => $ds];
        return array_map(static fn(string $v): array => [['value' => $v, 'flag' => 0]], array_filter($values, static fn(string $v): bool => $v !== ''));
    }

    /** @param Finding[] $findings @return list<string> */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, array_values($findings));
    }
}
