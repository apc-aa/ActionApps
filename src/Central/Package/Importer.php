<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** The import writer (design balicek-v1.md § 7.2 steps 2, 5-9; C4.4 parts 4, 5): an importable ImportPlan =>
 *  the app on this AA, or nothing.
 *
 *  Before anything else - no statement: the user may install (ImportAuthority, PS_INSTALL_APP -
 *  WriteError::DENIED; what the authority throws is a no), and the S findings of the plan are confirmed by
 *  the token of the preview (ImportConfirmation - WriteError::UNCONFIRMED; a plan without S needs none).
 *  Then the engines again (the plan is no reservation): a table not InnoDB = WriteError::ENGINE.
 *  Then inside ONE DB_AA::transaction() - every statement on its handle (getDB() refuses a second one); no
 *  transaction started = WriteError::DB before any write:
 *  1. the re-check: every group name and collection id of the plan still free (ImportTarget) - the plan
 *     is no reservation; taken = WriteError::TAKEN before any write (plan again);
 *  2. the rows - RowWriter (part 4a);
 *  3. the objects - ObjectWriter, then the items (only a package with items) - ItemWriter (part 4c), with
 *     the map RowWriter returns (the view and e-mail numbers). Both write the rows AA_Object::save() and
 *     storeItem() would leave, through RowStore - not those routines (die() on a refused statement, a
 *     DELETE before save(), storeItem()'s inserters, events, feeding and caches; see there), so nothing
 *     inside the transaction asks for a second handle.
 *  Any failure: DB_AA::transaction() rolls back and rethrows - ImportFailed with the cause; the rollback is
 *  the whole cleanup, so every table the import writes must be InnoDB (Planner::tables(); the planner's E
 *  Planner::NO_TRANSACTION in the preview, § 7.2 step 4, and the check above).
 *  A PHP fatal (or exit()) inside the write leaves its transaction open: PDO rolls it back when the request
 *  ends (FatalLogger then logs to error_log(), its `log` row would be rolled back with it).
 *  After the commit: ImportFinish (§ 7.2 step 8) - the log entry, the ACL objects, ConstantsChanged(), the
 *  page cache; none of them may run inside the transaction (§ 7.2 step 7), and none is undone: a failure
 *  there is a row of the report (ImportReport::$finish, § 7.2 step 9).
 *
 *  write() inside DB_AA::transaction() is a LogicException: its transaction would join the caller's - no
 *  commit of its own, a rollback of the caller's work, ImportFinish before the commit.
 *
 *  Two imports at once: see DbImportTarget::groupTaken() (the locking read before each group); collection
 *  ids are only re-read (alerts_collection.id is the PRIMARY key - a duplicate INSERT fails). */
final class Importer
{
    private readonly AA_Metabase $metabase;
    private readonly Schema $schema;
    private readonly ImportAuthority $authority;
    private readonly ImportConfirmation $confirmation;
    private readonly ImportFinish $finish;

    /**
     * @param ImportTarget     $target   the same target the plan asked (DbImportTarget)
     * @param string           $user     the importing user - module.created_by, slice.created_by
     * @param AA_Metabase|null $metabase the AA schema; null = AA::Metabase()
     * @param int|null         $now      module.created_at, alerts_collection_howoften.last; null = time()
     * @param ImportAuthority|null    $authority    null = PermImportAuthority (PS_INSTALL_APP) - another one for tests only
     * @param ImportConfirmation|null $confirmation null = keyed by the session (ImportConfirmation) - another key for tests only
     * @param AfterCommit|null        $afterCommit  null = AaAfterCommit - another one for tests only
     */
    public function __construct(
        private readonly ImportTarget $target,
        private readonly string $user,
        ?AA_Metabase $metabase = null,
        private readonly ?int $now = null,
        ?ImportAuthority $authority = null,
        ?ImportConfirmation $confirmation = null,
        ?AfterCommit $afterCommit = null,
    ) {
        $this->metabase     = $metabase ?? AA::Metabase();
        $this->schema       = new Schema($this->metabase);
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
     *  @return ImportReport the report of the written import - its maps with the view, e-mail and filter
     *                      numbers AUTO_INCREMENT gave
     *  @throws ImportFailed nothing was written: refused before the transaction (WriteError::DENIED, UNCONFIRMED,
     *                       ENGINE), or rolled back */
    public function write(ImportPlan $plan, ?string $confirmation = null): ImportReport
    {
        if (!$plan->importable()) {
            throw new InvalidArgumentException('The import plan has errors - it is not written');
        }
        if (DB_AA::inTransaction()) {
            throw new LogicException('Importer::write() inside DB_AA::transaction() - the import needs a transaction of its own');
        }
        $this->admit($plan, $confirmation);
        $not = $this->target->nonTransactional(Planner::tables($this->schema, $plan->package));
        if ($not) {
            throw new ImportFailed(new WriteError(WriteError::ENGINE, 'import', Planner::noTransaction($not)));
        }
        $store   = new RowStore($this->metabase, $this->schema);
        $rows    = new RowWriter($store, $this->schema, $this->target, $this->user, $this->now ?? time());
        $objects = new ObjectWriter($store);
        $items   = new ItemWriter($store, $this->metabase, $this->user);
        try {
            $map = DB_AA::transaction(function () use ($plan, $rows, $objects, $items): IdMap {
                if (!DB_AA::inTransaction()) {          // DB_AA::transaction() runs the block without one when it cannot start it
                    throw new WriteError(WriteError::DB, 'import', 'no database transaction could be started');
                }
                $this->recheck($plan);
                $map = $rows->write($plan);
                $objects->write($plan, $map);
                $items->write($plan, $map);
                return $map;
            });
        } catch (Throwable $e) {
            throw new ImportFailed($e);
        }
        $finish = $this->finish->run($plan, $this->user);     // committed: nothing of it is undone
        return ImportReport::of($plan, $map, $store->written(), $finish);
    }

    /** the user may install and confirmed the S findings - or ImportFailed before any statement */
    private function admit(ImportPlan $plan, ?string $confirmation): void
    {
        try {
            $may = $this->authority->mayInstall($this->user);
        } catch (Throwable $e) {                    // its message may tell about the perm system: the log, not the UI
            error_log('AA Central: the install permission could not be checked: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            throw new ImportFailed(new WriteError(WriteError::DENIED, 'import', 'the install permission could not be checked'));
        }
        if (!$may) {
            throw new ImportFailed(new WriteError(WriteError::DENIED, 'import', 'the user may not install app packages (PS_INSTALL_APP)'));
        }
        try {
            $confirmed = $this->confirmation->confirms($plan, $this->user, $confirmation);
        } catch (Throwable) {                       // no session: nothing can be confirmed
            $confirmed = false;
        }
        if (!$confirmed) {
            $n = count($plan->ofLevel(LintFinding::SECURITY));
            throw new ImportFailed(new WriteError(WriteError::UNCONFIRMED, 'import', "the $n security findings (S) of the package were not confirmed in the preview"
                . (($confirmation === null) ? '' : ' - the confirmation is of another package, findings, user or session')));
        }
    }

    /** a message for a log line: no control characters (a forged line) */
    private static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }

    /** every group name and collection id of the plan is still free on the target @throws WriteError */
    private function recheck(ImportPlan $plan): void
    {
        foreach ($plan->map->groups() as $old => $new) {
            if ($this->target->groupTaken($new)) {
                throw new WriteError(WriteError::TAKEN, "constant-group[$old]", "the constant group $new was created since the plan");
            }
        }
        foreach ($plan->map->collections() as $old => $new) {
            if ($this->target->collectionTaken($new)) {
                throw new WriteError(WriteError::TAKEN, "alerts_collection[$old]", "the alerts collection $new was created since the plan");
            }
        }
    }
}
