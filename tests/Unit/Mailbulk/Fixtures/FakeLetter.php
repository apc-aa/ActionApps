<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk\Fixtures;

use AA\Mailbulk\Letter;
use AA\Mailbulk\Message;

/** A letter whose answers the test sets: a message for every reader unless $messages says otherwise. */
final class FakeLetter implements Letter
{
    public ?string $problem = null;
    /** @var array<string, Message|\Throwable|null> reader => what messageFor() gives; a Throwable is thrown */
    public array $messages = [];
    /** @var list<string> the readers messageFor() was asked for */
    public array $asked = [];

    public function problem(): ?string
    {
        return $this->problem;
    }

    public function title(): string
    {
        return 'Newsletter';
    }

    public function selection(): array
    {
        return [];
    }

    public function messageFor(string $readerId, bool $test = false): ?Message
    {
        $this->asked[] = $readerId;
        if (!array_key_exists($readerId, $this->messages)) {
            return new Message("$readerId@example.org", 'Newsletter', '<p>Hello</p>', 'news@example.org');
        }
        $message = $this->messages[$readerId];
        if ($message instanceof \Throwable) {
            throw $message;
        }
        return $message;
    }
}
