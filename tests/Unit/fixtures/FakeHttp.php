<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use AA\Http\ClientInterface;
use AA\Http\Response;

/**
 * Answers from a script instead of from the network, and remembers what it was asked.
 *
 * Lives here rather than under Seo/: it stands in for AA\Http\ClientInterface, which is now the
 * one door for everything AA fetches - templates and the link checker included.
 */
final class FakeHttp implements ClientInterface
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    public array $calls = [];

    /** @param list<Response> $answers handed out in order; the last one repeats */
    public function __construct(private array $answers = []) {}

    public static function json(array ...$bodies): self
    {
        return new self(array_map(static fn(array $b): Response => new Response(200, (string) json_encode($b)), $bodies));
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        return count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? new Response(200, '{}'));
    }

    /** @return array<mixed> the decoded body of the nth call */
    public function sent(int $n = 0): array
    {
        return (array) json_decode((string) ($this->calls[$n]['body'] ?? ''), true);
    }
}
