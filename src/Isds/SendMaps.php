<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What the record of a sending keeps of each recipient, as maps of JSON index => value: the dmID
 * ISDS took a message under, the time a message of unknown fate was tried, the status ISDS refused
 * one with, why a box was skipped, the message that may be on its way - and the delivery of each
 * message sent. A message in more than one map counts by the first that says most: sent, then
 * refused, then uncertain; one sent that the antivirus check refused or that is undeliverable
 * (StatusSync::FAILED) counts as failed. Pure.
 */
final readonly class SendMaps
{
    /** dmMessageStatus of a message that reached its recipient's box: 7, 9 and 10 come only after 4 */
    private const DELIVERED = [4, 5, 6, 7, 9, 10];

    /** dmMessageStatus that tells the acceptance itself; 9 and 10 (deleted, in the vault) do not */
    private const ACCEPTED = [5, 6, 7];

    /**
     * @param string $inflight   the index whose message may be on its way, '' = none
     * @param int    $inflightAt since when
     * @param string $dmState    index => the delivery of the message, an entry of StatusSync::entry()
     */
    public function __construct(
        public string $dmIds = '',
        public string $uncertain = '',
        public string $failed = '',
        public string $skips = '',
        public string $inflight = '',
        public int $inflightAt = 0,
        public string $dmState = '',
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
     * The state the item of each recipient the record has the fate of must show, by the map that says
     * most; a message of unknown fate - in flight too - is uncertain
     * @return array<array-key, string> index => Schema::MESSAGE_STATE_*
     */
    public function fates(): array
    {
        $failed = $this->failedAfterSend();
        $fates  = [];
        foreach ([
            Schema::MESSAGE_STATE_SENT      => array_diff_key(self::entries($this->dmIds), $failed),
            Schema::MESSAGE_STATE_FAILED    => $failed + self::entries($this->failed),
            Schema::MESSAGE_STATE_UNCERTAIN => $this->unsettled(),
            Schema::MESSAGE_STATE_SKIPPED   => self::entries($this->skips),
        ] as $state => $map) {
            $fates += array_fill_keys(array_keys($map), $state);
        }
        return $fates;
    }

    /**
     * The code each failed message failed with: what ISDS refused it with when it was sent, notfound
     * - or, sent and then refused by the antivirus check or undeliverable, its dmMessageStatus
     * @return array<int, string> index => code
     */
    public function failures(): array
    {
        $refused = array_diff_key(self::entries($this->failed), self::entries($this->dmIds));
        return array_map(strval(...), $refused + $this->failedAfterSend());
    }

    /**
     * How many messages sent reached the recipient's box, and how many of those were accepted - by a
     * login, or 10 days after delivery. One refused by the antivirus check or undeliverable counts in
     * neither, an acceptance it had too; dm_state edited into no JSON is no delivery known.
     * @return array{delivered: int, accepted: int}
     * @throws \JsonException dm_ids edited into no JSON
     */
    public function delivery(): array
    {
        $reached = $this->reached();

        return ['delivered' => count($reached), 'accepted' => count(array_filter($reached))];
    }

    /**
     * The messages sent that reached the recipient's box, as delivery() counts them
     * @return array<int, bool> index => accepted
     * @throws \JsonException dm_ids edited into no JSON
     */
    public function reached(): array
    {
        $reached = [];
        foreach ($this->states() as $k => $e) {
            // StatusSync::FAILED is none of DELIVERED; an acceptance counts only of a message delivered
            if (!is_null($e) && in_array($e['s'], self::DELIVERED, true)) {
                $reached[$k] = ($e['a'] > 0) || in_array($e['s'], self::ACCEPTED, true);
            }
        }
        return $reached;
    }

    /**
     * What became of each recipient: the fate the record has (fates()) - of an index it names beyond
     * the recipients too, so that every index counts once -, else skipped for its frozen reason; one
     * never tried is stopped once nothing sends it any more ($over), queued till then. The one reading
     * of a fate the page, the end of the run and a copy of the unsent share.
     * @param list<array<string, string>> $rows Snapshot::recipients()
     * @return array<int, string> index => Schema::MESSAGE_STATE_SENT, FAILED, UNCERTAIN, SKIPPED, STOPPED or QUEUED
     */
    public function fatesOf(array $rows, bool $over): array
    {
        $fates = $this->fates();
        foreach ($rows as $k => $row) {
            $fates[$k] ??= match (true) {
                $row['reason'] !== '' => Schema::MESSAGE_STATE_SKIPPED,
                $over                 => Schema::MESSAGE_STATE_STOPPED,
                default               => Schema::MESSAGE_STATE_QUEUED,
            };
        }
        ksort($fates);

        return $fates;
    }

    /**
     * How many recipients have each fate (fatesOf()); the end of the run counts with $over
     * @param list<array<string, string>> $rows Snapshot::recipients()
     * @return array{sent: int, failed: int, uncertain: int, skipped: int, stopped: int, queued: int}
     */
    public function tally(array $rows, bool $over = true): array
    {
        $counts = array_count_values($this->fatesOf($rows, $over));

        return [
            'sent'      => $counts[Schema::MESSAGE_STATE_SENT] ?? 0,
            'failed'    => $counts[Schema::MESSAGE_STATE_FAILED] ?? 0,
            'uncertain' => $counts[Schema::MESSAGE_STATE_UNCERTAIN] ?? 0,
            'skipped'   => $counts[Schema::MESSAGE_STATE_SKIPPED] ?? 0,
            'stopped'   => $counts[Schema::MESSAGE_STATE_STOPPED] ?? 0,
            'queued'    => $counts[Schema::MESSAGE_STATE_QUEUED] ?? 0,
        ];
    }

    /**
     * The delivery of each message sent the record has one for; null = an entry edited out of shape,
     * none = the whole map so - not known either way. The entry of a message not sent is no delivery.
     * @return array<int, ?array{s: int, t: int, d: int, a: int}>
     * @throws \JsonException dm_ids edited into no JSON: what was sent is not known
     */
    private function states(): array
    {
        $sent = self::entries($this->dmIds);
        try {
            $states = self::entries($this->dmState);
        } catch (\JsonException) {
            // the delivery decides nothing of what is sent: a broken map stops no count
            return [];
        }
        return array_map(static fn(mixed $e): ?array => is_string($e) ? StatusSync::entry($e) : null, array_intersect_key($states, $sent));
    }

    /** @return array<int, int> index => dmMessageStatus of StatusSync::FAILED, of each message sent it failed */
    private function failedAfterSend(): array
    {
        $failed = [];
        foreach ($this->states() as $k => $e) {
            if (!is_null($e) && in_array($e['s'], StatusSync::FAILED, true)) {
                $failed[$k] = $e['s'];
            }
        }
        return $failed;
    }
}
