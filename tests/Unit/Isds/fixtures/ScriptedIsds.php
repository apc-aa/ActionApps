<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Http\ClientInterface;
use AA\Http\Response;

/**
 * ISDS answering each operation from a script of its own: a Response, an exception the connection
 * throws, or a closure that returns or throws one - where a test moves its clock or the process
 * dies. The last answer of a script repeats; an operation without a script fails the test.
 */
final class ScriptedIsds implements ClientInterface
{
    /** @var list<array{op: string, body: string}> */
    public array $calls = [];

    /** @param array<string, list<Response|\Throwable|\Closure(): Response>> $answers operation => answers in order */
    public function __construct(private array $answers) {}

    public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $op = (preg_match('~<\w+:Body>\s*<\w+:(\w+)~', (string) $body, $m) === 1) ? $m[1] : '?';
        $this->calls[] = ['op' => $op, 'body' => (string) $body];
        $script = $this->answers[$op] ?? throw new \LogicException("ISDS was asked $op, which the test did not script");
        $answer = (count($script) > 1) ? array_shift($this->answers[$op]) : $script[0];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        return ($answer instanceof \Closure) ? $answer() : $answer;
    }

    /** @return list<string> the operations asked, in order */
    public function ops(): array
    {
        return array_column($this->calls, 'op');
    }

    /** @return list<string> $element of each CreateMessage, in order - dbIDRecipient, dmSenderRefNumber */
    public function sent(string $element = 'dbIDRecipient'): array
    {
        $values = [];
        foreach ($this->calls as $call) {
            if (($call['op'] === 'CreateMessage') && (preg_match("~<\\w+:$element>([^<]*)<~", $call['body'], $m) === 1)) {
                $values[] = $m[1];
            }
        }
        return $values;
    }
}
