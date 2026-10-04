<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\DB\DB_AA;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
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

    private const MODES = ['run', 'changed', 'check'];

    private const TRIGGER = '/^(manual|(plan|event):[0-9a-f]{32})\z/';

    /** characters of a setting whose property sets no maximum */
    private const MAX_SETTING = 1000;

    /**
     * Queues a run of the definition $d of the slice $d belongs to, with the definition as stored now.
     * A manual start checks the user's right to run imports of the slice and to the slices its
     * settings name; a planned one keeps the right of whoever set them.
     * @param array{dry?: bool, streams?: list<string>, limit?: int} $opt  streams = some of the source's
     * @param string $mode    run | changed | check
     * @param string $trigger manual | plan:<task id> | event:<task id> - set by the caller, never from the request
     */
    public static function start(Definition $d, string $mode, array $opt, string $trigger, string $user): StartResult
    {
        if (preg_match(self::TRIGGER, $trigger) !== 1) {
            throw new \InvalidArgumentException('An import starts manual, plan:<task id> or event:<task id>');
        }
        if (!in_array($mode, self::MODES, true)) {
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
            $busy = self::busy($id, $trigger);
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
                $keys = array_map('strval', array_keys(Run::streamsOf(Registry::create($def->sourceType(), $settings))));
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
            ]);
            return new StartResult('queued', (string) $run->getId());
        });
    }

    /** Continue: a run in error goes on from its position, one waiting for a retry or hanging tries now.
     *  A run the user may not control is unknown, as one that is not there. The caller checks the run
     *  belongs to a definition of its page's slice. */
    public static function resume(string $runId, string $user): StartResult
    {
        return DB_AA::transaction(static function () use ($runId, $user): StartResult {
            $run = Run::lockForUpdate($runId);
            if (($run === null) || !$run->mayControl($user)) {
                return new StartResult('unknown');
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
     * change nothing, and the run goes on. Every change rebuilds the mapping from defaultMapping() -
     * a mapping edited by hand would be replaced.
     * @param array<mixed> $settings setting => text, only those the source declares
     * @return ?string why not, for the user; null = done
     * @throws BatchFailed FORBIDDEN when an open run cannot be ended by this user
     */
    public static function changeSettings(Definition $d, string $source, array $settings, string $user): ?string
    {
        $id    = (string) $d->getId();
        $slice = strtolower((string) $d->getOwnerId());
        // before any code of the source runs; one answer, so the page learns nothing of other slices' imports
        if (!self::isOf(Definition::fresh($id), $slice) || !$d->mayManage($user, $source)) {
            return Definition::refusal('FORBIDDEN');
        }
        $settings = self::checkSettings($id, $source, $settings, $user);
        if (is_string($settings)) {
            return Definition::refusal($settings);
        }
        try {
            $new = Registry::create($source, $settings);
        } catch (Throwable $e) {
            self::sourceFailure($id, $e, false);
            return Definition::refusal('SOURCE');
        }
        // valid UTF-8 - checkSettings() made sure
        $json = $settings ? json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '';

        return DB_AA::transaction(static function () use ($id, $slice, $source, $settings, $json, $new, $user): ?string {
            $def = Definition::fresh($id, true);
            if (!self::isOf($def, $slice)) {
                return Definition::refusal('NOT_OF_SLICE');
            }
            if (($def->sourceType() === $source) && self::sameSettings($def, $settings)) {
                return null;
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
            return null;
        });
    }

    /**
     * "Reset mapping": the mapping, key, store mode, validation and expression after a stream the
     * source gives for the stored settings - which the user must be allowed to set. An unfinished run
     * keeps working with its snapshot.
     * @return ?string why not, for the user; null = done
     */
    public static function resetMapping(Definition $d, string $user): ?string
    {
        $id    = (string) $d->getId();
        $slice = strtolower((string) $d->getOwnerId());
        if (!$d->mayManage($user)) {
            return Definition::refusal('FORBIDDEN');
        }
        return DB_AA::transaction(static function () use ($id, $slice, $user): ?string {
            $def = Definition::fresh($id, true);
            if (!self::isOf($def, $slice)) {
                return Definition::refusal('NOT_OF_SLICE');
            }
            try {
                $settings = self::checkSettings($id, $def->sourceType(), Definition::settingsOf($def->snapshot()), $user);
            } catch (SourceRefused) {
                $settings = 'SETTINGS';
            }
            if (is_string($settings)) {
                return Definition::refusal($settings);
            }
            try {
                $source = Registry::create($def->sourceType(), $settings);
            } catch (Throwable $e) {
                self::sourceFailure($id, $e, false);
                return Definition::refusal('NO_MAPPING');
            }
            $refused = self::takeDefaultMapping($def, $source, $slice);
            if ($refused !== null) {
                return $refused;
            }
            $def->update(Definition::MAPPING_PROPS);
            return null;
        });
    }

    /**
     * Whether the unfinished run $r keeps a new run of its definition from starting. Each one does,
     * also one in error or hanging: nothing ends a run by itself, the user ends it or lets it go on.
     */
    private static function blocking(Run $r, string $trigger): bool
    {
        return true;
    }

    /** busy when a run is in the way: unfinished, or ended while a step may still store items with its snapshot */
    private static function busy(string $id, string $trigger): ?StartResult
    {
        foreach (Run::unfinished($id) as $run) {
            if (self::blocking($run, $trigger)) {
                return new StartResult('busy', (string) $run->getId(), $run->state());
            }
        }
        $leased = Run::leasedOf($id);
        return $leased ? new StartResult('busy', $leased[0], 'LEASE') : null;
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
     *  @return ?string why not, for the user */
    private static function takeDefaultMapping(Definition $def, SourceInterface $source, string $slice): ?string
    {
        try {
            $mapping = $source->defaultMapping();
        } catch (Throwable $e) {
            self::sourceFailure((string) $def->getId(), $e, false);
            return Definition::refusal('NO_MAPPING');
        }
        $problems = $mapping->problems($slice);
        if ($problems) {
            return Definition::refusal('MAPPING', implode(', ', $problems));
        }
        $def->applyMapping($mapping);
        return null;
    }

    /**
     * Each setting by the validator of the property the source declares for it - which may normalise
     * it (a slice id loses its spaces) - and each slice it names one the user imports into. A blank
     * value is stored empty.
     * @param array<mixed> $settings
     * @return array<string, string>|string the settings to store, or why not: NO_SOURCE, SETTINGS, SLICE,
     *                                      SOURCE (the source's code failed)
     */
    private static function checkSettings(string $id, string $source, array $settings, string $user): array|string
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
            if (!($property instanceof \AA_Property) || !is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                return 'SETTINGS';
            }
            $validator = $property->getValidator();
            if ($validator->varempty($value)) {
                $value = '';
            } elseif (!$validator->validate($value)) {
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
            $checked[(string) $name] = (string) $value;
        }
        return $checked;
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
        // a check stores nothing in the slice to try out - the run would end it in error
        if ($dry && ($mode === 'check')) {
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

    /** @param array<string, string> $settings */
    private static function sameSettings(Definition $def, array $settings): bool
    {
        try {
            $stored = Definition::settingsOf($def->snapshot());
        } catch (SourceRefused) {
            return false;
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
