<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Note;

/** One row of State Check as StateCheckModel files it: a finding (or the last
 *  known state of a check that did not run now) with its place on the time
 *  axis, or a group of near-duplicate findings (members). The page renders it,
 *  ?format=json serializes it (toArray()).
 */
final class StateCheckItem
{
    /**
     * @param string                                  $id         stable anchor: f-{check}-{hash of key}, g-… for a group
     * @param string                                  $state      StateCheckModel::NEW, REGRESSED, ...
     * @param string                                  $severity   Finding::ERROR ... OK
     * @param string                                  $source     'statecheck' | 'nightly'
     * @param string                                  $title      plain text
     * @param string                                  $advice     plain text
     * @param int                                     $first_seen unixtime, 0 = unknown
     * @param int                                     $last_seen  unixtime, 0 = unknown
     * @param list<array{label: string, url: string}> $where      absolute urls
     * @param string                                  $verify     plain text: how to verify again after a fix
     * @param string                                  $reason     plain text: why it is muted / not verified
     * @param list<string>                            $actions    StateCheckModel::ACTION_* that can really be done
     * @param ?Finding                                $finding    null for a check known only from the snapshot
     * @param ?Note                                   $note       the resolution that mutes it
     * @param list<StateCheckItem>                    $members    a group row: the near-duplicates it stands for
     * @param bool                                    $holds_now  a state that holds right now (cron is dead): no time of its own,
     *                                                            so its last seen is "now", not the time of this load
     */
    public function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly string $severity,
        public readonly string $source,
        public readonly string $check,
        public readonly string $category,
        public readonly string $title,
        public readonly string $advice = '',
        public readonly int $count = 0,
        public readonly int $first_seen = 0,
        public readonly int $last_seen = 0,
        public readonly array $where = [],
        public readonly string $verify = '',
        public readonly string $reason = '',
        public readonly array $actions = [],
        public readonly ?Finding $finding = null,
        public readonly ?Note $note = null,
        public readonly array $members = [],
        public readonly bool $holds_now = false,
    ) {}

    /** the chip of the row where it is shown - '' when it says what the place already does
     * @param string $context  the section it is in, or its group's state for a member */
    public function chip(string $context): string
    {
        return StateCheckModel::chip($this->state, $context);
    }

    /** Finding::$kind, '' for a group or a check known only from the snapshot */
    public function kind(): string
    {
        return $this->finding->kind ?? '';
    }

    /** the Last seen cell: "now" for a state that holds now, else how long ago - "3 h"; '' = unknown */
    public function lastSeenLabel(int $now): string
    {
        return $this->holds_now ? _m('now') : StateCheckModel::ago($this->last_seen, $now);
    }

    /** the Since cell: the age of the problem - "3 w"; '' = unknown */
    public function sinceLabel(int $now): string
    {
        return StateCheckModel::ago($this->first_seen, $now);
    }

    /** the key Recheck asks about: the finding's own, or ANY_KEY for a check known only from the snapshot */
    public function recheckKey(): string
    {
        return $this->finding ? $this->finding->key : StateCheckModel::ANY_KEY;
    }

    public function can(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /** one item of ?format=json in the shape the nightly mail reads - plain text, the consumer escapes
     * @param string $context  the section it is in, or its group's state for a member (chip())
     * @return array<string, mixed>
     */
    public function toArray(string $page_url, string $context, int $now): array
    {
        return [
            'id'             => $this->id,
            'url'            => $page_url . '#' . $this->id,
            'state'          => $this->state,
            'state_label'    => $this->chip($context),
            'severity'       => $this->severity,
            'severity_label' => StateCheckModel::severityLabel($this->severity),
            'source'         => $this->source,
            'check'          => $this->check,
            'category'       => $this->category,
            'title'          => $this->title,
            'advice'         => $this->advice,
            'count'          => $this->count,
            'first_seen'     => $this->first_seen,
            'last_seen'      => $this->last_seen,
            'last_seen_label' => $this->lastSeenLabel($now),
            'since_label'    => $this->sinceLabel($now),
            'where'          => $this->where,
            'verify'         => $this->verify,
            'reason'         => $this->reason,
            'member_count'   => count($this->members),
            'members'        => array_map(fn(StateCheckItem $member): array => $member->toArray($page_url, $this->state, $now), $this->members),
        ];
    }
}
