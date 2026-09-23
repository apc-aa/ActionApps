<?php

declare(strict_types=1);

/**
 * Chain runs all optimizers of one type in priority order - service/sql_update.php and
 * Central's Do_Optimize_Type responder share it, so both run the same classes in the same order.
 */

namespace Tests\Unit\Optimize;

use AA\Optimize\AbstractOptimize;
use AA\Optimize\Chain;
use AA\Optimize\RedefineFieldTemplates;
use AA\Optimize\UpdateDbStructure;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/** records what the chain called; report() is the call log */
abstract class ChainFakeOptimize extends AbstractOptimize
{
    public array $calls = [];

    abstract protected function passes(): bool;

    public function test(): bool
    {
        $this->calls[] = 'test';
        $this->message('tested');
        return $this->passes();
    }

    public function repair(): bool
    {
        $this->calls[] = 'repair';
        $this->message('repaired');
        return static::name() !== 'broken';
    }

    public function report(int $verbosity = 1, string $type = 'html'): string
    {
        return implode('+', array_map(static fn($row) => $row['txt'], $this->reportRows($verbosity)));
    }
}

final class ChainFakePassing extends ChainFakeOptimize
{
    public static function name(): string
    {
        return 'passing';
    }

    protected function passes(): bool
    {
        return true;
    }
}

final class ChainFakeFailing extends ChainFakeOptimize
{
    public static function name(): string
    {
        return 'failing';
    }

    protected function passes(): bool
    {
        return false;
    }
}

final class ChainFakeBroken extends ChainFakeOptimize
{
    public static function name(): string
    {
        return 'broken';
    }

    protected function passes(): bool
    {
        return false;
    }
}

/** prints like DB_PDO::haltmsg() under set_halt('report') */
final class ChainFakeLoud extends ChainFakeOptimize
{
    public static function name(): string
    {
        return 'loud';
    }

    protected function passes(): bool
    {
        echo '<p><b>Database error:</b> x</p>';
        return true;
    }
}

final class ChainFakeCrashing extends ChainFakeOptimize
{
    public static function name(): string
    {
        return 'crashing';
    }

    protected function passes(): bool
    {
        throw new \RuntimeException('boom <b>');
    }
}

