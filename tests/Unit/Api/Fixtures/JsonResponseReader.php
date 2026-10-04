<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Fixtures;

use AA\Api\JsonResponse;

/** What a JsonResponse would send - its status and body - without emitting it (headers fail in CLI). */
final class JsonResponseReader
{
    /** @return array{status: int, body: array<mixed>} */
    public static function read(JsonResponse $response): array
    {
        $ref = new \ReflectionObject($response);
        return ['status' => $ref->getProperty('status')->getValue($response), 'body' => $ref->getProperty('payload')->getValue($response)];
    }
}
