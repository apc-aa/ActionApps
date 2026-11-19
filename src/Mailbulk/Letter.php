<?php

declare(strict_types=1);

namespace AA\Mailbulk;

/** What a sending sends: the mail of one reader, and whether it may be sent at all. */
interface Letter
{
    /** why the mail cannot be sent now, for the user; null = it can */
    public function problem(): ?string;

    /** the name of the sending in the overviews, at most 250 characters */
    public function title(): string;

    /** @return list<string> long ids of the valid readers it goes to, one per address */
    public function selection(): array;

    /** the mail of $readerId; null = the reader is no longer valid */
    public function messageFor(string $readerId, bool $test = false): ?Message;
}
