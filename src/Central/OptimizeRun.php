<?php

declare(strict_types=1);

namespace AA\Central;

use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use AA\Later\BatchFailed;
use AA\Later\BatchStates;
use AA\Later\BatchStepResult;
use AA\Optimize\AbstractOptimize;
use AA_Components;
use AA_Property;
use AA_Request;
use AA_Response;
use Closure;
use InvalidArgumentException;
use LogicException;

/** One Optimize task (a class, or the whole sql_update) run on chosen AAs of the Central list, one AA
 *  after another in the task queue; the page central/optimize.php only shows the results.
 *  Test asks each AA for the test. Repair asks for the test first, for the repair only where it fails,
 *  then for the test again in a new request (the caches of the repair's request could lie). Each of the
 *  three requests may come in a step of its own (position: i and next), and one repair is sent at most:
 *  after a repair that did not answer in time the AA is only tested.
 *  An AA that is busy (another sql_update runs there) or does not answer in time is asked again later;
 *  after MAX_FAILURES tries in a row its result says so and the run goes on with the next AA. */
final class OptimizeRun extends CentralRun
{
    public const RUNNING = 'running';
    public const ERROR   = 'error';
    public const DONE    = 'done';
    public const ENDED   = 'ended';

    /** the task of the whole chain of the AA's own version (Do_Optimize_Type); any other is a class */
    public const SQL_UPDATE = 'sql_update';
    public const METHODS    = ['test', 'repair'];

    // the result of an AA
    /** test: OK; repair: the test passed, nothing to repair */
    public const OK       = 'ok';
    /** test: the test failed */
    public const PROBLEM  = 'problem';
    /** repair: repaired, and the test passes now */
    public const REPAIRED = 'repaired';
    /** repair: the repair failed, or the test fails after it */
    public const FAILED   = 'failed';
    /** the AA refused or could not be asked - the message says why */
    public const REFUSED  = 'refused';

    /** retry codes: the AA runs another sql_update now; the AA did not answer (in time) */
    public const AA_BUSY      = 'AA_BUSY';
    public const AA_NO_ANSWER = 'AA_NO_ANSWER';

    /** what the form of central/optimize.php sends with its token */
    public const FORM_TOKEN = 'central-optimize';

    /** Optimize classes a run never offers: their test does not say "nothing to repair" - RestoreBckTables
     *  passes when backups exist, and its repair replaces the tables that have a backup by it */
    private const EXCLUDED = [\AA\Optimize\RestoreBckTables::class];

    /** finished runs kept for their results */
    private const KEEP = 20;
    /** AAs one run asks at most */
    private const MAX_AAS = 100;
    /** a request after the first of a step only with this much of the step left - less is no fair chance
     *  to answer, and a repair cut off at once would only run unseen */
    private const MIN_SECONDS = 15;
    /** a report kept of an AA - a whole sql_update test is some tens of kB */
    private const MAX_REPORT = 100_000;
    /** seconds before a busy or silent AA is asked again: after a step that still moved on, after the
     *  first and the second failure in a row - longer together than a remote sql_update may run (360 s) */
    protected const RETRY = [60, 120, 300];

    /** @var ?Closure(int, string, string, int): ?AA_Response */
    private static ?Closure $testRemote = null;

    /** JSON list of central_conf ids, in the order they are asked */
    protected $aas = '';
    /** self::SQL_UPDATE or an Optimize class */
    protected $task = '';
    protected $method = '';
    /** JSON central_conf id => {s: result, m: message, r: report, c: report of the test after the repair} -
     *  an AA asked for the test after its repair has the result of the repair here already */
    protected $results = '';

