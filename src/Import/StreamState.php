<?php

declare(strict_types=1);

namespace AA\Import;

use AA_Object;
use AA_Property;
use InvalidArgumentException;

/**
 * What the imports of one definition know of its streams - the data version each got to, when it
 * was probed and imported, by which run - and the last check(). One record per definition, owned by
 * it; a run changes it only in the transaction that ends a step, with its cursor.
 * Versions and messages come from the source: stored as clean UTF-8, cut to a length.
 */
final class StreamState extends AA_Object
{
    use OwnedByDefinition;

    /** a cut version differs from the source's own, so the stream only imports again */
    private const MAX_VERSION = 255;
    private const MAX_MESSAGE = 1000;
    /** {key: {field: value}} */
    private const STREAMS_DEPTH = 2;
    /** field => what it holds */
    private const FIELDS = ['v' => 'text', 'probed' => 'time', 'imported' => 'time', 'run' => 'id'];

    /** JSON {<stream key>: {"v": version, "probed": unixtime, "imported": unixtime, "run": run id}} */
    protected $streams = '';
    /** JSON {"time", "ok", "code", "msg"} of the last check() */
    protected $check = '';

    /** @var array<array-key, array<string, string|int>>|null $streams decoded once, encoded once in store() */
    private ?array $decoded = null;

    public static function getClassProperties(): array
    {
        return [
            'streams' => new AA_Property('streams', 'streams', 'text', false, true, 'text'),
            'check'   => new AA_Property('check', 'check', 'text', false, true, 'text'),
        ];
    }

    protected static function ownProperties(): array
    {
        return ['streams', 'check'];
    }

    /** null = no version recorded for the stream; '' = the source has no versions */
    public function versionOf(string $streamKey): ?string
    {
        $version = $this->streams()[$streamKey]['v'] ?? null;
        return is_string($version) ? $version : null;
    }

    /** @return array<array-key, array<string, string|int>> stream key => v, probed, imported, run */
    public function streams(): array
    {
        if ($this->decoded === null) {
            $this->decoded = array_filter($this->decode((string) $this->streams, self::STREAMS_DEPTH), 'is_array');
        }
        return $this->decoded;
    }

    /** Sets the given fields of a stream, keeps the others.
     *  @param array<string, mixed> $values of v (version), probed, imported (unixtimes), run (its id) */
    public function record(string $streamKey, array $values): void
    {
        if (preg_match(Stream::KEY, $streamKey) !== 1) {
            throw new InvalidArgumentException('Stream key must match ' . Stream::KEY);
        }
        $streams = $this->streams();
        $entry   = $streams[$streamKey] ?? [];
        foreach ($values as $field => $value) {
            $entry[$field] = match (self::FIELDS[$field] ?? '') {
                'text'  => is_string($value) ? self::cleanVersion($value) : throw new InvalidArgumentException('A version is a string'),
                'time'  => (is_int($value) && ($value >= 0)) ? $value : throw new InvalidArgumentException("Stream $field is a unixtime"),
                'id'    => (is_string($value) && (preg_match('/^[0-9a-f]{32}\z/', $value) === 1)) ? $value : throw new InvalidArgumentException('A run id is a long id'),
                default => throw new InvalidArgumentException("Stream state has no field $field"),
            };
        }
        $streams[$streamKey] = $entry;
        $this->decoded       = $streams;
    }

    /** a version as it is stored: valid UTF-8, clean, cut - a run keeps it in its cursor in the same
     *  form, so a version compares equal from step to step */
    public static function cleanVersion(string $version): string
    {
        return self::clean($version, self::MAX_VERSION);
    }

    /** Stores $r->message as given: the caller (Run) has redacted it with Context::redact() - the
     *  source may echo a key in it. */
    public function recordCheck(CheckResult $r, int $time): void
    {
        $this->check = self::encode(['time' => $time, 'ok' => $r->ok, 'code' => $r->code, 'msg' => self::clean($r->message, self::MAX_MESSAGE)], 1);
    }

    /** @return array{time: int, ok: bool, code: string, msg: string}|null null = no check() yet */
    public function lastCheck(): ?array
    {
        $check         = self::checkOf((string) $this->check, $damaged);
        $this->damaged = $this->damaged || $damaged;
        return $check;
    }

    /** The check() a stored `check` value holds - read without the record, as State Check reads it.
     *  @param-out bool $damaged the value cannot be read (and null is returned)
     *  @return array{time: int, ok: bool, code: string, msg: string}|null null = no check() yet */
    public static function checkOf(string $json, ?bool &$damaged = null): ?array
    {
        $check   = self::decoded($json, 1);
        $damaged = ($check === null);
        if (!$check) {
            return null;
        }
        return ['time' => (int) ($check['time'] ?? 0), 'ok' => (bool) ($check['ok'] ?? false), 'code' => (string) ($check['code'] ?? ''), 'msg' => (string) ($check['msg'] ?? '')];
    }

    /** see storeOwned() for the lock the caller holds */
    public function store(): void
    {
        if ($this->decoded !== null) {
            $this->streams = self::encode($this->decoded, self::STREAMS_DEPTH);
        }
        $this->storeOwned();
    }
}
