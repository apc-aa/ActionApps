<?php

declare(strict_types=1);

namespace AA\Import;

use AA;
use AA\Expand\InternalExpandInterface;
use AA\IO\DB\DB_AA;
use AA\IO\Grabber\ObjectForm;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\Toexecute;
use AA\Managed;
use AA\Manager;
use AA\Manageraction\AbstractManageraction;
use AA\Manageraction\Import_Delete;
use AA\Manageraction\Import_Run;
use AA\Manageraction\Manageractions;
use AA\Util\Searchfields;
use AA\Widget\ChbWidget;
use AA_Content;
use AA_Form;
use AA_Log;
use AA_Object;
use AA_Plannedtask;
use AA_Property;
use JsonException;
use LogicException;
use Throwable;

/**
 * An import definition: which source fills the slice that owns it, with which settings and mapping.
 * Every property is text, so the whole record lies in object_text. Settings never hold a secret -
 * keys come through AA\Import\Credential.
 *
 * The Manager form edits only the name, the source of a new definition and "enabled". Saving an
 * existing definition therefore rewrites only the name and "enabled", under the lock a starting run
 * takes; settings and mapping change through update() in the import detail - so neither a form
 * opened earlier nor a forged field can overwrite them.
 */
final class Definition extends AA_Object implements InternalExpandInterface
{
    /** log type of a failure of the imports outside a queue step (log.type is varbinary(10)) */
    public const ERROR_EVENT = 'IMPORT_ERR';

    /** the AA log type of a baseline started - who recorded versions no run imported */
    public const BASELINE_EVENT = 'IMPORT_BL';

    /** what applyMapping() sets */
    public const MAPPING_PROPS = ['mapping', 'key_fields', 'store_mode', 'validate', 'after_stream'];

    /** what a run works with - the same for its whole life, whatever is changed in the meantime */
    private const SNAPSHOT = ['source', 'settings', 'mapping', 'key_fields', 'store_mode', 'validate', 'after_stream'];

    /** type of the source: core:<name> | custom:<name> */
    protected $source = '';
    /** JSON object of the source's settings */
    protected $settings = '';
    /** JSON {"rows": [...]} of Mapping */
    protected $mapping = '';
    /** JSON list of field ids */
    protected $key_fields = '';
    /** one of Mapping::STORE_MODES */
    protected $store_mode = '';
    /** JSON list of validation expressions */
    protected $validate = '';
    /** expression run after a stream */
    protected $after_stream = '';
    /** '1' = a planned task starts it; by hand it runs always */
    protected $enabled = '';

    /** set by factoryFromForm() when the source gave no default mapping */
    private bool $noDefaultMapping = false;

    public static function getClassProperties(): array
    {
        $sources = static fn(): array => array_map(static fn(string $class): string => $class::name(), Registry::sources());
        return [
            'source'       => new AA_Property('source', _m('Source'), 'string', false, true, ['enum', $sources], true),
            'settings'     => new AA_Property('settings', _m('Settings'), 'text', false, true, 'text'),
            'mapping'      => new AA_Property('mapping', _m('Mapping'), 'text', false, true, 'text'),
            'key_fields'   => new AA_Property('key_fields', _m('Key fields'), 'text', false, true, 'text'),
            'store_mode'   => new AA_Property('store_mode', _m('Store mode'), 'string', false, true, 'text'),
            'validate'     => new AA_Property('validate', _m('Validation'), 'text', false, true, 'text'),
            'after_stream' => new AA_Property('after_stream', _m('After a stream'), 'text', false, true, 'text'),
            'enabled'      => new AA_Property('enabled', _m('Enabled'), 'string', false, true, 'text'),
        ];
    }

    /** the runs, the stream state and the catalog go with the definition */
    protected static function deleteAction(): AbstractManageraction
    {
        return new Import_Delete('DeleteObject');
    }

