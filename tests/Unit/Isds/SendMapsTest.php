<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

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

    /** @param array{sent: int, failed: int, uncertain: int, skipped: int, stopped: int} $expected */
    #[DataProvider('tallies')]
    public function test_tally_eachRecipientOnce(SendMaps $maps, array $expected): void
    {
        $rows = array_map(static fn(string $reason): array => ['reason' => $reason], ['', '', '', '', 'foreign', '']);

        self::assertSame($expected, $maps->tally($rows));
    }

    /** @return array<string, array{SendMaps, array<string, int>}> */
    public static function tallies(): array
    {
        return [
            'sent over refused and uncertain' => [new SendMaps('{"0":"901","1":"902"}', '{"1":1700}', '{"0":"1233"}'), ['sent' => 2, 'failed' => 0, 'uncertain' => 0, 'skipped' => 1, 'stopped' => 3]],
            'refused, skipped, in flight'     => [new SendMaps('', '', '{"0":"1233"}', '{"1":"inactive"}', '2', 1800), ['sent' => 0, 'failed' => 1, 'uncertain' => 1, 'skipped' => 2, 'stopped' => 2]],
            'all decided'                     => [new SendMaps('{"0":"901","1":"902","2":"903"}', '{"3":1700}', '{"5":"notfound"}'), ['sent' => 3, 'failed' => 1, 'uncertain' => 1, 'skipped' => 1, 'stopped' => 0]],
        ];
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
