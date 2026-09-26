<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\Central\InstallRun;
use AA\Central\Package\Model\Batch;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use AA_Perm;
use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Writes an importable ImportPlan to this AA for an install run - all of it or nothing; in a transfer
 *  then the items, one batch per call (writeBatch()), each all or nothing.
 *
 *  Before any statement: PS_INSTALL_APP, the S findings confirmed (ImportConfirmation), every table InnoDB
 *  (the rollback is the only cleanup). Then one DB_AA::transaction(); the group names and collection ids are
 *  checked again, as the plan reserves nothing. A PHP fatal inside leaves the transaction open - PDO rolls it
 *  back when the request ends. Inside the caller's transaction write() would have no commit of its own, so
 *  it refuses.
 *  Two imports at once: DbImportTarget::groupTaken() locks; a duplicate collection id fails its PRIMARY key.
 *  Two writes of one run: the locking read of the run (InstallRun::lockForUpdate()) makes the second wait
 *  and find the run moved on.
 *
 *  Item ids: another AA keeps them; a copy in the same AA derives them from the run (IdMap::item()), as the
 *  source items stay. There the ids a text names are looked up in the item table by their source id - one
 *  SELECT per write - and only items of the transferred slices get the derived id. */
final class Importer
{
    /** the tables a batch writes - the run's too */
    private const BATCH_TABLES = ['item', 'content', 'object_text'];

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

    /** The definitions of $run - the run moves to its items (or is done, when none follow) in the same
     *  transaction, so it never has items without definitions, nor definitions written twice. A transfer brings the state of the source
     *  installation (owner, reading password, domains), an install never has it (the Validator's E).
     *  The caller checks the admin FormToken first (CSRF) - neither the right nor the confirmation token
     *  protects against a forged request of the user's browser.
     *  @param string      $run          the InstallRun in its preview
     *  @param string      $definitions  the definitions package as downloaded - the run keeps its fingerprint
     *  @param string|null $confirmation ImportConfirmation::token(), passed once the user confirmed the S findings
     *  @throws InvalidArgumentException the plan has an E, or items - they come in batches
     *  @throws LogicException inside DB_AA::transaction()
     *  @return ImportReport with the view, e-mail and filter numbers AUTO_INCREMENT gave
     *  @throws ImportFailed nothing was written: refused, or rolled back */
    public function write(ImportPlan $plan, string $run, string $definitions, ?string $confirmation = null): ImportReport
    {
        if (!$plan->importable()) {
            throw new InvalidArgumentException('The import plan has errors - it is not written');
        }
        if (array_filter($plan->package->modules, static fn(Module $m): bool => $m->items !== [])) {
            throw new InvalidArgumentException('Items come in batches (writeBatch()), never with the definitions');
        }
        if (DB_AA::inTransaction()) {
            throw new LogicException('Importer::write() inside DB_AA::transaction() - the import needs a transaction of its own');
        }
        $this->admit($plan, $confirmation);
        $record   = InstallRun::get($run);                  // mode, source and user never change - the state is checked locked below
        if (($record !== null) && ($record->user() !== $this->user)) {
            throw new ImportFailed(new WriteError(WriteError::DENIED, 'import', 'the install run is another user\'s'));
        }
        $transfer = ($record?->mode() === InstallRun::TRANSFER);
        if (!$transfer && array_filter($plan->package->modules, static fn(Module $m): bool => $m->transfer !== [])) {
            throw new LogicException("The state of the source installation in an install (the Validator's E)");
        }
        if ($transfer && $record->sameAa()) {
            $plan = self::withoutDomains($plan);            // they are the source site's - still in use here
        }
        $not = $this->target->nonTransactional(Planner::tables($this->schema, $transfer));
        if ($not) {
            throw new ImportFailed(new WriteError(WriteError::ENGINE, 'import', Planner::noTransaction($not)));
        }
        $store   = new RowStore($this->metabase, $this->schema);
        $rows    = new RowWriter($store, $this->schema, $this->target, $this->user, $this->now ?? time());
        $objects = new ObjectWriter($store);
        try {
            $map = DB_AA::transaction(function () use ($plan, $run, $definitions, $transfer, $rows, $objects): IdMap {
                if (!DB_AA::inTransaction()) {          // DB_AA::transaction() runs the block without one when it cannot start it
                    throw new WriteError(WriteError::DB, 'import', 'no database transaction could be started');
                }
                $record = InstallRun::lockForUpdate($run);
                if ($record?->state() !== InstallRun::PREVIEW) {
                    throw new WriteError(WriteError::RUN, 'import', 'the install run is not in its preview - written already, ended or forgotten');
                }
                $this->recheck($plan);
                $slices = $transfer ? self::slices($plan->package) : [];
                $map    = $plan->map;
                if ($transfer && $record->sameAa()) {             // the source items are here: new ids
                    $map = $map->withItemKey($run);
                    $map = $map->withPackageItems(self::packageItems(self::definitionIds($plan->package), $slices));
                }
                $map = $rows->write($plan, $map);
                $objects->write($plan, $map);
                $record->definitionsWritten($map, $definitions, $slices);
                return $map;
            });
        } catch (Throwable $e) {
            throw new ImportFailed($e);
        }
        return ImportReport::of($plan, $map, $store->written(), $this->finish($plan));
    }

