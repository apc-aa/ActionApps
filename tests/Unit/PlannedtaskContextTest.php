<?php

declare(strict_types=1);

namespace Tests\Unit;

use AA;
use AA_Plannedtask;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * A planned task runs in the context of its slice and item. A task started by an event inside
 * another task (or by an item store) must give the outer code its own context back.
 */
#[RunClassInSeparateProcess]
final class PlannedtaskContextTest extends TestCase
{
    private const OUTER_SLICE = '0123456789abcdef0123456789abcdef';
    private const TASK_SLICE  = 'fedcba9876543210fedcba9876543210';
    public const ITEM        = 'aaaabbbbccccddddaaaabbbbccccdddd';

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/fixtures/util_shims.php';
            require_once __DIR__ . '/Legacy/fixtures/store_fixtures.php';
        } finally {
            restore_error_handler();
        }
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    public static function tearDownAfterClass(): void
    {
        $GLOBALS['spareDBs'] = [];
    }

    public function test_toexecutelater_restoresTheOuterSliceAndEvent(): void
    {
        AA::$slice_id = self::OUTER_SLICE;
        AA::Un('{define:aa_event_for:outer}');

        $task = new AA_Plannedtask();
        $task->setNew('11112222333344441111222233334444', self::TASK_SLICE, 'nested');
        (function (): void {
            $this->task      = '{var:aa_event_for}';
            $this->condition = '';
            $this->item_id   = PlannedtaskContextTest::ITEM;
        })->call($task);

        $ret = $task->toexecutelater();

        self::assertStringContainsString(self::ITEM, $ret, 'the task itself sees its own item');
        self::assertSame(self::OUTER_SLICE, AA::$slice_id);
        self::assertSame('outer', AA::Un('{var:aa_event_for}'));
    }
}