    /** The rest is edited in the import detail, which knows the source. The source of a stored
     *  definition is offered alone: changing it ends the run and drops the state, which the detail
     *  says before it does it. The Manager opens the form only for a definition of its slice. */
    public static function addFormrows(AA_Form $form): void
    {
        self::getPropertyObject('aa_name')?->addPropertyFormrows($form);
        $properties = self::getClassProperties();
        $stored     = self::fresh((string) $form->object_id);
        if ($stored !== null) {
            $source = $stored->sourceType();
            $properties['source'] = new AA_Property('source', _m('Source'), 'string', false, true, ['enum', [$source => self::sourceName($source)]], true, _m('The source is changed in the import detail.'));
        }
        $properties['source']->addPropertyFormrows($form);
        $properties['enabled']->addPropertyFormrows($form, new ChbWidget());
    }

    /** The Manager of imports: it may search and list a definition by its name, source and "enabled"
     *  only - the settings and the expressions are shown by the import detail - and it runs them. */
    public static function getManagerConf(?Manageractions $actions = null, ?Manageractions $switches = null): Managed
    {
        $actions ??= new Manageractions();
        $actions->addAction(new Import_Run(Import_Run::ID));
        $managed    = parent::getManagerConf($actions, $switches);
        $settings   = $managed->getSettings();
        $properties = self::getClassProperties();
        $settings['searchbar']['fields'] = Searchfields::factoryFromProperties(['aa_name' => self::getPropertyObject('aa_name'), 'source' => $properties['source'], 'enabled' => $properties['enabled']]);
        $settings['itemview']['format']['noitem_msg'] = _m('The slice has no import yet.') . '<br>';
        return $managed->setSettings($settings);
    }

    protected static function getManagerTopHtml($fields): string
    {
        $heads = ['', _m('Action'), _m('Name'), _m('Slice'), _m('Source'), _m('Enabled'), _m('Status'), _m('Last run'), _m('Next plan')];
        return '
          <table>
          <thead>
            <tr>
              <th scope=col>' . implode("</th>\n<th scope=col>", $heads) . '</th>
            </tr>
          </thead><tbody>
            ';
    }

    /** Edit and Detail are links that only read; Run and Delete are buttons that POST. The slice
     *  tells the imports apart on the overview of all slices. */
    protected static function getManagerRowHtml($fields, $aliases, $buttons): string
    {
        $detail  = a_href(get_admin_url('se_dataimport.php', ['change_id=_#AA_OWNER', 'id=_#AA_ID___']), _m('Detail'), ['class' => 'aa-button-show']);
        $actions = [$buttons['Edit'] ?? '', $detail, $buttons[Import_Run::ID] ?? '', $buttons['DeleteObject'] ?? ''];
        $cell    = static fn(string $info): string => '{internal:' . self::class . ':' . $info . '}';
        return '
            <tr>
              <td><input name="chb[x_#AA_ID___]" value="" type="checkbox"></td>
              <td style="white-space:nowrap;">' . implode(' ', array_filter($actions)) . '</td>
              <td>_#AA_NAME_</td>
              <td><span title="_#AA_OWNER">_#AA_OW_NM</span></td>
              <td>' . $cell('source') . '</td>
              <td>' . $cell('enabled') . '</td>
              <td>' . $cell('status') . '</td>
              <td style="font-variant-numeric:tabular-nums">' . $cell('lastrun') . '</td>
              <td style="font-variant-numeric:tabular-nums">' . $cell('nextplan') . '</td>
            </tr>
            ';
    }

    /** The cells of the imports Manager: source, enabled, status, lastrun, nextplan - text, escaped.
     *  {internal} works in any template, so it gives only what the list shows. */
    public static function internal_expand(?AA_Content $content, ...$params): string
    {
        $id = (string) $content?->getId();
        if (($content === null) || (preg_match('/^[0-9a-f]{32}\z/', $id) !== 1)) {
            return '';
        }
        $now = Run::clock();
        return match ((string) ($params[0] ?? '')) {
            'source'   => safe(self::sourceName((string) $content->getValue('source'))),
            'enabled'  => ((string) $content->getValue('enabled') === '1') ? _m('Yes') : _m('No'),
            'status'   => safe(self::statusWord(self::lastRun($id), $now)),
            'lastrun'  => self::lastRunHtml(self::lastRun($id)),
            'nextplan' => self::nextPlanHtml($content, $now),
            default    => '',
        };
    }

