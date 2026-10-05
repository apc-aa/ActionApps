<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\Central\Package\AaAfterCommit;
use AA\Central\Package\AfterCommit;
use AA\Central\Package\CollectOptions;
use AA\Central\Package\DbImportTarget;
use AA\Central\Package\DbSource;
use AA\Central\Package\IdMap;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\Importer;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Reader;
use AA\Central\Package\WriteError;
use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\BatchStates;
use AA\Later\BatchStepResult;
use AA_Property;
use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** One install or transfer of an app on this (the target) AA: what the preview chose, the id map of the
 *  written definitions and how far the item batches got. A BatchRun owned by AA_ID: the queue moves the
 *  items in steps, the progress page only shows them.
 *
 *  preview -> items (definitions written) -> items (batch) ... -> done
 *  preview -> items (closing: no items follow - an install, a transfer of no slice) -> done
 *  items <-> error (Continue);  preview | items | error -> ended (End)
 *
 *  A step reads its batch from the source with no transaction open (fetch()) and writes it in the
 *  transaction that moves the run on (work()): a batch commits with its position or not at all. */
final class InstallRun extends BatchRun
{
    public const PREVIEW = 'preview';
    public const ITEMS   = 'items';
    public const ERROR   = 'error';
    public const DONE    = 'done';
    public const ENDED   = 'ended';

    public const INSTALL  = 'install';
    public const TRANSFER = 'transfer';

    /** the source is this AA; any other source is the id of its central_conf row, or the store */
    public const LOCAL = 'local';
    /** the app store (AppStore::url()) - installs only, it gives nothing else */
    public const STORE = 'store';
    /** the form token of the install page (central/installapp.php) - the app store page posts to it too */
    public const FORM_TOKEN = 'central-installapp';

    // error codes of a step besides the source's (SourceFailed) and the write's (Importer::errorCode())
    /** the user who started the run is no superadmin any more - Continue once the rights are back */
    public const DENIED = 'DENIED';
    /** the source could not read the batch */
    public const COLLECT_FAILED = 'COLLECT_FAILED';
    /** the source gives no batch of the slice: the user may not export it, or it is gone there */
    public const SOURCE_DENIED = 'SOURCE_DENIED';
    /** this AA could not write the batch - the details are in the PHP error log */
    public const WRITE_FAILED = 'WRITE_FAILED';

    /** a preview nobody confirmed is forgotten after a day */
    private const PREVIEW_LIFETIME = 86400;
    /** finished runs of this AA kept for their reports */
    private const KEEP = 100;
    /** S, E and I findings of the batches kept for the progress page - the warnings are counted apart */
    private const KEPT_FINDINGS = 200;
    /** seconds before a failed source is asked again: after a step that still moved on, after the first
     *  and the second failure in a row (the third stops the run) - the user waits at the progress page */
    private const RETRY = [60, 300, 900];
    /** seconds for each answer of the source in the final check - it has no budget of its own */
    private const CHECK_TIMEOUT = 10;

    /** @var ?Closure(self, int): AppSource */
    private static ?Closure $testSources = null;
    private static ?AfterCommit $testAfter = null;

    protected $mode = '';
    /** '1' = items of all bins, '' = active only */
    protected $all_bins = '';
    protected $source = '';
    /** unixtime of the export the preview read - the same selection gives the same XML at the preview,
     *  at the write and in the batches */
    protected $exported = '';
    /** unixtime of the preview on the clock of the source; '' = the source is this AA (exported) */
    protected $source_time = '';
    /** '1' = the source is this installation (its AA_ID) - by this AA or by a Central row naming it: the
     *  copy's items get new ids and the site's domains stay with the source */
    protected $same_aa = '';
    protected $id_map = '';
    /** sha256 of the definitions package - of the preview's until they are written */
    protected $fingerprint = '';
    /** JSON list of the source slice ids, in the order the batches go */
    protected $slices = '';
    /** JSON source slice => slice of this AA it goes into, as the preview chose; the write plans with it */
    protected $targets = '';
    /** JSON of the other choices of the preview (ImportChoices); the write plans with them */
    protected $choices = '';
    /** JSON source slice => ItemFilter text of the preview - unlike the choices kept after the write: the
     *  batches, the progress page and the check read it */
    protected $filters = '';
    /** JSON source slice => its items on the source as the preview counted them (a filter's: those it selects) */
    protected $src_counts = '';
    /** JSON of the last S, E and I findings of the batches */
    protected $findings = '';
    /** items the next batch asks for: halved when the source did not answer in time, doubled when it
     *  answered fast; '' = a full batch */
    protected $batch_items = '';

