<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;

/** The requests of the progress page of a transfer: the state of the run, Continue and End. The queue
 *  moves the items (the steps of InstallRun) - the page only shows them and steers the run. An install
 *  has a page too, for a finish the queue never ran. Each
 *  answer is plain data for JSON; it quotes package text (finding refs, paths), the page escapes it.
 *  The user who started the run and any superadmin see it and may End it; Continue is that user's only -
 *  it goes on with that user's rights, and a new login to the source is that user's. The caller checks
 *  the admin FormToken first. */
final class TransferProgress
{
    // what Continue and End did
    public const RESUMED = 'resumed';
    public const ENDED   = 'ended';
    /** a step may still be working - Continue waits for it */
    public const BUSY = 'busy';
    /** the run has moved on meanwhile (another tab, the queue) - nothing done */
    public const STALE = 'stale';
    /** Continue needs a new login to the source */
    public const LOGIN = 'login';

    /** @return array<string, mixed> of() @throws BatchFailed FORBIDDEN no run $uid may see */
    public static function state(string $id, string $uid, string $status = ''): array
    {
        return self::of(self::visible(InstallRun::get($id), $uid), $uid, $status);
    }

    /** The run as the page shows it. running: a step works now; waiting: the next one waits for its
     *  retry (next_at); stuck: no step has come for long - the queue may not run.
     *  @return array{status: string, state: string, running: bool, waiting: bool, stuck: bool, nextAt: int, failures: int,
     *                moved: int, done: int, total: int, written: array<string, int>, warnings: array<string, array<string, int>>,
     *                error: ?array{code: string, where: string}, findings: list<array{level: string, code: string, path: string, ref: string}>,
     *                afterClose: bool, mayContinue: bool, mayEnd: bool, login: bool}
     *  @throws BatchFailed FORBIDDEN */
    public static function of(InstallRun $run, string $uid, string $status = ''): array
    {
        $now      = InstallRun::clock();
        $progress = $run->progress($uid);
        $open     = in_array($progress['role'], ['active', 'error'], true);
        return [
            'status'      => $status,
            'state'       => $run->state(),
            'running'     => $progress['running'],
            'waiting'     => $run->isWaiting($now),
            'stuck'       => $run->isStuck($now),
            'nextAt'      => $progress['next_at'],
            'failures'    => $progress['failures'],
            'moved'       => $progress['moved'],
            // the run's own sums: the page shows them before the first step too
            'done'        => array_sum($run->written()),
            'total'       => $run->total(),
            'written'     => $run->written(),
            'warnings'    => $run->warnings(),
            'error'       => $progress['error'],
            'findings'    => $run->findings(),
            'afterClose'  => $run->cachesLeft(),
            'mayContinue' => ($uid === $run->user()) && $run->mayResume($now),
            'mayEnd'      => $open,
            'login'       => $open && self::needsLogin($run),
        ];
    }

    /** Continue may not go on without a new login to the source: it refused the token, or the run keeps
     *  none any more (a day passed, AA_SECRET changed) - unless no batch asks the source */
    public static function needsLogin(InstallRun $run): bool
    {
        return $run->slices() && !in_array($run->source(), [InstallRun::LOCAL, InstallRun::STORE], true)
            && ((($run->error()[0] ?? '') === SourceFailed::LOGIN) || ($run->token() === ''));
    }

    /** Continue: the next step now, from the run's position
     *  @param ?string $sealed a new login token of the source (RemoteSource::seal()); null = keep the old one
     *  @return string RESUMED, BUSY, STALE, or LOGIN when the new token is past its time already (the
     *                 clocks of the two AAs differ) @throws BatchFailed FORBIDDEN not the run of $uid */
    public static function resume(string $id, string $uid, #[\SensitiveParameter] ?string $sealed = null): string
    {
        return DB_AA::transaction(static function () use ($id, $uid, $sealed): string {
            $run = self::visible(InstallRun::lockForUpdate($id), $uid);
            if ($run->user() !== $uid) {
                throw new BatchFailed('FORBIDDEN');
            }
            $until = ($sealed === null) ? null : InstallRun::sealedUntil($sealed);
            if (($until !== null) && ($until <= InstallRun::clock())) {
                return self::LOGIN;
            }
            try {
                $run->resume($uid, $sealed, $until);
            } catch (BatchFailed $e) {
                return ($e->reason === 'LEASE') ? self::BUSY : self::STALE;
            }
            return self::RESUMED;
        });
    }

    /** End: the written items stay, the rest is not transferred
     *  @return string ENDED or STALE @throws BatchFailed FORBIDDEN */
    public static function end(string $id, string $uid): string
    {
        return DB_AA::transaction(static function () use ($id, $uid): string {
            $run = self::visible(InstallRun::lockForUpdate($id), $uid);
            try {
                $run->end($uid);
            } catch (BatchFailed $e) {
                if ($e->reason === 'FORBIDDEN') {
                    throw $e;
                }
                return self::STALE;
            }
            return self::ENDED;
        });
    }

    /** @param list<array{string, LintFinding}> $findings @return list<array{level: string, code: string, path: string, ref: string}> */
    public static function plain(array $findings): array
    {
        return array_map(static fn(array $f): array => ['level' => $f[1]->level, 'code' => $f[1]->code, 'path' => $f[0], 'ref' => $f[1]->ref], $findings);
    }

    /** @throws BatchFailed FORBIDDEN - one answer for no run and no right, so the page learns nothing of other runs */
    private static function visible(?InstallRun $run, string $uid): InstallRun
    {
        if (($run === null) || !$run->mayControl($uid)) {
            throw new BatchFailed('FORBIDDEN');
        }
        return $run;
    }
}
