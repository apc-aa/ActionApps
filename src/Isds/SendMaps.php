<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What the record of a sending keeps of each recipient, as maps of JSON index => value: the dmID
 * ISDS took a message under, the time a message of unknown fate was tried, the status ISDS refused
 * one with, why a box was skipped - and the message that may be on its way. A message in more than
 * one map counts by the first that says most: sent, then refused, then uncertain. Pure.
 */
final readonly class SendMaps
{
    /**
     * @param string $inflight   the index whose message may be on its way, '' = none
     * @param int    $inflightAt since when
     */
    public function __construct(
        public string $dmIds = '',
        public string $uncertain = '',
        public string $failed = '',
        public string $skips = '',
        public string $inflight = '',
        public int $inflightAt = 0,
    ) {}

    /** $map with $value of $index; a value it has stays - the first attempt counts */
    public static function with(string $map, int $index, int|string $value): string
    {
        $entries = self::entries($map);
        $entries[$index] ??= $value;
        return json_encode($entries, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    /**
     * @return array<int, mixed>
     * @throws \JsonException a map edited by hand into no JSON
     */
    public static function entries(string $map): array
    {
        return ($map === '') ? [] : (array) json_decode($map, true, 2, JSON_THROW_ON_ERROR);
    }

    /**
     * The indexes the record has the fate of - sent, uncertain, refused, the box skipped - and the
     * one in flight
     * @return array<int, mixed>
     */
    public function decided(): array
    {
        $known = self::entries($this->dmIds) + self::entries($this->uncertain) + self::entries($this->failed) + self::entries($this->skips);
        if ($this->inflight !== '') {
            $known[(int) $this->inflight] = true;
        }
        return $known;
    }

    /**
     * The messages of unknown fate the record has no answer for: uncertain, or in flight - a step
     * that died sending left it so
     * @return array<int, int> index => the time it was tried
     */
    public function unsettled(): array
    {
        $open = self::entries($this->uncertain);
        if ($this->inflight !== '') {
            $open[(int) $this->inflight] ??= $this->inflightAt;
        }
        return array_map('intval', array_diff_key($open, self::entries($this->dmIds), self::entries($this->failed)));
    }

    /**
     * What became of each recipient
     * @param list<array<string, string>> $rows Snapshot::recipients()
     * @return array{sent: int, failed: int, uncertain: int, skipped: int, stopped: int}
     */
    public function tally(array $rows): array
    {
        $sent   = self::entries($this->dmIds);
        $failed = array_diff_key(self::entries($this->failed), $sent);
        $open   = $this->unsettled();
        $skips  = self::entries($this->skips);
        $tally  = ['sent' => count($sent), 'failed' => count($failed), 'uncertain' => count($open), 'skipped' => 0, 'stopped' => 0];
        foreach ($rows as $k => $row) {
            if (!isset($sent[$k]) && !isset($failed[$k]) && !isset($open[$k])) {
                // never tried: only a stop leaves one so
                ++$tally[(($row['reason'] !== '') || isset($skips[$k])) ? 'skipped' : 'stopped'];
            }
        }
        return $tally;
    }
}
