<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use AA_Perm;
use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Writes an importable ImportPlan to this AA - all of it or nothing.
 *
 *  Before any statement: PS_INSTALL_APP, the S findings confirmed (ImportConfirmation), every table InnoDB
 *  (the rollback is the only cleanup). Then one DB_AA::transaction(); the group names and collection ids are
 *  checked again, as the plan reserves nothing. A PHP fatal inside leaves the transaction open - PDO rolls it
 *  back when the request ends. Inside the caller's transaction write() would have no commit of its own, so
 *  it refuses.
 *  Two imports at once: DbImportTarget::groupTaken() locks; a duplicate collection id fails its PRIMARY key. */
final class Importer
{
    private readonly AA_Metabase $metabase;
    private readonly Schema $schema;
    /** @var Closure(string): bool */
    private readonly Closure $mayInstall;
    private readonly ImportConfirmation $confirmation;
    private readonly AfterCommit $aa;

    /**
     * @param ImportTarget     $target   the same target the plan asked (DbImportTarget)
     * @param string           $user     the importing user - module.created_by, slice.created_by
     * @param AA_Metabase|null $metabase the AA schema; null = AA::Metabase()
     * @param int|null         $now      module.created_at, alerts_collection_howoften.last; null = time()
     * @param (Closure(string): bool)|null $mayInstall   may the user install; throwing = no. null = hasInstallPermission() - another one for tests only
     * @param ImportConfirmation|null      $confirmation null = keyed by the session (ImportConfirmation) - another key for tests only
     * @param AfterCommit|null             $afterCommit  null = AaAfterCommit - another one for tests only
     */
    public function __construct(
        private readonly ImportTarget $target,
        private readonly string $user,
        ?AA_Metabase $metabase = null,
        private readonly ?int $now = null,
        ?Closure $mayInstall = null,
        ?ImportConfirmation $confirmation = null,
        ?AfterCommit $afterCommit = null,
    ) {
        $this->metabase     = $metabase ?? AA::Metabase();
        $this->schema       = new Schema($this->metabase);
        $this->mayInstall   = $mayInstall ?? self::hasInstallPermission(...);
        $this->confirmation = $confirmation ?? new ImportConfirmation();
        $this->aa           = $afterCommit ?? new AaAfterCommit();
    }

    /** PS_INSTALL_APP on the `aa` object (part of the SUPER role). Not IfSlPerm(): a module's perms must not
     *  decide an installation. Only the logged-in user: CheckPerms() caches the perms it asks about in the
     *  session, so asking about another user would put their perms into this session. */
    public static function hasInstallPermission(string $user): bool
    {
        class_exists(AA_Perm::class);           // loads PS_INSTALL_APP and CheckPerms()
        return ($user !== '') && ($user === \AuthGetUid()) && \CheckPerms($user, 'aa', constant('AA_ID'), \PS_INSTALL_APP);
    }

    /** The caller checks the admin FormToken first (CSRF) - neither the right nor the confirmation token
     *  protects against a forged request of the user's browser.
     *  @param string|null $confirmation ImportConfirmation::token(), passed once the user confirmed the S findings
     *  @throws InvalidArgumentException the plan has an E
     *  @throws LogicException inside DB_AA::transaction()
     *  @return ImportReport with the view, e-mail and filter numbers AUTO_INCREMENT gave
     *  @throws ImportFailed nothing was written: refused, or rolled back */
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
        return ImportReport::of($plan, $map, $store->written(), $this->finish($plan));
    }

    /** the user may install and confirmed the S findings - or ImportFailed before any statement */
    private function admit(ImportPlan $plan, ?string $confirmation): void
    {
        try {
            $may = ($this->mayInstall)($this->user);
        } catch (Throwable $e) {                    // its message may tell about the perm system: the log, not the UI
            error_log('AA Central: the install permission could not be checked: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            throw new ImportFailed(new WriteError(WriteError::DENIED, 'import', 'the install permission could not be checked'));
        }
        if ($may !== true) {
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

    /** The steps after the commit - none can be undone. A failing step does not stop the next ones. The log
     *  entry goes first, so a request dying here still leaves a trace.
     *  @return list<array{string, list<string>}> [step, errors] */
    private function finish(ImportPlan $plan): array
    {
        $steps   = [];
        $step    = static function (string $name, Closure $fn) use (&$steps): void {
            try {
                $fn();
                $steps[] = [$name, []];
            } catch (Throwable $e) {
                $steps[] = [$name, [get_class($e) . ': ' . $e->getMessage()]];
            }
        };
        $modules = array_values($plan->map->modules());
        $step('log', fn() => $this->aa->logInstall($plan->package, $modules));
        foreach ($modules as $id) {
            $step("permissions[$id]", fn() => $this->aa->permissions($id, $this->user));
        }
        if ($plan->map->groups()) {
            $step('constants', fn() => $this->aa->constantsChanged());
        }
        foreach ($modules as $id) {
            $step("pagecache[$id]", fn() => $this->aa->invalidatePagecache($id));
        }
        return $steps;
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