    protected static function states(): BatchStates
    {
        return new BatchStates(self::RUNNING, self::ERROR, self::DONE, self::ENDED);
    }

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (['aas', 'task', 'method', 'results'] as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    /** A new run with its first step queued; old finished runs go away on the way. Outside any transaction.
     *  The caller has checked that $user is a superadmin and the AAs are rows of the Central list.
     *  @param array<int> $aas central_conf ids, in the order they are asked
     *  @throws InvalidArgumentException an unknown task, a method the task has not, no AA
     *  @throws BatchFailed NO_TRANSACTION a table the run needs is not InnoDB */
    public static function begin(string $task, string $method, array $aas, string $user): self
    {
        if (!isset(self::tasks()[$task])) {
            throw new InvalidArgumentException("Unknown optimize task $task");
        }
        if (!in_array($method, self::METHODS, true) || (($task !== self::SQL_UPDATE) && !in_array($method, $task::actions(), true))) {
            throw new InvalidArgumentException("The task $task has no $method");
        }
        $aas = array_values(array_unique(array_filter(array_map('intval', $aas), static fn(int $id): bool => $id > 0)));
        if (!$aas || (count($aas) > self::MAX_AAS)) {
            throw new InvalidArgumentException('A run asks 1 to ' . self::MAX_AAS . ' AAs');
        }
        self::cleanup(self::subject(), self::KEEP);
        $id = DB_AA::transaction(static fn(): string => self::start([
            'subject'  => self::subject(),
            'user'     => $user,
            'aas'      => json_encode($aas, JSON_THROW_ON_ERROR),
            'task'     => $task,
            'method'   => $method,
            'position' => self::at(0),
        ])->getId());
        // read again: the instance of start() is not the run as stored
        return self::get($id) ?? throw new LogicException('The new optimize run is gone');
    }

    /** What a run may do: every task of taskGroups()
     *  @return array<string, string> task => name */
    public static function tasks(): array
    {
        return array_replace(...array_values(self::taskGroups()));
    }

    /** The tasks in groups for the choice, the recommended first: the whole sql_update; the repairs; the
     *  repairs that read whole big tables (CHECK_SLOW - minutes on a big database, longer than a request
     *  waits); the tests by their State Check category; the steps of sql_update one by one; the fixes
     *  made for one website. An Optimize class is a task when it has a name and a test (and is not EXCLUDED);
     *  the group comes from its types() and whether it has a repair.
     *  @return array<string, array<string, string>> group => task => name, by name */
    public static function taskGroups(): array
    {
        $found = [];
        foreach (AA_Components::getClassNames('AA\\Optimize\\', null, false) as $class) {
            if (!is_subclass_of($class, AbstractOptimize::class) || ($class::name() === '') || !in_array('test', $class::actions(), true) || in_array($class, self::EXCLUDED, true)) {
                continue;
            }
            $types = $class::types();
            [$rank, $group] = match (true) {
                in_array('private_fix', $types, true)                => [5, _m('Fixes made for one website')],
                in_array(self::SQL_UPDATE, $types, true)             => [4, _m('Steps of the DB update one by one')],
                !in_array('repair', $class::actions(), true)         => [3, _m('Tests - %1', [$class::category() ?: 'db'])],
                in_array(AbstractOptimize::CHECK_SLOW, $types, true) => [2, _m('Repairs reading whole big tables - minutes on a big database')],
                default                                              => [1, _m('Repairs')],
            };
            $found[$rank][$group][$class] = $class::name();
        }
        ksort($found);
        $groups = [_m('Recommended') => [self::SQL_UPDATE => self::taskName(self::SQL_UPDATE)]];
        foreach ($found as $ofRank) {
            ksort($ofRank, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($ofRank as $group => $tasks) {
                asort($tasks, SORT_NATURAL | SORT_FLAG_CASE);
                $groups[$group] = $tasks;
            }
        }
        return $groups;
    }

    /** the name of a task as tasks() gives it - without reading all the classes */
    public static function taskName(string $task): string
    {
        if ($task === self::SQL_UPDATE) {
            return _m('Update DB (sql_update) - all its steps');
        }
        return (is_subclass_of($task, AbstractOptimize::class) && ($task::name() !== '')) ? $task::name() : $task;
    }

    /** the runs of this AA, newest first - read-only, no rights checked
     *  @return list<self> */
    public static function recent(): array
    {
        return self::ofSubject(self::subject(), PHP_INT_MAX);
    }

    /** Continue: the next step now, from the run's position
     *  @throws BatchFailed FORBIDDEN (also for no run), LEASE while a step may still work, WRONG_STATE */
    public static function resumeRun(string $id, string $uid): void
    {
        DB_AA::transaction(static fn() => (self::lockForUpdate($id) ?? throw new BatchFailed(self::FORBIDDEN))->resume($uid));
    }

    /** End: the AAs asked so far keep their results, the rest is not asked
     *  @throws BatchFailed FORBIDDEN (also for no run), WRONG_STATE when finished */
    public static function endRun(string $id, string $uid): void
    {
        DB_AA::transaction(static fn() => (self::lockForUpdate($id) ?? throw new BatchFailed(self::FORBIDDEN))->end($uid));
    }

    /** @internal for tests only: what the AAs answer - $ask(central_conf id, task, method, timeout);
     *  null as its answer = no such row; null = the real AAs */
    public static function setTestRemote(?Closure $ask): void
    {
        self::$testRemote = $ask;
    }

    /** the position: the index of the AA asked now and what it is asked: '' the test, 'repair' (its test
     *  failed), 'check' (the test after the repair this run sent it)
     *  @return array{i: int, next?: string} */
    private static function at(int $i, string $next = ''): array
    {
        return ($next === '') ? ['i' => $i] : ['i' => $i, 'next' => $next];
    }

    /** the command that runs $method of $task on an AA */
    public static function request(string $task, string $method): AA_Request
    {
        return ($task === self::SQL_UPDATE)
            ? new AA_Request('Do_Optimize_Type', ['type' => $task, 'method' => $method])
            : new AA_Request('Do_Optimize', ['class' => $task, 'method' => $method]);
    }

    // ---------------------------------------------------------------- the steps

    public function selector(): string
    {
        return 'Optimize_' . $this->getId();
    }

    /** Central is for superadmins only - any of them sees and steers every run */
    public function mayControl(string $uid): bool
    {
        return self::isSuperadmin($uid);
    }

    /** a step starts only with time for an answer of an AA */
    protected function minStepSeconds(): int
    {
        return 20;
    }

    /** Asks the AAs from $from on while the budget lasts. The results go into the run at the end of the
     *  step (commitStep()). Should the step die, the next one asks again from the stored position - a test,
     *  or a repair, which the AA runs only where its own test fails (Chain::repair()); a repair done in the
     *  dead step then shows as OK. */
    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        $this->requireSuperadmin();
        $aas     = $this->aas();
        $i       = (int) ($from['i'] ?? 0);
        $next    = (string) ($from['next'] ?? '');
        $stored  = $this->results();
        $results = [];
        $asked   = false;
        while ($i < count($aas)) {
            if ($asked && ($budget->secondsLeft() < self::MIN_SECONDS)) {
                break;
            }
            $aa = $aas[$i];
            try {
                $answer = $this->ask($aa, ($next === 'repair') ? 'repair' : 'test', $budget);
            } catch (BatchBudgetSpent) {
                break;
            }
            $asked = true;
            // a busy AA did not start the repair: asked again from where this step took it up
            $again = (($i === (int) ($from['i'] ?? 0)) ? (string) ($from['next'] ?? '') : '');
            [$result, $next, $retry] = $this->advance($next, $answer, $results[$aa] ?? $stored[$aa] ?? null, $again);
            if ($result !== null) {
                $results[$aa] = $result;
            }
            if ($retry !== '') {
                return new BatchStepResult(self::at($i, (string) $next), false, done: $i, total: count($aas), retry: $retry, extra: ['results' => $results]);
            }
            if ($next === null) {
                $i++;
                $next = '';
            }
        }
        return new BatchStepResult(self::at($i, $next), $i >= count($aas), done: $i, total: count($aas), extra: ['results' => $results]);
    }

    /** What an answer of the AA means at the stage $next.
     *  @param array{kind: string, text: string} $answer
     *  @param ?array{s: string, m: string, r: string, c: string} $repaired the result of the repair it answered
     *  @param string $again the stage a busy repair is asked again from
     *  @return array{?array{s: string, m: string, r: string, c: string}, ?string, string} the result to keep
     *          (null = none), the next stage (null = the AA is done), the retry code ('' = none) */
    private function advance(string $next, array $answer, ?array $repaired, string $again): array
    {
        $kind = $answer['kind'];
        $text = $answer['text'];
        if (($kind === self::AA_BUSY) || ($kind === self::AA_NO_ANSWER)) {
            return match (true) {
                $next !== 'repair'      => [null, $next, $kind],
                $kind === self::AA_BUSY => [null, $again, $kind],
                default                 => [null, 'check', $kind],      // it may be repairing
            };
        }
        if ($kind === self::REFUSED) {
            return match (true) {
                $next === ''       => [self::result(self::REFUSED, $text), null, ''],
                $next === 'repair' => [self::result(self::REFUSED, _m('The repair did not start or its answer is unusable: %1 - run Test to see the state of the AA.', [$text])), null, ''],
                $repaired !== null => [['m' => _m('The test after the repair failed: %1', [$text])] + $repaired, null, ''],
                default            => [self::result(self::REFUSED, _m('The repair was sent, but: %1 - run Test to see what it did.', [$text])), null, ''],
            };
        }
        return match ($next) {
            'repair' => [self::result(($kind === self::OK) ? self::REPAIRED : self::FAILED, '', $text), 'check', ''],
            'check'  => ($kind === self::OK)
                ? [$repaired ?? self::result(self::REPAIRED, ''), null, '']
                : [self::result(self::FAILED, ($repaired === null) ? _m('The repair did not answer in time and the test still fails - it may still work; run Test later.') : '', $repaired['r'] ?? '', $text), null, ''],
            default  => match (true) {
                $this->method() === 'test' => [self::result($kind, '', ($kind === self::OK) ? '' : $text), null, ''],
                $kind === self::OK         => [self::result(self::OK, ''), null, ''],
                default                    => [null, 'repair', ''],
            },
        };
    }

    /** An AA that kept failing: its result says why, the run goes on with the next one - a last AA too,
     *  whose next step only finishes the run (a result of this hook may not finish it). The failing step
     *  asked this AA first, so it brought no other result. */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        $i  = (int) ($last->position['i'] ?? 0);
        $aa = $this->aas()[$i] ?? null;
        if ($aa === null) {
            return null;
        }
        $busy     = ($last->retry === self::AA_BUSY);
        $repaired = $this->results()[$aa] ?? null;
        $result   = match ((string) ($last->position['next'] ?? '')) {
            'repair' => self::result(self::REFUSED, _m('The test fails, but the AA ran another update the whole time - run this again later.')),
            'check'  => ($repaired !== null)
                ? ['m' => _m('The test after the repair got no answer - run Test later.')] + $repaired
                : self::result(self::REFUSED, _m('The repair was sent, but the AA has not answered since - it may still work; run Test later to see the result.')),
            default  => self::result(self::REFUSED, $busy
                ? _m('The AA ran another update the whole time - run this again later.')
                : _m('The AA did not answer in time - it may still work; run Test later to see the result.')),
        };
        return new BatchStepResult(self::at($i + 1), false, done: $i + 1, total: count($this->aas()), extra: ['results' => [$aa => $result]]);
    }