    /** The state of $run in words; null = no run. A run that works holds a live lease; one whose next
     *  step is due later waits for a retry; one that hangs only the State Check explains - checked in
     *  the order the import detail describes the open run. */
    public static function statusWord(?Run $run, int $now): string
    {
        if ($run === null) {
            return _m('Never ran');
        }
        return match ($run->state()) {
            Run::RUNNING => self::runningWord($run, $now),
            Run::DONE    => ($run->counts()['errors'] > 0) ? _m('Finished with item errors') : _m('Finished'),
            Run::ERROR   => _m('Stopped by an error'),
            Run::ENDED   => _m('Ended'),
            default      => $run->state(),
        };
    }

    private static function runningWord(Run $run, int $now): string
    {
        return match (true) {
            $run->isStuck($now)      => _m('Stuck — see State Check'),
            $run->hasLiveLease($now) => _m('Running'),
            $run->isWaiting($now)    => _m('Waiting to retry'),
            default                  => _m('Queued'),
        };
    }

    /**
     * When a planned task of the slice starts the import next, at $now or later: the earliest queued
     * step of each task calling {import} with the id or the name of the import (its "Run now" too),
     * else when the scheduler queues it (AA_Plannedtask::nextStart()); null = no task does.
     */
    public function nextPlanned(int $now): ?int
    {
        return self::nextPlannedOf((string) $this->getOwnerId(), (string) $this->getId(), (string) $this->aa_name, $now);
    }

    private static function nextPlannedOf(string $slice, string $id, string $name, int $now): ?int
    {
        if (!is_long_id($slice)) {
            return null;
        }
        $refs  = [self::nameKey($id), self::nameKey($name)];
        $times = [];
        // read once for the installation: a list of the imports of all slices asks for many
        foreach (AA::Contentcache()->get_result([self::class, 'plannedTasksFromDb']) as $task) {
            if (($task['slice'] !== strtolower($slice)) || !array_intersect($task['calls'], $refs)) {
                continue;
            }
            $times[] = $task['queued'] ?? (new AA_Plannedtask(['time' => $task['time'], 'shift' => $task['shift']]))->nextStart($now);
        }
        $times = array_filter($times, static fn(int $at): bool => $at > 0);
        return $times ? min($times) : null;
    }

