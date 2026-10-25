<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\DatovkaConf;
use AA\Isds\SliceRules;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsViews;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

require_once __DIR__ . '/../Isds/fixtures/Sending.php';

/**
 * CheckIsdsViews: a name of a view of the page of a mass sending twice in the pair of slices of an
 * account, where the page finds it, and the entry view twice among the slices of its site. The accounts
 * and the slices are given here, the views too.
 */
final class CheckIsdsViewsTest extends TestCase
{
    private const OTHER_SITE = '5e7e0000000000000000000000000002';
    private const PUBLIC     = '9b1c0000000000000000000000000009';   // another slice the site uses

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../Isds/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/../Isds/fixtures/FakeSliceRules.php';
    }

    protected function tearDown(): void
    {
        FakeSliceRules::forget();
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    /**
     * The check of SITE, bound to the pair, using PUBLIC too; OTHER_SITE uses PUBLIC alone
     * @param array<string, array<int, string>> $views   slice => id => the name of each of its views
     * @param list<array<string, ?string>>      $missing LogOnce::recent() rows of Gate::VIEW_EVENT
     */
    private static function check(array $views, ?DatovkaConf $conf = null, array $missing = []): CheckIsdsViews
    {
        $rules                              = FakeSliceRules::ready(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE);
        $rules->related[Sending::SITE][]    = self::PUBLIC;
        $rules->related[self::OTHER_SITE]   = [self::PUBLIC];
        $rules->names[self::PUBLIC]         = 'News';

        return new class ($conf ?? Sending::bound(), $rules, $views, $missing) extends CheckIsdsViews {
            /** @var list<list<string>> the slices of each question */
            public array $asked = [];

            /**
             * @param array<string, array<int, string>> $rows
             * @param list<array<string, ?string>>      $logged
             */
            public function __construct(private DatovkaConf $file, private FakeSliceRules $rules, private array $rows, private array $logged) {}

            protected function missingViews(): array
            {
                return $this->logged;
            }

            protected function conf(): DatovkaConf
            {
                return $this->file;
            }

            protected function modulesHere(array $ids): array
            {
                return $ids;
            }

            protected function sliceRules(): SliceRules
            {
                return $this->rules;
            }

            protected function views(array $slices): array
            {
                $this->asked[] = $slices;
                $views         = [];
                foreach ($slices as $slice) {
                    foreach ($this->rows[$slice] ?? [] as $id => $name) {
                        $views[] = ['id' => (string) $id, 'slice' => $slice, 'name' => $name];
                    }
                }

                return $views;
            }
        };
    }

    public function test_types_basicOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_BASIC], CheckIsdsViews::types());
    }

    public function test_findings_duplicateViewName(): void
    {
        $check = self::check([
            Sending::CAMPAIGN => [7 => 'isdscampainer', 30 => 'isds_history', 9 => 'isds_compose'],
            Sending::MESSAGE  => [12 => 'isds_messages', 8 => 'isds_history'],
            // beside the pair: the page never looks here, only {view:isdscampainer} would
            self::PUBLIC      => [3 => 'isdscampainer', 4 => 'isds', 5 => 'isds_compose', 6 => 'isds_compose', 10 => 'isds_messages'],
        ]);

        $findings = $check->findings();

        $pair = 'view:pair:' . Sending::CAMPAIGN . ':' . Sending::MESSAGE;
        self::assertSame(["$pair:isds_history", 'entry:' . Sending::SITE], array_map(static fn(Finding $f): string => $f->key, $findings));
        self::assertSame([Finding::ERROR, 'Views of the slices of ISDS account NSZM (test) share the name isds_history', 2, 'ISDS campaigns'], [$findings[0]->severity, $findings[0]->title, $findings[0]->count, $findings[0]->place]);
        self::assertSame([['View' => '8', 'Slice' => 'ISDS messages'], ['View' => '30', 'Slice' => 'ISDS campaigns']], $findings[0]->evidence, 'the lowest id first');
        self::assertSame([Finding::WARNING, 'Site Terminal has the entry view isdscampainer more than once', 2, 'Terminal'], [$findings[1]->severity, $findings[1]->title, $findings[1]->count, $findings[1]->place]);
        self::assertSame([['View' => '3', 'Slice' => 'News'], ['View' => '7', 'Slice' => 'ISDS campaigns']], $findings[1]->evidence);
        self::assertSame([[Sending::CAMPAIGN, Sending::MESSAGE], [Sending::CAMPAIGN, Sending::MESSAGE, self::PUBLIC]], $check->asked, 'the pair, then the slices of its site');
    }

    // a test and a production account may share the site, each with a pair of its own: each pair has the views
    public function test_findings_twoPairsOfOneSiteAreOk(): void
    {
        $other = 'cafe0000000000000000000000000009';
        $msg2  = 'beef0000000000000000000000000009';
        $conf  = Sending::conf(['NSZM' => Sending::account(), 'NSZMT' => Sending::account(pair: [$other, $msg2])]);
        $check = self::check([Sending::CAMPAIGN => [1 => 'isdscampainer', 2 => 'isds_history'], $other => [3 => 'isds_history'], $msg2 => [4 => 'isds_messages'], Sending::MESSAGE => [5 => 'isds_messages']], $conf);

        self::assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $check->findings()));
    }

    // the error of the pair tells it all: the site has no other view of the name
    public function test_findings_entryTwiceInThePairOneError(): void
    {
        $twice = [Sending::CAMPAIGN => [1 => 'isdscampainer'], Sending::MESSAGE => [2 => 'isdscampainer']];

        self::assertSame(['view:pair:' . Sending::CAMPAIGN . ':' . Sending::MESSAGE . ':isdscampainer'], array_map(static fn(Finding $f): string => $f->key, self::check($twice)->findings()));
        self::assertSame(
            ['view:pair:' . Sending::CAMPAIGN . ':' . Sending::MESSAGE . ':isdscampainer', 'entry:' . Sending::SITE],
            array_map(static fn(Finding $f): string => $f->key, self::check($twice + [self::PUBLIC => [3 => 'isdscampainer']])->findings()),
            'a third one beside the pair',
        );
    }

    // the page asked for a name no view of its pair has: that part of it stayed empty
    public function test_findings_missingViewLogged(): void
    {
        $row      = ['selector' => Sending::SITE . ':isds_history', 'params' => 'No view of the slices of the page has this name', 'days' => '3', 'first' => '1799000000', 'last' => '1799200000'];
        $findings = self::check([Sending::CAMPAIGN => [1 => 'isdscampainer']], missing: [$row])->findings();

        self::assertCount(1, $findings);
        self::assertSame([Finding::WARNING, 'A page of a mass sending on site Terminal shows a view no view of its slices is named: isds_history', 3, 'missing:' . Sending::SITE . ':isds_history', 'Terminal'], [$findings[0]->severity, $findings[0]->title, $findings[0]->count, $findings[0]->key, $findings[0]->place]);
        self::assertSame([['View' => 'isds_history', 'Days seen' => '3', 'Last' => date('Y-m-d', 1799200000)]], $findings[0]->evidence);
        self::assertSame(['Log rows'], array_keys($findings[0]->links));
    }

    public function test_findings_eachNameOnceIsOk(): void
    {
        $findings = self::check([Sending::CAMPAIGN => [1 => 'isdscampainer', 2 => 'isds_history'], Sending::MESSAGE => [3 => 'isds_messages'], self::PUBLIC => [4 => 'isds', 5 => 'isdsx', 6 => 'isds_history']])->findings();

        self::assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_findings_noAccountAsksNothing(): void
    {
        $check = self::check([Sending::CAMPAIGN => [1 => 'isds_history', 2 => 'isds_history']], Sending::conf([]));

        self::assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $check->findings()));
        self::assertSame([], $check->asked);
    }

    // the index of view.slice_id; names compared in PHP, as LIKE takes "_" for any character
    public function test_views_bySlicesAndTheBeginningOfTheName(): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql): array => [['id' => '8', 'slice' => Sending::CAMPAIGN, 'name' => 'isds_history']];
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $check               = new class extends CheckIsdsViews {
            /** @return list<array<string, string>> */
            public function viewsOf(string ...$slices): array
            {
                return $this->views($slices);
            }
        };

        self::assertSame([], $check->viewsOf(), 'no slice, no question');
        self::assertSame([['id' => '8', 'slice' => Sending::CAMPAIGN, 'name' => 'isds_history']], $check->viewsOf(Sending::CAMPAIGN, Sending::MESSAGE));
        self::assertSame(['SELECT id, LOWER(HEX(`slice_id`)) AS slice, field3 AS name FROM view WHERE slice_id IN (0x' . Sending::CAMPAIGN . ',0x' . Sending::MESSAGE . ") AND field3 LIKE 'isds%'"], RecordingDb::$log);
    }
}
