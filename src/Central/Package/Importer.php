<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** The import writer (design balicek-v1.md § 7.2 steps 2, 5-9; C4.4 parts 4, 5): an importable ImportPlan =>
 *  the app on this AA, or nothing (as far as the compensation reaches).
 *
 *  Before anything else - no statement, no lock (ImportFailed with an empty journal): the user may install
 *  (ImportAuthority, PS_INSTALL_APP - WriteError::DENIED; what the authority throws is a no), and the S
 *  findings of the plan are confirmed by the token of the preview (ImportConfirmation - WriteError::UNCONFIRMED;
 *  a plan without S needs none).
 *  Under the named lock (ImportTarget::lock(), GET_LOCK - one import at a time: two imports claiming one
 *  free group name would deadlock on InnoDB and merge on MyISAM; held by another = WriteError::LOCKED, not
 *  asked (no connection, GET_LOCK() NULL) = WriteError::DB, both before any statement), inside ONE
 *  DB_AA::transaction() - every statement on its handle (getDB() refuses a second one):
 *  1. the re-check: every group name and collection id of the plan still free (ImportTarget) - the plan
 *     is no reservation; taken = WriteError::TAKEN before any write (plan again);
 *  2. the rows - RowWriter (part 4a);
 *  3. the objects - ObjectWriter, then the items (only a package with items) - ItemWriter (part 4c), with
 *     the map RowWriter returns (the view and e-mail numbers). Both write the rows AA_Object::save() and
 *     storeItem() would leave, through RowStore - not those routines (die() on a refused statement, a
 *     DELETE before save(), storeItem()'s inserters, events, feeding and caches; see there), so nothing
 *     inside the transaction asks for a second handle.
 *  Any failure: DB_AA::transaction() rolls back and rethrows; then the Compensation from the journal -
 *  always, the rollback does nothing to MyISAM tables (§ 13.5) - still under the lock, with the user abort
 *  ignored and the time limit raised to at least 60 s (0 = none, CLI: left), the shutdown handler still armed
 *  (the compensation is idempotent - a request dying in it is compensated again), and ImportFailed with the
 *  journal, the cause and the compensation's report (a compensation that throws is a row of it).
 *  After the commit and the release of the lock: ImportFinish (§ 7.2 step 8) - the log entry, the ACL objects,
 *  ConstantsChanged(), the page cache; none of them may run inside the transaction (§ 7.2 step 7), and none
 *  is compensated: a failure there is a row of the report (ImportResult::$report, § 7.2 step 9).
 *
 *  A request that dies inside write() (a fatal error, the time limit, exit() in legacy code, the client
 *  gone) never reaches the catch: a shutdown handler (DB_AA::atShutdown()), armed for the duration of
 *  write(), rolls the open transaction back (DB_AA::abandonTransaction(); a persistent connection whose
 *  rollback failed may keep it open - the report then says the cleanup is not reliable), runs the compensation, releases
 *  the lock and logs the report with error_log() - package text there with its control characters replaced
 *  (no forged log lines). It runs whenever the request ends inside write() - not only on an error_get_last()
 *  fatal: exit() leaves none. PHP cannot unregister it; write() disarms it on the way out. Nothing it does
 *  throws out of it (error_log() instead). AA\Util\FatalLogger (AA_LOG_PHP_FATALS) was registered earlier
 *  (locsess.php), so its shutdown function runs first - with the transaction still open: its PHP_FATAL row
 *  of the `log` table goes to the transaction's handle and the rollback here removes it again (or, the
 *  handle checked out, it falls back to error_log()); the importer's own error_log() line keeps the message.
 *
 *  The engine check (§ 7.2 step 4) is the planner's W Planner::NO_TRANSACTION in the preview.
 *  write() inside DB_AA::transaction() is a LogicException: its transaction would join the caller's - no
 *  commit of its own, a rollback of the caller's work, ImportFinish before the commit.
 *
 *  Two imports at once: the lock. Besides, RowWriter reads each group name again with SELECT ... FOR
 *  UPDATE right before it creates the group (InnoDB: a concurrent insert of the name waits for the commit),
 *  for writers that do not take the lock (the group admin). Collection ids are only re-read (5 random
 *  characters; alerts_collection.id is the PRIMARY key - a duplicate INSERT fails). */
final class Importer
{
    /** the name of the import lock - server-wide in MySQL: the database name keeps AAs on one server apart */
    public const LOCK = 'aa_central_import';

