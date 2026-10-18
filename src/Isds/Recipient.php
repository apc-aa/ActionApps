<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * One item of the source slice a mass sending chose (Recipients). The name is a raw item value -
 * escape it (safe()) when shown.
 */
final readonly class Recipient
{
    /**
     * @param string $itemId long id of the item in the source slice
     * @param string $box    the box id as ISDS knows it; when the field holds none, its text trimmed
     * @param string $reason '' = the letter goes to $box; else why not, one of Schema::BOX_PROBLEMS
     */
    public function __construct(
        public string $itemId,
        public string $name,
        public string $box,
        public string $reason,
    ) {}

    public function sendable(): bool
    {
        return $this->reason === '';
    }

    /** the field holds a box id - a duplicate one too */
    public function boxValid(): bool
    {
        return !in_array($this->reason, [Schema::BOX_MISSING, Schema::BOX_FORMAT], true);
    }
}
