<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

/**
 * One item of a Matcher run: its data box in the register, or why there is none. Values are raw
 * text of the item and of the register - escape them (safe()) when shown.
 *
 * No match is certain: a BY_ICO one rests on an IČO whoever edited the item typed in, so a page
 * shows $boxName next to $name on every matched row. It never pre-ticks a BY_NAME row, and shows
 * both IČOs when $icoMismatch.
 */
final readonly class MatchRow
{
    /** the item already has the box */
    public const SAME = 'same';

    /** the item has no box yet */
    public const NEW = 'new';

    /** the item has another box */
    public const CHANGED = 'changed';

    public const UNMATCHED = 'unmatched';

    /** several boxes fit, see $candidates */
    public const AMBIGUOUS = 'ambiguous';

    public const STATUSES = [self::SAME, self::NEW, self::CHANGED, self::UNMATCHED, self::AMBIGUOUS];

    public const BY_ICO = 'ico';

    /** a suggestion only - a person compares $name with $boxName */
    public const BY_NAME = 'name';

    /** $box->id, '' when unmatched or ambiguous */
    public string $newDs;

    /** $box->name, the register's name of the matched box */
    public string $boxName;

    /**
     * @param string    $oldDs       the item's box as given, untrimmed; statuses compare it trimmed and
     *                               lowercased
     * @param ?Box      $box         the matched box, null when unmatched or ambiguous
     * @param string    $method      BY_ICO, BY_NAME, or '' when unmatched; for an ambiguous row the way the
     *                               candidates were found
     * @param list<Box> $candidates  the boxes that fit, only for AMBIGUOUS
     * @param bool      $icoMismatch the item has an IČO, the box another one - only on a BY_NAME match: the
     *                               register does not know the item's IČO (another country's, outdated),
     *                               or knows it as another kind of box (a school, a region, a court,
     *                               Praha's IČO on a Praha district)
     * @param string    $ico         the item's IČO as typed, raw - escape it (safe()) when shown;
     *                               $icoMismatch compares it with the box's once both are normalised
     */
    public function __construct(
        public int|string $itemId,
        public string $name,
        public string $type,
        public string $oldDs,
        public ?Box $box,
        public string $method,
        public string $status,
        public array $candidates = [],
        public bool $icoMismatch = false,
        public string $ico = '',
    ) {
        $this->newDs   = $box->id ?? '';
        $this->boxName = $box->name ?? '';
    }

    /** a person may write its box: a page offers a checkbox exactly for these, Sync::toWrite() and
     *  Sync::write() take no other */
    public function writable(): bool
    {
        return ($this->box !== null) && in_array($this->status, [self::NEW, self::CHANGED], true);
    }

    /** the word for a status a page filters by and the State Check counts with */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::NEW       => _m('New'),
            self::CHANGED   => _m('Changed'),
            self::SAME      => _m('Same'),
            self::UNMATCHED => _m('Not found'),
            self::AMBIGUOUS => _m('Several candidates'),
            default         => safe($status),
        };
    }
}