    private readonly AA_Metabase $metabase;
    private readonly Schema $schema;
    private readonly string $lock;
    private readonly ImportAuthority $authority;
    private readonly ImportConfirmation $confirmation;
    private readonly ImportFinish $finish;
    /** @var array{ImportPlan, ImportJournal}|null the write in progress - what the shutdown handler compensates */
    private ?array $running = null;
    /** @var null|Closure(): ?CompensationReport the registered shutdown function (DB_AA::atShutdown()), once per Importer */
    private ?Closure $shutdown = null;

    /**
     * @param ImportTarget     $target   the same target the plan asked (DbImportTarget)
     * @param string           $user     the importing user - module.created_by, slice.created_by
     * @param AA_Metabase|null $metabase the AA schema; null = AA::Metabase()
     * @param int|null         $now      module.created_at, alerts_collection_howoften.last; null = time()
     * @param string|null      $lock     the name of the import lock; null = LOCK . '.' . DB_NAME (MySQL: 64 characters)
     * @param int              $wait     seconds to wait for the lock
     * @param ImportAuthority|null    $authority    null = PermImportAuthority (PS_INSTALL_APP) - another one for tests only
     * @param ImportConfirmation|null $confirmation null = keyed by the session (ImportConfirmation) - another key for tests only
     * @param AfterCommit|null        $afterCommit  null = AaAfterCommit - another one for tests only
     */
    public function __construct(
        private readonly ImportTarget $target,
        private readonly string $user,
        ?AA_Metabase $metabase = null,
        private readonly ?int $now = null,
        ?string $lock = null,
        private readonly int $wait = 3,
        ?ImportAuthority $authority = null,
        ?ImportConfirmation $confirmation = null,
        ?AfterCommit $afterCommit = null,
    ) {
        $this->metabase     = $metabase ?? AA::Metabase();
        $this->schema       = new Schema($this->metabase);
        $this->lock         = $lock ?? substr(self::LOCK . '.' . constant('DB_NAME'), 0, 64);
        $this->authority    = $authority ?? new PermImportAuthority();
        $this->confirmation = $confirmation ?? new ImportConfirmation();
        $this->finish       = new ImportFinish($afterCommit ?? new AaAfterCommit());
    }

    /** The caller checks the admin FormToken of the posting form first (CSRF) - neither the right nor the
     *  confirmation token protects against a forged request of the user's browser.
     *  @param string|null $confirmation the token of ImportConfirmation::token() for this plan and user - the
     *                                  preview's (this session), passed only once the user confirmed the S
     *                                  findings; null = none
     *  @throws InvalidArgumentException the plan has an E - it is never written
     *  @throws LogicException inside DB_AA::transaction()
     *  @throws ImportFailed nothing was written (WriteError::DENIED, UNCONFIRMED, LOCKED, DB at `import`), or it was
     *                       rolled back and compensated - ImportFailed::$cleanup lists what is left for a manual action */
    public function write(ImportPlan $plan, ?string $confirmation = null): ImportResult
    {
        if (!$plan->importable()) {
            throw new InvalidArgumentException('The import plan has errors - it is not written');
        }
        if (DB_AA::inTransaction()) {
            throw new LogicException('Importer::write() inside DB_AA::transaction() - the import needs a transaction of its own');
        }
        $journal = new ImportJournal();
        $this->admit($plan, $confirmation, $journal);
        $store   = new RowStore($this->metabase, $this->schema);
        $rows    = new RowWriter($store, $this->schema, $this->target, $this->user, $this->now ?? time());
        $objects = new ObjectWriter($store);
        $items   = new ItemWriter($store, $this->metabase, $this->user);
        try {
            $locked = $this->target->lock($this->lock, $this->wait);
        } catch (Throwable $e) {
            throw new ImportFailed($journal, new WriteError(WriteError::DB, 'import', 'the import lock could not be asked for: ' . $e->getMessage()));
        }
        if (!$locked) {
            throw new ImportFailed($journal, new WriteError(WriteError::LOCKED, 'import', "another import holds the lock $this->lock"));
        }
        try {
            $this->arm($plan, $journal);
            $map = DB_AA::transaction(function () use ($plan, $journal, $rows, $objects, $items): IdMap {
                $this->recheck($plan, $journal);
                $map = $rows->write($plan, $journal);
                $objects->write($plan, $map, $journal);
                $items->write($plan, $map, $journal);
                return $map;
            });
        } catch (Throwable $e) {
            self::keepAlive();
            $cleanup       = self::compensate($plan, $journal);       // still armed: a request dying in it is compensated again
            $this->running = null;
            throw new ImportFailed($journal, $e, $cleanup);
        } finally {
            $this->running = null;
            $this->target->unlock($this->lock);
        }
        $finish = $this->finish->run($plan, $journal, $this->user);     // committed: nothing of it is compensated
        return new ImportResult($map, $journal, ImportReport::of($plan, $map, $journal, $finish, $this->schema->rowTables()));
    }

