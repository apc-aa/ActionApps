<?php

declare(strict_types=1);

/**
 * BatchRun::recordsOfAll(): every run of the class by a few properties, without reading a run - and
 * never its sealed secret, which only the run itself unseals.
 */

namespace Tests\Unit\Later;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

require_once __DIR__ . '/Fixtures/BatchRunTables.php';

final class BatchRunRecordsOfAllTest extends TestCase
{
    use BatchRunTables;

    private const OTHER_SUBJECT = '7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b';

    public function test_recordsOfAll_everyRunOfTheClass(): void
    {
        $mine  = self::start()->getId();
        $other = self::start(null, ['subject' => self::OTHER_SUBJECT])->getId();

        $records = ScriptedBatchRun::recordsOfAll(['subject']);

        self::assertEqualsCanonicalizing([$mine, $other], array_keys($records));
        self::assertEquals(['aa_type' => ScriptedBatchRun::class, 'subject' => self::OTHER_SUBJECT], $records[$other]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function withSecret(): iterable
    {
        yield 'alone' => [['sealed']];
        yield 'among others' => [['state', 'sealed', 'sealed_until']];
    }

    /** @param list<string> $props */
    #[DataProvider('withSecret')]
    public function test_recordsOfAll_sealedSecret_refused(array $props): void
    {
        self::start();

        $this->expectException(\InvalidArgumentException::class);
        ScriptedBatchRun::recordsOfAll($props);
    }
}
