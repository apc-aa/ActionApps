<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\Account;
use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\SdsList;
use AA\Isds\SliceRules;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsRegistry;
use AA\Optimize\Finding;
use AA_Field;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Registry\Fixtures\LockedRegisterIndex;
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

    private const SITE     = '1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c';
    private const SITE2    = '2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d';
    private const CAMPAIGN = '3e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3e';

    private const NAME = 'headline........';
    private const ICO  = 'text...........1';
    private const DS   = 'text...........2';

    /** names of items the findings must never carry */
    private const ITEM_NAMES = ['Brno', 'Liberec', 'Cerhenice', 'Adamov', 'Nikde', 'Praha'];

    private const STATUS_LABELS = ['New', 'Changed', 'Not found', 'Several candidates'];

    /** @var list<Box> */
    private static array $boxes = [];

    /** a directory of the test's own for a register file, '' = none */
    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../Isds/Registry/fixtures/RecordingSync.php';
        require_once __DIR__ . '/fixtures/RecordingIsdsCheck.php';
        self::$boxes = iterator_to_array(SdsList::fromString((string) file_get_contents(self::FIXTURE)), false);
    }

    protected function setUp(): void
    {
        LockedRegisterIndex::reset();
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', array_map(fn(string $name): string => "$this->dir/$name", array_diff((array) scandir($this->dir), ['.', '..'])));
            rmdir($this->dir);
        }
    }

    public function test_extendedOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_EXTENDED], CheckIsdsRegistry::types());
    }

    public function test_noPairedSlice_ok(): void
    {
        self::assertSame([Finding::OK], self::severities((new RecordingIsdsCheck())->findings()));
    }

    // a mass sending takes the names, types and boxes of its recipients from a paired slice the site of
    // its page uses: each site counts, a paired slice of the account's other site does not help
    public function test_sitesThatUseNoPairedSlice_warningNamesEach(): void
    {
        $check = self::check([self::SLICE => self::allFine(), self::SLICE2 => self::allFine()]);
        $check->trashed     = [self::SLICE2];
        $check->bindings    = [self::binding('NSZM', [self::SITE, self::SITE2]), self::binding('OBCE', [self::SITE2])];
        $check->siteModules = [self::SITE => [self::CAMPAIGN, self::SLICE], self::SITE2 => [self::CAMPAIGN, self::SLICE2, self::SLICE3]];
        $check->names       = [self::SITE2 => 'Terminál <b>2</b>'];
        $check->isdsAccount = true;

        $findings = $check->findings();

        self::assertSame(['unpaired'], self::keys($findings));
        self::assertSame(Finding::WARNING, $findings[0]->severity);
        self::assertSame(2, $findings[0]->count);
        $site2 = safe('Terminál <b>2</b>');
        self::assertSame([
            [_m('ISDS account') => Accounts::prefix(Account::TEST, 'NSZM'), _m('Site') => $site2],
            [_m('ISDS account') => Accounts::prefix(Account::TEST, 'OBCE'), _m('Site') => $site2],
        ], $findings[0]->evidence, 'the site with a paired slice is not listed');
        self::assertSame(['Site settings: Terminál <b>2</b>'], array_keys($findings[0]->links));
        self::assertStringContainsString(SliceRules::SITE_PAGE . '?change_id=' . self::SITE2, $findings[0]->links['Site settings: Terminál <b>2</b>']);
        $check->siteModules[self::SITE2][] = self::SLICE;
        self::assertSame([Finding::OK], self::severities($check->findings()));
    }

    // nothing to compare, no page to download the register on: only what to pair
    public function test_noPairedSliceWithAnAccount_onlyThatWarning(): void
    {
        $check = self::check([]);
        $check->bindings    = [self::binding('NSZM', [self::SITE])];
        $check->siteModules = [self::SITE => [self::CAMPAIGN, self::SLICE]];
        $check->isdsAccount = true;
        $check->register    = new \RuntimeException('PHP cannot look into the directory /srv/aa/img_upload/abc');

        $findings = $check->findings();

        self::assertSame(['unpaired'], self::keys($findings));
        self::assertSame(1, $findings[0]->count);
        self::assertSame([0, 0], [$check->registerReads, $check->indexChecks]);
    }

    // CheckIsdsAccounts reports each as an error
    public function test_bindingWithoutSitesOrSlicesOrWithNoSite_ok(): void
    {
        $check = self::check([]);
        $check->siteModules = [self::SITE => [self::CAMPAIGN]];
        $check->isdsAccount = true;
        $without = [
            'no sites'      => new Binding('NSZM', Account::TEST, [], self::CAMPAIGN, str_repeat('4f', 16), ['A broken constant'], Account::BASIC),
            'no slices'     => new Binding('NSZM', Account::TEST, [self::SITE], '', '', ['A broken constant'], Account::BASIC),
            'not a site'    => self::binding('NSZM', [self::SLICE]),
        ];
        foreach ($without as $case => $binding) {
            $check->bindings = [$binding];
            self::assertSame([Finding::OK], self::severities($check->findings()), $case);
        }
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

    // CheckIsdsAccounts warns of it every day, for each account
    public function test_registerMissing_infoWithAnIsdsAccountToo(): void
    {
        $check = self::check([self::SLICE => self::allFine()]);
        $check->register    = null;
        $check->isdsAccount = true;

        self::assertSame([Finding::INFO], self::severities($check->findings()));
        self::assertSame(0, $check->indexChecks);
    }

    // a mass sending checks its recipients in it
    public function test_registerOld_warningWithAnIsdsAccount(): void
    {
        $check = self::check([self::SLICE => self::allFine()]);
        $check->register    = ['state' => RegisterIndex::RECORDED, 'time' => 1760000000, 'size' => 17000000, 'sha256' => str_repeat('a', 64)];
        $check->now         = 1760000000 + 8 * 86400;
        $check->isdsAccount = true;

        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('register', $findings[0]->key);
    }

    // the index is made or checked only where a mass sending may read it
    public function test_indexProblem_warningWithAnIsdsAccount(): void
    {
        $check = self::check([self::SLICE => self::allFine()]);
        $check->indexProblem = 'The register of data boxes is not the one AA downloaded <b>- download it again</b>';

        self::assertSame([Finding::OK], self::severities($check->findings()));
        self::assertSame(0, $check->indexChecks);

        $check->isdsAccount = true;
        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('register-index', $findings[0]->key);
        self::assertSame(safe($check->indexProblem), $findings[0]->evidence[0][_m('Problem')]);
        $check->indexProblem = '';
        self::assertSame([Finding::OK], self::severities($check->findings()));
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
        $check->register = ['state' => RegisterIndex::RECORDED, 'time' => (int) mktime(10, 0, 0, 10, 16, 2026), 'size' => 17000000, 'sha256' => str_repeat('a', 64)];
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

    // another PHP user of the server put another register in place of the downloaded one: the items
    // are not compared with it; the index made of the downloaded one still serves a mass sending; the
    // age of a register that cannot be used does not matter
    public function test_registerSwapped_oneWarningInsteadOfTheCounts(): void
    {
        $check = $this->checkOverFile([self::SLICE => self::needsWork()]);
        $check->isdsAccount = true;
        self::assertSame([self::SLICE], self::keys($check->findings()), 'the downloaded register compares as before');

        LockedRegisterIndex::swap((string) $check->file);
        LockedRegisterIndex::$record['time'] = (string) ($check->now - 30 * 86400);
        $findings = $check->findings();

        self::assertSame(['register-changed'], self::keys($findings));
        self::assertSame(Finding::WARNING, $findings[0]->severity);
        self::assertSame('The register of data boxes is not the one AA downloaded', $findings[0]->title);
        self::assertStringContainsString('Download the register again on the page Data boxes from the register of a paired slice.', $findings[0]->advice);
        self::assertStringNotContainsString('mass sending', $findings[0]->advice);
        self::assertCount(1, $findings[0]->links);
    }

    // the index cannot be made of another file either: the same cause, one finding
    public function test_registerSwappedWithoutAnIndex_oneWarningThatNamesTheSending(): void
    {
        $check = $this->checkOverFile([self::SLICE => self::needsWork()]);
        $check->isdsAccount = true;
        LockedRegisterIndex::swap((string) $check->file);
        unlink(dirname((string) $check->file) . '/' . RegisterIndex::FILE);

        $findings = $check->findings();

        self::assertSame(['register-changed'], self::keys($findings));
        self::assertStringContainsString('A mass sending cannot use the register either.', $findings[0]->advice);
        self::assertSame(1, $check->indexChecks, 'the index was checked');
    }

    // an index that failed for another cause - a directory PHP cannot write into - keeps its finding
    // and its evidence: downloading the register again does not fix it
    public function test_registerSwappedAndTheIndexFailedOtherwise_twoFindings(): void
    {
        $check = $this->checkOverFile([self::SLICE => self::needsWork()]);
        $check->isdsAccount = true;
        LockedRegisterIndex::swap((string) $check->file);
        unlink(dirname((string) $check->file) . '/' . RegisterIndex::FILE);
        $failure = 'Cannot create a file in /srv/aa/img_upload/abc - it is missing or not writable';
        LockedRegisterIndex::$record = ['failed' => (string) time(), 'failed_of' => LockedRegisterIndex::$record['xml'], 'failure' => $failure] + LockedRegisterIndex::$record;

        $findings = $check->findings();

        self::assertSame(['register-index', 'register-changed'], self::keys($findings));
        self::assertSame(safe($failure), $findings[0]->evidence[0][_m('Problem')]);
        self::assertStringNotContainsString('mass sending', $findings[1]->advice, 'the finding of the index says so');
    }

    // downloaded by an older AA, or put there by hand: nothing vouches for it, nothing is read
    public function test_registerNotRecorded_oneWarningInsteadOfTheCounts(): void
    {
        $check = $this->checkOverFile([self::SLICE => self::needsWork()], recorded: false);
        $check->isdsAccount = true;
        $check->now = 1760000000 + 365 * 86400;

        $findings = $check->findings();

        self::assertSame(['register-changed'], self::keys($findings), 'no age finding, no second one of the index');
        self::assertSame('AA has no record of downloading this copy of the register', $findings[0]->title);
        self::assertStringContainsString('A mass sending cannot use the register either.', $findings[0]->advice);
        self::assertSame([0, 0], [$check->registerReads, $check->indexChecks]);
        $check->isdsAccount = false;
        self::assertStringNotContainsString('mass sending', $check->findings()[0]->advice);
    }

    // the downloaded register was deleted: a warning, so a deletion does not silently stop the comparison
    public function test_recordedRegisterGone_warningNotInfo(): void
    {
        $check = $this->checkOverFile([self::SLICE => self::needsWork()]);
        unlink((string) $check->file);

        $findings = $check->findings();

        self::assertSame(['register-changed'], self::keys($findings));
        self::assertSame('The register of data boxes is not the one AA downloaded', $findings[0]->title);
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

    /** @param list<string> $sites */
    private static function binding(string $name, array $sites): Binding
    {
        return new Binding($name, Account::TEST, $sites, self::CAMPAIGN, str_repeat('4f', 16), [], Account::BASIC);
    }

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

    /** the check over the fixture register in a file, downloaded - recorded and indexed - when $recorded
     *  @param array<string, array<string, mixed>> $slices see check() */
    private function checkOverFile(array $slices, bool $recorded = true): RecordingIsdsCheck
    {
        $this->dir = sys_get_temp_dir() . '/isds-check-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        $check       = self::check($slices);
        $check->file = $this->dir . '/register.xml';
        LockedRegisterIndex::genuine($check->file, $recorded);
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
    private static function keys(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->key, array_values($findings));
    }

    /** @param Finding[] $findings @return list<string> */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, array_values($findings));
    }
}