    /**
     * For the cache of nextPlanned(): the planned tasks that call {import} - their slice, the import
     * names and ids they call (nameKey()), their time and the earliest step queued for them.
     * @return list<array{slice: string, calls: list<string>, time: string, shift: int, queued: ?int}>
     */
    public static function plannedTasksFromDb(): array
    {
        $ids = array_values(array_unique(array_map('strval', DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'aa_type'], ['value', AA_Plannedtask::class]]))));
        if (!$ids) {
            return [];
        }
        $rows = [];
        foreach (DB_AA::select([], 'SELECT object_id, property, value FROM `object_text`', [['object_id', $ids], ['property', ['aa_owner', 'task', 'time', 'shift']]]) as $row) {
            $rows[(string) $row['object_id']][(string) $row['property']] = (string) $row['value'];
        }
        $tasks = [];
        foreach ($rows as $taskId => $task) {
            // a bare {import} names no import
            $calls = array_values(array_unique(array_map(self::nameKey(...), array_filter(AA_Plannedtask::importCalls($task['task'] ?? '')))));
            if ($calls) {
                $tasks[AA_Plannedtask::toexecuteSelector((string) $taskId)] = ['slice' => strtolower($task['aa_owner'] ?? ''), 'calls' => $calls, 'time' => $task['time'] ?? '', 'shift' => (int) ($task['shift'] ?? 0), 'queued' => null];
            }
        }
        if (!$tasks) {
            return [];
        }
        // the rows schedule() and "Run now" queue; one of priority 0 never runs
        $selectors = array_keys($tasks);
        foreach (DB_AA::select([], 'SELECT selector, execute_after, priority FROM `toexecute`', [['selector', [...$selectors, ...array_map(static fn(string $s): string => "$s:now", $selectors)]]]) as $row) {
            $selector = preg_replace('/:now\z/', '', (string) $row['selector']);
            if (((int) $row['priority'] > 0) && isset($tasks[$selector])) {
                $at = (int) $row['execute_after'];
                $tasks[$selector]['queued'] = min($tasks[$selector]['queued'] ?? $at, $at);
            }
        }
        return array_values($tasks);
    }

    /** the newest run of definition $id, by its start - a fresh copy of the cached one */
    private static function lastRun(string $id): ?Run
    {
        $props = AA::Contentcache()->get_result([self::class, 'lastRunFromDb'], [$id]);
        return $props ? new Run($props) : null;
    }

    /** For the cache of lastRun(): the properties of the newest run of definition $id; [] = none.
     *  @return array<string, string> */
    public static function lastRunFromDb(string $id): array
    {
        $run = Run::ofSubject($id, 1)[0] ?? null;
        if ($run === null) {
            return [];
        }
        $props = [];
        foreach (array_keys(Run::getClassProperties()) as $property) {
            $props[$property] = (string) $run->getProperty($property);
        }
        return $props;
    }

    private static function lastRunHtml(?Run $run): string
    {
        if ($run === null) {
            return '--';
        }
        $started = (int) $run->getProperty('started');
        return ($started ? date('Y-m-d H:i', $started) : '--') . ' <small>' . safe(self::howItRan($run)) . '</small>';
    }

    /** how $run ran - its mode, dry, its trigger - in words, plain text */
    public static function howItRan(Run $run): string
    {
        $trigger = (string) $run->getProperty('trigger');
        $about   = [
            match ($run->mode()) {
                'changed'  => _m('changed only'),
                'check'    => _m('check'),
                'baseline' => _m('recording the state of the source'),
                default    => '',
            },
            $run->isDry() ? _m('dry run') : '',
            match (true) {
                $trigger === 'manual'               => _m('by hand'),
                str_starts_with($trigger, 'plan:')  => _m('planned'),
                str_starts_with($trigger, 'event:') => _m('on an event'),
                default                             => '',
            },
        ];
        return implode(', ', array_filter($about));
    }

    private static function nextPlanHtml(AA_Content $content, int $now): string
    {
        // a planned start of a disabled import does nothing
        if ((string) $content->getValue('enabled') !== '1') {
            return _m('Off');
        }
        $next = self::nextPlannedOf((string) $content->getOwnerId(), (string) $content->getId(), (string) $content->getName(), $now);
        return ($next === null) ? _m('Not planned') : date('Y-m-d H:i', $next);
    }

    /** the name of source $type, or the type when the source is not available */
    public static function sourceName(string $type): string
    {
        $class = ($type === '') ? null : (Registry::sources()[$type] ?? null);
        return ($class === null) ? $type : $class::name();
    }

    /**
     * Only the posted name, source and "enabled" - not through parent::factoryFromForm(), which takes
     * every property from the request. The mapping is the source's default, which only a new
     * definition stores (save()); the source's code runs only for a user who may manage imports.
     */
    public static function factoryFromForm($oowner, $otype = null): self
    {
        $grabber = new ObjectForm();
        $grabber->prepare();
        $content = $grabber->getItem() ?? throw new LogicException('No definition in the form');
        $grabber->finish();

        $def = new self();
        $def->setNew($content->getId(), $oowner, (string) $content->getName());
        $def->source  = (string) ($content->getAaValue('source')->getValues()[0] ?? '');
        $def->enabled = (($content->getAaValue('enabled')->getValues()[0] ?? '') === '1') ? '1' : '';
        if ($def->mayManage(AuthGetUid())) {
            $def->takeDefaultMapping();
        }
        return $def;
    }

    /**
     * Checked when the definition is saved from a form; saving from code is not. An existing
     * definition is checked only for what the form changes, so a broken one can still be renamed or
     * disabled - by whoever may change it (manageRefusal()), a superadmin when its source needs keys.
     */
    public function formRefusal(): ?string
    {
        $refused = $this->manageRefusal(AuthGetUid());
        if ($refused !== null) {
            return self::refusal($refused);
        }
        $name = (string) $this->aa_name;
        if ($name === '') {
            return _m('The import needs a name.');
        }
        // {import:<name>:<mode>} - and a name like an id would hide the definition of that id
        if (str_contains($name, ':') || (preg_match('/^[0-9a-f]{32}\z/i', $name) === 1)) {
            return _m('The name of an import may not contain a colon (:) or look like an id.');
        }
        if (array_diff(self::named((string) $this->getOwnerId(), $name), [$this->getId()])) {
            return _m('Another import of the slice has this name.');
        }
        $stored = self::textRows((string) $this->getId(), false, ['aa_type', 'aa_owner', 'source']);
        if ($stored) {
            if (!$this->isOwn($stored)) {
                return self::refusal('NOT_OF_SLICE');
            }
            // a run, the stream state and the catalog belong to the old source - they go with the change
            return (($stored['source'] ?? '') !== (string) $this->source) ? _m('Change the source in the import detail.') : null;
        }
        if (!isset(Registry::sources()[(string) $this->source])) {
            return self::refusal('NO_SOURCE');
        }
        try {
            BatchRun::requireTransactional([]);
        } catch (BatchFailed) {
            return _m('Imports cannot run here: their database tables (object_text, toexecute) do not support transactions (InnoDB).');
        }
        if ($this->noDefaultMapping) {
            return self::refusal('NO_MAPPING');
        }
        try {
            $problems = $this->mapping()->problems((string) $this->getOwnerId());
        } catch (SourceRefused) {
            return _m('The mapping of the import is not valid.');
        }
        if ($problems) {
            return self::refusal('MAPPING', implode(', ', $problems));
        }
        return null;
    }

    /**
     * Why a definition or a change of it is refused, for the user - plain text.
     * @param string $code FORBIDDEN, KEYS, NOT_OF_SLICE, NO_SOURCE, NO_MAPPING, MAPPING (with the problem
     *                     codes in $detail), SETTINGS and SLICE (with the label of the setting in $detail,
     *                     if known), STALE; any other = the source refused the settings
     */
    public static function refusal(string $code, string $detail = ''): string
    {
        return match ($code) {
            'FORBIDDEN'    => _m('Only a user who manages the feeding of the slice may change its imports.'),
            'KEYS'         => _m('This import uses API keys, so only a superadmin may change or delete it.'),
            'NOT_OF_SLICE' => _m('The import was not saved - it is not an import of this slice.'),
            'NO_SOURCE'    => _m('The import source is not available.'),
            'NO_MAPPING'   => _m('The import source did not give its mapping.'),
            'MAPPING'      => _m('The mapping of the import does not fit the slice: %1', [$detail]),
            'SETTINGS'     => ($detail === '') ? _m('A setting of the import source is not valid.') : _m('The setting "%1" of the import source is not valid.', [$detail]),
            'SLICE'        => ($detail === '') ? _m('The settings name a slice whose feeding you do not manage.') : _m('The setting "%1" names a slice whose feeding you do not manage.', [$detail]),
            'STALE'        => _m('The settings of the import changed meanwhile. Reload the page and set them again.'),
            default        => _m('The import source refused these settings.'),
        };
    }

    /** @param ?string $source the source the definition is to have; null = its own */
    public function mayManage(string $uid, ?string $source = null): bool
    {
        return $this->manageRefusal($uid, $source) === null;
    }

    /**
     * The one check of who may create, change or delete a definition - its name, settings, mapping,
     * the definition itself: whoever imports into the slice, and only a superadmin when the source
     * needs keys, before or after a change, as its settings decide where the keys are sent. Running
     * it stays the slice's.
     * @param ?string $source the source the definition is to have; null = its own
     * @return ?string null = he may; FORBIDDEN, or KEYS when only the keys of a source stop him
     */
    public function manageRefusal(string $uid, ?string $source = null): ?string
    {
        if (!self::mayImportInto($uid, (string) $this->getOwnerId())) {
            return 'FORBIDDEN';
        }
        // IsSuperadmin() knows the logged-in user only
        if (($uid === AuthGetUid()) && IsSuperadmin()) {
            return null;
        }
        $keyed = (self::sourceKeys($this->sourceType()) !== []) || (($source !== null) && (self::sourceKeys($source) !== []));
        return $keyed ? 'KEYS' : null;
    }

    /** The keys of source $type as Context takes them - the list the right to change the definition
     *  was judged by, whatever class the source makes itself. Unknown keys = none: a secret is not
     *  handed out.
     *  @return list<array{vendor: string, context: string}> */
    public static function sourceCredentials(string $type): array
    {
        return array_map(static fn(array $k): array => ['vendor' => $k[0], 'context' => $k[1]], self::sourceKeys($type) ?? []);
    }

    /**
     * The keys source $type needs - SourceInterface::credentials(), static, so no code of the source
     * runs beyond it. [] for a source that is gone.
     * @return ?list<array{string, string}> vendor and context of each; null when it is unknown - the
     *                                      source did not load, or its list fails or has another shape
     */
    public static function sourceKeys(string $type): ?array
    {
        $class = Registry::sources()[$type] ?? null;
        if ($class === null) {
            return Registry::isRejected($type) ? null : [];
        }
        try {
            return self::keysOf($class::credentials());
        } catch (Throwable) {
            return null;
        }
    }

    /** @return ?list<array{string, string}> */
    private static function keysOf(mixed $declared): ?array
    {
        if (!is_array($declared) || !array_is_list($declared)) {
            return null;
        }
        $keys = [];
        foreach ($declared as $key) {
            if (!is_array($key) || !is_string($key['vendor'] ?? null) || !is_string($key['context'] ?? null)) {
                return null;
            }
            $keys[] = [$key['vendor'], $key['context']];
        }
        return $keys;
    }

    /** Whether the actions of an imports Manager are offered on its page. The overview of all slices
     *  is a superadmin's - the module selected in the session may be no slice; the action then checks
     *  each import on its own slice (actionable(), Service, deleteWithRuns()). */
    public static function mayActOn(Manager $manager): bool
    {
        return $manager->isAllModules() ? IsSuperadmin() : self::mayImportInto(AuthGetUid(), (string) $manager->getModuleId());
    }

    /** The one rule of imports: whoever manages the feeding of a slice imports into it - defines its
     *  imports and controls their runs. Only a slice: the admin role of a site or alerts module holds
     *  PS_FEEDING too. */
    public static function mayImportInto(string $uid, string $slice): bool
    {
        return is_long_id($slice) && CheckPerms($uid, 'slice', $slice, PS_FEEDING) && (\AA_Slice::getOwnModule(strtolower($slice)) !== null);
    }

    /**
     * A new definition is stored whole. An existing one of this slice changes only its name and
     * "enabled" - the rest changes through update() in the import detail; false when the id holds an
     * object of another class or slice, or the stored source differs. Decided by one read, locked
     * as a starting run locks it (on a table without transactions the lock does nothing - no run
     * can exist there).
     */
    public function save(): false|string
    {
        $id = DB_AA::transaction(function (): false|string {
            $stored = self::textRows((string) $this->getId(), DB_AA::inTransaction());
            if (!$stored) {
                if (!self::ownsRecords((string) $this->getId())) {
                    return parent::save();
                }
                // what a deleted definition left at the id would become this one's - a stale form or a forged id
                AA_Log::write(self::ERROR_EVENT, 'Import_' . $this->getId(), '#' . $this->getId() . ' LEFTOVERS');
                return false;
            }
            if (!$this->isOwn($stored) || (($stored['source'] ?? '') !== (string) $this->source)) {
                return false;
            }
            $this->update(['aa_name', 'enabled']);
            return (string) $this->getId();
        });
        return is_string($id) ? $id : false;
    }

    /**
     * Deletes the definition with what it owns: its runs, its stream state, its catalog and its queued
     * steps - left behind, they would lie orphaned with nothing to show or delete them. One transaction
     * under the lock of the definition, which a starting run takes too, so no run starts meanwhile.
     * A step working during the delete may still store items until its lease ends (the step's 30 s
     * plus 60 s); its run is gone, so it writes nothing back. Nothing happens when the definition is
     * no longer stored.
     * @throws BatchFailed FORBIDDEN or KEYS (manageRefusal()) when $uid may not delete it
     */
    public function deleteWithRuns(string $uid): void
    {
        $id    = (string) $this->getId();
        $slice = DB_AA::transaction(static function () use ($id, $uid): ?string {
            $def = self::fresh($id, true);
            if ($def === null) {
                return null;
            }
            // decided by the stored slice and source under the lock, not by the page that asks
            $refused = $def->manageRefusal($uid);
            if ($refused !== null) {
                throw new BatchFailed($refused);
            }
            $runs = self::deleteRecordsOf($id)['runs'];
            self::deleteObjects([$id]);
            // a run's afterClose() no longer comes - a step that died, or the one working meanwhile, stored items;
            // a definition never run stored nothing
            return ($runs > 0) ? (string) $def->getOwnerId() : null;
        });
        if (is_string($slice)) {
            // the delete has committed - stale pages are no reason to report it failed
            try {
                AA::Pagecache()->invalidateFor([$slice]);
            } catch (\Throwable $e) {
                self::logError($id, $e);
            }
        }
    }

    /**
     * What definition $id owns - its runs, stream state, catalog and queued steps - deleted in the
     * caller's transaction, which holds the lock of the definition (or knows it is gone). A step working
     * meanwhile finds no run at its end and writes nothing back.
     * @return array{runs: int, records: int} the runs deleted, and the stream state and catalog
     */
    public static function deleteRecordsOf(string $id): array
    {
        $runs    = Run::deleteOfSubject($id);
        $records = (int) StreamState::deleteFor($id) + (int) Catalog::deleteFor($id);
        // the safety row of a finished run's final phase - the core cancels only the steps of unfinished runs
        (new Toexecute())->cancel_all('Import_' . $id);
        return ['runs' => $runs, 'records' => $records];
    }

    /** whether anything is stored for definition $id: its runs, stream state and catalog are owned
     *  by it, its queued steps carry its selector */
    private static function ownsRecords(string $id): bool
    {
        return DB_AA::test('object_text', [['property', 'aa_owner'], ['value', $id]]) || DB_AA::test('toexecute', [['selector', 'Import_' . $id]]);
    }

    /** How the names of two definitions compare when {import} looks one up - as object_text.value does
     *  in its case-insensitive collation, trailing spaces ignored. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(rtrim($name, ' '));
    }

    /** A failure of the imports outside a queue step, as a failed step is logged: the class and the
     *  line, or the code of a source's refusal - never the text, it may carry source data or a secret. */
    public static function logError(string $id, \Throwable $e): void
    {
        // a refusal of the source says enough by its code
        $params = ($e instanceof SourceFailure) ? "#$id " . $e->reason : Toexecute::failureParams($id, $e);
        AA_Log::write(self::ERROR_EVENT, 'Import_' . $id, $params);
    }

    /**
     * The definition as stored now - AA_Object::load() caches per request; null when $id is none.
     * With $lock its rows stay locked until the caller's transaction ends: one locking read, so the
     * values are the ones locked, not an older snapshot.
     */
    public static function fresh(string $id, bool $lock = false): ?self
    {
        if (preg_match('/^[0-9a-f]{32}\z/', $id) !== 1) {
            return null;
        }
        if ($lock && !DB_AA::inTransaction()) {
            throw new LogicException('A definition is locked only inside a transaction');
        }
        $rows = self::textRows($id, $lock);
        if (($rows['aa_type'] ?? '') !== self::class) {
            return null;
        }
        $def = new self();
        $def->setNew($id, $rows['aa_owner'] ?? '', $rows['aa_name'] ?? '');
        foreach (array_keys(self::getClassProperties()) as $property) {
            $def->$property = $rows[$property] ?? '';
        }
        return $def;
    }

    /**
     * The definition of slice $sliceId given by its id or name; never one of another slice, so a
     * slice cannot start the import of another one. null also for a name two definitions share.
     */
    public static function byNameOrId(string $sliceId, string $ref): ?self
    {
        $sliceId = strtolower($sliceId);
        if (preg_match('/^[0-9a-f]{32}\z/i', $ref) === 1) {
            $def = self::fresh(strtolower($ref));
            return (($def !== null) && ($def->getOwnerId() === $sliceId)) ? $def : null;
        }
        $ids = self::named($sliceId, $ref);
        return (count($ids) === 1) ? self::fresh($ids[0]) : null;
    }

    /** @return array<string, string> the target slice and the properties a run works with - its copy for a whole run */
    public function snapshot(): array
    {
        $snapshot = ['slice' => (string) $this->getOwnerId()];
        foreach (self::SNAPSHOT as $property) {
            $snapshot[$property] = (string) $this->$property;
        }
        return $snapshot;
    }

    /** @throws SourceRefused DEFINITION when a stored property is not valid */
    public function mapping(): Mapping
    {
        return self::mappingOf($this->snapshot());
    }

    /**
     * The mapping of a snapshot(), so a run reads it the same way as the definition.
     * @param array<string, string> $snapshot
     * @throws SourceRefused DEFINITION when a property is not valid
     */
    public static function mappingOf(array $snapshot): Mapping
    {
        return new Mapping(
            Mapping::fromJson($snapshot['mapping'] ?? '')->rows,
            self::listOf($snapshot['key_fields'] ?? '', 'key fields'),
            $snapshot['store_mode'] ?? '',
            self::listOf($snapshot['validate'] ?? '', 'validation'),
            $snapshot['after_stream'] ?? '',
        );
    }

    /**
     * The settings of a snapshot() as the source gets them: text values only.
     * @param array<string, string> $snapshot
     * @return array<string, string>
     * @throws SourceRefused DEFINITION when they are not a JSON object
     */
    public static function settingsOf(array $snapshot): array
    {
        $json = $snapshot['settings'] ?? '';
        if ($json === '') {
            return [];
        }
        try {
            $settings = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $settings = null;
        }
        // a list or numbered keys name no setting
        if (!is_array($settings) || array_filter(array_keys($settings), 'is_int')) {
            throw new SourceRefused('DEFINITION', 'Definition: settings are not a JSON object');
        }
        return array_map('strval', array_filter($settings, 'is_scalar'));
    }

    public function applyMapping(Mapping $m): void
    {
        $this->mapping      = $m->toJson();
        $this->key_fields   = json_encode($m->keyFields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->store_mode   = $m->storeMode;
        $this->validate     = json_encode($m->validate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->after_stream = $m->afterStream;
    }

    public function sourceType(): string
    {
        return (string) $this->source;
    }

    public function isEnabled(): bool
    {
        return (string) $this->enabled === '1';
    }

    /** @param array<string, string> $rows of textRows() */
    private function isOwn(array $rows): bool
    {
        return (($rows['aa_type'] ?? '') === self::class) && (($rows['aa_owner'] ?? '') === strtolower((string) $this->getOwnerId()));
    }

    /** A new definition has no settings yet, so the source is asked without them. An unknown source
     *  is left to formRefusal(); any other failure of the source's code is reported there too. */
    private function takeDefaultMapping(): void
    {
        if (!isset(Registry::sources()[(string) $this->source])) {
            return;
        }
        try {
            $this->applyMapping(Registry::create((string) $this->source, [])->defaultMapping());
        } catch (Throwable) {
            $this->noDefaultMapping = true;
        }
    }

    /** @return list<string> ids of the definitions of slice $sliceId called $name */
    private static function named(string $sliceId, string $name): array
    {
        if ($name === '') {
            return [];
        }
        $ids = array_values(array_unique(array_map('strval', DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'aa_name'], ['value', $name]]))));
        if (!$ids) {
            return [];
        }
        $records = [];
        foreach (DB_AA::select([], 'SELECT object_id, property, value FROM `object_text`', [['object_id', $ids], ['property', ['aa_type', 'aa_owner']]]) as $row) {
            $records[(string) $row['object_id']][(string) $row['property']] = (string) $row['value'];
        }
        $own = array_filter($records, static fn(array $r): bool => (($r['aa_type'] ?? '') === self::class) && (($r['aa_owner'] ?? '') === strtolower($sliceId)));
        return array_map('strval', array_keys($own));
    }

    /** @return array<mixed> Mapping checks it is a list of strings */
    private static function listOf(string $json, string $what): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $list = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $list = null;
        }
        if (!is_array($list)) {
            throw new SourceRefused('DEFINITION', "Definition: $what is not JSON");
        }
        return $list;
    }
}
