<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\HitcounterStats;
use PHPUnit\Framework\TestCase;

class HitcounterStatsTest extends TestCase
{
    private const A     = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B     = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const C     = 'cccccccccccccccccccccccccccccccc';
    private const FIELD = 'hit_7...........';

    /** GetItemContent() shape for one value of the field */
    private static function content(string $id, ?string $value): array
    {
        return [$id => [self::FIELD => [['value' => $value, 'flag' => 0]]]];
    }

    public function test_changedValues_skipsAnUnchangedCount(): void
    {
        $content = self::content(self::A, '7') + self::content(self::B, '0');

        $this->assertSame([], HitcounterStats::changedValues([self::A, self::B], [self::A => '7'], $content, self::FIELD));
    }

    public function test_changedValues_writesAChangedCount(): void
    {
        $this->assertSame([self::A => 8], HitcounterStats::changedValues([self::A], [self::A => '8'], self::content(self::A, '7'), self::FIELD));
    }

    public function test_changedValues_writesZeroForAnItemThatLostItsHitsOrHasNoValue(): void
    {
        // B lost its hits, C was never counted
        $content = self::content(self::A, '3') + self::content(self::B, '5') + [self::C => ['headline........' => [['value' => 'x']]]];

        $this->assertSame([self::B => 0, self::C => 0], HitcounterStats::changedValues([self::A, self::B, self::C], [self::A => '3'], $content, self::FIELD));
    }

    public function test_changedValues_rewritesAnEmptyValue(): void
    {
        // a text stored row whose text is NULL reads back as NULL, not as 0
        $this->assertSame([self::A => 0], HitcounterStats::changedValues([self::A], [], self::content(self::A, null), self::FIELD));
    }

    public function test_changedValues_rewritesMoreValues(): void
    {
        $content = [self::A => [self::FIELD => [['value' => '0'], ['value' => '4']]]];

        $this->assertSame([self::A => 0], HitcounterStats::changedValues([self::A], [], $content, self::FIELD));
    }

    public function test_changedValues_readsATextStoredValueByItsIndex(): void
    {
        // text stored values are keyed by `number`, which need not be 0
        $content = [self::A => [self::FIELD => [3 => ['value' => '12']]]];

        $this->assertSame([], HitcounterStats::changedValues([self::A], [self::A => '12'], $content, self::FIELD));
    }
}
