<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Optimize\CheckIsdsRunItems;
use AA\Optimize\CheckIsdsRuns;
use AA\Optimize\Finding;
use AA_Credentials;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/../../Isds/fixtures/SendRunCase.php';

/** The checks of mass sendings over the runs of TestSendRun and the items of its MemorySendItems, each read of items listed */
trait IsdsRunsHooks
{
    /** @var list<array{ids: list<string>, field: string}> */
    public array $reads = [];

    /** @var ?\Closure(list<string>): void runs at each read of items, given their ids - where a step may claim the run */
    public ?\Closure $onRead = null;

    protected function runClass(): string
    {
        return TestSendRun::class;
    }

    // as GetItemContent() by id: every item with its slice, in lowercase; the value masked when the
    // request cannot read that slice - the items of other slices in the read are not
    protected function content(array $ids, string $field): array
    {
        $this->reads[] = ['ids' => $ids, 'field' => $field];
        if ($this->onRead) {
            ($this->onRead)($ids);
        }
        $content = [];
        foreach (array_map('strtolower', $ids) as $id) {
            $item = TestSendRun::$items?->stored[$id] ?? null;
            if (!is_null($item)) {
                $value        = AA_Credentials::singleton()->checkSlice($item['slice']) ? ($item['fields'][$field] ?? '') : 'Error: Missing Reading Password';
                $content[$id] = ['u_slice_id......' => [['value' => $item['slice']]], $field => [['value' => $value]]];
            }
        }
        return $content;
    }

    protected function sliceName(string $slice): string
    {
        return ($slice === Sending::CAMPAIGN) ? 'Campaigns' : '';
    }
}

final class TestIsdsRunsCheck extends CheckIsdsRuns
{
    use IsdsRunsHooks;
}

final class TestIsdsRunItemsCheck extends CheckIsdsRunItems
{
    use IsdsRunsHooks;
}

/** Runs of mass sendings as SendRunCase makes them, and what the tests of their checks look at */
trait IsdsRunCheckCase
{
    use SendRunCase;

    /** the run $id finished $ago seconds ago in $state, its campaign showing $campaign */
    private static function finished(string $id, string $state, int $ago = 7200, string $campaign = Schema::CAMPAIGN_STATE_DONE): string
    {
        self::storedSet($id, 'state', $state);
        self::storedSet($id, 'finished', (string) (self::NOW - $ago));
        self::campaignShows($campaign);
        return $id;
    }

    /**
     * A run that sent $n messages as 901, 902, ... and follows their delivery since $from, read up to
     * $last ($from when null), waiting for its next round; its items sent, the campaign done
     * @param array<string, string> $props own properties over those
     */
    private static function following(int $n = 2, array $props = [], int $from = self::NOW - 3600, ?int $last = null): string
    {
        $now              = (int) TestSendRun::$now;
        TestSendRun::$now = $from - 600;
        $id               = self::queued($n, $props + [
            'dm_ids'     => (string) json_encode((object) array_map(static fn(int $k): string => (string) (901 + $k), range(0, $n - 1))),
            'cursor'     => (string) $n,
            'camp_state' => Schema::CAMPAIGN_STATE_DONE,
            'watch_from' => (string) $from,
            'watch_last' => (string) ($last ?? $from),
        ], self::WATCH);
        TestSendRun::$now = $now;
        self::storedSet($id, 'next_at', (string) ($now + 600));
        self::storedSet($id, 'retry_code', SendRun::WATCHING);
        self::itemsAre($id, array_fill(0, $n, Schema::MESSAGE_STATE_SENT));
        self::campaignShows(Schema::CAMPAIGN_STATE_DONE);
        return $id;
    }

    /** @param array<int, string> $statuses index => the dmMessageStatus the message item of the run $id shows */
    private static function itemStatusesAre(string $id, array $statuses): void
    {
        foreach ($statuses as $k => $s) {
            self::items()->stored[self::message($id, $k)]['fields'][Schema::MESSAGE_DM_STATUS] = $s;
        }
    }

    private static function campaignShows(string $state): void
    {
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = $state;
    }

    /** @param list<string> $states the state of each message item of the run $id */
    private static function itemsAre(string $id, array $states): void
    {
        foreach ($states as $k => $state) {
            self::items()->stored[self::message($id, $k)]['fields'][Schema::MESSAGE_STATE] = $state;
        }
    }

    /** @param Finding[] $findings */
    private static function only(array $findings): Finding
    {
        self::assertCount(1, $findings, implode(' | ', array_map(static fn(Finding $f): string => "$f->key: $f->title", $findings)));
        return $findings[0];
    }

    /** @param Finding[] $findings
     *  @return list<string> */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, $findings);
    }

    /** @param Finding[] $findings
     *  @return list<string> */
    private static function keys(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->key, $findings);
    }
}
