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
     * @throws ResponseTooLargeException when the client limits the size and the body is over it
     *
     * A key can travel in each of $url, $body and $headers (a query parameter, a form with a signed
     * assertion, the Authorization header), so PHP keeps them out of stack traces and dumps. The
     * attribute is not inherited: an implementation marks them again, or its traces show them.
     */
    public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] ?string $body = null, #[\SensitiveParameter] array $headers = []): Response;
}