    /** One batch of the items of a transfer (Collector::itemBatch()) in one transaction, written only when
     *  the run is at $expected - the position the batch was read from. A failure rolls the batch back and
     *  puts the run in error at its position (a transaction of its own): Continue sends the batch again.
     *  The caller checks the admin FormToken first (CSRF).
     *  @param array{string, string}           $expected [source slice, cursor]
     *  @param list<array{string, LintFinding}> $source   what the source found making the batch (Collector, Writer,
     *                                                   Reader): its W are counted in the run, an E stops the batch
     *  @throws ImportFailed the user may not install - the run stays as it is
     *  @throws LogicException inside DB_AA::transaction() */
    public function writeBatch(string $run, Package $batch, array $expected, array $source = []): BatchResult
    {
        if (DB_AA::inTransaction()) {
            throw new LogicException('Importer::writeBatch() inside DB_AA::transaction() - a batch needs a transaction of its own');
        }
        $this->permitted();
        if (InstallRun::get($run)?->user() !== $this->user) {
            throw new ImportFailed(new WriteError(WriteError::DENIED, 'batch', 'the install run is another user\'s'));
        }
        $findings = new FindingList();
        foreach ($source as [$path, $finding]) {
            $findings->add($path, $finding);
        }
        try {
            return DB_AA::transaction(fn(): BatchResult => $this->batch($run, $batch, $expected, $findings));
        } catch (Throwable $e) {
            $error = self::failure($e);
            try {
                DB_AA::transaction(static function () use ($run, $expected, $error): void {
                    $record = InstallRun::lockForUpdate($run);
                    if ($record?->at($expected)) {                  // another request may have moved it on meanwhile
                        $record->fail(...$error);
                    }
                });
            } catch (Throwable $e) {
                error_log('AA Central: a failed item batch could not be marked in its run: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            }
            return new BatchResult(BatchResult::FAILED, $findings->all(), $error);
        }
    }

    /** writeBatch() inside its transaction @param array{string, string} $expected
     *  @throws WriteError|RewriteFailed|LogicException */
    private function batch(string $run, Package $batch, array $expected, FindingList $findings): BatchResult
    {
        if (!DB_AA::inTransaction()) {
            throw new WriteError(WriteError::DB, 'batch', 'no database transaction could be started');
        }
        $record = InstallRun::lockForUpdate($run) ?? throw new LogicException("No install run $run");
        if (!$record->at($expected)) {
            return new BatchResult(BatchResult::STALE);
        }
        if ($record->mode() !== InstallRun::TRANSFER) {
            throw new LogicException('Items belong to a transfer run');
        }
        $not = $this->target->nonTransactional(self::BATCH_TABLES);
        if ($not) {
            throw new WriteError(WriteError::ENGINE, 'batch', Planner::noTransaction($not));
        }
        if ($batch->batch === null) {
            throw new WriteError(Validator::BATCH, 'meta/batch', 'no item batch');
        }
        foreach ((new Validator($this->metabase))->validate($batch, true)->findings as [$path, $finding]) {
            $findings->add($path, $finding);
        }
        self::stopAtError($findings);
        $m = $batch->modules[0];
        if (($m->id !== $expected[0]) || ($batch->batch->after !== $expected[1])) {
            throw new WriteError(Validator::BATCH, 'meta/batch', 'the batch was read from another position than the run is at');
        }
        if (!$m->items && !$batch->batch->finished) {           // the source stops the slice at an item over its batch limit
            throw new WriteError(Collector::ITEM_TOO_BIG, "$m->type[$m->id]/item", 'the next item of the slice is over the batch limit');
        }
        $map   = $record->idMap();
        $slice = TargetSlice::read($map->module($m->id) ?? throw new LogicException("The run does not map slice $m->id"))
            ?? throw new WriteError(WriteError::GONE, "$m->type[$m->id]", 'the slice the definitions wrote is not there any more');
        if ($map->itemKey() !== '') {
            $map = $map->withPackageItems(self::packageItems(self::batchIds($m), $record->slices()));
        }
        (new TargetCharset($this->schema, $map))->check($batch, [$m->id => $slice->charset], [], $findings);
        (new ItemLint($this->target, $this->metabase))->check($m, $slice, $map, $findings);
        self::stopAtError($findings);
        (new ItemWriter(new RowStore($this->metabase, $this->schema), $this->metabase))->write($m, $slice, $map);
        $record->advance($expected, self::next($record, $m->id, $batch->batch), count($m->items), $findings->counts(LintFinding::WARNING));
        return new BatchResult(BatchResult::WRITTEN, $findings->all());
    }

    /** the first E stops the batch @throws WriteError */
    private static function stopAtError(FindingList $findings): void
    {
        foreach ($findings->all() as [$path, $finding]) {
            if ($finding->level === LintFinding::ERROR) {
                throw new WriteError($finding->code, $path, "the batch has an error ($finding->code)");
            }
        }
    }

    /** The position after a written batch: on in its slice, or the next slice once it is finished. The
     *  last slice stays at its end - a batch from there is empty and finished.
     *  @return array{string, string} */
    private static function next(InstallRun $record, string $slice, Batch $batch): array
    {
        $slices = $record->slices();
        $after  = $slices[(int) array_search($slice, $slices, true) + 1] ?? null;
        return ($batch->finished && ($after !== null)) ? [$after, ''] : [$slice, $batch->next];
    }

    /** [code, path] of a failed batch for the run: a WriteError's reason and path; anything else goes to the
     *  log only - a DB message names tables and columns @return array{string, string} */
    private static function failure(Throwable $e): array
    {
        if ($e instanceof WriteError) {
            return [self::errorCode($e->reason), $e->path];
        }
        error_log('AA Central: an item batch failed: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
        return ['WRITE_FAILED', 'batch'];
    }

    /** a finding code as the error code of a run (InstallRun::fail()): item-too-big -> ITEM_TOO_BIG */
    public static function errorCode(string $reason): string
    {
        return strtoupper(strtr($reason, '-', '_'));
    }

    /** the source slices whose items follow, in package order @return list<string> */
    private static function slices(Package $package): array
    {
        return array_values(array_map(static fn(Module $m): string => strtolower($m->id), array_filter($package->modules, static fn(Module $m): bool => $m->type === Module::SLICE)));
    }

    /** those of $ids that are items of $slices - read where the source keeps them: in a copy in the same
     *  AA that is this DB @param list<string> $ids @param list<string> $slices @return list<string> */
    private static function packageItems(array $ids, array $slices): array
    {
        $ids = array_values(array_unique($ids));
        if (!$ids || !$slices) {
            return [];
        }
        $of = DB_AA::select(['id' => 'slice_id'], 'SELECT LOWER(HEX(`id`)) AS id, LOWER(HEX(`slice_id`)) AS slice_id FROM `item`', [['id', $ids, 'l']]);
        return array_map('strval', array_keys(array_intersect($of, $slices)));
    }

    /** the long ids in the texts of the definitions @return list<string> */
    private static function definitionIds(Package $package): array
    {
        $data = array_map(static fn(Module $m): array => [$m->rows, $m->settings, $m->objects, $m->spotTree, $m->transfer['setting-values'] ?? []], $package->modules);
        foreach ($package->constantGroups as $group) {
            $data[] = $group->rows;
        }
        $ids = [];
        array_walk_recursive($data, static function (mixed $value) use (&$ids): void {
            if (is_string($value) || ($value instanceof RawBytes)) {
                $ids[] = ReferenceRewriter::longIdsIn(ValueCodec::bytes($value));
            }
        });
        return array_merge(...$ids);
    }

    /** the item ids of a batch and the long ids in its values @return list<string> */
    private static function batchIds(Module $m): array
    {
        $ids = [];
        foreach ($m->items as $item) {
            $ids[] = [strtolower(ValueCodec::bytes($item['id'] ?? ''))];
            foreach ((array) ($item['content'] ?? []) as $values) {
                foreach ((array) $values as $v) {
                    $ids[] = ReferenceRewriter::longIdsIn(ValueCodec::bytes(((array) $v)['value'] ?? ''));
                }
            }
        }
        return array_merge(...$ids);
    }

    /** the user may install and confirmed the S findings - or ImportFailed before any statement */
    private function admit(ImportPlan $plan, ?string $confirmation): void
    {
        $this->permitted();
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

    /** the user may install - or ImportFailed before any statement */
    private function permitted(): void
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
        foreach (array_diff_key($plan->map->modules(), $plan->existing) as $id) {      // an existing slice keeps its rights
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

    /** the plan without the site's domains, a W for each - a copy in the same AA @return ImportPlan */
    private static function withoutDomains(ImportPlan $plan): ImportPlan
    {
        $modules = $dropped = [];
        foreach ($plan->package->modules as $m) {
            foreach ($m->transfer['alternate-url'] ?? [] as $i => $url) {
                $dropped[] = ["$m->type[$m->id]/alternate-url[$i]", new LintFinding(LintFinding::WARNING, Planner::DOMAIN_DROPPED, ValueCodec::bytes($url), 0)];
            }
            $modules[] = isset($m->transfer['alternate-url'])
                ? new Module($m->type, $m->id, $m->rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, array_diff_key($m->transfer, ['alternate-url' => true]))
                : $m;
        }
        $findings = array_values(array_filter($plan->findings, static fn(array $f): bool => $f[1]->code !== Planner::DOMAIN_KEPT));
        return new ImportPlan(...array_merge(get_object_vars($plan), [
            'package'  => new Package(...array_merge(get_object_vars($plan->package), ['modules' => $modules])),
            'findings' => [...$findings, ...$dropped],
        ]));
    }

    /** every group name, collection id and site domain of the plan is still free on the target, every
     *  existing slice still there in its charset, locked and editable by the user, every group its fields
     *  name still there @throws WriteError */
    private function recheck(ImportPlan $plan): void
    {
        foreach ($plan->existing as $old => $slice) {
            if ($this->target->existingSlice($slice->id, true)?->charset !== $slice->charset) {
                throw new WriteError(WriteError::GONE, Module::SLICE . "[$old]", 'the slice it goes into is gone or has another language since the plan');
            }
            if (!$this->target->mayEditSlice($slice->id)) {
                throw new WriteError(WriteError::DENIED, Module::SLICE . "[$old]", 'the user may not edit the slice it goes into any more');
            }
        }
        $planner = new Planner($this->target, $this->metabase);
        foreach ($plan->package->modules as $m) {
            foreach ($m->transfer['alternate-url'] ?? [] as $i => $url) {
                if ($planner->domainTaken(Planner::domainHost(ValueCodec::bytes($url)) ?? '')) {
                    throw new WriteError(WriteError::TAKEN, "$m->type[$m->id]/alternate-url[$i]", 'a site of this AA answers to the domain since the plan');
                }
            }
        }
        $written = array_map(static fn($g): string => $g->name, $plan->package->constantGroups);
        foreach (array_diff_key($plan->map->groups(), array_flip($written)) as $old => $new) {     // mapped to a group an existing slice names
            if (!$this->target->groupTaken($new, true)) {
                throw new WriteError(WriteError::GONE, "constant-group[$old]", "the constant group $new is gone since the plan");
            }
        }
        foreach ($plan->package->constantGroups as $group) {
            $new = $plan->map->group($group->name);
            if (($new !== null) && $this->target->groupTaken($new)) {
                throw new WriteError(WriteError::TAKEN, "constant-group[$group->name]", "the constant group $new was created since the plan");
            }
        }
        foreach ($plan->map->collections() as $old => $new) {
            if ($this->target->collectionTaken($new)) {
                throw new WriteError(WriteError::TAKEN, "alerts_collection[$old]", "the alerts collection $new was created since the plan");
            }
        }
    }
}
