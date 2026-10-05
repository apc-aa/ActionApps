<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Import\Catalog;
use AA\Import\Credential\Store;
use AA\Import\Definition;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\Toexecute;
use AA_Log;

/** Imports run in queue steps nobody watches, so a run stopped by an error or hanging, a definition
 *  that cannot run and the records a deleted import left behind are seen only here. Reads the small
 *  properties of the import objects in two index reads - never the stream versions or the catalog
 *  entries, which may be megabytes per import (CheckImportStores reads those) - and runs no code of
 *  a source. */
class CheckImports extends AbstractOptimize
{
    /** how far back a failed check() and logged failures count */
    private const WINDOW = 7 * 86400;
    private const LOGGED_ROWS = 5;
    private const CLASSES = [Definition::class, Run::class, StreamState::class, Catalog::class];
    /** what is read of each import object - small values only */
    private const PROPS = ['aa_type', 'aa_owner', 'aa_name', 'source', 'enabled', 'subject', 'state', 'mode', 'dry', 'started', 'finished', 'error_code', 'counts', 'check', 'time'];

    public static function name(): string
    {
        return _m('Imports');
    }

    public static function description(): string
    {
        return _m('Lists imports stopped by an error or not moving on, imports that cannot run, damaged keys, failures in the log and records left by deleted imports.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'feeds';
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    public function findings(): array
    {
        return self::findingsAt(time());
    }

    /**
     * @param ?array<string, mixed> $constants the defined constants to look for damaged keys in; null = the user's
     * @return Finding[]
     */
    public static function findingsAt(int $now, ?array $constants = null): array
    {
        $o        = self::objects();
        $findings = [
            ...self::runFindings($o, $now),
            ...self::definitionFindings($o, $now),
            ...self::orphanFindings($o, $now),
            ...self::keyFindings($constants ?? (get_defined_constants(true)['user'] ?? [])),
            ...self::loggedFindings($o['defs'], $now),
        ];
        if ($findings) {
            return $findings;
        }
        return [new Finding(Finding::OK, $o['defs'] ? _m('%1 imports, none needs attention', [count($o['defs'])]) : _m('No import is defined'))];
    }

    /** Deletes what deleted imports left - runs, unfinished ones too, stream states, catalogs, queued
     *  steps - as deleting the import does, each after its definition is found missing under the lock. */
    public function repair(): bool
    {
        $removed = ['runs' => 0, 'records' => 0];
        foreach (array_keys(self::orphans(self::objects())) as $def) {
            $done = DB_AA::transaction(static fn(): array => (Definition::fresh((string) $def, true) === null) ? Definition::deleteRecordsOf((string) $def) : ['runs' => 0, 'records' => 0]);
            $removed['runs']    += $done['runs'];
            $removed['records'] += $done['records'];
        }
        $this->message(_m('Removed %1 runs and %2 stream states and catalogs of deleted imports.', [$removed['runs'], $removed['records']]));
        return true;
    }

    /**
     * The import objects by their aa_type, then their small properties by object_id - two index reads -
     * and the rows at the ids where each definition's stream state and catalog belong.
     * @return array{defs: array<string, array<string, string>>, runs: array<string, array<string, string>>,
     *               owned: array<string, array<string, string>>, at: array<string, array<string, string>>}
     */
    private static function objects(): array
    {
        $o     = ['defs' => [], 'runs' => [], 'owned' => [], 'at' => []];
        $types = DB_AA::select(['object_id' => 'value'], 'SELECT object_id, value FROM `object_text`', [['property', 'aa_type'], ['value', self::CLASSES]]);
        if (!$types) {
            return $o;
        }
        $probe = [];
        foreach ($types as $id => $type) {
            if (($type === Definition::class) && self::isId((string) $id)) {
                array_push($probe, StreamState::idOf((string) $id), Catalog::idOf((string) $id));
            }
        }
        $rows = [];
        $ids  = array_values(array_unique([...array_map('strval', array_keys($types)), ...$probe]));
        foreach (DB_AA::select([], 'SELECT object_id, property, value FROM `object_text`', [['object_id', $ids], ['property', self::PROPS]]) as $row) {
            $rows[(string) $row['object_id']][(string) $row['property']] = (string) $row['value'];
        }
        foreach ($rows as $id => $r) {
            $type = $r['aa_type'] ?? '';
            if (($type === Definition::class) && self::isId((string) $id)) {
                $o['defs'][(string) $id] = $r;
            } elseif ($type === Run::class) {
                $o['runs'][(string) $id] = $r;
            } elseif (in_array($type, [StreamState::class, Catalog::class], true)) {
                $o['owned'][(string) $id] = $r;
            }
        }
        foreach ($probe as $id) {
            $o['at'][$id] = array_intersect_key($rows[$id] ?? [], ['aa_type' => 1, 'aa_owner' => 1, 'check' => 1, 'time' => 1]);
        }
        return $o;
    }

    /** @return Finding[] runs of existing imports stopped by an error or hanging */
    private static function runFindings(array $o, int $now): array
    {
        $findings = [];
        foreach ($o['runs'] as $id => $r) {
            $def = $r['subject'] ?? '';
            if (!isset($o['defs'][$def])) {
                continue;
            }
            $about   = self::about($o['defs'][$def], $def);
            $started = (int) ($r['started'] ?? 0);
            if (($r['state'] ?? '') === Run::ERROR) {
                // only the code: the place names stream and job keys of the source - the import detail shows it
                $findings[] = new Finding(Finding::ERROR, _m('An import stopped by an error'), since: $started, evidence: [$about + [_m('Error') => safe($r['error_code'] ?? ''), _m('Started') => self::date($started)]], advice: _m('Nothing more is imported until someone acts or a planned task starts the import again (that ends this run). Open the import, see the error, fix its cause and choose Continue - or End, which keeps what was imported.'), links: self::detail($o['defs'][$def], $def), key: "error:$id");
            } elseif ((($r['state'] ?? '') === Run::RUNNING) && ($run = Run::get((string) $id)) && $run->isStuck($now)) {
                // a running run is loaded whole for isStuck() - one per import at most
                $idle       = $run->idleSince();
                $findings[] = new Finding(Finding::WARNING, _m('An import has not moved on for %1 hours', [intdiv($now - $idle, 3600)]), since: $idle, evidence: [$about + [_m('Started') => self::date($started), _m('Idle since') => self::date($idle)]], advice: _m('No queue step works on it. Check that cron runs the task queue and that its rounds are long enough for a step (TOEXECUTE_ALLOWED_TIME in config.php), then choose Continue on the import. A planned start of the import ends it and starts a new run.'), links: self::detail($o['defs'][$def], $def), key: "stuck:$id");
            }
        }
        return $findings;
    }

    /** @return Finding[] */
    private static function definitionFindings(array $o, int $now): array
    {
        $defs = $o['defs'];
        if (!$defs) {
            return [];
        }
        $findings = [];
        try {
            BatchRun::requireTransactional([]);
        } catch (BatchFailed $e) {
            $findings[] = new Finding(Finding::ERROR, _m('Imports cannot run: a table does not support transactions'), evidence: [[_m('Table') => safe($e->where)]], advice: _m('Every run is refused. Convert the table to InnoDB or ask the server administrator.'), key: 'engine');
        }
        $sources = Registry::sources();
        $unknown = array_filter($defs, static fn(array $r): bool => !isset($sources[$r['source'] ?? '']));
        if ($unknown) {
            $evidence = array_map(static fn(array $r): array => [_m('Import') => safe($r['aa_name'] ?? ''), _m('Source') => safe($r['source'] ?? ''), _m('Why') => _m('not found')], array_values($unknown));
            foreach (Registry::rejected() as $what => $why) {
                $evidence[] = [_m('Import') => '', _m('Source') => safe((string) $what), _m('Why') => safe($why)];
            }
            $findings[] = new Finding(Finding::ERROR, _m('%1 imports have a source that is not available', [count($unknown)]), count: count($unknown), evidence: $evidence, advice: _m('A run of such an import stops in error at once. Fix or put back the source file, or delete the import.'), key: 'source');
        }

        $planned = self::planned($defs);
        $runsOf  = [];
        foreach ($o['runs'] as $id => $r) {
            $runsOf[$r['subject'] ?? ''][] = (string) $id;
        }
        $named = [];
        foreach ($defs as $id => $r) {
            $id    = (string) $id;
            $about = self::about($r, $id);
            $links = self::detail($r, $id);
            if (($r['aa_name'] ?? '') !== '') {
                $named[($r['aa_owner'] ?? '') . ':' . Definition::nameKey($r['aa_name'])][] = $id;
            }
            if (StreamState::foreignAt(self::held($o, StreamState::idOf($id)), $id) || Catalog::foreignAt(self::held($o, Catalog::idOf($id)), $id)) {
                $findings[] = new Finding(Finding::ERROR, _m('Another record holds the place of the stream state or catalog of an import'), evidence: [$about], advice: _m('The end of each step of its runs fails on it, and deleting the import leaves it. Delete the object at the id of its stream state or catalog.'), links: $links, key: "foreign:$id");
            }
            $findings = [...$findings, ...self::stateFindings($o, $id, $about, $links, $runsOf[$id] ?? [], $now)];
            if (($last = self::lastFinished($o, $runsOf[$id] ?? [])) !== null) {
                $errors = Run::countsOf($o['runs'][$last]['counts'] ?? '')['errors'];
                if ($errors > 0) {
                    $finished   = (int) ($o['runs'][$last]['finished'] ?? 0);
                    $findings[] = new Finding(Finding::INFO, _m('The last run of an import had %1 item errors', [$errors]), count: $errors, since: $finished, evidence: [$about + [_m('Finished') => self::date($finished)]], advice: _m('The other items were stored. The import detail lists the first errors; fix the data or the mapping and run the import again.'), links: $links, key: "items:$id", last: $finished);
                }
            }
            if ((($r['enabled'] ?? '') === '1') && !isset($planned[$id])) {
                $findings[] = new Finding(Finding::INFO, _m('An enabled import has no planned task'), evidence: [$about], advice: _m('No planned task of the slice calls {import} with its name or id, so it runs only by hand. Plan a task, or switch Enabled off.'), links: $links, key: "plan:$id");
            }
        }
        foreach ($named as $key => $ids) {
            if (count($ids) > 1) {
                $findings[] = new Finding(Finding::WARNING, _m('%1 imports of one slice have the same name', [count($ids)]), count: count($ids), evidence: array_map(static fn(string $id): array => self::about($defs[$id], $id) + [_m('Id') => $id], $ids), advice: _m('{import} cannot tell them apart and refuses the name, so a planned task with it fails. Rename all but one.'), links: self::detail($defs[$ids[0]], $ids[0]), key: 'names:' . md5($key));
            }
        }
        return $findings;
    }

    /** @param list<string> $runs of definition $id
     *  @return Finding[] the last check() of the source and the catalog of definition $id */
    private static function stateFindings(array $o, string $id, array $about, array $links, array $runs, int $now): array
    {
        $findings = [];
        $state    = $o['at'][StreamState::idOf($id)] ?? [];
        if (StreamState::isOwn($state, $id)) {
            $check = StreamState::checkOf($state['check'] ?? '', $damaged);
            if ($damaged) {
                $findings[] = new Finding(Finding::WARNING, _m('The last check of an import source cannot be read'), evidence: [$about], advice: _m('Its stored result is damaged. The next check of the source stores it again.'), links: $links, key: "checkdata:$id");
            } elseif ($check && !$check['ok'] && ($check['time'] >= $now - self::WINDOW)) {
                // the code only: the message is the source's
                $findings[] = new Finding(Finding::WARNING, _m('The last check of an import source failed'), since: $check['time'], evidence: [$about + [_m('Checked') => self::date($check['time']), _m('Code') => safe($check['code'])]], advice: _m('A live run stops in error when the source fails its check. The import detail shows what the source said; fix the key or the settings and check the source again.'), links: $links, key: "check:$id", last: $check['time']);
            } elseif ($check && $check['ok'] && ($check['code'] !== '') && ($check['time'] >= $now - self::WINDOW)) {
                $findings[] = new Finding(Finding::INFO, _m('The last check of an import source passed with a remark'), since: $check['time'], evidence: [$about + [_m('Checked') => self::date($check['time']), _m('Code') => safe($check['code'])]], advice: _m('The import runs, but the source points at something to fix - for example definitions it leaves out. The import detail shows what the source said.'), links: $links, key: "checknote:$id", last: $check['time']);
            }
        }
        $catalog  = $o['at'][Catalog::idOf($id)] ?? [];
        $checking = array_filter($runs, static fn(string $run): bool => in_array($o['runs'][$run]['state'] ?? '', [Run::RUNNING, Run::ERROR], true) && (($o['runs'][$run]['mode'] ?? '') === 'check'));
        if (Catalog::isOwn($catalog, $id) && (($catalog['time'] ?? '') === '') && !$checking) {
            $findings[] = new Finding(Finding::INFO, _m('The catalog of an import source is incomplete'), evidence: [$about], advice: _m('The last check did not finish listing the source, so the import detail cannot tell which streams the source no longer has. Check the source again.'), links: $links, key: "catalog:$id");
        }
        return $findings;
    }

    /** @return Finding[] what deleted imports left - deleting a slice deletes its imports, not their records */
    private static function orphanFindings(array $o, int $now): array
    {
        $orphans = self::orphans($o);
        $count   = count(array_merge(...array_values($orphans)));
        if ($count === 0) {
            return [];
        }
        $working = array_filter(array_keys($orphans), static fn($def): bool => (bool) Run::leasedOf((string) $def, $now));
        return [new Finding(
            $working ? Finding::WARNING : Finding::INFO,
            _m('%1 records of deleted imports are left: runs, stream states, catalogs', [$count]),
            count: $count,
            advice: $working ? _m('A step of a deleted import is still working and may store items until its time runs out. Repair deletes the records; the step then writes nothing back.') : _m('Nothing uses them and nothing else removes them. Repair deletes them with their queued steps.'),
            repairable: true,
            key: 'orphans',
        )];
    }

    /** @param array<string, mixed> $constants
     *  @return Finding[] key constants a source cannot send */
    private static function keyFindings(array $constants): array
    {
        return array_map(static fn(string $name): Finding => new Finding(Finding::ERROR, _m('A key in config.php is damaged'), evidence: [[_m('Constant') => safe($name)]], advice: _m('The key holds a line break or NUL, so no import source may use it. Fix the constant in config.php.'), key: "key:$name"), Store::damagedIn($constants));
    }

    /** @return Finding[] failures of import steps and of the imports outside a step, from the log */
    private static function loggedFindings(array $defs, int $now): array
    {
        $where = [['type', [Toexecute::FAILED_EVENT, Definition::ERROR_EVENT]], ['time', $now - self::WINDOW, '>='], ['selector', 'Import_', 'BEGIN']];
        $sum   = DB_AA::select1('', 'SELECT COUNT(*) AS cnt, MIN(`time`) AS first, MAX(`time`) AS last FROM `log`', $where);
        $count = (int) ($sum['cnt'] ?? 0);
        if ($count === 0) {
            return [];
        }
        $evidence = [];
        foreach (DB_AA::select([], 'SELECT `time`, `selector`, `params` FROM `log`', $where, ['time-'], null, self::LOGGED_ROWS) as $row) {
            $def        = substr((string) $row['selector'], strlen('Import_'));
            $evidence[] = [_m('Time') => self::date((int) $row['time']), _m('Import') => safe(($defs[$def]['aa_name'] ?? '') ?: $def), _m('Error') => safe((string) $row['params'])];
        }
        return [new Finding(
            Finding::WARNING,
            _m('Imports logged %1 failures in the last 7 days', [$count]),
            count: $count,
            since: (int) $sum['first'],
            evidence: $evidence,
            advice: _m('A failed queue step stops its import in error; the other rows are failures outside a step, such as refreshing cached pages after an import was deleted. Only the error class and place, or the code of a refusal, are logged - the message may hold a key.'),
            links: [_m('Failed steps') => AA_Log::getAdminUrl(Toexecute::FAILED_EVENT, 'Import_', 'RLIKE'), _m('Other failures') => AA_Log::getAdminUrl(Definition::ERROR_EVENT, 'Import_', 'RLIKE')],
            key: 'logged',
            last: (int) $sum['last'],
        )];
    }

    /** @return array<string, list<string>> id of a definition that is gone => ids of its runs, stream state and catalog */
    private static function orphans(array $o): array
    {
        $orphans = [];
        foreach ($o['runs'] + $o['owned'] as $id => $r) {
            $def = $r['subject'] ?? ($r['aa_owner'] ?? '');
            // Definition::deleteRecordsOf() takes only a definition id; anything else is no record of an import
            if (self::isId($def) && !isset($o['defs'][$def])) {
                $orphans[$def][] = (string) $id;
            }
        }
        return $orphans;
    }

    /** @param list<string> $runs  @return ?string the newest finished run that stored items - a check, a baseline or a dry run stores none */
    private static function lastFinished(array $o, array $runs): ?string
    {
        $last = null;
        $at   = [0, 0];
        foreach ($runs as $id) {
            $r = $o['runs'][$id];
            if (!in_array($r['state'] ?? '', [Run::DONE, Run::ENDED], true) || in_array($r['mode'] ?? '', Run::SOURCE_ONLY_MODES, true) || (($r['dry'] ?? '') === '1')) {
                continue;
            }
            $when = [(int) ($r['finished'] ?? 0), (int) ($r['started'] ?? 0)];
            if (($last === null) || ($when > $at)) {
                [$last, $at] = [$id, $when];
            }
        }
        return $last;
    }

    /** @return array<string, true> the enabled definitions a planned task of their slice calls by name or id */
    private static function planned(array $defs): array
    {
        $planned = [];
        foreach ($defs as $id => $r) {
            if ((($r['enabled'] ?? '') === '1') && Definition::isPlanned((string) ($r['aa_owner'] ?? ''), (string) $id, (string) ($r['aa_name'] ?? ''))) {
                $planned[(string) $id] = true;
            }
        }
        return $planned;
    }

    /** @return array<string, string> aa_type and aa_owner of the object at $id */
    private static function held(array $o, string $id): array
    {
        return array_intersect_key($o['at'][$id] ?? [], ['aa_type' => 1, 'aa_owner' => 1]);
    }

    private static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}\z/', $id) === 1;
    }

    /** @return array<string, string> the definition in the evidence, escaped */
    private static function about(array $r, string $id): array
    {
        return [_m('Import') => safe(($r['aa_name'] ?? '') ?: $id), _m('Slice') => safe($r['aa_owner'] ?? '')];
    }

    /** @return array<string, string> the import detail - its page decides who may open it */
    private static function detail(array $r, string $id): array
    {
        return [_m('Import detail') => AA_INSTAL_PATH . 'admin/se_dataimport.php?change_id=' . rawurlencode($r['aa_owner'] ?? '') . '&id=' . $id];
    }

    private static function date(int $time): string
    {
        return $time ? date('Y-m-d H:i', $time) : '';
    }
}
