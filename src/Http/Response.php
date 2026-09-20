<?php

declare(strict_types=1);

namespace AA\Http;

use JsonException;

/** One answer from an outside service: what came back, and what the service said about it */
final readonly class Response
{
    /**
     * @param array<string, string> $headers  lower-cased names
     * @param string                $redirect absolute target of a 3xx the client did not follow,
     *                                        '' when there was none or it was followed
     */
    public function __construct(public int $status, public string $body, public array $headers = [], public string $redirect = '') {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The body as an array.
     *
     * @throws JsonException when the answer is not JSON - which usually means something in front
     *                       of the service answered instead of it
     * @return array<mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('The answer is JSON, but not an object or a list');
        }

        return $decoded;
    }

    /** The start of the body, for an error message a person will read */
    public function excerpt(int $length = 200): string
    {
        return mb_strcut(trim($this->body), 0, $length);
    }
}
