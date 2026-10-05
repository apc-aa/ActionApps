<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\DB\DB_AA;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA_Log;
use JsonException;
use Throwable;

/**
 * The one way to start, continue and end an import run, and to change what runs are made of - the
 * source, its settings, the mapping. Starts and changes of a definition take the lock of its rows,
 * so they happen one after another: a double click, or a plan and a click at once, get one run.
 * Starting only queues the run; its steps run in the queue, never in the request.
 */
final class Service
{
    /** finished runs a definition keeps for its page */
    private const KEEP_RUNS = 20;

    /** source items a dry run reads unless told */
    private const DRY_LIMIT = 500;

    private const MODES = ['run', 'changed', 'check', 'baseline'];

    private const TRIGGER = '/^(manual|(plan|event):[0-9a-f]{32})\z/';

    /** characters of a setting whose property sets no maximum */
    private const MAX_SETTING = 1000;

    /** what adopt() may spend on the network - it runs in the request of the page */
    private const ADOPT_SECONDS = 20;

    /**
     * Queues a run of the definition $d of the slice $d belongs to, with the definition as stored now.
     * A manual start checks the user's right to run imports of the slice and to the slices its
     * settings name; a planned one keeps the right of whoever set them. A planned start ends a run in
     * error or hanging (blocking()) in the transaction that starts the new one, which names it.
     * @param array{dry?: bool, streams?: list<string>, limit?: int} $opt  streams = some of the source's
     * @param string $mode    run | changed | check | baseline - a baseline by hand only, by whoever may
     *                        manage the definition, of a Probeable source (else NO_VERSIONS): it records
     *                        versions no run fetched; logged with its user
     * @param string $trigger manual | plan:<task id> | event:<task id> - set by the caller, never from the request
     */
    public static function start(Definition $d, string $mode, array $opt, string $trigger, string $user): StartResult
    {
        if (preg_match(self::TRIGGER, $trigger) !== 1) {
            throw new \InvalidArgumentException('An import starts manual, plan:<task id> or event:<task id>');
        }
        if (!in_array($mode, self::MODES, true) || (($mode === 'baseline') && ($trigger !== 'manual'))) {
            return new StartResult('invalid', reason: 'MODE');
        }
        $options = self::options($mode, $opt);
        if ($options === null) {
            return new StartResult('invalid', reason: 'OPTIONS');
        }
        $id     = (string) $d->getId();
        $slice  = strtolower((string) $d->getOwnerId());
        $manual = ($trigger === 'manual');
        if (!self::isOf(Definition::fresh($id), $slice)) {
            return new StartResult('unknown');
        }
        if (!$manual && !self::isTaskOf($trigger, $slice)) {
            return new StartResult('invalid', reason: 'TRIGGER');
        }
        // the right to run imports of the slice, as for Continue and End
        if ($manual && !Definition::mayImportInto($user, $slice)) {
            return new StartResult('invalid', reason: 'FORBIDDEN');
        }
        try {
            BatchRun::requireTransactional([]);
        } catch (BatchFailed $e) {
            return new StartResult('invalid', reason: $e->reason);
        }
        // it refuses to run inside a transaction
        Run::cleanup($id, self::KEEP_RUNS);

        return DB_AA::transaction(static function () use ($id, $slice, $mode, $options, $trigger, $manual, $user): StartResult {
            $def = Definition::fresh($id, true);
            if (!self::isOf($def, $slice)) {
                return new StartResult('unknown');
            }
            if (!$manual && !$def->isEnabled()) {
                return new StartResult('off');
            }
            $refused = ($mode === 'baseline') ? $def->manageRefusal($user) : null;
            if ($refused !== null) {
                return new StartResult('invalid', reason: $refused);
            }
            $toEnd = [];
            $busy  = self::busy($id, $trigger, $toEnd);
            if ($busy !== null) {
                return $busy;
            }
            $snapshot = $def->snapshot();
            try {
                $settings = Definition::settingsOf($snapshot);
                $problems = Definition::mappingOf($snapshot)->problems($slice);
                $json     = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (SourceRefused|JsonException) {
                return new StartResult('invalid', reason: 'DEFINITION');
            }
            if ($problems) {
                return new StartResult('invalid', reason: 'DEFINITION');
            }
            if ($manual) {
                $settings = self::checkSettings($id, $def->sourceType(), $settings, $user);
                if (is_string($settings)) {
                    return new StartResult('invalid', reason: $settings);
                }
            }
            try {
                $source = Registry::create($def->sourceType(), $settings);
                // a source without versions has no state to record: a baseline would only drop versions
                if (($mode === 'baseline') && !($source instanceof Probeable)) {
                    return new StartResult('invalid', reason: 'NO_VERSIONS');
                }
                $keys = array_map('strval', array_keys(Run::streamsOf($source)));
            } catch (Throwable $e) {
                // a planned start has no page to show its refusal
                return new StartResult('invalid', reason: self::sourceFailure($id, $e, !$manual));
            }
            if ($options['streams'] !== null) {
                if (array_diff($options['streams'], $keys)) {
                    return new StartResult('invalid', reason: 'STREAMS');
                }
                $keys = array_values(array_intersect($keys, $options['streams']));
            }
            // the last moment: a start refused above must leave the old run as it was
            $busy = self::endInTheWay($toEnd, $trigger, $user);
            if ($busy !== null) {
                return $busy;
            }
            $run = Run::start([
                'subject'     => $id,
                'user'        => $user,
                'position'    => RunCursor::START,
                'mode'        => $mode,
                'dry'         => $options['dry'] ? '1' : '',
                'limit'       => $options['limit'],
                'snapshot'    => $json,
                'stream_keys' => json_encode($keys, JSON_THROW_ON_ERROR),
                'trigger'     => $trigger,
                'replaced'    => $toEnd ? json_encode($toEnd, JSON_THROW_ON_ERROR) : '',
            ]);
            if ($mode === 'baseline') {
                // the run is cleaned away with the older ones; the log keeps who recorded versions nobody imported
                AA_Log::write(Definition::BASELINE_EVENT, 'Import_' . $id, "#$id " . $run->getId() . " $user");
            }
            return new StartResult('queued', (string) $run->getId());
        });
    }

    /** Continue: a run in error goes on from its position, one waiting for a retry, hanging or whose
     *  step died tries now (BatchRun::mayResume()).
     *  A run the user may not control is unknown, as one that is not there. The caller checks the run
     *  belongs to a definition of its page's slice. */
    public static function resume(string $runId, string $user): StartResult
    {
        return DB_AA::transaction(static function () use ($runId, $user): StartResult {
            $run = Run::lockForUpdate($runId);
            if (($run === null) || !$run->mayControl($user)) {
                return new StartResult('unknown');
            }
            // whoever may start it - a baseline of a source with keys is a superadmin's
            if ($run->mode() === 'baseline') {
                $def     = Definition::fresh($run->definitionId());
                $refused = ($def === null) ? 'FORBIDDEN' : $def->manageRefusal($user);
                if ($refused !== null) {
                    return new StartResult('invalid', $runId, $refused);
                }
            }
            // a manual action, as a manual start: the slices of the settings are checked for this user
            $snapshot = $run->snapshot();
            try {
                $checked = self::checkSettings($run->definitionId(), $snapshot['source'] ?? '', Definition::settingsOf($snapshot), $user);
            } catch (SourceRefused) {
                $checked = 'DEFINITION';
            }
            if (is_string($checked)) {
                return new StartResult('invalid', $runId, $checked);
            }
            try {
                $run->resume($user);
            } catch (BatchFailed $e) {
                // finished for good, or its step is on its way
                $finished = in_array($run->progress($user)['role'], ['done', 'ended'], true);
                return new StartResult($finished ? 'invalid' : 'busy', $runId, $e->reason);
            }
            return new StartResult('queued', $runId);
        });
    }

    /** End: the run stops for good, what it stored stays. The caller checks the run belongs to a
     *  definition of its page's slice.
     *  @throws BatchFailed UNKNOWN when there is no such run the user may control, WRONG_STATE when it is finished */
    public static function end(string $runId, string $user): void
    {
        DB_AA::transaction(static function () use ($runId, $user): void {
            $run = Run::lockForUpdate($runId);
            if (($run === null) || !$run->mayControl($user)) {
                throw new BatchFailed('UNKNOWN');
            }
            $run->end($user);
        });
    }

    /**
     * A new source or new settings: the unfinished run ends (SETTINGS_CHANGED), the stream state and
     * the catalog go, the definition takes them with the source's default mapping for them - its
     * expressions may hold a setting - all in one transaction under the lock of the definition, so
     * stream versions of the old settings never decide what the new ones import. Unchanged ones
     * change nothing (SAME), and the run goes on. Every change rebuilds the mapping from
     * defaultMapping() - a mapping edited by hand would be replaced.
     * @param array<mixed> $settings setting => text, only those the source declares
     * @param ?string $expected settingsHash() of the definition the form was made from; another one = STALE
     * @throws BatchFailed FORBIDDEN when an open run cannot be ended by this user
     */
    public static function changeSettings(Definition $d, string $source, array $settings, string $user, ?string $expected = null): ChangeResult
    {
        $id    = (string) $d->getId();
        $slice = strtolower((string) $d->getOwnerId());
        // one answer, so the page learns nothing of other slices' imports
        if (!self::isOf(Definition::fresh($id), $slice)) {
            return new ChangeResult('FORBIDDEN');
        }
        $refused = self::manageRefusal($d, $user, $source);
        if ($refused !== null) {
            return $refused;
        }
        $bad      = '';
        $settings = self::checkSettings($id, $source, $settings, $user, $bad);
        if (is_string($settings)) {
            return new ChangeResult($settings, $bad);
        }
        try {
            $new = Registry::create($source, $settings);
        } catch (Throwable $e) {
            // SETTINGS: each setting passed alone, the source refused them together
            return new ChangeResult((self::sourceFailure($id, $e, false) === 'SETTINGS') ? 'SETTINGS' : 'SOURCE');
        }
        // valid UTF-8 - checkSettings() made sure
        $json = $settings ? json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '';

        return DB_AA::transaction(static function () use ($id, $slice, $source, $settings, $json, $new, $user, $expected): ChangeResult {
            $def = Definition::fresh($id, true);
            if (!self::isOf($def, $slice)) {
                return new ChangeResult('NOT_OF_SLICE');
            }
            if (($expected !== null) && !hash_equals(self::settingsHash($def), $expected)) {
                return new ChangeResult('STALE');
            }
            if (($def->sourceType() === $source) && self::sameSettings($def, $settings)) {
                return new ChangeResult('SAME');
            }
            $refused = self::takeDefaultMapping($def, $new, $slice);
            if ($refused !== null) {
                return $refused;
            }
            foreach (Run::unfinished($id) as $open) {
                try {
                    Run::lockForUpdate((string) $open->getId())?->end($user, 'SETTINGS_CHANGED');
                } catch (BatchFailed $e) {
                    // done in between - nothing left to end
                    if ($e->reason !== 'WRONG_STATE') {
                        throw $e;
                    }
                }
            }
            StreamState::deleteFor($id);
            Catalog::deleteFor($id);
            $def->setProperty('source', $source)->setProperty('settings', $json);
            $def->update([...Definition::MAPPING_PROPS, 'source', 'settings']);
            return new ChangeResult('CHANGED');
        });
    }

    /** What a settings form carries to tell the definition it was made from: its source and settings as stored. */
    public static function settingsHash(Definition $d): string
    {
        return hash('sha256', $d->sourceType() . "\0" . $d->snapshot()['settings']);
    }

    /** Definition::manageRefusal() as a result */
    private static function manageRefusal(Definition $d, string $user, ?string $source = null): ?ChangeResult
    {
        $code = $d->manageRefusal($user, $source);
        return ($code === null) ? null : new ChangeResult($code);
    }

    /**
     * "Reset mapping": the mapping, key, store mode, validation and expression after a stream the
     * source gives for the stored settings - which the user must be allowed to set. An unfinished run
     * keeps working with its snapshot.
     */
    public static function resetMapping(Definition $d, string $user): ChangeResult
    {
        $id    = (string) $d->getId();
        $slice = strtolower((string) $d->getOwnerId());
        $refused = self::manageRefusal($d, $user);
        if ($refused !== null) {
            return $refused;
        }
        return DB_AA::transaction(static function () use ($id, $slice, $user): ChangeResult {
            $def = Definition::fresh($id, true);
            if (!self::isOf($def, $slice)) {
                return new ChangeResult('NOT_OF_SLICE');
            }
            $bad = '';
            try {
                $settings = self::checkSettings($id, $def->sourceType(), Definition::settingsOf($def->snapshot()), $user, $bad);
            } catch (SourceRefused) {
                $settings = 'SETTINGS';
            }
            if (is_string($settings)) {
                return new ChangeResult($settings, $bad);
            }
            try {
                $source = Registry::create($def->sourceType(), $settings);
            } catch (Throwable $e) {
                return new ChangeResult((self::sourceFailure($id, $e, false) === 'SETTINGS') ? 'SETTINGS' : 'NO_MAPPING');
            }
            $refused = self::takeDefaultMapping($def, $source, $slice);
            if ($refused !== null) {
                return $refused;
            }
            $def->update(Definition::MAPPING_PROPS);
            return new ChangeResult('CHANGED');
        });
    }

    /**
     * Add/Update of a catalog entry: the source turns the entry the last check stored under $key into
     * a stream - only the key comes from the request. As other changes of what runs are made of, for
     * whoever may manage the definition and set its settings, and he must edit all items of the slice
     * the source writes to. Runs the source's own code, which writes through storeItem() itself.
     * Not while a run is in the way of a start by hand (busy()): the new stream would change the
     * streams it goes through, or a step still finishing would store with the old ones. No lock is
     * held over the call - the lock of the definition for up to ADOPT_SECONDS of network would stop
     * every start and step; a change of the settings meanwhile only lets the source write with the old
     * ones into the slice whose rights were checked.
     * @return ChangeResult CHANGED; NO_ENTRY, NOT_ADOPTABLE, ADOPT_SLICE, BUSY (with the state of the
     *                      open run, or LEASE), a refusal of manageRefusal() or checkSettings(), TRANSPORT when
     *                      the time ran out, or the code of a failure of the source
     */
    public static function adopt(Definition $d, string $key, string $user): ChangeResult
    {
        $id  = (string) $d->getId();
        $def = Definition::fresh($id);
        if (!self::isOf($def, strtolower((string) $d->getOwnerId()))) {
            return new ChangeResult('FORBIDDEN');
        }
        $refused = self::manageRefusal($def, $user);
        if ($refused !== null) {
            return $refused;
        }
        // in the way as of a start by hand - so the two cannot drift apart
        $toEnd = [];
        $busy  = self::busy($id, 'manual', $toEnd);
        if ($busy !== null) {
            return new ChangeResult('BUSY', $busy->reason);
        }
        $entry = Catalog::forDefinition($id)?->entry($key);
        if ($entry === null) {
            return new ChangeResult('NO_ENTRY');
        }
        try {
            $settings = self::checkSettings($id, $def->sourceType(), Definition::settingsOf($def->snapshot()), $user);
        } catch (SourceRefused) {
            $settings = 'SETTINGS';
        }
        if (is_string($settings)) {
            return new ChangeResult($settings);
        }
        try {
            $source = Registry::create($def->sourceType(), $settings);
            if (!($source instanceof CatalogAdoptable)) {
                return new ChangeResult('NOT_ADOPTABLE');
            }
            $slice = $source->adoptSlice();
            if (!is_long_id($slice) || !CheckPerms($user, 'slice', strtolower($slice), PS_EDIT_ALL_ITEMS)) {
                return new ChangeResult('ADOPT_SLICE');
            }
            $source->adopt($entry, new Context(Definition::sourceCredentials($def->sourceType()), new BatchBudget(microtime(true) + self::ADOPT_SECONDS)));
        } catch (BatchBudgetSpent) {
            // the time ran out: no answer in time, not a bug of the source
            return new ChangeResult('TRANSPORT');
        } catch (Throwable $e) {
            return new ChangeResult(self::sourceFailure($id, $e, false));
        }
        return new ChangeResult('CHANGED');
    }

    /**
     * Whether the unfinished run $r keeps a new run of its definition from starting. For a manual
     * start each one does - the user ends it or lets it go on. A planned start ends a run in error or
     * hanging instead, else one outage of the source would stop the planned imports for good - but
     * never one whose step may still be working: both would store the same items.
     */
    private static function blocking(Run $r, string $trigger, int $now): bool
    {
        if (!str_starts_with($trigger, 'plan:') || $r->stepMayStillWork($now)) {
            return true;
        }
        return ($r->state() !== Run::ERROR) && !$r->isStuck($now);
    }

    /**
     * busy when a run is in the way: unfinished, or ended while a step may still store items with its snapshot
     * @param list<string> $toEnd the runs a planned start ends
     */
    private static function busy(string $id, string $trigger, array &$toEnd): ?StartResult
    {
        $now = Run::clock();
        foreach (Run::unfinished($id) as $run) {
            if (self::blocking($run, $trigger, $now)) {
                return new StartResult('busy', (string) $run->getId(), $run->state());
            }
            $toEnd[] = (string) $run->getId();
        }
        $leased = Run::leasedOf($id, $now);
        return $leased ? new StartResult('busy', $leased[0], 'LEASE') : null;
    }

    /** Ends the runs busy() let a planned start end. Each is decided again under its lock - a step
     *  or a Continue may have taken it since - and all of them before the first is ended: a refusal
     *  ends none. The answer when one is in the way now, else null.
     *  @param list<string> $toEnd left with the runs it ended */
    private static function endInTheWay(array &$toEnd, string $trigger, string $user): ?StartResult
    {
        $now  = Run::clock();
        $runs = [];
        foreach ($toEnd as $runId) {
            $run = Run::lockForUpdate($runId);
            if ($run === null) {
                continue;
            }
            if (in_array($run->state(), [Run::DONE, Run::ENDED], true)) {
                if ($run->hasLiveLease($now)) {
                    return new StartResult('busy', $runId, 'LEASE');
                }
                continue;
            }
            if (self::blocking($run, $trigger, $now)) {
                return new StartResult('busy', $runId, $run->state());
            }
            // whoever set the plan has lost the right to the imports of the slice
            if (!$run->mayControl($user)) {
                return new StartResult('invalid', reason: 'FORBIDDEN');
            }
            $runs[] = $run;
        }
        foreach ($runs as $run) {
            $run->end($user, 'SCHEDULED_START');
        }
        $toEnd = array_map(static fn(Run $run): string => (string) $run->getId(), $runs);
        return null;
    }

    /** A planned start comes from a task of the slice. Tasks run on events have no rule for imports yet. */
    private static function isTaskOf(string $trigger, string $slice): bool
    {
        [$kind, $task] = explode(':', $trigger);
        if ($kind !== 'plan') {
            return false;
        }
        $rows = DB_AA::select(['property' => 'value'], 'SELECT property, value FROM `object_text`', [['object_id', $task], ['property', ['aa_type', 'aa_owner']]]);
        return (($rows['aa_type'] ?? '') === \AA_Plannedtask::class) && (strtolower((string) ($rows['aa_owner'] ?? '')) === $slice);
    }

    /** The source's default mapping into $def, unless it does not fit the slice - it would only refuse every start.
     *  @return ?ChangeResult why not */
    private static function takeDefaultMapping(Definition $def, SourceInterface $source, string $slice): ?ChangeResult
    {
        try {
            $mapping = $source->defaultMapping();
        } catch (Throwable $e) {
            self::sourceFailure((string) $def->getId(), $e, false);
            return new ChangeResult('NO_MAPPING');
        }
        $problems = $mapping->problems($slice);
        if ($problems) {
            return new ChangeResult('MAPPING', implode(', ', $problems));
        }
        $def->applyMapping($mapping);
        return null;
    }

    /**
     * Each setting by the validator of the property the source declares for it - which may normalise
     * it (a slice id loses its spaces) - and each slice it names one the user imports into. A setting
     * left blank, or a bool one switched off, is not stored (isUnset()): a form posts every field it
     * has, and the stored settings must not change only by the empty ones.
     * @param array<mixed> $settings
     * @param string $bad set to the label of the setting refused with SETTINGS or SLICE
     * @return array<string, string>|string the settings to store, or why not: NO_SOURCE, SETTINGS, SLICE,
     *                                      SOURCE (the source's code failed)
     */
    private static function checkSettings(string $id, string $source, array $settings, string $user, string &$bad = ''): array|string
    {
        $class = Registry::sources()[$source] ?? null;
        if ($class === null) {
            return 'NO_SOURCE';
        }
        try {
            $declared = $class::getClassProperties();
        } catch (Throwable $e) {
            return self::sourceFailure($id, $e, false);
        }
        $checked = [];
        foreach ($settings as $name => $value) {
            $property = $declared[$name] ?? null;
            $bad      = ($property instanceof \AA_Property) ? (string) $property->getName() : (string) $name;
            if (!($property instanceof \AA_Property) || !is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                return 'SETTINGS';
            }
            $validator = $property->getValidator();
            if ($validator->varempty($value)) {
                continue;
            }
            if (!$validator->validate($value)) {
                return 'SETTINGS';
            }
            // int, bool and date validators hand back an int
            $value = (string) $value;
            if ((mb_strlen($value) > self::MAX_SETTING) && !($validator->getHtmlInputAttr()['maxlength'] ?? 0)) {
                return 'SETTINGS';
            }
            // however the source declared it, and wherever in the value - SliceIdSetting is the strict way
            foreach (self::slicesIn($value) as $named) {
                if (!Definition::mayImportInto($user, $named)) {
                    return 'SLICE';
                }
            }
            if (!self::isUnset($property, $value)) {
                $checked[(string) $name] = $value;
            }
        }
        $bad = '';
        return $checked;
    }

    /** Blank, or a bool setting switched off - an unchecked checkbox posts 0. To a source, off and
     *  absent are the same, so neither is stored. */
    private static function isUnset(\AA_Property $property, string $value): bool
    {
        return (trim($value) === '') || (($property->getType() === 'bool') && ($value === '0'));
    }

    /** @return list<string> the slices whose ids stand anywhere in $value, however written - a
     *                       command such as {ids:} splits a value and reads each */
    private static function slicesIn(string $value): array
    {
        preg_match_all('/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/i', $value, $found);
        $ids = array_unique(array_map('strtolower', $found[0]));
        return array_values(array_filter($ids, static fn(string $id): bool => DB_AA::test('module', [['id', $id, 'l'], ['type', 'S']])));
    }

    /** The code of a failure of the source's own code. A bug is logged (class and line, never its
     *  text); a refusal only with $logRefusal - otherwise a page shows it. */
    private static function sourceFailure(string $id, Throwable $e, bool $logRefusal): string
    {
        if (!($e instanceof SourceFailure) || $logRefusal) {
            Definition::logError($id, $e);
        }
        return ($e instanceof SourceFailure) ? $e->reason : 'SOURCE';
    }

    /**
     * @param array<mixed> $opt
     * @return ?array{dry: bool, streams: ?list<string>, limit: string} null when not valid
     */
    private static function options(string $mode, array $opt): ?array
    {
        if (array_diff_key($opt, ['dry' => 0, 'streams' => 0, 'limit' => 0])) {
            return null;
        }
        $dry   = $opt['dry'] ?? false;
        $limit = $opt['limit'] ?? null;
        if (!is_bool($dry) || (($limit !== null) && (!is_int($limit) || ($limit < 1)))) {
            return null;
        }
        // these store nothing in the slice to try out - the run would end them in error
        if ($dry && in_array($mode, Run::SOURCE_ONLY_MODES, true)) {
            return null;
        }
        $streams = null;
        if (isset($opt['streams'])) {
            if (!is_array($opt['streams']) || !$opt['streams'] || !array_is_list($opt['streams'])) {
                return null;
            }
            $streams = [];
            foreach ($opt['streams'] as $key) {
                if (!is_string($key)) {
                    return null;
                }
                $streams[] = $key;
            }
        }
        return ['dry' => $dry, 'streams' => $streams, 'limit' => (string) ($limit ?? ($dry ? self::DRY_LIMIT : ''))];
    }

    /** Whether the stored settings are $settings (from checkSettings()), the unset ones of older
     *  stored settings left out as checkSettings() leaves them out.
     *  @param array<string, string> $settings */
    private static function sameSettings(Definition $def, array $settings): bool
    {
        try {
            $stored   = Definition::settingsOf($def->snapshot());
            $class    = Registry::sources()[$def->sourceType()] ?? null;
            $declared = ($class === null) ? [] : $class::getClassProperties();
        } catch (Throwable) {
            return false;
        }
        foreach ($stored as $name => $value) {
            $property = $declared[$name] ?? null;
            if (($property instanceof \AA_Property) ? self::isUnset($property, $value) : (trim($value) === '')) {
                unset($stored[$name]);
            }
        }
        ksort($stored);
        ksort($settings);
        return $stored === $settings;
    }

    /** @phpstan-assert-if-true Definition $def */
    private static function isOf(?Definition $def, string $slice): bool
    {
        return ($def !== null) && (strtolower((string) $def->getOwnerId()) === $slice);
    }
}