    /** the user may install and confirmed the S findings - or ImportFailed before any statement and the lock */
    private function admit(ImportPlan $plan, ?string $confirmation, ImportJournal $journal): void
    {
        try {
            $may = $this->authority->mayInstall($this->user);
        } catch (Throwable $e) {                    // its message may tell about the perm system: the log, not the UI
            error_log('AA Central: the install permission could not be checked: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            throw new ImportFailed($journal, new WriteError(WriteError::DENIED, 'import', 'the install permission could not be checked'));
        }
        if (!$may) {
            throw new ImportFailed($journal, new WriteError(WriteError::DENIED, 'import', 'the user may not install app packages (PS_INSTALL_APP)'));
        }
        try {
            $confirmed = $this->confirmation->confirms($plan, $this->user, $confirmation);
        } catch (Throwable) {                       // no session: nothing can be confirmed
            $confirmed = false;
        }
        if (!$confirmed) {
            $n = count($plan->ofLevel(LintFinding::SECURITY));
            throw new ImportFailed($journal, new WriteError(WriteError::UNCONFIRMED, 'import', "the $n security findings (S) of the package were not confirmed in the preview"
                . (($confirmation === null) ? '' : ' - the confirmation is of another package, findings, user or session')));
        }
    }

    /** The registered shutdown function - null before the first write(). Tests call it to play a request
     *  that ends inside write(); it returns the compensation's report (null: nothing was running). */
    public function shutdownHandler(): ?Closure
    {
        return $this->shutdown;
    }

    /** the write in progress for the shutdown handler, registered once per Importer */
    private function arm(ImportPlan $plan, ImportJournal $journal): void
    {
        $this->running    = [$plan, $journal];
        $this->shutdown ??= DB_AA::atShutdown(function (): ?CompensationReport {
            try {
                return $this->died();
            } catch (Throwable $t) {
                error_log('AA Central: the cleanup of a dead import failed: ' . self::oneLine(get_class($t) . ': ' . $t->getMessage()));
                return null;
            }
        });
    }

    /** the request ended inside write(): roll the open transaction back, compensate, release the lock, log */
    private function died(): ?CompensationReport
    {
        if ($this->running === null) {
            return null;
        }
        [$plan, $journal] = $this->running;
        self::keepAlive();                      // the client may be gone, the time limit may be what ended the request
        $last = error_get_last();
        try {
            $reliable = DB_AA::abandonTransaction();
            $report   = self::compensate($plan, $journal);
            if (!$reliable) {
                $report->add('transaction', ['cleanup not reliable: the transaction could not be rolled back - check manually']);
            }
        } finally {
            $this->running = null;
            $this->target->unlock($this->lock);
        }
        error_log('AA Central: the import died at ' . self::oneLine($journal->lastStep() . ($last ? " ({$last['message']})" : ''))
            . ' - compensated' . ($report->clean() ? '' : '; left for a manual action: ' . $report->summary()));
        return $report;
    }

    /** the compensation; what it throws (it should not) is a row of its report */
    private static function compensate(ImportPlan $plan, ImportJournal $journal): CompensationReport
    {
        try {
            return (new Compensation($plan))->run($journal);
        } catch (Throwable $e) {
            $report = new CompensationReport();
            $report->add('compensation', [get_class($e) . ': ' . $e->getMessage()]);
            return $report;
        }
    }

    /** the cleanup runs to its end: the user abort ignored, the time limit at least 60 s (0 = none: left) */
    private static function keepAlive(): void
    {
        ignore_user_abort(true);
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            set_time_limit(max(60, $limit));
        }
    }

    /** package text for a log line: no control characters (a forged line) */
    private static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }

    /** every group name and collection id of the plan is still free on the target @throws WriteError */
    private function recheck(ImportPlan $plan, ImportJournal $journal): void
    {
        foreach ($plan->map->groups() as $old => $new) {
            $journal->step("constant-group[$old]");
            if ($this->target->groupTaken($new)) {
                throw new WriteError(WriteError::TAKEN, "constant-group[$old]", "the constant group $new was created since the plan");
            }
        }
        foreach ($plan->map->collections() as $old => $new) {
            $journal->step("alerts_collection[$old]");
            if ($this->target->collectionTaken($new)) {
                throw new WriteError(WriteError::TAKEN, "alerts_collection[$old]", "the alerts collection $new was created since the plan");
            }
        }
    }
}
