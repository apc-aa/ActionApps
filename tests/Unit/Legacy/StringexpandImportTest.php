<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA\Import\Definition;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Later\BatchStep;
use AA_Perm;
use AA_Plannedtask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;

require_once __DIR__ . '/../Import/fixtures/RunSteps.php';

/**
 * {import:<import>[:run|changed|check]} queues a run of an import of the slice - only from a planned
 * task of that slice while it runs, never from a view, a page or a step of an import, and only with
 * the right of whoever saved the task. It answers with a short code, as the log of the task keeps
 * 40 bytes of its output. Each test in its own process: FakeSlice loads include/locsess.php.
 */
#[RunTestsInSeparateProcesses]
final class StringexpandImportTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE = 'custom:service';
    private const TASK   = '7d7d7d7d7d7d7d7d7d7d7d7d7d7d7d7d';
    /** who saved the task - never the user of the queue, who is none */
    private const AUTHOR = 'uid-author';

    /** @var array<string, array<string, array<string, string>>> uid => object type => id => perms, as the storage keeps them */
    private static array $stored = [];

    private ?AA_Perm $perm = null;

    protected function setUp(): void
    {
        $this->stepsSetUp();
        $dir = __DIR__ . '/../Import/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \ServiceSource::$streamKeys     = ['s1'];
        \ServiceSource::$refuse         = null;
        \ServiceSource::$failStreams    = null;
        \ServiceSource::$onStreams      = null;
        \ServiceSource::$created        = [];
        \ServiceSource::$mapping        = null;
        \ServiceSource::$failProperties = null;
        \ServiceSource::$failMapping    = null;
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S']];
        $this->perm = AA::$perm;
        AA::$perm   = new class ([]) extends AA_Perm {
            public function getUserPerms($uid, $objectType, $flags = 0): array
            {
                return StringexpandImportTest::storedPerms((string) $uid, (string) $objectType);
            }
        };
        self::$stored = [self::AUTHOR => ['slice' => [self::SLICE => PS_FEEDING]]];
    }

    protected function tearDown(): void
    {
        AA::$perm = $this->perm;
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
    }

    /** @return array<string, string> */
    public static function storedPerms(string $uid, string $objectType): array
    {
        return self::$stored[$uid][$objectType] ?? [];
    }

    public function test_import_outsidePlannedTaskDoesNothing(): void
    {
        self::definition('Prices');

        // a page, then a view of an item
        $this->assertSame('', AA::Un('{import:prices}'));
        $this->assertSame('x', AA::Un('x{import:prices:changed}', '', null, true));
        $this->assertSame([], self::runIds());
    }

    public function test_import_duringImportStepDoesNothing(): void
    {
        self::definition('Prices');

        $said = BatchStep::during(new Run(), static fn(): string => self::task('{import:prices}')->toexecutelater());

        $this->assertSame('nightly:=', $said);
        $this->assertSame([], self::runIds());
    }

    /** a view, an included text or an item the task shows is not the task - its author did not save it */
    public function test_import_callNotInTheTaskTextInvalid(): void
    {
        require_once __DIR__ . '/fixtures/import_view_expand.php';
        self::definition('Prices');

        \AA_Stringexpand_Testimportview::$text = '{import:prices}';
        $this->assertSame('nightly:=imp:invalid', self::task('{testimportview}')->toexecutelater());
        // the task writes {import:prices}, the view another mode
        \AA_Stringexpand_Testimportview::$text = '{import:prices:check}';
        $this->assertSame('nightly:=imp:queued imp:invalid', self::task('{import:prices} {testimportview}')->toexecutelater());
        $this->assertCount(1, self::runIds());
    }

    public function test_import_queuesFromOwnSliceTask(): void
    {
        self::definition('Prices');

        $this->assertSame('nightly:=imp:queued', self::task('{import:' . self::DEF . '}')->toexecutelater());

        $runs = self::runIds();
        $this->assertCount(1, $runs);
        $run = self::stored($runs[0]);
        $this->assertSame('plan:' . self::TASK, $run['trigger'], 'the task that runs, not a text of the template');
        $this->assertSame(self::AUTHOR, $run['user']);
        $this->assertSame('run', $run['mode']);
    }

    /** an import of another slice is the same as none - by its id as by its name */
    public function test_import_definitionOfOtherSliceIsUnknown(): void
    {
        self::definition('Prices', self::OTHER);
        self::$stored[self::AUTHOR]['slice'][self::OTHER] = PS_FEEDING;

        $this->assertSame('nightly:=imp:unknown', self::task('{import:' . self::DEF . '}')->toexecutelater());
        $this->assertSame('nightly:=imp:unknown', self::task('{import:prices}')->toexecutelater());
        $this->assertSame([], self::runIds());
    }

    public function test_import_byName(): void
    {
        self::definition('Prices');

        $this->assertSame('nightly:=imp:queued', self::task('{import:PRICES:check}')->toexecutelater());
        $this->assertSame('check', self::stored(self::runIds()[0])['mode']);
    }

    /** two imports of one name - started together they may both get it - start neither */
    public function test_import_ambiguousNameInvalid(): void
    {
        self::definition('Prices');
        self::definition('Prices', self::SLICE, '1111111111111111aaaaaaaaaaaaaaaa');

        $this->assertSame('nightly:=imp:invalid', self::task('{import:prices}')->toexecutelater());
        $this->assertSame([], self::runIds());
        $this->assertEqualsCanonicalizing(['#' . self::DEF . ' AMBIGUOUS', '#1111111111111111aaaaaaaaaaaaaaaa AMBIGUOUS'], self::logged());
        $this->assertSame('nightly:=imp:unknown', self::task('{import:rents}')->toexecutelater(), 'no import of the name');
    }

    /** {import} trims its parameters and reads the mode in any case - as the import detail lists the task */
    public function test_import_modeTrimmedAndCaseless(): void
    {
        self::definition('Prices');

        $this->assertSame('nightly:=imp:queued', self::task("{import: prices : Changed\n}")->toexecutelater());
        $this->assertSame('changed', self::stored(self::runIds()[0])['mode']);
    }

    /** @return array<string, array{string, string}> task text, the answer */
    public static function computedCalls(): array
    {
        return [
            'import computed' => ['{define:x:prices}{import:{var:x}}', 'imp:invalid'],
            'mode computed'   => ['{define:m:check}{import:prices:{var:m}}', 'imp:invalid'],
            'spaces and case' => ['{import: PRICES :Run }', 'imp:queued'],
        ];
    }

    /** only a call the task writes out runs: a computed import or mode is not the task's own text */
    #[DataProvider('computedCalls')]
    public function test_import_computedRefOrModeRefused(string $text, string $answer): void
    {
        self::definition('Prices');

        $this->assertSame("nightly:=$answer", self::task($text)->toexecutelater());
        $this->assertCount(($answer === 'imp:queued') ? 1 : 0, self::runIds());
    }

    /** @return array<string, array{string}> */
    public static function badCalls(): array
    {
        return [
            'unknown mode'      => ['{import:prices:all}'],
            'baseline'          => ['{import:prices:baseline}'],
            'zero'              => ['{import:prices:0}'],
            'one more argument' => ['{import:prices:run:x}'],
            'no import'         => ['{import::run}'],
            'bare'              => ['{import}'],
            'parenthesis'       => ['{import(prices)}'],
        ];
    }

    #[DataProvider('badCalls')]
    public function test_import_badModeInvalid(string $call): void
    {
        self::definition('Prices');

        $this->assertSame('nightly:=imp:invalid', self::task($call)->toexecutelater());
        $this->assertSame([], self::runIds());
    }

    /** the import would save items, the event would run the task again - for good */
    public function test_import_eventTaskInvalid(): void
    {
        self::definition('Prices');

        $task = self::task('{import:prices}', ['event' => ['ITEM_UPDATED']]);

        $this->assertSame('nightly:=imp:invalid', $task->toexecutelater());
        $this->assertSame([], self::runIds());
    }

    public function test_import_busy(): void
    {
        self::definition('Prices');
        self::task('{import:prices}')->toexecutelater();

        $this->assertSame('nightly:=imp:busy', self::task('{import:prices}')->toexecutelater());
        $this->assertCount(1, self::runIds());
    }

    public function test_import_disabledOff(): void
    {
        self::definition('Prices', self::SLICE, self::DEF, '');

        $this->assertSame('nightly:=imp:off', self::task('{import:prices}')->toexecutelater());
        $this->assertSame([], self::runIds());
    }

    /** the right of the author is read as it is now, and not into the session of whoever runs the queue */
    public function test_import_taskSavedWithoutFeedingDenied(): void
    {
        self::definition('Prices');
        self::$stored[self::AUTHOR] = ['slice' => [self::SLICE => PS_FORMS . PS_EDIT_ALL_ITEMS, self::OTHER => PS_FEEDING]];

        $this->assertSame('nightly:=imp:denied', self::task('{import:prices}')->toexecutelater());
        $this->assertSame([], self::runIds());
        $this->assertSame(AuthGetUid(), $GLOBALS['permission_uid'], 'the session keeps its own permissions');
    }

    /** a task saved before tasks recorded their author must be saved again */
    public function test_import_taskWithoutSavedByDenied(): void
    {
        self::definition('Prices');

        $this->assertSame('nightly:=imp:denied', self::task('{import:prices}', ['saved_by' => ''])->toexecutelater());
        $this->assertSame([], self::runIds());
    }

    /** @return array<string, array{string, string}> what is arranged, the answer */
    public static function answers(): array
    {
        return [
            'queued'  => ['queued', 'imp:queued'],
            'busy'    => ['busy', 'imp:busy'],
            'off'     => ['off', 'imp:off'],
            'unknown' => ['unknown', 'imp:unknown'],
            'invalid' => ['invalid', 'imp:invalid'],
            'denied'  => ['denied', 'imp:denied'],
        ];
    }

    #[DataProvider('answers')]
    public function test_import_outputMax12Bytes(string $case, string $answer): void
    {
        self::definition('Prices', self::SLICE, self::DEF, ($case === 'off') ? '' : '1');
        $call = match ($case) {
            'unknown' => '{import:rents}',
            'invalid' => '{import:prices:all}',
            default   => '{import:prices}',
        };
        if ($case === 'busy') {
            self::task($call)->toexecutelater();
        }
        if ($case === 'denied') {
            self::$stored = [];
        }

        $said = substr(self::task($call)->toexecutelater(), strlen('nightly:='));

        $this->assertSame($answer, $said);
        $this->assertLessThanOrEqual(12, strlen($said));
    }

    // ------------------------------------------------------------ helpers

    /** the task of SLICE, as the queue runs it
     *  @param array<string, mixed> $props */
    private static function task(string $expression, array $props = []): AA_Plannedtask
    {
        $task = new AA_Plannedtask($props + ['task' => $expression, 'saved_by' => self::AUTHOR]);
        $task->setId(self::TASK)->setOwnerId(self::SLICE)->setName('nightly');
        if (!array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === self::TASK)) {
            foreach (['aa_type' => AA_Plannedtask::class, 'aa_owner' => self::SLICE] as $property => $value) {
                TableDb::$tables['object_text'][] = ['id' => 900000 + count(TableDb::rows('object_text')), 'object_id' => self::TASK, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
            }
        }
        return $task;
    }

    private static function definition(string $name, string $slice = self::SLICE, string $id = self::DEF, string $enabled = '1'): void
    {
        $def = new Definition();
        $def->setNew($id, $slice, $name);
        foreach (['source' => self::SOURCE, 'mapping' => self::NO_PARAM_MAPPING, 'key_fields' => '["relation........"]', 'store_mode' => 'update/insert', 'enabled' => $enabled] as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame($id, $def->save());
    }

    /** @return list<string> the ids of the stored import runs */
    private static function runIds(): array
    {
        $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && ($r['value'] === Run::class));
        return array_values(array_map(static fn(array $r): string => (string) $r['object_id'], $rows));
    }

    /** @return list<string> the params of the logged failures of the imports */
    private static function logged(): array
    {
        return array_values(array_map(static fn(array $r): string => (string) $r['params'], array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Definition::ERROR_EVENT)));
    }
}
