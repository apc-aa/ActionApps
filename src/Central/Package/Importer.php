<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use InvalidArgumentException;
use Throwable;

/** The import writer (design balicek-v1.md § 7.2 steps 5-8; C4.4 part 4): an importable ImportPlan =>
 *  the app on this AA, or nothing (as far as the compensation reaches).
 *
 *  Inside ONE DB_AA::transaction() - every statement on its handle (getDB() refuses a second one):
 *  1. the re-check: every group name and collection id of the plan still free (ImportTarget) - the plan
 *     is no reservation; taken = WriteError::TAKEN before any write (plan again);
 *  2. the rows - RowWriter (part 4a);
 *  3. objects through the AA_Object API and items through storeItem() - part 4c, here, with the map
 *     RowWriter returns.
 *  Any failure: DB_AA::transaction() rolls back and rethrows; then (part 4b) the compensation from the
 *  journal - always, the rollback does nothing to MyISAM tables (§ 13.5) - and ImportFailed with the
 *  journal and the cause. After the commit (part 4d): the importer's rights, ConstantsChanged(), the
 *  pagecache - none of them may run inside the transaction (§ 7.2 step 7).
 *
 *  Not here: rights (PS_INSTALL_APP) and the S confirmation (part 5, the caller), the engine check and
 *  its preview warning (§ 7.2 step 4), the report (part 5).
 *
 *  Two imports at once: RowWriter reads each group name again with SELECT ... FOR UPDATE right before it
 *  creates the group; on InnoDB that keeps a concurrent insert of the name out until the commit. On MyISAM
 *  tables nothing is locked (and nothing rolled back): two imports may still create one name - part 4b puts
 *  a GET_LOCK around write(). Collection ids are only re-read (5 random characters; alerts_collection.id is
 *  the PRIMARY key - a duplicate INSERT fails).
 *
 *  For part 4b: AA_Module_Alerts::_deleteModules() was suspected to pass the rows of DB_AA::select('', ..) as
 *  collection ids; select('') returns the first column of each row, so it looks right - verify it with a
 *  test before the compensation relies on it (the journal has the collection ids anyway). */
final class Importer
{
    private readonly AA_Metabase $metabase;
    private readonly Schema $schema;

    /**
     * @param ImportTarget     $target   the same target the plan asked (DbImportTarget)
     * @param string           $user     the importing user - module.created_by, slice.created_by
     * @param AA_Metabase|null $metabase the AA schema; null = AA::Metabase()
     * @param int|null         $now      module.created_at, alerts_collection_howoften.last; null = time()
     */
    public function __construct(
        private readonly ImportTarget $target,
        private readonly string $user,
        ?AA_Metabase $metabase = null,
        private readonly ?int $now = null,
    ) {
        $this->metabase = $metabase ?? AA::Metabase();
        $this->schema   = new Schema($this->metabase);
    }

    /** @throws InvalidArgumentException the plan has an E - it is never written
     *  @throws ImportFailed nothing (or, on MyISAM until part 4b, the journal) was written */
    public function write(ImportPlan $plan): ImportResult
    {
        if (!$plan->importable()) {
            throw new InvalidArgumentException('The import plan has errors - it is not written');
        }
        $journal = new ImportJournal();
        $rows    = new RowWriter(new RowStore($this->metabase, $this->schema), $this->schema, $this->target, $this->user, $this->now ?? time());
        try {
            $map = DB_AA::transaction(function () use ($plan, $journal, $rows): IdMap {
                $this->recheck($plan, $journal);
                // part 4c: objects (AA_Object API, § 5.4) and items (storeItem()) after the rows, with their map
                return $rows->write($plan, $journal);
            });
        } catch (Throwable $e) {
            // part 4b: compensation from $journal by the module types of the package, idempotent, also after
            // a successful rollback (MyISAM); what it could not delete goes to the report
            throw new ImportFailed($journal, $e);
        }
        // part 4d: after the commit - the importer's roles (AddPerm...), ConstantsChanged(), AA::Pagecache()->invalidateFor()
        return new ImportResult($map, $journal);
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
