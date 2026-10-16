<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\MatchReport;
use AA\Isds\Registry\MatchRow;
use PHPUnit\Framework\TestCase;

final class MatchReportTest extends TestCase
{
    public function test_statusCounts_everyStatusInOrder(): void
    {
        $report = self::report();

        self::assertSame(['same' => 1, 'new' => 3, 'changed' => 1, 'unmatched' => 1, 'ambiguous' => 1], $report->statusCounts());
    }

    public function test_statusCountsByType_typesInTheOrderMet(): void
    {
        self::assertSame([
            'city'     => ['same' => 1, 'new' => 1, 'changed' => 1, 'unmatched' => 0, 'ambiguous' => 1],
            'district' => ['same' => 0, 'new' => 2, 'changed' => 0, 'unmatched' => 1, 'ambiguous' => 0],
        ], self::report()->statusCountsByType());
    }

    // a suggestion of a box another item already has is as doubtful as one box suggested twice
    public function test_sharedBoxes_boxAlreadyStoredOnAnotherItem(): void
    {
        $adamov = self::box('xx3bzck');
        $report = new MatchReport([
            new MatchRow('a', 'Adamov', 'city', ' XX3BZCK', null, MatchRow::BY_NAME, MatchRow::AMBIGUOUS, [$adamov, self::box('jm6amhn')]),
            new MatchRow('b', 'Adamov', 'city', '', $adamov, MatchRow::BY_ICO, MatchRow::NEW),
            new MatchRow('c', 'Brno', 'city', 'x2pbqzq', self::box('a7kbrrn'), MatchRow::BY_ICO, MatchRow::CHANGED),
            new MatchRow('d', 'Jihomoravský kraj', 'region', '', self::box('x2pbqzq'), MatchRow::BY_ICO, MatchRow::NEW),
        ]);

        self::assertSame(['xx3bzck' => ['a', 'b']], $report->sharedBoxes(), 'Brno gives up x2pbqzq');
    }

    public function test_sharedBoxes_boxesOfMoreThanOneRow(): void
    {
        $poruba = self::box('xpkbv55');
        $report = new MatchReport([
            new MatchRow('a', 'Poruba', 'district', '', $poruba, MatchRow::BY_NAME, MatchRow::NEW),
            new MatchRow(7, 'Brno', 'city', '', self::box('a7kbrrn'), MatchRow::BY_ICO, MatchRow::NEW),
            new MatchRow('b', 'Ostrava - Poruba', 'district', 'xpkbv55', $poruba, MatchRow::BY_NAME, MatchRow::SAME),
            new MatchRow('c', 'Adamov', 'city', '', null, MatchRow::BY_NAME, MatchRow::AMBIGUOUS, [self::box('xx3bzck'), self::box('jm6amhn')]),
            new MatchRow('d', 'Nikde', 'city', '', null, '', MatchRow::UNMATCHED),
        ]);

        self::assertSame(['xpkbv55' => ['a', 'b']], $report->sharedBoxes());
    }

    public function test_emptyReport(): void
    {
        $report = new MatchReport([]);

        self::assertSame(array_fill_keys(MatchRow::STATUSES, 0), $report->statusCounts());
        self::assertSame([], $report->statusCountsByType());
        self::assertSame([], $report->sharedBoxes());
    }

    private static function report(): MatchReport
    {
        return new MatchReport([
            new MatchRow('brno', 'Brno', 'city', 'x2pbqzq', self::box('a7kbrrn'), MatchRow::BY_ICO, MatchRow::CHANGED),
            new MatchRow('liberec', 'Liberec', 'city', '7c6by6u', self::box('7c6by6u'), MatchRow::BY_ICO, MatchRow::SAME),
            new MatchRow('plzen', 'Plzeň', 'city', '', self::box('6iybfxn'), MatchRow::BY_NAME, MatchRow::NEW),
            new MatchRow('stred', 'Brno-střed', 'district', '', self::box('qykbwe7'), MatchRow::BY_NAME, MatchRow::NEW),
            new MatchRow('jih', 'Brno-jih', 'district', '', self::box('xphb8g8'), MatchRow::BY_NAME, MatchRow::NEW),
            new MatchRow('adamov', 'Adamov', 'city', '', null, MatchRow::BY_NAME, MatchRow::AMBIGUOUS, [self::box('xx3bzck'), self::box('jm6amhn')]),
            new MatchRow('nikde', 'Nikde', 'district', '', null, '', MatchRow::UNMATCHED),
        ]);
    }

    private static function box(string $id): Box
    {
        return new Box($id, '', '', "Box $id", 'OVM', 10, true, false);
    }
}
