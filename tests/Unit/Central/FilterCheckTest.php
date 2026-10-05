<?php

declare(strict_types=1);

/**
 * FilterCheck: the preview of a filtered transfer - a filter of a form AA cannot read is E without a
 * question to the source, one the source refuses (a field it lacks) is E too, and then no other question
 * carries the filters; a source that does not answer about them makes them E; a usable filter brings the
 * matching counts, W filter-matches-all and W relation-left-out; a filter of a slice the transfer does
 * not take goes nowhere.
 */

namespace Tests\Unit\Central;

use AA\Central\AppSource;
use AA\Central\ExportRefused;
use AA\Central\FilterCheck;
use AA\Central\SourceFailed;
use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilterCheckTest extends TestCase
{
    private const DEFS   = '61481bfa1643a04ef53db630e041fe9b';
    private const VALUES = 'd80dca64a31b47fc60e61ac6fba3556d';
    private const OTHER  = '2a9468da454348f3c805b2e88744f9cd';
    private const BY_IMPORTER = 'd-relation.......4-==-5b9e0c7d1a2f4e6b8c3d9a0f1e2d3c4b';

    private string|false $log = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';     // AA_View_Commands, AA_Dbcondition
    }

    protected function setUp(): void
    {
        $this->log = ini_set('error_log', '/dev/null');           // a source that does not answer is logged
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->log);
    }

    /**
     * @param array<string, int>  $all      slice => items
     * @param array<string, int>  $matching slice => items the filters select
     * @param array<string, int>  $leftOut  slice => links left out
     */
    private static function source(array $all, array $matching = [], array $leftOut = [], ?\Throwable $refused = null, ?\Throwable $uncounted = null): AppSource
    {
        return new class ($all, $matching, $leftOut, $refused, $uncounted) implements AppSource {
            /** @var list<array{string, mixed}> */
            public array $calls = [];

            /** @param ?\Throwable $refused thrown by a question with filters, $uncounted by the counts of all the items */
            public function __construct(private array $all, private array $matching, private array $leftOut, private ?\Throwable $refused, private ?\Throwable $uncounted) {}

            public function sameAa(): bool
            {
                return false;
            }

            public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
            {
                throw new \LogicException('not asked');
            }

            public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
            {
                throw new \LogicException('not asked');
            }

            public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = 500): array
            {
                throw new \LogicException('not asked');
            }

            public function logins(array $slices, bool $allBins, array $filters = []): array
            {
                throw new \LogicException('not asked');
            }

            public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
            {
                $this->calls[] = ['itemCounts', $slices, $filters];
                if ($filters && $this->refused) {
                    throw $this->refused;
                }
                if (!$filters && $this->uncounted) {
                    throw $this->uncounted;
                }
                return array_intersect_key($filters ? $this->matching : $this->all, array_flip($slices));
            }

            public function leftOut(array $filters, array $slices, bool $allBins): array
            {
                $this->calls[] = ['leftOut', $slices, $filters];
                return array_replace(array_fill_keys($slices, 0), $this->leftOut);
            }
        };
    }

    /** @param list<array{string, LintFinding}> $findings @return list<string> "level code path ref" */
    private static function lines(array $findings): array
    {
        return array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $findings);
    }

    public function test_without_filters_it_only_counts(): void
    {
        $source = self::source([self::DEFS => 12190, self::VALUES => 136000]);

        $out = (new FilterCheck($source))->check([self::DEFS, self::VALUES], false, []);

        self::assertSame(['findings' => [], 'counts' => [self::DEFS => 12190, self::VALUES => 136000], 'matching' => [], 'usable' => true], $out);
        self::assertSame([['itemCounts', [self::DEFS, self::VALUES], []]], $source->calls);
    }

    public function test_a_usable_filter_brings_its_counts_and_the_links_it_breaks(): void
    {
        $source = self::source([self::DEFS => 12190, self::VALUES => 136000], [self::DEFS => 1612], [self::VALUES => 120433]);

        $out = (new FilterCheck($source))->check([self::DEFS, self::VALUES], true, [strtoupper(self::DEFS) => self::BY_IMPORTER]);

        self::assertTrue($out['usable']);
        self::assertSame([self::DEFS => 1612], $out['matching']);
        self::assertSame(['W relation-left-out slice-module[' . self::VALUES . '] 120433'], self::lines($out['findings']));
        self::assertSame(['leftOut', [self::DEFS, self::VALUES], [self::DEFS => self::BY_IMPORTER]], $source->calls[2], 'the filters of the call, slice ids lower case');
    }

    public function test_a_filter_selecting_every_item_is_a_warning(): void
    {
        $source = self::source([self::DEFS => 12190, self::VALUES => 0], [self::DEFS => 12190, self::VALUES => 0]);

        $out = (new FilterCheck($source))->check([self::DEFS, self::VALUES], false, [self::DEFS => 'd-status_code.....-==-1', self::VALUES => 'd-headline........-=-x']);

        self::assertSame(['W filter-matches-all slice-module[' . self::DEFS . '] 12190'], self::lines($out['findings']), 'an empty slice narrows nothing either way');
    }

    public function test_a_filter_of_no_form_is_an_error_and_the_source_is_not_asked_with_it(): void
    {
        $source = self::source([self::DEFS => 12190, self::VALUES => 136000]);

        $out = (new FilterCheck($source))->check([self::DEFS, self::VALUES], false, [self::DEFS => 'relation.......4 = 5b9e', self::VALUES => self::BY_IMPORTER]);

        self::assertFalse($out['usable']);
        self::assertSame(['E filter-invalid slice-module[' . self::DEFS . '] form'], self::lines($out['findings']));
        self::assertSame([], $out['matching']);
        self::assertSame([['itemCounts', [self::DEFS, self::VALUES], []]], $source->calls, 'only the counts of all the items');
    }

    public function test_a_filter_the_source_refuses_is_an_error(): void
    {
        // a field id of the right form the slice lacks: only the source knows its fields
        $refused = ExportRefused::failed([['slice-module[' . self::DEFS . ']', new LintFinding(LintFinding::ERROR, ItemFilter::INVALID, 'field:relation.......9', 0)]]);
        $source  = self::source([self::DEFS => 12190], [], [], $refused);

        $out = (new FilterCheck($source))->check([self::DEFS], false, [self::DEFS => 'd-relation.......9-==-5b9e0c7d1a2f4e6b8c3d9a0f1e2d3c4b']);

        self::assertFalse($out['usable']);
        self::assertSame(['E filter-invalid slice-module[' . self::DEFS . '] field:relation.......9'], self::lines($out['findings']));
        self::assertSame([self::DEFS => 12190], $out['counts'], 'the counts of all the items stay');
        self::assertCount(2, $source->calls, 'no question for the links left out');
    }

    public function test_a_field_id_of_a_wrong_form_is_caught_here(): void
    {
        $source = self::source([self::DEFS => 12190]);

        $out = (new FilterCheck($source))->check([self::DEFS], false, [self::DEFS => 'd-relation......4-==-5b9e0c7d1a2f4e6b8c3d9a0f1e2d3c4b']);

        self::assertSame(['E filter-invalid slice-module[' . self::DEFS . '] field:relation......4'], self::lines($out['findings']));
        self::assertCount(1, $source->calls);
    }

    public function test_another_refusal_of_the_source_is_no_filter_finding(): void
    {
        $source = self::source([self::DEFS => 12190], [], [], ExportRefused::denied());

        $this->expectException(ExportRefused::class);
        (new FilterCheck($source))->check([self::DEFS], false, [self::DEFS => self::BY_IMPORTER]);
    }

    /** @return list<array{string, \Throwable}> */
    public static function unanswered(): array
    {
        return [
            'an older AA'         => [new SourceFailed(SourceFailed::FAILED, 'The source AA cannot filter items - update it first')],
            'no connection'       => [new SourceFailed(SourceFailed::UNREACHABLE, 'timeout')],
            'another E of the source' => [ExportRefused::failed([['slice-module[' . self::DEFS . ']', new LintFinding(LintFinding::ERROR, 'too-big', '', 0)]])],
        ];
    }

    #[DataProvider('unanswered')]
    public function test_a_source_not_answering_about_the_filters_makes_them_errors(\Throwable $failure): void
    {
        $source = self::source([self::DEFS => 12190, self::VALUES => 136000], [], [], $failure);

        $out = (new FilterCheck($source))->check([self::DEFS, self::VALUES], false, [self::DEFS => self::BY_IMPORTER, self::VALUES => 'd-headline........-=-x']);

        self::assertFalse($out['usable'], 'the write waits for a preview that checked them');
        self::assertSame(['E filter-invalid slice-module[' . self::DEFS . '] source', 'E filter-invalid slice-module[' . self::VALUES . '] source'], self::lines($out['findings']));
        self::assertSame([self::DEFS => 12190, self::VALUES => 136000], $out['counts']);
    }

    public function test_a_refused_login_is_no_filter_finding(): void
    {
        $source = self::source([self::DEFS => 12190], [], [], new SourceFailed(SourceFailed::LOGIN, 'login'));

        $this->expectException(SourceFailed::class);
        (new FilterCheck($source))->check([self::DEFS], false, [self::DEFS => self::BY_IMPORTER]);
    }

    public function test_counts_the_source_cannot_tell_stay_unknown(): void
    {
        $source = self::source([], [], [], null, new SourceFailed(SourceFailed::UNREACHABLE, 'timeout'));

        self::assertSame(['findings' => [], 'counts' => [], 'matching' => [], 'usable' => true], (new FilterCheck($source))->check([self::DEFS], false, []));
    }

    public function test_a_filter_of_a_slice_not_transferred_goes_nowhere(): void
    {
        $source = self::source([self::DEFS => 12190]);

        $out = (new FilterCheck($source))->check([self::DEFS], false, [self::OTHER => 'no filter at all']);

        self::assertSame([[], [], true], [$out['findings'], $out['matching'], $out['usable']]);
        self::assertCount(1, $source->calls);
    }

    public function test_an_install_asks_nothing(): void
    {
        $source = self::source([]);

        self::assertSame(['findings' => [], 'counts' => [], 'matching' => [], 'usable' => true], (new FilterCheck($source))->check([], false, [self::DEFS => self::BY_IMPORTER]));
        self::assertSame([], $source->calls);
    }
}
