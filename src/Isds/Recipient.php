<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * One item of the source slice a mass sending chose (Recipients). The name and the IČO are raw item
 * values, the official name raw text of the register - escape them (safe()) when shown.
 */
final readonly class Recipient
{
    /**
     * @param string $itemId   long id of the item in the source slice
     * @param string $box      the box id as ISDS knows it; when the field holds none, its text trimmed
     * @param string $reason   '' = the letter goes to $box; else why not, one of Schema::BOX_PROBLEMS
     * @param string $ico      the item's IČO as 8 digits (Registry\Box::normalizeIco()); when the field
     *                         holds none, its text trimmed (Schema::BOX_ICO); '' = no IČO
     * @param string $official the name the register of data boxes gives $box; '' = not in it, or not
     *                         checked against it
     */
    public function __construct(
        public string $itemId,
        public string $name,
        public string $box,
        public string $reason,
        public string $ico = '',
        public string $official = '',
    ) {}

    /** the same recipient for another reason; the official name too when given */
    public function with(string $reason, ?string $official = null): self
    {
        return new self($this->itemId, $this->name, $this->box, $reason, $this->ico, $official ?? $this->official);
    }

    public function sendable(): bool
    {
        return $this->reason === '';
    }

    /** the field holds a box id - a duplicate or a foreign one too */
    public function boxValid(): bool
    {
        return !in_array($this->reason, [Schema::BOX_MISSING, Schema::BOX_FORMAT], true);
    }
}
