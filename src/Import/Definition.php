<?php

declare(strict_types=1);

namespace AA\Import;

use AA;
use AA\IO\DB\DB_AA;
use AA\IO\Grabber\ObjectForm;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\Toexecute;
use AA\Manageraction\AbstractManageraction;
use AA\Manageraction\Import_Delete;
use AA\Widget\ChbWidget;
use AA_Form;
use AA_Log;
use AA_Object;
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
final class Definition extends AA_Object
{
    /** log type of a failure of the imports outside a queue step (log.type is varbinary(10)) */
    public const ERROR_EVENT = 'IMPORT_ERR';

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

    /** the rest is edited in the import detail, which knows the source */
    public static function addFormrows(AA_Form $form): void
    {
        self::getPropertyObject('aa_name')?->addPropertyFormrows($form);
        $properties = self::getClassProperties();
        $properties['source']->addPropertyFormrows($form);
        $properties['enabled']->addPropertyFormrows($form, new ChbWidget());
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
     * disabled.
     */
    public function formRefusal(): ?string
    {
        if (!$this->mayManage(AuthGetUid())) {
            return self::refusal('FORBIDDEN');
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
     * Why a definition or a change of it is refused, for the user.
     * @param string $code FORBIDDEN, NOT_OF_SLICE, NO_SOURCE, NO_MAPPING, MAPPING (with the problem
     *                     codes in $detail), SETTINGS, SLICE; any other = the source refused the settings
     */
    public static function refusal(string $code, string $detail = ''): string
    {
        return match ($code) {
            'FORBIDDEN'    => _m('Only a user who manages the feeding of the slice may change its imports.'),
            'NOT_OF_SLICE' => _m('The import was not saved - it is not an import of this slice.'),
            'NO_SOURCE'    => _m('The import source is not available.'),
            'NO_MAPPING'   => _m('The import source did not give its mapping.'),
            'MAPPING'      => _m('The mapping of the import does not fit the slice: %1', [$detail]),
            'SETTINGS'     => _m('A setting of the import source is not valid.'),
            'SLICE'        => _m('The settings name a slice whose feeding you do not manage.'),
            default        => _m('The import source refused these settings.'),
        };
    }

    /** The one check of who may create, change or delete a definition and its settings and mapping.
     *  @param ?string $source the source the definition is to have; null = its own. Every source asks
     *                         the same right now - a stricter rule for some sources belongs here. */
    public function mayManage(string $uid, ?string $source = null): bool
    {
        return self::mayImportInto($uid, (string) $this->getOwnerId());
    }

    /** The one rule of imports: whoever manages the feeding of a slice imports into it - defines its
     *  imports and controls their runs. */
    public static function mayImportInto(string $uid, string $slice): bool
    {
        return is_long_id($slice) && CheckPerms($uid, 'slice', $slice, PS_FEEDING);
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
                return parent::save();
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
     * @throws BatchFailed FORBIDDEN when $uid may not manage the imports of the slice
     */
    public function deleteWithRuns(string $uid): void
    {
        $id    = (string) $this->getId();
        $slice = DB_AA::transaction(static function () use ($id, $uid): ?string {
            $def = self::fresh($id, true);
            if ($def === null) {
                return null;
            }
            // decided by the stored slice under the lock, not by the page that asks
            if (!$def->mayManage($uid)) {
                throw new BatchFailed('FORBIDDEN');
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
