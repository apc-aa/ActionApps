<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk\Fixtures;

use AA\Mailbulk\Mailer;
use AA\Mailbulk\Message;
use AA\Mailbulk\Outcome;

/** A mailer that sends nothing: send() gives the scripted outcomes (Sent when none is left), every call is logged. */
final class ScriptedMailer implements Mailer
{
    /** @var list<Outcome> */
    public array $outcomes = [];
    /** @var array<string, \Throwable> address => what prepare() throws */
    public array $prepareThrows = [];
    /** @var ?\Closure(string, string): void runs on every call with its name and the address */
    public ?\Closure $onCall = null;
    /** what detail() says after each send */
    public string $detail = '250 OK';
    /** @var list<array{string, string}> [prepare|send, address] */
    public array $calls = [];

    private string $to = '';

    public function prepare(Message $message): void
    {
        $this->log('prepare', $message->to);
        if (isset($this->prepareThrows[$message->to])) {
            throw $this->prepareThrows[$message->to];
        }
        $this->to = $message->to;
    }

    public function send(): Outcome
    {
        $this->log('send', $this->to);
        return array_shift($this->outcomes) ?? Outcome::Sent;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    /** @return list<string> the addresses sent to, in order */
    public function sent(): array
    {
        return array_values(array_map(static fn(array $c): string => $c[1], array_filter($this->calls, static fn(array $c): bool => $c[0] === 'send')));
    }

    private function log(string $call, string $to): void
    {
        $this->calls[] = [$call, $to];
        if ($this->onCall) {
            ($this->onCall)($call, $to);
        }
    }
}
