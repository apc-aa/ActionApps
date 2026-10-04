<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\AfterCommit;
use AA\Central\Package\BatchResult;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Reader;
use AA\Central\Package\WriteError;
use AA\IO\DB\DB_AA;
use Closure;
use Throwable;

/** The requests of the progress page of a transfer: the next item batch, Continue, End - each answers
 *  with the state of the run as plain data for JSON. The run record is the only state: every request
 *  names the position it expects, so a double click or a second tab finds the run moved on (stale) and
 *  does nothing. A batch travels as a package XML - read by Reader like a package from elsewhere, whether
 *  this AA made it (AppExport) or another one (RemoteAppSource). When the last slice is finished it compares source and
 *  target (TransferCheck), clears the caches of the new modules and finishes the run with that report.
 *  Answers quote package text (finding refs, paths) - the page escapes them. The caller checks the admin
 *  FormToken and PS_INSTALL_APP first. */
final class TransferProgress
{
    /** statuses beside BatchResult's */
    public const DONE    = 'done';
    public const RESUMED = 'resumed';
    public const ENDED   = 'ended';

    /** the error code of a batch the source could not read */
    public const COLLECT_FAILED = 'COLLECT_FAILED';
    /** the source gives no batch of the slice: the user may not export it, or it is gone there */
    public const SOURCE_DENIED = 'SOURCE_DENIED';

    /** @var Closure(): AppSource */
    private readonly Closure $from;

    /**
     * @param AppSource|(Closure(): AppSource) $from the source of the run, with the rights of its user there;
     *        a closure is called only by the steps that ask the source, so End works with the source gone
     * @param string $user the logged-in user - only the run's user drives it
     */
    public function __construct(
        private readonly Importer $importer,
        AppSource|Closure $from,
        private readonly AfterCommit $after,
        private readonly string $user,
    ) {
        $this->from = ($from instanceof AppSource) ? static fn(): AppSource => $from : $from;
    }

