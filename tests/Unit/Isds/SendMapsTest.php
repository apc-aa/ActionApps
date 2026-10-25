<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Schema;
use AA\Isds\SendMaps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the record of a sending keeps of each recipient */
final class SendMapsTest extends TestCase
{
    // the first attempt counts: a message tried again never changes what the first one left
    public function test_with_keepsTheFirstValue(): void
    {
        $map = SendMaps::with('', 2, '901');

        self::assertSame('{"2":"901"}', $map);
        self::assertSame('{"2":"901","0":1700}', SendMaps::with(SendMaps::with($map, 2, '902'), 0, 1700));
        self::assertSame('{"0":"x"}', SendMaps::with('{}', 0, 'x'), 'an object even for index 0');
    }

    public function test_entries_ofTheRecord(): void
    {
        self::assertSame([], SendMaps::entries(''));
        self::assertSame([1 => '901', 3 => 1700], SendMaps::entries('{"1":"901","3":1700}'));

        $this->expectException(\JsonException::class);
        SendMaps::entries('{broken');
    }

    public function test_decided_everyMapAndInflight(): void
    {
        $maps = new SendMaps('{"0":"901"}', '{"1":1700}', '{"2":"1233"}', '{"3":"inactive"}', '5', 1800);

        self::assertSame([0, 1, 2, 3, 5], array_keys(self::sorted($maps->decided())));
        self::assertSame([], (new SendMaps())->decided());
    }

    // an answer settles a message: sent or refused, it is no longer looked up
    public function test_unsettled_uncertainAndInflightWithoutAnswer(): void
    {
        $maps = new SendMaps('{"1":"902"}', '{"0":1700,"1":1710,"2":1720}', '{"2":"notfound"}', '{"4":"inactive"}', '3', 1800);

        self::assertSame([0 => 1700, 3 => 1800], $maps->unsettled());
    }

    // in flight and uncertain at once: the step died after recording it, the attempt it recorded counts
    public function test_unsettled_inflightKeepsRecordedTime(): void
    {
        self::assertSame([1 => 1700], (new SendMaps('', '{"1":"1700"}', '', '', '1', 1800))->unsettled());
    }

    // what each item must show: by the map that says most, the message in flight uncertain
    public function test_fates_ofEveryDecidedRecipient(): void
    {
        $maps = new SendMaps('{"0":"901","1":"902"}', '{"1":1700,"2":1710,"3":1720}', '{"1":"1233","3":"notfound"}', '{"4":"inactive"}', '5', 1800);

        self::assertSame([0 => 'sent', 1 => 'sent', 3 => 'failed', 2 => 'uncertain', 5 => 'uncertain', 4 => 'skipped'], $maps->fates());
        self::assertSame([], (new SendMaps())->fates());
    }

    /** @param array{sent: int, failed: int, uncertain: int, skipped: int, stopped: int} $expected */
    #[DataProvider('tallies')]
    public function test_tally_eachRecipientOnce(SendMaps $maps, array $expected): void
    {
        $rows = array_map(static fn(string $reason): array => ['reason' => $reason], ['', '', '', '', 'duplicate', '']);

        self::assertSame($expected, $maps->tally($rows));
    }

    /** @return array<string, array{SendMaps, array<string, int>}> */
    public static function tallies(): array
    {
        return [
            'sent over refused and uncertain' => [new SendMaps('{"0":"901","1":"902"}', '{"1":1700}', '{"0":"1233"}'), ['sent' => 2, 'failed' => 0, 'uncertain' => 0, 'skipped' => 1, 'stopped' => 3, 'queued' => 0]],
            'refused, skipped, in flight'     => [new SendMaps('', '', '{"0":"1233"}', '{"1":"inactive"}', '2', 1800), ['sent' => 0, 'failed' => 1, 'uncertain' => 1, 'skipped' => 2, 'stopped' => 2, 'queued' => 0]],
            'all decided'                     => [new SendMaps('{"0":"901","1":"902","2":"903"}', '{"3":1700}', '{"5":"notfound"}'), ['sent' => 3, 'failed' => 1, 'uncertain' => 1, 'skipped' => 1, 'stopped' => 0, 'queued' => 0]],
        ];
    }

    /**
     * Before nothing sends them any more, the recipients never tried are queued, not stopped; each
     * recipient has one fate, by its index
     * @param array{sent: int, failed: int, uncertain: int, skipped: int, stopped: int, queued: int} $expected
     */
    #[DataProvider('tallies')]
    public function test_fatesOf_countsEqualTally(SendMaps $maps, array $expected): void
    {
        $rows = array_map(static fn(string $reason): array => ['reason' => $reason], ['', '', '', '', 'duplicate', '']);

        self::assertSame(array_replace($expected, ['stopped' => 0, 'queued' => $expected['stopped']]), $maps->tally($rows, false));
        self::assertSame(range(0, 5), array_keys($maps->fatesOf($rows, true)));
        self::assertSame($expected, array_merge(array_fill_keys(array_keys($expected), 0), array_count_values($maps->fatesOf($rows, true))));
    }

