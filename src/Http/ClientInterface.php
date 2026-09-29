<?php

declare(strict_types=1);

namespace AA\Http;

/**
 * How AA talks to an outside service. Handed to whoever calls out rather than created there, so
 * a test runs against a recorded answer and a dry run can refuse to call anything at all.
 */
interface ClientInterface
{
    /**
     * @param  string                $method  GET, POST, ...
     * @param  ?string               $body    already encoded - the caller knows whether it is JSON or a form
     * @param  array<string, string> $headers
     * @throws TransportException    when there was no answer at all (timeout, DNS, refused)
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): Response;
}
