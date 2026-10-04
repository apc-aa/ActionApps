<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\Grabber\AbstractGrabber;
use InvalidArgumentException;

/**
 * One download within a stream. Lives only during a step: the grabber is never serialized, the
 * queue gets just the run id and the cursor.
 */
final class Job
{
    /** printable ASCII: the key is stored in the cursor and shown */
    public const KEY = '/^[\x21-\x7E]{1,200}$/D';

    /** @var array<string, string> */
    public readonly array $params;

    /**
     * @param string $key stable and unique within the run: the stream key, or the stream key, ':'
     *                    and more; stream keys have no ':', so jobs of two streams never collide
     * @param array<string, string|int> $params override the stream's parameters (rules as Stream)
     * @param string $url for showing only, masked by Context::redact(); it comes from the source,
     *                    so the caller redacts it again before storing and escapes it on output
     */
    public function __construct(
        public readonly string $key,
        public readonly AbstractGrabber $grabber,
        array $params,
        public readonly string $url = '',
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('Job key must match ' . self::KEY);
        }
        $this->params = Stream::strings($params);
    }

    /** @return array<string, string> parameters of the stream overridden by the job's own */
    public function params(Stream $s): array
    {
        if (($this->key !== $s->key) && !str_starts_with($this->key, $s->key . ':')) {
            throw new InvalidArgumentException('Job key must be the key of its stream, or start with it and ":"');
        }
        return array_merge($s->params, $this->params);
    }
}