    /** Reads the batch at $expected from the source and writes it.
     *  @param array{string, string} $expected [source slice, cursor] the page saw last
     *  @return array<string, mixed> state()
     *  @throws ImportFailed not the user's transfer run, or the user may not install */
    public function next(string $id, array $expected): array
    {
        $run = $this->own($id);
        if (!$run->at($expected)) {
            return self::state($run, BatchResult::STALE);
        }
        $path = Module::SLICE . "[$expected[0]]";
        try {
            [$xml, $found] = ($this->from)()->batch($expected[0], $expected[1], $run->allBins(), $run->started(), $run->filters());
            $read          = (new Reader())->read($xml);
        } catch (ExportRefused $e) {
            $first = $e->errors()[0] ?? null;
            return $first ? $this->fail($id, $expected, Importer::errorCode($first[1]->code), $first[0], $e->findings) : $this->fail($id, $expected, self::SOURCE_DENIED, $path);
        } catch (SourceFailed $e) {              // SOURCE_LOGIN: Continue logs in again; else Continue retries
            error_log('AA Central: the source of a transfer failed: ' . self::oneLine($e->getMessage()));
            return $this->fail($id, $expected, $e->reason, 'source');
        } catch (Throwable $e) {            // e.g. the source slice was deleted meanwhile
            error_log('AA Central: an item batch could not be read: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            return $this->fail($id, $expected, self::COLLECT_FAILED, $path);
        }
        $result = $this->importer->writeBatch($id, $read->package, $expected, [...$found, ...$read->findings]);
        $batch  = $read->package->batch;
        $slices = $run->slices();
        if (($result->status === BatchResult::WRITTEN) && $batch?->finished && ($expected[0] === end($slices))) {
            return $this->finish($id, [$expected[0], $batch->next], $result->findings);
        }
        return self::state(self::reread($id), $result->status, $result->findings);
    }

    /** Continue after an error - from the position of the run
     *  @param string $token a new login token of the source (its old one was refused); '' = keep it
     *  @return array<string, mixed> state() @throws ImportFailed */
    public function resume(string $id, #[\SensitiveParameter] string $token = ''): array
    {
        $this->own($id);
        $moved = DB_AA::transaction(static function () use ($id, $token): bool {
            $run = InstallRun::lockForUpdate($id);
            if ($run?->state() !== InstallRun::ERROR) {
                return false;
            }
            $run->resume($token);
            return true;
        });
        return self::state(self::reread($id), $moved ? self::RESUMED : BatchResult::STALE);
    }

    /** End: the written items stay, the rest is not transferred
     *  @return array<string, mixed> state() @throws ImportFailed */
    public function end(string $id): array
    {
        $run   = $this->own($id);
        $moved = DB_AA::transaction(static function () use ($id): bool {
            $run = InstallRun::lockForUpdate($id);
            if (!in_array($run?->state(), InstallRun::UNFINISHED, true)) {
                return false;
            }
            $run->end();
            return true;
        });
        $cleanup = $moved ? $this->cleanup($run) : [];         // pages of the new site may have been cached half-filled
        return self::state(self::reread($id), $moved ? self::ENDED : BatchResult::STALE, [], $cleanup);
    }

    /** The run as the page shows it. Findings are package text.
     *  @param list<array{string, LintFinding}> $findings of this request
     *  @param list<array{string, string}>      $cleanup  steps after the finish that failed: [step, error]
     *  @return array{status: string, state: string, position: array{slice: string, cursor: string}, written: array<string, int>,
     *                warnings: array<string, array<string, int>>, error: array{code: string, path: string}|null,
     *                findings: list<array{level: string, code: string, path: string, ref: string}>, cleanup: list<array{string, string}>} */
    public static function state(InstallRun $run, string $status, array $findings = [], array $cleanup = []): array
    {
        [$slice, $cursor] = $run->position();
        $error            = $run->error();
        return [
            'status'   => $status,
            'state'    => $run->state(),
            'position' => ['slice' => $slice, 'cursor' => $cursor],
            'written'  => $run->written(),
            'warnings' => $run->warnings(),
            'error'    => (($run->state() === InstallRun::ERROR) && $error) ? ['code' => $error[0], 'path' => $error[1]] : null,
            'findings' => self::plain($findings),
            'cleanup'  => $cleanup,
        ];
    }

    /** The last slice is written up to $at: the check for the report, the caches of the new modules, then
     *  the run is finished with the report. The run goes last: when the request dies before, the next one
     *  finds it at its end and does all again.
     *  @param array{string, string} $at the position after the last batch @param list<array{string, LintFinding}> $findings
     *  @return array<string, mixed> */
    private function finish(string $id, array $at, array $findings): array
    {
        $run     = self::reread($id);
        try {
            $source = ($this->from)();
        } catch (SourceFailed) {                 // the Central row is gone: the check without the source
            $source = null;
        }
        $check   = (new TransferCheck($source))->check($run);
        $report  = ['checked' => time(), 'slices' => $check['slices'], 'findings' => self::plain($check['findings'])];
        $cleanup = $this->cleanup($run);
        $done    = DB_AA::transaction(static function () use ($id, $at, $report): bool {
            $run = InstallRun::lockForUpdate($id);
            if (!$run?->at($at)) {
                return false;               // another request finished it
            }
            $run->finish($report);
            return true;
        });
        return self::state(self::reread($id), $done ? self::DONE : BatchResult::STALE, $findings, $cleanup);
    }

    /** @param list<array{string, LintFinding}> $findings @return list<array{level: string, code: string, path: string, ref: string}> */
    public static function plain(array $findings): array
    {
        return array_map(static fn(array $f): array => ['level' => $f[1]->level, 'code' => $f[1]->code, 'path' => $f[0], 'ref' => $f[1]->ref], $findings);
    }

    /** a batch that never reached the importer puts the run in error at its position, as a failed write
     *  @param array{string, string} $expected @param list<array{string, LintFinding}> $findings of the source
     *  @return array<string, mixed> */
    private function fail(string $id, array $expected, string $code, string $path, array $findings = []): array
    {
        DB_AA::transaction(static function () use ($id, $expected, $code, $path): void {
            $run = InstallRun::lockForUpdate($id);
            if ($run?->at($expected)) {
                $run->fail($code, $path);
            }
        });
        return self::state(self::reread($id), BatchResult::FAILED, $findings);
    }

    /** the page cache of every new module and the constants - each step on its own
     *  @return list<array{string, string}> the failed steps: [step, error] */
    private function cleanup(InstallRun $run): array
    {
        $steps = array_map(fn(string $module): array => ["pagecache[$module]", fn() => $this->after->invalidatePagecache($module)], array_values($run->idMap()->modules()));
        $steps[] = ['constants', fn() => $this->after->constantsChanged()];
        $failed  = [];
        foreach ($steps as [$step, $fn]) {
            try {
                $fn();
            } catch (Throwable $e) {
                error_log("AA Central: $step after a transfer failed: " . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
                $failed[] = [$step, get_class($e)];
            }
        }
        return $failed;
    }

    /** the run, when it is a transfer of this user @throws ImportFailed */
    private function own(string $id): InstallRun
    {
        $run = InstallRun::get($id);
        if (($run === null) || ($run->mode() !== InstallRun::TRANSFER) || ($run->user() !== $this->user) || ($this->user === '')) {
            throw new ImportFailed(new WriteError(WriteError::DENIED, 'run', 'no transfer run of this user'));
        }
        return $run;
    }

    private static function reread(string $id): InstallRun
    {
        return InstallRun::get($id) ?? throw new \LogicException("The install run $id is gone");
    }

    /** a message for a log line: no control characters (a forged line) */
    private static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }
}