    protected function commitStep(BatchStepResult $r): void
    {
        $new = $r->extra['results'] ?? [];
        if ($new) {
            $this->results = json_encode(array_replace($this->results(), $new), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->update(['results']);
        }
    }

    /** @return array{kind: string, text: string} kind OK, PROBLEM, REFUSED, AA_BUSY or AA_NO_ANSWER
     *  @throws BatchBudgetSpent nothing of the step left */
    private function ask(int $aa, string $method, BatchBudget $budget): array
    {
        $timeout  = max(1, (int) floor($budget->httpTimeout()));
        $response = self::$testRemote
            ? (self::$testRemote)($aa, $this->task(), $method, $timeout)
            : Actionapps::find($aa)?->getResponse(self::request($this->task(), $method), $timeout);
        return ($response === null) ? ['kind' => self::REFUSED, 'text' => _m('No such AA in the Central list any more')] : self::answer($response);
    }

    /** What an AA answered: its report starts 'OK ' or 'Err ' (Chain::run()); a busy AA and one that did not
     *  answer in time are asked again, any other error is final.
     *  @return array{kind: string, text: string} */
    public static function answer(AA_Response $response): array
    {
        if ($response->isError()) {
            $kind = match ($response->getError()) {
                ResponderPolicy::ERR_OPTIMIZE_TYPE_RUNNING => self::AA_BUSY,
                Actionapps::ERR_TIMEOUT                    => self::AA_NO_ANSWER,
                default                                    => self::REFUSED,
            };
            return ['kind' => $kind, 'text' => Actionapps::responseText($response)];
        }
        $report = $response->getResponse();
        return match (true) {
            is_string($report) && str_starts_with($report, 'OK ')  => ['kind' => self::OK, 'text' => substr($report, 3)],
            is_string($report) && str_starts_with($report, 'Err ') => ['kind' => self::PROBLEM, 'text' => substr($report, 4)],
            default => ['kind' => self::REFUSED, 'text' => _m('The AA answered in an older way - update it first')],
        };
    }

    /** @return array{s: string, m: string, r: string, c: string} */
    private static function result(string $status, string $message, string $report = '', string $check = ''): array
    {
        return ['s' => $status, 'm' => $message, 'r' => self::cut($report), 'c' => self::cut($check)];
    }

    private static function cut(string $report): string
    {
        return (strlen($report) > self::MAX_REPORT) ? mb_strcut($report, 0, self::MAX_REPORT) . '<p>' . _m('(the report is cut - see the Optimize page of that AA)') . '</p>' : $report;
    }

    // ---------------------------------------------------------------- the record

    /** @return list<int> central_conf ids */
    public function aas(): array
    {
        return ((string) $this->aas === '') ? [] : array_map('intval', json_decode((string) $this->aas, true, 2, JSON_THROW_ON_ERROR));
    }

    public function task(): string
    {
        return (string) $this->task;
    }

    public function method(): string
    {
        return (string) $this->method;
    }

    public function started(): int
    {
        return (int) $this->started;
    }

    /** the AAs done - an AA tested after its repair has its result already, yet is not done */
    public function done(): int
    {
        return min((int) ($this->position()['i'] ?? 0), count($this->aas()));
    }

    /** the AA asked now and what it is asked ('' the test, 'repair', 'check'); null when none is
     *  @return ?array{int, string} */
    public function current(): ?array
    {
        $position = $this->position();
        $aa       = $this->aas()[(int) ($position['i'] ?? 0)] ?? null;
        return ($aa === null) ? null : [$aa, (string) ($position['next'] ?? '')];
    }

    /** @return array<int, array{s: string, m: string, r: string, c: string}> central_conf id => its result */
    public function results(): array
    {
        return ((string) $this->results === '') ? [] : (array) json_decode((string) $this->results, true, 4, JSON_THROW_ON_ERROR);
    }
}