    protected static function states(): BatchStates
    {
        return new BatchStates(self::ITEMS, self::ERROR, self::DONE, self::ENDED, [self::PREVIEW => [self::ITEMS]]);
    }

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (['mode', 'all_bins', 'source', 'exported', 'source_time', 'same_aa', 'id_map', 'fingerprint', 'slices', 'targets', 'choices', 'filters', 'src_counts', 'findings', 'batch_items'] as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    /** A new run in the preview state; old previews and reports go away on the way. Outside any transaction.
     *  @param string $token  the source's login token as RemoteSource::seal() gives it; '' = none
     *  @param int    $now    unixtime of the export the preview reads
     *  @param string $fingerprint sha256 of the definitions package the preview showed - the write compares it
     *  @param array<string, string> $targets source slice => slice of this AA it goes into
     *  @param array<string, int>    $counts  source slice => its items on the source
     *  @throws BatchFailed NO_TRANSACTION a table the run needs is not InnoDB */
    public static function preview(string $mode, bool $allBins, string $source, string $user, #[\SensitiveParameter] string $token, int $now, string $fingerprint = '', array $targets = [], ?ImportChoices $choices = null, array $counts = []): self
    {
        if (!in_array($mode, [self::INSTALL, self::TRANSFER], true)) {
            throw new InvalidArgumentException("Unknown mode $mode");
        }
        if (!in_array($source, [self::LOCAL, self::STORE], true) && !preg_match('/^[1-9]\d{0,9}\z/', $source)) {
            throw new InvalidArgumentException('The source is "local", "store" or a central_conf id');
        }
        if (($source === self::STORE) && ($mode !== self::INSTALL)) {
            throw new InvalidArgumentException('The app store gives installs only');
        }
        self::cleanup(self::subject(), self::KEEP);
        $props = [
            'subject'     => self::subject(),
            'user'        => $user,
            'mode'        => $mode,
            'all_bins'    => $allBins ? '1' : '',
            'source'      => $source,
            'exported'    => (string) $now,
            'same_aa'     => ($source === self::LOCAL) ? '1' : '',       // another AA says it at the preview (previewed())
            'fingerprint' => $fingerprint,
            'targets'     => self::targetsJson($targets),
            'choices'     => $choices?->toJson() ?? '',
            'filters'     => self::filtersJson($choices),
            'src_counts'  => self::countsJson($counts),
        ];
        return DB_AA::transaction(static function () use ($props, $token): self {
            $run   = self::start($props, self::PREVIEW);
            $until = self::sealedUntil($token);
            if (($token !== '') && ($until > static::now())) {
                $run = self::lockForUpdate($run->getId()) ?? throw new LogicException('The new install run is gone');
                $run->seal($token, $until);
            }
            return $run;
        });
    }

    /** The preview of a run opened at the login to another AA: the definitions package it showed, which
     *  the write compares. The run stays in preview. On an instance of lockForUpdate().
     *  @param bool $sameAa the source reported the AA_ID of this AA (AppSource::sameAa())
     *  @param array<string, string> $targets source slice => slice of this AA it goes into
     *  @param array<string, int>    $counts  source slice => its items on the source */
    public function previewed(string $fingerprint, int $sourceNow, bool $sameAa, array $targets = [], ?ImportChoices $choices = null, array $counts = []): void
    {
        if (!DB_AA::inTransaction() || ($this->state() !== self::PREVIEW)) {
            throw new LogicException('An install run takes its preview only in the preview state, locked');
        }
        $this->fingerprint = $fingerprint;
        $this->source_time = (string) $sourceNow;
        $this->same_aa     = $sameAa ? '1' : '';
        $this->targets     = self::targetsJson($targets);
        $this->choices     = $choices?->toJson() ?? '';
        $this->filters     = self::filtersJson($choices);
        $this->src_counts  = self::countsJson($counts);
        $this->update(['fingerprint', 'source_time', 'same_aa', 'targets', 'choices', 'filters', 'src_counts']);
    }

    /** Call it inside the definitions transaction, on an instance of lockForUpdate(): the run reaches the
     *  items only with its definitions - its first step queued with them. With no items to follow the
     *  step only finishes the run.
     *  @param string   $definitions the definitions package as downloaded (its fingerprint is kept)
     *  @param string[] $slices      source slice ids in the order their items are transferred */
    public function definitionsWritten(IdMap $map, string $definitions, array $slices): void
    {
        $slices = array_values($slices);
        foreach ($slices as $slice) {
            if (!preg_match('/^[0-9a-f]{32}\z/', $slice)) {
                throw new InvalidArgumentException('A slice id is 32 lower-case hex');
            }
        }
        $this->enqueueFrom(self::PREVIEW, self::at($slices[0] ?? '', ''), !$slices);
        $this->choices     = '';          // written: the values typed (keys among them) are in the slices now
        $this->id_map      = $map->toJson();
        $this->fingerprint = hash('sha256', $definitions);
        $this->slices      = json_encode($slices, JSON_THROW_ON_ERROR);
        if (!$slices) {                   // no batch asks the source any more - the token goes now, not with the queue
            $this->sealed       = '';
            $this->sealed_until = '';
        }
        $this->update(['choices', 'id_map', 'fingerprint', 'slices', 'sealed', 'sealed_until']);
    }

    /** till when a token of RemoteSource::seal() is good: the expiry it claims, a day at most
     *  @return int unixtime; not in the future = the token is of no use */
    public static function sealedUntil(#[\SensitiveParameter] string $sealed): int
    {
        $data  = json_decode($sealed, true);
        $exp   = RemoteSource::claims(is_string($data['t'] ?? null) ? $data['t'] : '')['exp'] ?? null;
        $limit = static::now() + RemoteSource::TOKEN_TTL;
        return is_int($exp) ? min($exp, $limit) : $limit;
    }

    /** the position of a batch: the source slice and the last item id written in it ('' = none yet)
     *  @return array{slice: string, cursor: string} */
    public static function at(string $slice, string $cursor): array
    {
        return ['slice' => $slice, 'cursor' => $cursor];
    }

    /** @internal for tests only: the source of a run's steps and what clears the caches after it; null = the real one
     *  @param ?Closure(self, int): AppSource $sources gets the run and the seconds an answer may take */
    public static function setTestDoubles(?Closure $sources, ?AfterCommit $after = null): void
    {
        self::$testSources = $sources;
        self::$testAfter   = $after;
    }

    private static function subject(): string
    {
        return strtolower((string) constant('AA_ID'));
    }

    private static function filtersJson(?ImportChoices $choices): string
    {
        return $choices?->filters ? json_encode($choices->filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    /** @param array<string, string> $targets */
    private static function targetsJson(array $targets): string
    {
        foreach ($targets as $old => $new) {
            if (!preg_match('/^[0-9a-f]{32}\z/', (string) $old) || !preg_match('/^[0-9a-f]{32}\z/', $new)) {
                throw new InvalidArgumentException('A target is a slice id to a slice id, 32 lower-case hex');
            }
        }
        return $targets ? json_encode($targets, JSON_THROW_ON_ERROR) : '';
    }

    /** @param array<string, int> $counts */
    private static function countsJson(array $counts): string
    {
        return $counts ? json_encode(array_map('intval', $counts), JSON_THROW_ON_ERROR) : '';
    }

    // ---------------------------------------------------------------- the steps

    public function selector(): string
    {
        return 'Install_' . $this->getId();
    }

    /** the user who started the run and any superadmin - End is for both, Continue for that user only
     *  (TransferProgress) */
    public function mayControl(string $uid): bool
    {
        return ($uid !== '') && (($uid === $this->user()) || self::isSuperadmin($uid));
    }

    protected function stepInTransaction(): bool
    {
        return true;
    }

    protected function transactionalTables(): array
    {
        return ['item', 'content'];
    }

    protected function retryDelay(int $failures): int
    {
        return self::RETRY[$failures] ?? self::RETRY[count(self::RETRY) - 1];
    }

    /** the write of a full batch after the budget is spent */
    protected function overrun(): int
    {
        return 90;
    }

    protected function budgetLimits(): array
    {
        return ['items' => min(CollectOptions::MAX_ITEMS, max(1, (int) $this->batch_items ?: CollectOptions::MAX_ITEMS)), 'bytes' => CollectOptions::MAX_BATCH_BYTES];
    }

    protected static function sealPurpose(): string
    {
        return 'install-run';
    }

    /** previews a day old; other classes may have a state "preview" too */
    protected static function expireExtra(int $now): void
    {
        $previews = DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'state'], ['value', self::PREVIEW]]);
        if (!$previews) {
            return;
        }
        $props = [];
        foreach (DB_AA::select([], 'SELECT object_id, property, value FROM `object_text`', [['object_id', $previews], ['property', ['aa_type', 'started']]]) as $row) {
            $props[$row['object_id']][$row['property']] = $row['value'];
        }
        $old = array_keys(array_filter($props, static fn(array $p): bool => (($p['aa_type'] ?? '') === self::class) && ((int) ($p['started'] ?? 0) <= $now - self::PREVIEW_LIFETIME)));
        if ($old) {
            self::deleteObjects(array_map('strval', $old));
        }
    }

    /** The batch at $from, read from the source with the rights of the user who started the run - who
     *  must still be a superadmin here. A source that failed is asked again later; one that refused the
     *  login waits for Continue with a new one.
     *  @return array{package: \AA\Central\Package\Model\Package, found: list<array{string, LintFinding}>, items: int}|BatchStepResult */
    protected function fetch(array $from, BatchBudget $budget): mixed
    {
        if (!self::isSuperadmin($this->user())) {
            throw new BatchFailed(self::DENIED, 'user');
        }
        $slice = (string) ($from['slice'] ?? '');
        $left  = $budget->secondsLeft();
        try {
            $source        = $this->sourceFor(max(1, (int) floor($budget->httpTimeout())));
            [$xml, $found] = $source->batch($slice, (string) ($from['cursor'] ?? ''), $this->allBins(), $this->started(), $this->filters(), $budget->items);
            $read          = (new Reader())->read($xml);
        } catch (BatchBudgetSpent|BatchFailed $e) {
            throw $e;
        } catch (ExportRefused $e) {
            $first = $e->errors()[0] ?? null;
            throw $first ? new BatchFailed(Importer::errorCode($first[1]->code), self::where($first[0])) : new BatchFailed(self::SOURCE_DENIED, self::where(self::slicePath($slice)));
        } catch (SourceFailed $e) {
            if ($e->reason === SourceFailed::LOGIN) {
                throw new BatchFailed($e->reason, 'source');
            }
            if ($e->reason === SourceFailed::TIMEOUT) {
                // the batch takes the source longer than a step has: no wait, half the items at once
                return new BatchStepResult($from, false, retry: 'NO_PROGRESS', extra: ['items' => max(1, intdiv($budget->items, 2))]);
            }
            error_log('AA Central: the source of a transfer failed: ' . self::oneLine($e->getMessage()));
            return new BatchStepResult($from, false, retry: $e->reason);
        } catch (Throwable $e) {            // e.g. the source slice was deleted meanwhile
            error_log('AA Central: an item batch could not be read: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            throw new BatchFailed(self::COLLECT_FAILED, self::where(self::slicePath($slice)));
        }
        // a source that took under a quarter of the time gets twice the items next
        $fast = ($left - $budget->secondsLeft()) < ($left / 4);
        return ['package' => $read->package, 'found' => [...$found, ...$read->findings], 'items' => $fast ? min(CollectOptions::MAX_ITEMS, 2 * $budget->items) : $budget->items];
    }

    /** writes the batch fetch() read, in the transaction that moves the run on - a failed write throws,
     *  so neither half the batch nor the position commits */
    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        try {
            $done = (new Importer(new DbImportTarget(), $this->user()))->writeItems($this, $fetched['package'], $from, $fetched['found']);
        } catch (WriteError $e) {
            throw new BatchFailed(Importer::errorCode($e->reason), self::where($e->path));
        } catch (Throwable $e) {            // a DB message names tables and columns: the log, not the page
            error_log('AA Central: an item batch failed: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            throw new BatchFailed(self::WRITE_FAILED, 'batch');
        }
        $slice = (string) $from['slice'];
        return new BatchStepResult(
            $done['position'],
            $done['finished'],
            counts: [$slice => $done['written']],
            warnings: $done['warnings'] ? [$slice => $done['warnings']] : [],
            done: array_sum($this->written()) + $done['written'],
            total: $this->total(),
            extra: ['findings' => array_values(array_filter($done['findings'], static fn(array $f): bool => $f['level'] !== LintFinding::WARNING)), 'items' => $fetched['items']],
        );
    }

    protected function commitStep(BatchStepResult $r): void
    {
        $changed = [];
        $new     = $r->extra['findings'] ?? [];
        if ($new) {
            $this->findings = json_encode(array_slice([...$this->findings(), ...$new], -self::KEPT_FINDINGS), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $changed[]      = 'findings';
        }
        if (isset($r->extra['items']) && ((string) $r->extra['items'] !== (string) $this->batch_items)) {
            $this->batch_items = (string) $r->extra['items'];
            $changed[]         = 'batch_items';
        }
        if ($changed) {
            $this->update($changed);
        }
    }

    /** the check of a finished transfer for its report - with the source while it can still be asked, and
     *  with the rights of the user who started the run only while that user is a superadmin */
    protected function finalCheck(): array
    {
        if (($this->mode() !== self::TRANSFER) || !$this->slices()) {
            return [];
        }
        try {
            $source = self::isSuperadmin($this->user()) ? $this->sourceFor(self::CHECK_TIMEOUT) : null;
        } catch (SourceFailed) {                 // the Central row is gone: the check without the source
            $source = null;
        }
        $check = (new TransferCheck($source))->check($this);
        return ['checked' => time(), 'slices' => $check['slices'], 'findings' => TransferProgress::plain($check['findings'])];
    }

    /** pages of the new modules may have been cached half-filled; an install cleared them with its write */
    protected function afterClose(string $state): void
    {
        if (($this->mode() !== self::TRANSFER) || ((string) $this->id_map === '')) {     // ended in its preview: nothing written
            return;
        }
        $after  = self::$testAfter ?? new AaAfterCommit();
        $failed = 0;
        foreach (array_values($this->idMap()->modules()) as $module) {
            try {
                $after->invalidatePagecache($module);
            } catch (Throwable $e) {
                error_log("AA Central: pagecache[$module] after a transfer failed: " . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
                $failed++;
            }
        }
        $after->constantsChanged();
        if ($failed) {
            throw new RuntimeException("$failed page caches were not cleared");
        }
    }

    /** the source with the rights of the user who started the run: this AA, or the remote one with the
     *  token sealed in the run
     *  @param int $timeout seconds each answer of a remote source may take
     *  @throws SourceFailed no such Central row any more */
    private function sourceFor(int $timeout): AppSource
    {
        if (self::$testSources) {
            return (self::$testSources)($this, $timeout);
        }
        if ($this->source() === self::LOCAL) {
            // a superadmin exports every module - fetch() made sure the user is one
            return new AppExport(new DbSource(), ['url' => (string) constant('AA_INSTAL_URL'), 'aa-version' => aa_version('aa')], static fn(): bool => true, true);
        }
        return $this->remoteSource($timeout);
    }

    /** the AA of the run's central_conf row or the app store, with the token the run keeps sealed - the
     *  store logs in by itself again when that one is too old
     *  @param int $timeout seconds each answer may take @throws SourceFailed no such Central row any more */
    public function remoteSource(int $timeout = RemoteSource::TIMEOUT): RemoteAppSource
    {
        $remote = RemoteSource::forSource($this->source(), $timeout);
        $token  = $remote->unseal($this->token());
        return ($this->source() === self::STORE) ? AppStore::source($remote, $token) : new RemoteAppSource($remote, $token);
    }

    /** read from the perm storage, not by IsSuperadmin(): the step may run in a request of another user */
    private static function isSuperadmin(string $uid): bool
    {
        class_exists(\AA_Perm::class);           // include/perm_core.php sets AA::$perm - a queue step may not have loaded it
        return ($uid !== '') && AA::$perm->isSuperadminUser($uid);
    }

    /** the items on the source as the preview counted them; 0 = unknown - a slice it did not count would
     *  make the sum promise too little */
    public function total(): int
    {
        $counts = $this->sourceCounts();
        return array_diff($this->slices(), array_keys($counts)) ? 0 : array_sum(array_intersect_key($counts, array_flip($this->slices())));
    }

    /** A package path as the place of an error, which keeps only words and a few separators:
     *  slice-module[<id>]/item[<id>]/headline........[0] -> slice-module:<id>/item:<id>/headline........:0
     *  (pathOf() reads it back) */
    public static function where(string $path): string
    {
        return mb_strcut((string) preg_replace(['~\[([^\[\]]*)\]~', '~[^\w .:/#-]+~u'], [':$1', ''], $path), 0, 200);
    }

    /** the package path of where() */
    public static function pathOf(string $where): string
    {
        return (string) preg_replace('~:([^/:]*)~', '[$1]', $where);
    }

    private static function slicePath(string $slice): string
    {
        return Module::SLICE . "[$slice]";
    }

    /** a message for a log line: no control characters (a forged line) */
    private static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }

    // ---------------------------------------------------------------- the record

    /** the source's login token as RemoteSource::seal() gave it; '' when there is none, it is past its
     *  time or cannot be opened (AA_SECRET changed) - the source refuses then, Continue logs in again */
    public function token(): string
    {
        return $this->unseal() ?? '';
    }

    public function mode(): string
    {
        return (string) $this->mode;
    }

    public function allBins(): bool
    {
        return $this->all_bins === '1';
    }

    public function source(): string
    {
        return (string) $this->source;
    }

    public function user(): string
    {
        return (string) $this->user;
    }

    /** unixtime of the export the preview read - the definitions, the batches and the check name it */
    public function started(): int
    {
        return (int) ((string) $this->exported ?: (string) $this->started);
    }

    /** the source is this installation, whatever the transport - see $same_aa */
    public function sameAa(): bool
    {
        return $this->same_aa === '1';
    }

    /** the start of the run on the clock of the source - items it edited after it may have missed their batch */
    public function sourceStarted(): int
    {
        return ((string) $this->source_time === '') ? $this->started() : (int) $this->source_time;
    }

    /** unixtime of the last progress; the start before any */
    public function moved(): int
    {
        return (int) ((string) $this->moved ?: (string) $this->started);
    }

    public function idMap(): IdMap
    {
        return IdMap::fromJson((string) $this->id_map);
    }

    public function fingerprint(): string
    {
        return (string) $this->fingerprint;
    }

    /** the write reads the source again; the same package from what now is (or is not) this AA would
     *  keep (or renew) other item ids than the preview checked; a write form of an older preview names
     *  other targets than the run keeps
     *  @param array<string, string> $targets the targets the write form sends */
    public function matchesPreview(string $definitions, bool $sameAa, array $targets): bool
    {
        return hash_equals($this->fingerprint(), hash('sha256', $definitions)) && ($this->sameAa() === $sameAa) && $this->chose($targets);
    }

    /** the run keeps these targets - a write form of an older preview chose others
     *  @param array<string, string> $targets */
    public function chose(array $targets): bool
    {
        $mine = $this->targets();
        ksort($mine);
        ksort($targets);
        return $mine === $targets;
    }

    /** The targets a form sends (into[<source slice>] = slice of this AA): pairs of long ids, lower case;
     *  '' - a new slice - and anything else left out
     *  @param array<mixed, mixed> $posted @return array<string, string> */
    public static function targetsFrom(array $posted): array
    {
        $targets = [];
        foreach ($posted as $old => $new) {
            if (preg_match('/^[0-9a-f]{32}\z/i', (string) $old) && is_string($new) && preg_match('/^[0-9a-f]{32}\z/i', $new)) {
                $targets[strtolower((string) $old)] = strtolower($new);
            }
        }
        return $targets;
    }

    /** the form chose the targets - the preview is sent again, "a new slice" ('') is a choice too; the first
     *  preview posts none @param array<mixed, mixed> $posted into[] */
    public static function choiceMade(array $posted): bool
    {
        return $posted !== [];
    }

    /** @return list<string> */
    public function slices(): array
    {
        return ((string) $this->slices === '') ? [] : array_map('strval', json_decode((string) $this->slices, true, 2, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, int> slice => items written */
    public function written(): array
    {
        return array_filter(self::decoded((string) $this->counts), 'is_int');
    }

    /** @return array<string, array<string, int>> slice => finding code => warnings of its batches; slice '' =
     *          no slice (a step that died left something undone) */
    public function warnings(): array
    {
        $warnings = [];
        foreach (self::decoded((string) $this->warnings) as $key => $value) {
            if (is_array($value)) {
                $warnings[(string) $key] = $value + ($warnings[(string) $key] ?? []);
            } else {
                $warnings[''][(string) $key] = (int) $value;
            }
        }
        return $warnings;
    }

    /** @return array<string, int> source slice => its items on the source as the preview counted them */
    public function sourceCounts(): array
    {
        return array_map('intval', self::decoded((string) $this->src_counts));
    }

    /** @return list<array{level: string, code: string, path: string, ref: string}> the last S, E and I findings of the batches */
    public function findings(): array
    {
        return array_values(self::decoded((string) $this->findings));
    }

    /** the choices of the preview besides the targets */
    public function choices(): ImportChoices
    {
        return ImportChoices::fromJson((string) $this->choices);
    }

    /** @return array<string, string> source slice => ItemFilter text; [] = all the items of every slice */
    public function filters(): array
    {
        return array_map('strval', self::decoded((string) $this->filters));
    }

    /** what a write form of this preview sends back: a form of an older preview of the run has another */
    public function choicesSum(): string
    {
        return hash('sha256', (string) $this->choices);
    }

    /** @return array<string, string> source slice => slice of this AA it goes into */
    public function targets(): array
    {
        $targets = self::decoded((string) $this->targets);
        return array_map('strval', array_combine(array_map('strval', array_keys($targets)), $targets));
    }

    /** the caches of the new modules were not cleared at the end (afterClose()) */
    public function cachesLeft(): bool
    {
        return (self::decoded((string) $this->progress)['warning'] ?? '') === 'after_close';
    }

    /** @return array<string, mixed> the final check's report; [] = none */
    public function report(): array
    {
        return self::decoded((string) $this->report);
    }

    /** @return array{string, string}|null [code, where] of the error the run stopped at - where() of a package path */
    public function error(): ?array
    {
        return ((string) $this->error_code === '') ? null : [(string) $this->error_code, (string) $this->error_path];
    }

    /** @return array<mixed> */
    private static function decoded(string $json): array
    {
        return ($json === '') ? [] : (array) json_decode($json, true, BatchStepResult::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
    }
}