final class ChainTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';          // config constants, safe()
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';  // RecordingDb, getDB()
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
    }

    /** @return list<class-string> */
    private static function sqlUpdateClasses(): array
    {
        return array_values(array_map(static fn($optimizer) => get_class($optimizer), Chain::optimizers('sql_update')));
    }

    public function test_optimizers_sqlUpdate_areSqlUpdateOptimizers_inPriorityOrder(): void
    {
        $classes = self::sqlUpdateClasses();

        self::assertNotEmpty($classes);
        $prev = [PHP_INT_MIN, ''];
        foreach ($classes as $class) {
            self::assertTrue(is_subclass_of($class, AbstractOptimize::class), $class);
            self::assertContains('sql_update', $class::types(), $class);
            $key = [$class::priority(), $class];
            self::assertLessThan(0, $prev <=> $key, "$class: priority, then class name");
            $prev = $key;
        }
    }

    public function test_optimizers_structureIsUpdatedBeforeTemplatesAreRedefined(): void
    {
        $classes = self::sqlUpdateClasses();

        self::assertLessThan(array_search(RedefineFieldTemplates::class, $classes, true), array_search(UpdateDbStructure::class, $classes, true));
    }

    public function test_optimizers_unknownType_isEmpty(): void
    {
        self::assertSame([], Chain::optimizers('no_such_type'));
    }

    public function test_test_runsTestOnEach_andFailsWhenAnyFails(): void
    {
        $passing = new ChainFakePassing();
        $failing = new ChainFakeFailing();

        [$ok, $html] = Chain::test([$passing, $failing]);

        self::assertFalse($ok);
        self::assertSame(['test'], $passing->calls);
        self::assertSame(['test'], $failing->calls);
        self::assertSame('<table><tr><td>passing</td><td>OK</td><td>tested</td></tr><tr><td>failing</td><td>Problem</td><td>tested</td></tr></table>', $html);
    }

    public function test_test_allPass_isOk(): void
    {
        [$ok] = Chain::test([new ChainFakePassing()]);

        self::assertTrue($ok);
    }

    public function test_repair_skipsPassing_repairsFailing_withFreshReport(): void
    {
        $passing = new ChainFakePassing();
        $failing = new ChainFakeFailing();

        [$ok, $html] = Chain::repair([$passing, $failing]);

        self::assertTrue($ok);
        self::assertSame(['test'], $passing->calls);
        self::assertSame(['test', 'repair'], $failing->calls);
        // the test() message is cleared, so only the repair shows
        self::assertSame('<table><tr><td>passing</td><td>test passed - skipping</td></tr><tr><td>failing</td><td>repaired</td></tr></table>', $html);
    }

    public function test_repair_failedRepair_isNotOk_andGoesOn(): void
    {
        $broken  = new ChainFakeBroken();
        $failing = new ChainFakeFailing();

        [$ok] = Chain::repair([$broken, $failing]);

        self::assertFalse($ok);
        self::assertSame(['test', 'repair'], $failing->calls);
    }

    public function test_run_test_onlyTests_andPrefixesErr(): void
    {
        $failing = new ChainFakeFailing();

        [$ok, $report] = Chain::run('test', [$failing]);

        self::assertFalse($ok);
        self::assertSame(['test'], $failing->calls);
        self::assertStringStartsWith('Err <table><tr><td>failing</td><td>Problem</td>', $report);
    }

    public function test_run_repair_repairs_andPrefixesOk(): void
    {
        $failing = new ChainFakeFailing();

        [$ok, $report] = Chain::run('repair', [$failing]);

        self::assertTrue($ok);
        self::assertSame(['test', 'repair'], $failing->calls);
        self::assertSame('OK <table><tr><td>failing</td><td>repaired</td></tr></table>', $report);
    }

    public function test_run_unknownMethod_throws(): void
    {
        $this->expectException(\UnhandledMatchError::class);
        Chain::run('update', [new ChainFakePassing()]);
    }

    public function test_run_printedOutput_goesIntoTheReport_notOut(): void
    {
        $this->expectOutputString('');

        [$ok, $report] = Chain::run('test', [new ChainFakeLoud()]);

        self::assertTrue($ok);
        self::assertStringContainsString('<p><b>Database error:</b> x</p>', $report);
        self::assertStringContainsString('<td>loud</td>', $report);
    }

    public function test_run_throwingOptimizer_isErr_withTheEscapedMessage(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();

        [$ok, $report] = Chain::run('repair', [new ChainFakeLoud(), new ChainFakeCrashing()]);

        self::assertFalse($ok);
        self::assertSame($level, ob_get_level());
        self::assertStringStartsWith('Err ', $report);
        self::assertStringContainsString('RuntimeException: boom &lt;b&gt;', $report);
        self::assertStringContainsString('<p><b>Database error:</b> x</p>', $report, 'what was printed before the throw stays');
    }

    public function test_tryLock_free_holdsTheLock_untilReleased_once(): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'GET_LOCK') ? [['got' => '1', 'holder' => '7']] : [];
        $GLOBALS['spareDBs'] = [];

        $release = Chain::tryLock('sql_update', $holder, new RecordingDb());

        self::assertNotNull($release);
        self::assertSame(["SELECT GET_LOCK('aa_optimize_sql_update_aa_test', 0) AS got, IS_USED_LOCK('aa_optimize_sql_update_aa_test') AS holder"], RecordingDb::$log);

        $release();
        $release();   // the shutdown function calls it again

        self::assertSame(["SELECT RELEASE_LOCK('aa_optimize_sql_update_aa_test')"], array_slice(RecordingDb::$log, 1));
        self::assertSame(RecordingDb::$handles[0], RecordingDb::$handles[1]);
        self::assertSame([], $GLOBALS['spareDBs'], 'the lock connection never goes to the pool');
    }

    public function test_tryLock_held_isNull_andNamesTheHolder(): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql) => [['got' => '0', 'holder' => '4711']];

        self::assertNull(Chain::tryLock('sql_update', $holder, new RecordingDb()));
        self::assertSame(4711, $holder);
        self::assertCount(1, RecordingDb::$log);
        self::assertStringContainsString('4711', Chain::runningMessage('sql_update', $holder));
    }

    public function test_lockConnection_isNotPersistent(): void
    {
        // a persistent connection outlives a die() in the run - and the lock with it
        $params = Chain::lockConnectionParams();

        self::assertFalse($params['persistent']);
        self::assertSame(constant('DB_NAME'), $params['database']);
    }
}
