<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\IdMap;
use AA\Util\Crypt;
use AA\Util\Secret;
use AA_Object;
use AA_Property;
use AA\IO\DB\DB_AA;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/** One install or transfer of an app on this (the target) AA: what the preview chose, the id map of the
 *  written definitions and how far the item batches got. An AA_Object owned by AA_ID.
 *
 *  preview -> items (definitions written) -> items (batch) ... -> done
 *  preview -> done (definitions written, no items follow: an install, a transfer of no slice)
 *                                   items <-> error (Continue);  items | error -> ended (End)
 *
 *  Every property is text, so the whole record is rows of object_text: one locking read of them
 *  (lockForUpdate()) serializes the batches of a run. It needs object_text InnoDB - the import's engine
 *  check (Planner::tables(), ObjectExport::TABLES) refuses to run without it.
 *  Transitions work only on a locked instance inside a transaction; get() gives a read-only one. */
final class InstallRun extends AA_Object
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

    /** a preview nobody confirmed is forgotten after a day */
    private const PREVIEW_LIFETIME = 86400;
    /** the source's token lives a day (RemoteSource::TOKEN_TTL) - a run that did not move on keeps it no longer */
    private const TOKEN_LIFETIME = 86400;
    /** bytes of the path of an error - it may name source text */
    private const ERROR_PATH_BYTES = 255;

    /** the states a run can still go on from */
    public const UNFINISHED = [self::ITEMS, self::ERROR];

    protected $state = '';
    protected $mode = '';
    /** '1' = items of all bins, '' = active only */
    protected $all_bins = '';
    protected $source = '';
    protected $user = '';
    protected $started = '';
    /** unixtime the run last moved on (definitions, a batch, Continue) - State Check finds stuck runs */
    protected $moved = '';
    /** unixtime of the preview on the clock of the source; '' = the source is this AA (started) */
    protected $source_time = '';
    /** '1' = the source is this installation (its AA_ID) - by this AA or by a Central row naming it: the
     *  copy's items get new ids and the site's domains stay with the source */
    protected $same_aa = '';
    protected $id_map = '';
    /** sha256 of the definitions package - of the preview's until they are written */
    protected $fingerprint = '';
    /** JSON list of the source slice ids, in the order the batches go */
    protected $slices = '';
    /** position: the slice of the next batch and the last source item id written in it ('' = none yet) */
    protected $slice = '';
    protected $cursor = '';
    /** JSON slice => items written */
    protected $written = '';
    /** JSON slice => finding code => warnings of its batches */
    protected $warnings = '';
    protected $error_code = '';
    protected $error_path = '';
    /** the source's login token, encrypted */
    protected $token = '';
    /** JSON of the check when the transfer finished (TransferCheck), for the report */
    protected $report = '';
    /** JSON source slice => slice of this AA it goes into, as the preview chose; the write plans with it */
    protected $targets = '';

    /** read by lockForUpdate() - only such an instance may change the run */
    private bool $locked = false;

    public static function getClassProperties(): array
    {
        $props = [];
        foreach (['state', 'mode', 'all_bins', 'source', 'user', 'started', 'moved', 'source_time', 'same_aa', 'fingerprint', 'slice', 'cursor', 'error_code', 'error_path', 'token'] as $id) {
            $props[$id] = new AA_Property($id, $id, 'string', false, true, 'string');
        }
        foreach (['id_map', 'slices', 'written', 'warnings', 'report', 'targets'] as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    /** a new run in the preview state; forgotten previews go away on the way
     *  @param string $fingerprint sha256 of the definitions package the preview showed - the write compares it
     *  @param array<string, string> $targets source slice => slice of this AA it goes into */
    public static function preview(string $mode, bool $allBins, string $source, string $user, string $token, int $now, string $fingerprint = '', array $targets = []): self
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
        self::deleteOldPreviews($now);
        self::forgetOldTokens($now);
        $run = new self();
        $run->setOwnerId((string) constant('AA_ID'));
        $run->state       = self::PREVIEW;
        $run->mode        = $mode;
        $run->all_bins    = $allBins ? '1' : '';
        $run->source      = $source;
        $run->user        = $user;
        $run->started     = (string) $now;
        $run->same_aa     = ($source === self::LOCAL) ? '1' : '';       // another AA says it at the preview (previewed())
        $run->fingerprint = $fingerprint;
        $run->token       = ($token === '') ? '' : Crypt::encryptAesGcm($token, Secret::key('install-run'));
        $run->targets     = self::targetsJson($targets);
        $run->save();
        return $run;
    }

    /** the run, or null when $id is no install run */
    public static function get(string $id): ?self
    {
        return self::read($id, false);
    }

    /** the runs that can still go on (items, error), read-only
     *  @return list<self> */
    public static function unfinished(): array
    {
        $ids  = DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'state'], ['value', self::UNFINISHED]]);
        $runs = array_map(self::get(...), array_values(array_unique(array_map('strval', $ids))));
        return array_values(array_filter($runs));      // another class may have such a state too
    }

    /** get() with SELECT ... FOR UPDATE on the record's rows: call it inside the caller's
     *  DB_AA::transaction() - the lock holds until it commits, so two batches cannot both pass at().
     *  A waiting reader resumes at the aa_owner row (first in the index), which update() never rewrites,
     *  so it goes on to read every row the other transaction re-inserted. */
    public static function lockForUpdate(string $id): ?self
    {
        $run = self::read($id, true);
        if ($run) {
            $run->locked = true;
        }
        return $run;
    }

    /** Forgets the source's token of the runs that did not move on for a day: it has expired then (a token
     *  renewed by Continue moves the run on), and a live transfer moves on with every batch. */
    public static function forgetOldTokens(int $now): void
    {
        $states = [self::PREVIEW, ...self::UNFINISHED];
        $ids    = array_values(array_unique(array_map('strval', DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'state'], ['value', $states]]))));
        if (!$ids) {
            return;
        }
        $props = [];            // one query for all of them, not a read per run
        foreach (DB_AA::select([], 'SELECT object_id, property, value FROM `object_text`', [['object_id', $ids], ['property', ['aa_type', 'token', 'moved', 'started']]]) as $row) {
            $props[$row['object_id']][$row['property']] = (string) $row['value'];
        }
        $old = static fn(array $p): bool => (($p['aa_type'] ?? '') === self::class) && (($p['token'] ?? '') !== '')
            && ((int) (($p['moved'] ?? '') ?: ($p['started'] ?? 0)) <= $now - self::TOKEN_LIFETIME);
        foreach (array_keys(array_filter($props, $old)) as $id) {
            DB_AA::transaction(static function () use ($id, $now, $states): void {
                $run = self::lockForUpdate((string) $id);
                if ($run && in_array($run->state, $states, true) && ($run->moved() <= $now - self::TOKEN_LIFETIME)) {
                    $run->token = '';
                    $run->update(['token']);
                }
            });
        }
    }

    /** Deletes previews started a day or more before $now. Other classes may have a state "preview" too,
     *  so the class is checked on the candidates. */
    private static function deleteOldPreviews(int $now): void
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
            AA_Object::deleteObjects($old);
        }
    }

    /** Call it inside the definitions transaction: the run reaches the items only with its definitions -
     *  or is done at once when none follow (it has no position a batch could wait for).
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
        $this->move([self::PREVIEW], $slices ? self::ITEMS : self::DONE);
        if (!$slices) {
            $this->token = '';
        }
        $this->id_map      = $map->toJson();
        $this->fingerprint = hash('sha256', $definitions);
        $this->slices      = json_encode($slices, JSON_THROW_ON_ERROR);
        $this->slice       = $slices[0] ?? '';
        $this->cursor      = '';
        $this->written     = json_encode((object) array_fill_keys($slices, 0), JSON_THROW_ON_ERROR);
        $this->warnings    = '{}';
        $this->moved       = (string) time();
        $this->update(['state', 'moved', 'id_map', 'fingerprint', 'slices', 'slice', 'cursor', 'written', 'warnings', 'token']);
    }

    /** The preview of a run opened at the login to another AA: the definitions package it showed, which
     *  the write compares. The run stays in preview.
     *  @param bool $sameAa the source reported the AA_ID of this AA (AppSource::sameAa())
     *  @param array<string, string> $targets source slice => slice of this AA it goes into */
    public function previewed(string $fingerprint, int $sourceNow, bool $sameAa, array $targets = []): void
    {
        $this->move([self::PREVIEW], self::PREVIEW);
        $this->fingerprint = $fingerprint;
        $this->source_time = (string) $sourceNow;
        $this->same_aa     = $sameAa ? '1' : '';
        $this->targets     = self::targetsJson($targets);
        $this->update(['fingerprint', 'source_time', 'same_aa', 'targets']);
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

    /** The batch read from $expected may be written: the run is in items and still at that position.
     *  False = a second tab, a double click or a retry got there first. Ask on a lockForUpdate() instance.
     *  @param array{string, string} $expected [slice, cursor] */
    public function at(array $expected): bool
    {
        return ($this->state === self::ITEMS) && ($expected === $this->position());
    }

    /** Moves the position after the batch read from $expected was written; a position other than
     *  $expected throws, so the transaction rolls the batch back.
     *  @param array{string, string} $expected [slice, cursor] the batch was read from - its counts belong to that slice
     *  @param array{string, string} $new      [slice, cursor] of the next batch - the same or a later slice
     *  @param int $written  items this batch wrote
     *  @param array<string, int> $warnings finding code => warnings of the batch */
    public function advance(array $expected, array $new, int $written, array $warnings): void
    {
        $this->move([self::ITEMS], self::ITEMS);
        $order = array_flip($this->slices());
        if (!isset($order[$new[0]]) || ($order[$new[0]] < ($order[$this->slice] ?? 0)) || !preg_match('/^([0-9a-f]{32})?\z/', $new[1]) || ($written < 0)
            || array_filter($warnings, static fn(int $n, int|string $code): bool => ($n < 1) || !preg_match('/^[a-z0-9-]{1,64}\z/', (string) $code), ARRAY_FILTER_USE_BOTH)) {
            throw new InvalidArgumentException('The new position or counts are outside the run');
        }
        if (!$this->at($expected)) {
            throw new LogicException('The install run is not at the position of the batch');
        }
        $counts                = $this->written();
        $counts[$expected[0]] += $written;
        $all                   = $this->warnings();
        foreach ($warnings as $code => $n) {
            $all[$expected[0]][$code] = ($all[$expected[0]][$code] ?? 0) + $n;
        }
        [$this->slice, $this->cursor] = $new;
        $this->written   = json_encode($counts, JSON_THROW_ON_ERROR);
        $this->warnings  = json_encode((object) $all, JSON_THROW_ON_ERROR);
        $this->moved     = (string) time();
        $this->update(['slice', 'cursor', 'written', 'warnings', 'moved']);
    }

    /** a batch failed: $code a finding / error code, $path where - never the raw DB message, which
     *  would show table and column names on the progress page */
    public function fail(string $code, string $path): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}\z/', $code)) {
            throw new InvalidArgumentException('An error code is an upper-case identifier');
        }
        $this->move([self::ITEMS], self::ERROR);
        $this->error_code = $code;
        $this->error_path = mb_strcut($path, 0, self::ERROR_PATH_BYTES);
        $this->update(['state', 'error_code', 'error_path']);
    }

    /** Continue after an error - from the stored position
     *  @param string $token a new login token of the source, when it refused the old one; '' = keep it */
    public function resume(string $token = ''): void
    {
        $this->move([self::ERROR], self::ITEMS);
        $this->moved = (string) time();
        if ($token !== '') {
            $this->token = Crypt::encryptAesGcm($token, Secret::key('install-run'));
        }
        $this->update(['state', 'moved', ...(($token === '') ? [] : ['token'])]);
    }

    /** @param array<string, mixed> $report the check of the finished transfer (TransferCheck), plain data */
    public function finish(array $report = []): void
    {
        $this->move([self::ITEMS], self::DONE);
        $this->token  = '';
        $this->report = $report ? json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) : '';
        $this->update(['state', 'token', 'report']);
    }

    /** End: what is written stays, the rest is not transferred */
    public function end(): void
    {
        $this->move([self::ITEMS, self::ERROR], self::ENDED);
        $this->token = '';
        $this->update(['state', 'token']);
    }

    /** the source's login token; '' when there is none (a local source, a finished run) */
    public function token(): string
    {
        if ($this->token === '') {
            return '';
        }
        $token = Crypt::decryptAesGcm($this->token, Secret::key('install-run'));
        if (!is_string($token)) {
            throw new RuntimeException('The source token cannot be decrypted - AA_SECRET changed? Log in to the source again.');
        }
        return $token;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function allBins(): bool
    {
        return $this->all_bins === '1';
    }

    public function source(): string
    {
        return $this->source;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function started(): int
    {
        return (int) $this->started;
    }

    /** the source is this installation, whatever the transport - see $same_aa */
    public function sameAa(): bool
    {
        return $this->same_aa === '1';
    }

    /** the start of the run on the clock of the source - items it edited after it may have missed their batch */
    public function sourceStarted(): int
    {
        return ($this->source_time === '') ? $this->started() : (int) $this->source_time;
    }

    /** unixtime of the last move on; the start before the definitions */
    public function moved(): int
    {
        return (int) ($this->moved ?: $this->started);
    }

    public function idMap(): IdMap
    {
        return IdMap::fromJson($this->id_map);
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** the write reads the source again; the same package from what now is (or is not) this AA would
     *  keep (or renew) other item ids than the preview checked; a write form of an older preview names
     *  other targets than the run keeps
     *  @param array<string, string> $targets the targets the write form sends */
    public function matchesPreview(string $definitions, bool $sameAa, array $targets): bool
    {
        return hash_equals($this->fingerprint, hash('sha256', $definitions)) && ($this->sameAa() === $sameAa) && $this->chose($targets);
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

    /** @return string[] */
    public function slices(): array
    {
        return ($this->slices === '') ? [] : json_decode($this->slices, true, 2, JSON_THROW_ON_ERROR);
    }

    /** @return array{string, string} [slice, cursor] */
    public function position(): array
    {
        return [$this->slice, $this->cursor];
    }

    /** @return array<string, int> slice => items written */
    public function written(): array
    {
        return ($this->written === '') ? [] : json_decode($this->written, true, 2, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, int>> slice => finding code => warnings of its batches */
    public function warnings(): array
    {
        return ($this->warnings === '') ? [] : json_decode($this->warnings, true, 3, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> source slice => slice of this AA it goes into */
    public function targets(): array
    {
        $targets = ($this->targets === '') ? [] : json_decode($this->targets, true, 2, JSON_THROW_ON_ERROR);
        return array_map('strval', array_combine(array_map('strval', array_keys($targets)), $targets));
    }

    /** @return array<string, mixed> finish()'s report; [] = none */
    public function report(): array
    {
        return ($this->report === '') ? [] : json_decode($this->report, true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array{string, string}|null [code, path] of the last error */
    public function error(): ?array
    {
        return ($this->error_code === '') ? null : [$this->error_code, $this->error_path];
    }

    /** @param string[] $from */
    private function move(array $from, string $to): void
    {
        if (!$this->locked || !DB_AA::inTransaction()) {
            throw new LogicException('An install run changes only after lockForUpdate() inside a transaction');
        }
        if (!in_array($this->state, $from, true)) {
            throw new LogicException("An install run cannot go from $this->state to $to");
        }
        $this->state = $to;
    }

    /** AA_Object::load() caches per request - a batch must see the rows as they are now */
    private static function read(string $id, bool $lock): ?self
    {
        if (!preg_match('/^[0-9a-f]{32}\z/', $id)) {
            return null;
        }
        $rows = DB_AA::select(['property' => 'value'], 'SELECT property, value FROM `object_text` ' . DB_AA::makeWhere([['object_id', $id]]) . ($lock ? ' FOR UPDATE' : ''));
        if (($rows['aa_type'] ?? '') !== self::class) {
            return null;
        }
        $run = new self();
        $run->setId($id);
        $run->setOwnerId($rows['aa_owner'] ?? '');
        foreach (array_keys(self::getClassProperties()) as $property) {
            $run->$property = (string) ($rows[$property] ?? '');
        }
        return $run;
    }
}