    // a message that may be on its way may have gone: never stopped, never queued, never sent again
    public function test_fatesOf_inflightIsUncertain(): void
    {
        $rows = [['reason' => ''], ['reason' => ''], ['reason' => '']];
        $maps = new SendMaps('{"0":"901"}', '', '', '', '1', 1800);

        foreach ([true, false] as $over) {
            self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, $maps->fatesOf($rows, $over)[1]);
        }
        self::assertSame([0 => 'sent', 1 => 'uncertain', 2 => 'stopped'], $maps->fatesOf($rows, true));
    }

    // only a recipient never tried is stopped - once nothing sends it any more; one skipped stays skipped
    public function test_fatesOf_neverTriedStoppedOnlyWhenOver(): void
    {
        $rows = [['reason' => ''], ['reason' => 'duplicate'], ['reason' => ''], ['reason' => '']];
        $maps = new SendMaps('', '', '', '{"2":"inactive"}');

        self::assertSame([0 => 'queued', 1 => 'skipped', 2 => 'skipped', 3 => 'queued'], $maps->fatesOf($rows, false));
        self::assertSame([0 => 'stopped', 1 => 'skipped', 2 => 'skipped', 3 => 'stopped'], $maps->fatesOf($rows, true));
    }

    // the antivirus check refused it or the box was closed after it was sent: it reached no one
    public function test_fates_av_and_undeliverable_failed_with_code(): void
    {
        $maps = new SendMaps('{"0":"901","1":"902","2":"903"}', '{"3":1700}', '{"4":"1233"}', '', '', 0, '{"0":"3:0:0:0","1":"8:1900:1800:1850","2":"6:1900:1800:1900"}');

        self::assertSame([2 => 'sent', 0 => 'failed', 1 => 'failed', 4 => 'failed', 3 => 'uncertain'], $maps->fates());
        self::assertSame([0 => '3', 1 => '8', 4 => '1233'], self::sorted($maps->failures()));
    }

    // what the page and the end of the run show: every recipient once, the failed after sending too
    public function test_tally_failed_after_send_sums_to_total(): void
    {
        $rows = array_map(static fn(string $reason): array => ['reason' => $reason], ['', '', '', '', '', 'duplicate', '']);
        $maps = new SendMaps('{"0":"901","1":"902","2":"903","3":"904"}', '{"4":1700}', '', '', '', 0, '{"0":"3:0:0:0","1":"8:1900:1800:0","2":"4:1800:1800:0"}');

        $tally = $maps->tally($rows);

        self::assertSame(['sent' => 2, 'failed' => 2, 'uncertain' => 1, 'skipped' => 1, 'stopped' => 1, 'queued' => 0], $tally);
        self::assertSame(count($rows), array_sum($tally));
    }

    /** @param array{delivered: int, accepted: int} $expected */
    #[DataProvider('deliveries')]
    public function test_delivery_counts(string $entry, array $expected): void
    {
        self::assertSame($expected, (new SendMaps('{"0":"901"}', dmState: json_encode(['0' => $entry], JSON_FORCE_OBJECT)))->delivery());
    }

    /** @return array<string, array{string, array{delivered: int, accepted: int}}> */
    public static function deliveries(): array
    {
        return [
            'stamped'                         => ['2:0:0:0', ['delivered' => 0, 'accepted' => 0]],
            'delivered'                       => ['4:1800:1800:0', ['delivered' => 1, 'accepted' => 0]],
            'accepted after 10 days'          => ['5:1900:1800:1900', ['delivered' => 1, 'accepted' => 1]],
            'accepted by a login'             => ['6:1850:1800:1850', ['delivered' => 1, 'accepted' => 1]],
            'read'                            => ['7:1950:1800:0', ['delivered' => 1, 'accepted' => 1]],
            // deleted or in the vault: delivered first, the acceptance only its time tells
            'deleted'                         => ['9:0:0:0', ['delivered' => 1, 'accepted' => 0]],
            'deleted after an acceptance'     => ['9:0:1800:1900', ['delivered' => 1, 'accepted' => 1]],
            'in the vault'                    => ['10:0:0:0', ['delivered' => 1, 'accepted' => 0]],
            'accepted while still delivered'  => ['4:1800:1800:1900', ['delivered' => 1, 'accepted' => 1]],
            'undeliverable after acceptance'  => ['8:2000:1800:1900', ['delivered' => 0, 'accepted' => 0]],
            'refused by the antivirus check'  => ['3:0:0:0', ['delivered' => 0, 'accepted' => 0]],
            // a time of acceptance with no delivery is nonsense, not an acceptance
            'stamped with an acceptance'      => ['2:0:0:7', ['delivered' => 0, 'accepted' => 0]],
        ];
    }

    // an entry edited by hand is not known: the message counts as sent, delivered it is not
    #[DataProvider('badEntries')]
    public function test_maps_bad_dm_state_entry_is_unknown(string $dmState): void
    {
        $maps = new SendMaps('{"0":"901","1":"902"}', dmState: $dmState);

        self::assertSame([0 => 'sent', 1 => 'sent'], $maps->fates());
        self::assertSame(['sent' => 2, 'failed' => 0, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 0, 'queued' => 0], $maps->tally([['reason' => ''], ['reason' => '']]));
        self::assertSame(['delivered' => 0, 'accepted' => 0], $maps->delivery());
        self::assertSame([], $maps->failures());
    }

    /** @return array<string, array{string}> */
    public static function badEntries(): array
    {
        return [
            'status 0'        => ['{"0":"0:0:0:0","1":"0:0:0:0"}'],
            'status 11'       => ['{"0":"11:0:0:0","1":"11:0:0:0"}'],
            'a negative time' => ['{"0":"3:-1:0:0","1":"8:0:-1:0"}'],
            'a part missing'  => ['{"0":"3:0:0","1":"8"}'],
            'a number'        => ['{"0":3,"1":8}'],
            'null'            => ['{"0":null,"1":null}'],
            'text'            => ['{"0":"refused","1":"x"}'],
        ];
    }

    // what a sending without delivery reads gives what it gave before
    #[DataProvider('noDelivery')]
    public function test_maps_without_dm_state_unchanged(string $dmState): void
    {
        $rows    = array_map(static fn(string $reason): array => ['reason' => $reason], ['', '', '', '', 'duplicate', '']);
        $without = new SendMaps('{"0":"901","1":"902"}', '{"1":1700,"2":1710,"3":1720}', '{"1":"1233","3":"notfound"}', '{"4":"inactive"}', '5', 1800);
        $with    = new SendMaps($without->dmIds, $without->uncertain, $without->failed, $without->skips, $without->inflight, $without->inflightAt, $dmState);

        self::assertSame($without->fates(), $with->fates());
        self::assertSame($without->tally($rows), $with->tally($rows));
        self::assertSame($without->decided(), $with->decided());
        self::assertSame($without->unsettled(), $with->unsettled());
        self::assertSame([3 => 'notfound'], $with->failures());
        self::assertSame(['delivered' => 0, 'accepted' => 0], $with->delivery());
    }

    /** @return array<string, array{string}> */
    public static function noDelivery(): array
    {
        return [
            'none'                     => [''],
            'an empty map'             => ['{}'],
            // the record keeps the delivery of a message sent only; an index edited in is none
            'of messages not sent'     => ['{"2":"3:0:0:0","3":"8:0:0:0","5":"6:1:1:1"}'],
        ];
    }

    // a map edited by hand into no JSON, or too deep: the delivery is not known, the counts go on as without it
    #[DataProvider('brokenDmState')]
    public function test_maps_broken_dm_state_is_unknown(string $dmState): void
    {
        $rows    = array_map(static fn(string $reason): array => ['reason' => $reason], ['', '', '', '']);
        $without = new SendMaps('{"0":"901","1":"902"}', '{"2":1700}', '{"3":"1233"}');
        $with    = new SendMaps($without->dmIds, $without->uncertain, $without->failed, dmState: $dmState);

        self::assertSame([$without->fates(), $without->tally($rows), $without->failures()], [$with->fates(), $with->tally($rows), $with->failures()]);
        self::assertSame(['delivered' => 0, 'accepted' => 0], $with->delivery());
    }

    /** @return array<string, array{string}> */
    public static function brokenDmState(): array
    {
        return ['no JSON' => ['{"0":"3:0:0:0"'], 'too deep' => ['{"0":{"a":1}}']];
    }

    // what was sent decides what is sent again: a dm_ids edited into no JSON stops the count, whatever dm_state is
    #[DataProvider('anyDmState')]
    public function test_maps_broken_dm_ids_throws(string $dmState): void
    {
        $this->expectException(\JsonException::class);
        (new SendMaps('{"0":', dmState: $dmState))->delivery();
    }

    /** @return array<string, array{string}> */
    public static function anyDmState(): array
    {
        return ['readable' => ['{"0":"4:1:1:0"}'], 'no JSON either' => ['{"0":']];
    }

    // a dmID edited into null is still a message sent: counted once, the sum is every recipient
    public function test_tally_null_dm_id_counts_once(): void
    {
        $rows = [['reason' => ''], ['reason' => ''], ['reason' => '']];

        $tally = (new SendMaps('{"0":null,"1":"902"}'))->tally($rows);

        self::assertSame(['sent' => 2, 'failed' => 0, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 1, 'queued' => 0], $tally);
        self::assertSame(count($rows), array_sum($tally));
    }

    // a code edited by hand into null: a failure without a code, counted once
    public function test_failures_null_code_counts_once(): void
    {
        $maps = new SendMaps('', '', '{"0":null,"1":"1233"}');

        self::assertSame([0 => '', 1 => '1233'], $maps->failures());
        self::assertSame(['sent' => 0, 'failed' => 2, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 0, 'queued' => 0], $maps->tally([['reason' => ''], ['reason' => '']]));
    }

    /**
     * @param array<int, mixed> $map
     * @return array<int, mixed>
     */
    private static function sorted(array $map): array
    {
        ksort($map);
        return $map;
    }
}
