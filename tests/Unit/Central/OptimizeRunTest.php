<?php

declare(strict_types=1);

/**
 * OptimizeRun: one Optimize task on chosen AAs of the Central list, an AA after another in the queue -
 * test only asks for the test; repair tests first, repairs only where it fails and tests again; a busy
 * or silent AA is asked again and given up after the retries; a refusal is the AA's result.
 */

// Loading AA_Object (include/statestore.php) needs the config constants already (as InstallRunTest).

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Central {

    use AA\Central\Actionapps;
    use AA\Central\OptimizeRun;
    use AA\Central\ResponderPolicy;
    use AA\Later\BatchBudgetSpent;
    use AA\Later\BatchFailed;
    use AA\Later\BatchStep;
    use AA_Response;
    use InvalidArgumentException;
    use LogicException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\FakeSuperadmins;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class OptimizeRunTest extends TestCase
    {
        private const USER  = 'admin-uid';
        private const CLASS_ = 'AA\Optimize\UpdateDbStructure';
        private const SPENT  = 'spent';

        private FakeSuperadmins $superadmins;
        private mixed $perm = null;
        /** @var list<array{int, string, string, int}> what the AAs were asked: id, task, method, timeout */
        private array $asked = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/Package/fixtures/TableDb.php';
            require_once __DIR__ . '/Package/fixtures/FakeSuperadmins.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$next       = ['object_text' => 1];
            TableDb::$engines    = ['object_text' => 'InnoDB', 'toexecute' => 'InnoDB'];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
            class_exists(\AA_Perm::class);              // its first load sets AA::$perm - not over the stub
            $this->perm          = \AA::$perm;
            $this->superadmins   = new FakeSuperadmins([self::USER]);
            \AA::$perm           = $this->superadmins;
            $this->asked         = [];
            OptimizeRun::setTestRemote(null);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
            \AA::$perm = $this->perm;
            OptimizeRun::setTestRemote(null);
            set_time_limit(0);   // a task later() runs at once sets a 30 s limit - it must not outlive the test
        }

        /** the AAs answer from $answers[id] in turn (the last one repeats); null = no such row,
         *  self::SPENT = no time left in the step for that request (nothing is sent)
         *  @param array<int, list<?AA_Response|string>> $answers */
        private function remote(array $answers): void
        {
            $asked = &$this->asked;
            OptimizeRun::setTestRemote(static function (int $aa, string $task, string $method, int $timeout) use (&$answers, &$asked): ?AA_Response {
                $list   = &$answers[$aa];
                $answer = (count($list) > 1) ? array_shift($list) : $list[0];
                if ($answer === self::SPENT) {
                    throw new BatchBudgetSpent('no time left');
                }
                $asked[] = [$aa, $task, $method, $timeout];
                return $answer;
            });
        }

        private static function late(): AA_Response
        {
            return new AA_Response('The remote AA did not answer in time', Actionapps::ERR_TIMEOUT);
        }

        private static function busy(): AA_Response
        {
            return new AA_Response('sql_update is already running on this AA', ResponderPolicy::ERR_OPTIMIZE_TYPE_RUNNING);
        }

        /** runs the queued steps of run $id until it is finished, $max at most
         *  @return int the steps run */
        private static function runAll(string $id, int $max = 10): int
        {
            for ($n = 1; $n <= $max; $n++) {
                if (self::runQueued($id) === OptimizeRun::DONE) {
                    return $n;
                }
            }
            throw new LogicException("Not finished in $max steps");
        }

        private static function ok(string $report = '<table>fine</table>'): AA_Response
        {
            return new AA_Response("OK $report");
        }

        private static function err(string $report = '<table>broken</table>'): AA_Response
        {
            return new AA_Response("Err $report");
        }

        /** runs the oldest queued step of run $id as the queue does - the row goes away after it */
        private static function runQueued(string $id): string
        {
            $rows = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['selector'] === "Optimize_$id"));
            $row  = $rows[0] ?? throw new LogicException('Nothing queued');
            $step = unserialize((string) $row['object']);
            self::assertInstanceOf(BatchStep::class, $step);
            $ret = $step->toexecutelater();
            TableDb::$tables['toexecute'] = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['id'] !== $row['id']));
            return $ret;
        }

        /** @return array<int, string> central_conf id => its result */
        private static function statuses(string $id): array
        {
            return array_map(static fn(array $r): string => $r['s'], OptimizeRun::get($id)->results());
        }

        /** @return list<string> the methods the AA $aa was asked, in order */
        private function methodsOf(int $aa): array
        {
            return array_column(array_values(array_filter($this->asked, static fn(array $a): bool => $a[0] === $aa)), 2);
        }

        // ---------------------------------------------------------------- the record

        public function test_begin_stores_the_task_and_queues_the_first_step(): void
        {
            $run = OptimizeRun::begin(self::CLASS_, 'repair', [7, 3, 7], self::USER);

            $this->assertSame(OptimizeRun::RUNNING, $run->state());
            $this->assertSame([7, 3], $run->aas(), 'in the order chosen, each once');
            $this->assertSame([self::CLASS_, 'repair', self::USER], [$run->task(), $run->method(), $run->user()]);
            $this->assertSame(['i' => 0], $run->position());
            $this->assertCount(1, array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['selector'] === 'Optimize_' . $run->getId()));
        }

        /** @return array<string, array{string, string, list<int>}> */
        public static function wrongStarts(): array
        {
            return [
                'unknown task'          => ['AA\Optimize\NoSuchClass', 'test', [1]],
                'a class that is no Optimize' => ['AA\Central\Actionapps', 'test', [1]],
                'unknown method'        => [OptimizeRun::SQL_UPDATE, 'revert', [1]],
                'a class with no repair' => ['AA\Optimize\CheckInstallRuns', 'repair', [1]],
                'no AA'                 => [OptimizeRun::SQL_UPDATE, 'test', []],
                'no valid AA'           => [OptimizeRun::SQL_UPDATE, 'test', [0, -3]],
            ];
        }

        /** @param list<int> $aas */
        #[DataProvider('wrongStarts')]
        public function test_begin_refuses(string $task, string $method, array $aas): void
        {
            $this->expectException(InvalidArgumentException::class);
            OptimizeRun::begin($task, $method, $aas, self::USER);
        }

        public function test_tasks_start_with_the_whole_sql_update_and_name_the_classes(): void
        {
            $tasks = OptimizeRun::tasks();

            $this->assertSame(OptimizeRun::SQL_UPDATE, array_key_first($tasks));
            $this->assertArrayHasKey(self::CLASS_, $tasks);
            $this->assertArrayHasKey('AA\Optimize\ChangeQte2Trm', $tasks);
            $this->assertArrayNotHasKey('AA\Optimize\Chain', $tasks, 'no Optimize class');
            $this->assertArrayNotHasKey('AA\Optimize\AbstractOptimize', $tasks);
        }

        public function test_recent_lists_the_runs_newest_first(): void
        {
            $first  = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1], self::USER)->getId();
            $second = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [2], self::USER)->getId();
            foreach (TableDb::$tables['object_text'] as $i => $row) {      // started a minute apart
                if (($row['object_id'] === $first) && ($row['property'] === 'started')) {
                    TableDb::$tables['object_text'][$i]['value'] = (string) ((int) $row['value'] - 60);
                }
            }

            $this->assertSame([$second, $first], array_map(static fn(OptimizeRun $r): string => $r->getId(), OptimizeRun::recent()));
        }

        // ---------------------------------------------------------------- test

        public function test_test_asks_each_aa_for_the_test_only(): void
        {
            $this->remote([1 => [self::ok()], 2 => [self::err('<p>missing column</p>')]]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1, 2], self::USER)->getId();

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id), 'both AAs in one step');

            $this->assertSame([1 => OptimizeRun::OK, 2 => OptimizeRun::PROBLEM], self::statuses($id));
            $this->assertSame(['test'], $this->methodsOf(1));
            $this->assertSame(['test'], $this->methodsOf(2));
            $this->assertSame([OptimizeRun::SQL_UPDATE, OptimizeRun::SQL_UPDATE], array_column($this->asked, 1));
            $this->assertSame('<p>missing column</p>', OptimizeRun::get($id)->results()[2]['r'], 'the report without the OK/Err mark');
            $this->assertSame('', OptimizeRun::get($id)->results()[1]['r'], 'no report of a test that passed');
            $this->assertSame(2, OptimizeRun::get($id)->done());
            foreach ($this->asked as $a) {
                $this->assertGreaterThan(0, $a[3], 'every request has a timeout');
                $this->assertLessThanOrEqual(30, $a[3], 'within the step');
            }
        }

        // ---------------------------------------------------------------- repair

        public function test_repair_repairs_only_where_the_test_fails_and_tests_again(): void
        {
            $this->remote([
                1 => [self::ok()],
                2 => [self::err(), self::ok('<p>repaired</p>'), self::ok()],
                3 => [self::err(), self::ok('<p>repaired</p>'), self::err('<p>still broken</p>')],
                4 => [self::err(), self::err('<p>repair failed</p>'), self::err()],
            ]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1, 2, 3, 4], self::USER)->getId();

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));

            $this->assertSame([1 => OptimizeRun::OK, 2 => OptimizeRun::REPAIRED, 3 => OptimizeRun::FAILED, 4 => OptimizeRun::FAILED], self::statuses($id));
            $this->assertSame(['test'], $this->methodsOf(1), 'nothing to repair');
            $this->assertSame(['test', 'repair', 'test'], $this->methodsOf(2));
            $results = OptimizeRun::get($id)->results();
            $this->assertSame(['<p>repaired</p>', ''], [$results[2]['r'], $results[2]['c']], 'the repair report; the test after it passed');
            $this->assertSame(['<p>repaired</p>', '<p>still broken</p>'], [$results[3]['r'], $results[3]['c']], 'what the test still finds');
            $this->assertSame('<p>repair failed</p>', $results[4]['r']);
        }

        public function test_a_repair_that_answered_too_late_is_not_sent_again(): void
        {
            $this->remote([1 => [self::err(), self::late(), self::ok()]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runQueued($id);
            $run = OptimizeRun::get($id);
            $this->assertSame(['i' => 0, 'next' => 'check'], $run->position(), 'the AA again, the test after the repair');
            $this->assertSame([1, 'check'], $run->current());
            $this->assertSame(OptimizeRun::AA_NO_ANSWER, $run->lastFailure()['code'] ?? null);
            $this->assertTrue($run->isWaiting(OptimizeRun::clock()), 'waits before asking again');

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));
            $this->assertSame(['test', 'repair', 'test'], $this->methodsOf(1), 'the test passes now');
            $this->assertSame([1 => OptimizeRun::REPAIRED], self::statuses($id));
        }

        public function test_a_failing_test_after_a_repair_that_answered_too_late_is_no_second_repair(): void
        {
            $this->remote([1 => [self::err(), self::late(), self::err('<p>still broken</p>')]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runAll($id);

            $this->assertSame(['test', 'repair', 'test'], $this->methodsOf(1));
            $result = OptimizeRun::get($id)->results()[1];
            $this->assertSame([OptimizeRun::FAILED, '', '<p>still broken</p>'], [$result['s'], $result['r'], $result['c']]);
            $this->assertStringContainsString('may still work', $result['m']);
        }

        public function test_each_request_may_come_in_a_step_of_its_own(): void
        {
            $this->remote([1 => [self::err(), self::SPENT, self::ok('<p>repaired</p>'), self::SPENT, self::ok()]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runQueued($id);
            $run = OptimizeRun::get($id);
            $this->assertSame(['i' => 0, 'next' => 'repair'], $run->position(), 'the test failed - the repair next');
            $this->assertFalse($run->isWaiting(OptimizeRun::clock()), 'only out of time - it goes on at once');

            self::runQueued($id);
            $run = OptimizeRun::get($id);
            $this->assertSame(['i' => 0, 'next' => 'check'], $run->position());
            $this->assertSame([1 => OptimizeRun::REPAIRED], self::statuses($id), 'the repair kept before the test after it');
            $this->assertSame(0, $run->done(), 'not done before that test');

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));
            $this->assertSame(['test', 'repair', 'test'], $this->methodsOf(1), 'no request twice');
            $this->assertSame('<p>repaired</p>', OptimizeRun::get($id)->results()[1]['r']);
        }

        public function test_a_refused_test_after_the_repair_keeps_the_repair(): void
        {
            $this->remote([1 => [self::err(), self::ok('<p>repaired</p>'), new AA_Response('The login token was refused', ResponderPolicy::ERR_TOKEN_REFUSED)]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runAll($id);

            $result = OptimizeRun::get($id)->results()[1];
            $this->assertSame([OptimizeRun::REPAIRED, '<p>repaired</p>'], [$result['s'], $result['r']]);
            $this->assertStringContainsString('refused', $result['m']);
        }

        public function test_a_refused_repair_is_no_repair_sent(): void
        {
            $this->remote([1 => [self::err(), new AA_Response('The login token was refused', ResponderPolicy::ERR_TOKEN_REFUSED)]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runAll($id);

            $result = OptimizeRun::get($id)->results()[1];
            $this->assertSame(OptimizeRun::REFUSED, $result['s']);
            $this->assertStringContainsString('did not start', $result['m']);
            $this->assertStringContainsString('refused', $result['m']);
            $this->assertSame(['test', 'repair'], $this->methodsOf(1), 'no test after a repair that did not run');
        }

        public function test_a_repair_with_no_answer_since_is_given_up_with_a_word_of_it(): void
        {
            $this->remote([1 => [self::err(), self::late()]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runAll($id);

            $result = OptimizeRun::get($id)->results()[1];
            $this->assertSame(OptimizeRun::REFUSED, $result['s']);
            $this->assertStringContainsString('repair was sent', $result['m']);
            $this->assertSame(1, array_count_values($this->methodsOf(1))['repair'], 'one repair');
        }

        public function test_a_repair_put_off_waits_while_the_aa_is_busy(): void
        {
            $this->remote([1 => [self::err(), self::SPENT, self::busy()]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runQueued($id);
            self::runQueued($id);
            $this->assertSame(['i' => 0, 'next' => 'repair'], OptimizeRun::get($id)->position(), 'still to repair');

            self::runAll($id);
            $result = OptimizeRun::get($id)->results()[1];
            $this->assertSame(OptimizeRun::REFUSED, $result['s']);
            $this->assertStringContainsString('The test fails', $result['m']);
            $this->assertSame(1, array_count_values($this->methodsOf(1))['test'], 'not tested again');
        }

        public function test_a_repair_a_busy_aa_refused_is_no_repair_of_this_run(): void
        {
            $this->remote([1 => [self::err(), self::busy(), self::ok()]]);
            $id = OptimizeRun::begin(self::CLASS_, 'repair', [1], self::USER)->getId();

            self::runQueued($id);
            $this->assertSame(['i' => 0], OptimizeRun::get($id)->position(), 'no repair sent');

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));
            $this->assertSame([1 => OptimizeRun::OK], self::statuses($id), 'the other update fixed it, not this run');
        }

        // ---------------------------------------------------------------- failures

        public function test_a_refusal_is_the_result_of_the_aa_and_the_run_goes_on(): void
        {
            $this->remote([
                1 => [new AA_Response('The login token was refused', ResponderPolicy::ERR_TOKEN_REFUSED)],
                2 => [null],
                3 => [new AA_Response('Bad request sent for responder.php - Do_Optimize', ResponderPolicy::ERR_UNKNOWN_COMMAND)],
                4 => [self::ok()],
            ]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'repair', [1, 2, 3, 4], self::USER)->getId();

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));

            $results = OptimizeRun::get($id)->results();
            $this->assertSame([1 => OptimizeRun::REFUSED, 2 => OptimizeRun::REFUSED, 3 => OptimizeRun::REFUSED, 4 => OptimizeRun::OK], self::statuses($id));
            $this->assertStringContainsString('refused', $results[1]['m']);
            $this->assertStringContainsString('No such AA', $results[2]['m']);
            $this->assertStringContainsString('update it first', $results[3]['m']);
            $this->assertSame(['test'], $this->methodsOf(3), 'no repair after a refused test');
        }

        public function test_a_busy_aa_is_asked_again_then_given_up_and_the_next_one_asked(): void
        {
            $this->remote([1 => [self::busy()], 2 => [self::ok()]]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1, 2], self::USER)->getId();

            self::runQueued($id);
            self::runQueued($id);
            $this->assertSame([], OptimizeRun::get($id)->results(), 'still trying the first AA');
            $this->assertSame(OptimizeRun::AA_BUSY, OptimizeRun::get($id)->lastFailure()['code'] ?? null);

            self::runQueued($id);         // the third try gives it up
            $this->assertSame([1 => OptimizeRun::REFUSED], self::statuses($id));
            $this->assertStringContainsString('another update', OptimizeRun::get($id)->results()[1]['m']);
            $this->assertSame(OptimizeRun::RUNNING, OptimizeRun::get($id)->state(), 'the run goes on');

            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));
            $this->assertSame([1 => OptimizeRun::REFUSED, 2 => OptimizeRun::OK], self::statuses($id));
            $this->assertSame(['test', 'test', 'test'], $this->methodsOf(1));
        }

        public function test_a_last_aa_given_up_still_finishes_the_run(): void
        {
            $this->remote([1 => [self::late()]]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1], self::USER)->getId();

            self::runQueued($id);
            self::runQueued($id);
            self::runQueued($id);
            $this->assertSame(OptimizeRun::DONE, self::runQueued($id));
            $this->assertStringContainsString('did not answer in time', OptimizeRun::get($id)->results()[1]['m']);
        }

        public function test_a_user_no_more_superadmin_stops_the_run(): void
        {
            $this->remote([1 => [self::ok()]]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1], self::USER)->getId();
            $this->superadmins->superadmins = [];

            $this->assertSame('error ' . OptimizeRun::DENIED, self::runQueued($id));
            $this->assertSame([], $this->asked, 'no AA asked');
        }

        public function test_a_long_report_is_cut(): void
        {
            $this->remote([1 => [self::err(str_repeat('x', 300_000))]]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1], self::USER)->getId();

            self::runQueued($id);

            $report = OptimizeRun::get($id)->results()[1]['r'];
            $this->assertLessThan(101_000, strlen($report));
            $this->assertStringContainsString('cut', $report);
        }

        // ---------------------------------------------------------------- control

        public function test_end_and_continue_only_for_a_superadmin(): void
        {
            $this->remote([1 => [self::busy()]]);
            $id = OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1], self::USER)->getId();
            self::runQueued($id);

            try {
                OptimizeRun::resumeRun($id, 'someone-else');
                $this->fail('Continue by no superadmin');
            } catch (BatchFailed $e) {
                $this->assertSame('FORBIDDEN', $e->reason);
            }
            OptimizeRun::resumeRun($id, self::USER);        // waits for its retry: Continue asks now
            $this->assertFalse(OptimizeRun::get($id)->isWaiting(OptimizeRun::clock()));

            OptimizeRun::endRun($id, self::USER);
            $this->assertSame(OptimizeRun::ENDED, OptimizeRun::get($id)->state());
        }

        // ---------------------------------------------------------------- the answers

        /** @return array<string, array{AA_Response, string, string}> */
        public static function answers(): array
        {
            return [
                'test passed'         => [new AA_Response('OK <table></table>'), OptimizeRun::OK, '<table></table>'],
                'test failed'         => [new AA_Response('Err <table></table>'), OptimizeRun::PROBLEM, '<table></table>'],
                'an older AA'         => [new AA_Response('<p>No duplicates found</p>'), OptimizeRun::REFUSED, 'update it first'],
                'no text'             => [new AA_Response(['OK ']), OptimizeRun::REFUSED, 'update it first'],
                'busy'                => [new AA_Response('running', ResponderPolicy::ERR_OPTIMIZE_TYPE_RUNNING), OptimizeRun::AA_BUSY, 'running'],
                'no answer in time'   => [new AA_Response('did not answer in time', Actionapps::ERR_TIMEOUT), OptimizeRun::AA_NO_ANSWER, 'did not answer in time'],
                'not reached'         => [new AA_Response('The remote AA answered HTTP 500', Actionapps::ERR_TRANSPORT), OptimizeRun::REFUSED, 'HTTP 500'],
                'unknown command'     => [new AA_Response('Bad request', ResponderPolicy::ERR_UNKNOWN_COMMAND), OptimizeRun::REFUSED, 'update it first'],
                'unknown class'       => [new AA_Response('Unknown optimize class: AA\Optimize\X', 4), OptimizeRun::REFUSED, 'Unknown optimize class'],
            ];
        }

        #[DataProvider('answers')]
        public function test_answer_tells_the_kind(AA_Response $response, string $kind, string $text): void
        {
            $answer = OptimizeRun::answer($response);

            $this->assertSame($kind, $answer['kind']);
            $this->assertStringContainsString($text, $answer['text']);
        }
    }
}
