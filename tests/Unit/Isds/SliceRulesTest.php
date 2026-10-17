<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Schema;
use AA\Isds\SliceProblem;
use AA\Isds\SliceRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * The rules a site and the two slices of a mass sending must pass - one problem per thing to fix,
 * telling how to fix it and on which page, never a reading password.
 */
final class SliceRulesTest extends TestCase
{
    private const SITE     = '51de0000000000000000000000000001';
    private const SITE2    = '51de0000000000000000000000000002';
    private const CAMPAIGN = 'cafe0000000000000000000000000001';
    private const MESSAGE  = 'beef0000000000000000000000000002';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/fixtures/FakeSliceRules.php';
    }

    protected function tearDown(): void
    {
        FakeSliceRules::forget();
    }

    public function test_sliceProblems_readyHasNone(): void
    {
        self::assertSame([], self::ready()->sliceProblems(self::CAMPAIGN, self::MESSAGE));
        self::assertSame([], self::ready()->siteProblems(self::SITE, self::CAMPAIGN, self::MESSAGE));
    }

    // AA stores ids in small letters; config.php or a template may not
    public function test_sliceProblems_idsInCapitalsAreTheSame(): void
    {
        self::assertSame([], self::ready()->sliceProblems(strtoupper(self::CAMPAIGN), strtoupper(self::MESSAGE)));
        self::assertSame([], self::ready()->siteProblems(strtoupper(self::SITE), strtoupper(self::CAMPAIGN), strtoupper(self::MESSAGE)));
    }

    public function test_sliceProblems_oneSliceTwice(): void
    {
        self::assertSame(['The campaign slice and the message slice must be two slices'], self::texts(self::ready()->sliceProblems(self::CAMPAIGN, self::CAMPAIGN)));
        self::assertSame(['The campaign slice and the message slice must be two slices'], self::texts(self::ready()->sliceProblems(self::CAMPAIGN, strtoupper(self::CAMPAIGN))));
    }

    // its other problems would only repeat that it is not there
    public function test_sliceProblems_missingSliceOnce(): void
    {
        $rules = self::ready();
        unset($rules->slices[self::MESSAGE]);

        self::assertSame(['There is no slice ' . self::MESSAGE], self::texts($rules->sliceProblems(self::CAMPAIGN, self::MESSAGE)));
        self::assertSame(['There is no slice x'], self::texts($rules->sliceProblems(self::CAMPAIGN, 'x')));
    }

    public function test_sliceProblems_sliceInTheTrash(): void
    {
        $rules = self::ready();
        unset($rules->slices[self::MESSAGE]);
        $rules->trash = [self::MESSAGE];

        self::assertSame(['Slice "ISDS messages" is in the trash - restore it from the trash, or name another slice'], self::texts($rules->sliceProblems(self::CAMPAIGN, self::MESSAGE)));
    }

    /** @return array<string, array{mixed}> */
    public static function noPasswords(): array
    {
        // '0': AA_Slice does not hash a falsy password, and every request then matches it
        return ['empty' => [''], 'zero' => ['0'], 'no column' => [false], 'none' => [null]];
    }

    #[DataProvider('noPasswords')]
    public function test_sliceProblems_noReadingPassword(mixed $password): void
    {
        $rules = self::ready();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => $password]);

        self::assertEquals([new SliceProblem('Slice "ISDS campaigns" has no reading password - fill in Password for Reading in the settings of the slice', self::CAMPAIGN, SliceRules::SLICE_PAGE)], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    /** @return array<string, array{string, string}> */
    public static function openSettings(): array
    {
        return [
            'listed by slice.php' => ['d_listlen', 'Listing length: 0'],
            'anonymous posting'   => ['permit_anonymous_post', 'Allow anonymous posting of items: Not allowed'],
            'anonymous editing'   => ['permit_anonymous_edit', 'Allow anonymous editing of items: Not allowed'],
        ];
    }

    #[DataProvider('openSettings')]
    public function test_sliceProblems_openSetting(string $setting, string $fix): void
    {
        $rules = self::ready();
        $rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, [$setting => '1']);

        self::assertEquals([new SliceProblem("Close slice \"ISDS messages\" - set in its settings $fix", self::MESSAGE, SliceRules::SLICE_PAGE)], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    public function test_sliceProblems_openSettingsInOneProblem(): void
    {
        $rules = self::ready();
        $rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, array_fill_keys(SliceRules::CLOSED, '2'));

        self::assertSame(['Close slice "ISDS messages" - set in its settings Listing length: 0; Allow anonymous posting of items: Not allowed; Allow anonymous editing of items: Not allowed'], self::texts($rules->sliceProblems(self::CAMPAIGN, self::MESSAGE)));
    }

    public function test_sliceProblems_exportToAll(): void
    {
        $rules = self::ready();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['export_to_all' => '1']);

        self::assertEquals([new SliceProblem('Slice "ISDS campaigns" lets any slice import its items - turn off Enable export to any slice on its Inner Node Feeding page', self::CAMPAIGN, SliceRules::FEEDING_PAGE)], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    /** @return array<string, array{string, string, string}> */
    public static function outlets(): array
    {
        return [
            'export granted' => ['export', 'Slice "ISDS messages" lets other slices import its items - move them to Export disable on its Inner Node Feeding page', SliceRules::FEEDING_PAGE],
            'fed out'        => ['feeds_out', 'Items of slice "ISDS messages" are fed to other slices - stop the import in the slices its Inner Node Feeding page lists as Currently exported to', SliceRules::FEEDING_PAGE],
            'fed in'         => ['feeds_in', 'Slice "ISDS messages" imports items from other slices - move them to Do not import on its Inner Node Feeding page', SliceRules::FEEDING_PAGE],
            'notification'   => ['notify', 'Slice "ISDS messages" mails its items to the addresses of its Email Notification - remove the addresses there', SliceRules::NOTIFY_PAGE],
        ];
    }

    // copies of the items the reading password does not cover
    #[DataProvider('outlets')]
    public function test_sliceProblems_outlet(string $outlet, string $text, string $page): void
    {
        $rules = self::ready();
        $rules->outlets[self::MESSAGE] = [$outlet];

        self::assertEquals([new SliceProblem($text, self::MESSAGE, $page)], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    public function test_sliceProblems_missingFieldsOnly(): void
    {
        $rules = self::ready();
        // a slice that lacks fields gets no advice about the storage or the defaults of the others
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, without: [Schema::CAMPAIGN_BODY, Schema::CAMPAIGN_STARTED_AT], asText: [Schema::CAMPAIGN_RECIPIENTS], stateDefault: '');
        $rules->slices[self::MESSAGE]  = FakeSliceRules::sliceOf(self::MESSAGE, false, without: [Schema::MESSAGE_TEST]);

        self::assertEquals([
            new SliceProblem('Slice "ISDS campaigns" lacks fields of a campaign slice: full_text......., date...........1', self::CAMPAIGN, SliceRules::FIELDS_PAGE),
            new SliceProblem('Slice "ISDS messages" lacks fields of a message slice: switch.........1', self::MESSAGE, SliceRules::FIELDS_PAGE),
        ], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    // the campaign first: two slices given the other way round are one mistake
    public function test_sliceProblems_slicesReversed(): void
    {
        $rules = self::ready();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, false);
        $rules->slices[self::MESSAGE]  = FakeSliceRules::sliceOf(self::MESSAGE, true);

        self::assertEquals([new SliceProblem('Slices "ISDS campaigns" and "ISDS messages" are in reverse order - name the campaign slice first')], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    // reversed only when each slice has all fields of the other role
    public function test_sliceProblems_notReversed(): void
    {
        $rules = self::ready();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, false);
        self::assertSame(['Slice "ISDS campaigns" lacks fields of a campaign slice: ' . implode(', ', array_diff(Schema::CAMPAIGN_FIELDS, Schema::MESSAGE_FIELDS))], self::texts($rules->sliceProblems(self::CAMPAIGN, self::MESSAGE)), 'two message slices');

        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, without: [Schema::CAMPAIGN_BODY]);
        $rules->slices[self::MESSAGE]  = FakeSliceRules::sliceOf(self::MESSAGE, true);
        self::assertSame([
            'Slice "ISDS campaigns" lacks fields of a campaign slice: ' . Schema::CAMPAIGN_BODY,
            'Slice "ISDS messages" lacks fields of a message slice: ' . implode(', ', array_diff(Schema::MESSAGE_FIELDS, Schema::CAMPAIGN_FIELDS)),
        ], self::texts($rules->sliceProblems(self::CAMPAIGN, self::MESSAGE)), 'a campaign slice and one short of it');
    }

    public function test_sliceProblems_numberStoredAsText(): void
    {
        $rules = self::ready();
        $rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, asText: [Schema::MESSAGE_SENT_AT, Schema::MESSAGE_DM_STATUS]);

        self::assertEquals([new SliceProblem('Slice "ISDS messages": store the fields number.........1, date...........1 as Number (Stored as, in the settings of each field)', self::MESSAGE, SliceRules::FIELDS_PAGE)], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    /** @return array<string, array{string}> */
    public static function otherDefaults(): array
    {
        return [
            'none'          => [''],
            'another state' => ['txt:queued'],
            'in capitals'   => ['txt:DRAFT'],
            // draft as its first value, but not a fixed text - a generator is never run
            'multiple'      => ['mul:draft|x'],
            'random'        => ['rnd:5'],
        ];
    }

    // a campaign added in the admin must not start as one being sent
    #[DataProvider('otherDefaults')]
    public function test_sliceProblems_stateNotDraftByDefault(string $default): void
    {
        $rules = self::ready();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, stateDefault: $default);

        self::assertEquals([new SliceProblem('Slice "ISDS campaigns": set the Default of the field text...........3 to txt:draft', self::CAMPAIGN, SliceRules::FIELDS_PAGE)], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    // qte is the old name of txt
    public function test_sliceProblems_draftAsQuotedText(): void
    {
        $rules = self::ready();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, stateDefault: 'qte:draft');

        self::assertSame([], $rules->sliceProblems(self::CAMPAIGN, self::MESSAGE));
    }

    /** @return array<string, array{string}> */
    public static function noSites(): array
    {
        return ['unknown' => [self::SITE2], 'not an id' => ['<b>site</b>'], 'empty' => ['']];
    }

    #[DataProvider('noSites')]
    public function test_siteProblems_noSite(string $site): void
    {
        self::assertEquals([new SliceProblem("There is no site $site")], self::ready()->siteProblems($site, self::CAMPAIGN, self::MESSAGE));
    }

    public function test_siteProblems_siteInTheTrash(): void
    {
        $rules = self::ready();
        $rules->trash = [self::SITE2];
        $rules->names[self::SITE2] = 'Old terminal';

        self::assertSame(['Site "Old terminal" is in the trash - restore it from the trash, or name another site'], self::texts($rules->siteProblems(self::SITE2, self::CAMPAIGN, self::MESSAGE)));
    }

    public function test_siteProblems_slicesTheSiteDoesNotUse(): void
    {
        $rules = self::ready();
        $rules->related[self::SITE] = [];

        self::assertEquals([
            new SliceProblem('Site "Terminal" does not use slice "ISDS campaigns" - add the slice to Uses modules in the settings of the site', self::SITE, SliceRules::SITE_PAGE),
            new SliceProblem('Site "Terminal" does not use slice "ISDS messages" - add the slice to Uses modules in the settings of the site', self::SITE, SliceRules::SITE_PAGE),
        ], $rules->siteProblems(self::SITE, self::CAMPAIGN, self::MESSAGE));
        self::assertCount(1, $rules->siteProblems(self::SITE, self::CAMPAIGN, strtoupper(self::CAMPAIGN)), 'one slice named twice');
    }

    // a slice that is not there is a problem of the slices, not of the site
    public function test_siteProblems_missingSliceNotAsUnused(): void
    {
        $rules = self::ready();
        unset($rules->slices[self::MESSAGE]);
        $rules->related[self::SITE] = [self::CAMPAIGN];

        self::assertSame([], $rules->siteProblems(self::SITE, self::CAMPAIGN, self::MESSAGE));
        self::assertSame([], $rules->siteProblems(self::SITE, self::CAMPAIGN, 'x'));
    }

    // the statements themselves - FakeSliceRules answers in their place everywhere else
    public function test_outlets_fourLookupsOncePerRequest(): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql): array => str_starts_with($sql, 'SELECT to_id ') ? [['to_id' => 'x']] : [];
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        \AA::Contentcache()->clear();
        $outlets = new \ReflectionMethod(SliceRules::class, 'outlets');
        try {
            $first  = $outlets->invoke(new SliceRules(), self::MESSAGE);
            $second = $outlets->invoke(new SliceRules(), self::MESSAGE);
            $log    = RecordingDb::$log;
        } finally {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            \AA::Contentcache()->clear();
        }

        self::assertSame(['feeds_out' => false, 'feeds_in' => true, 'export' => false, 'notify' => false], $first);
        self::assertSame($first, $second);
        $id = '0x' . self::MESSAGE;
        self::assertSame([
            "SELECT from_id FROM \"feeds\" WHERE from_id = $id LIMIT  1",
            "SELECT to_id FROM \"feeds\" WHERE to_id = $id LIMIT  1",
            "SELECT from_id FROM \"feedperms\" WHERE from_id = $id LIMIT  1",
            "SELECT slice_id FROM \"email_notify\" WHERE slice_id = $id LIMIT  1",
        ], $log, 'the second time from the cache');
    }

    private static function ready(): FakeSliceRules
    {
        return FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE);
    }

    /**
     * @param list<SliceProblem> $problems
     * @return list<string>
     */
    private static function texts(array $problems): array
    {
        return array_map(static fn(SliceProblem $p): string => $p->text, $problems);
    }
}
