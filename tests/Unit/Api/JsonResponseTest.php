<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\JsonResponse;
use PHPUnit\Framework\TestCase;

/**
 * The headers every JSON answer of the API carries.
 *
 * no-store is here and not in the controllers: an API answer is about the person asking - their
 * data, or the refusal that tells an outsider nothing - and a shared cache holding any of it under
 * the URL would hand it to the next person through it.
 */
final class JsonResponseTest extends TestCase
{
    public function testEveryAnswerSaysItIsNobodysToKeep(): void
    {
        self::assertContains(JsonResponse::NO_STORE, JsonResponse::ok(['pong' => true])->headers());
    }

    public function testARefusalSaysItTooAndIsNoDifferentOnTheWire(): void
    {
        self::assertSame(JsonResponse::ok([])->headers(), JsonResponse::error(500, 'Internal error')->headers());
        self::assertContains(JsonResponse::NO_STORE, JsonResponse::error(404, 'Not Found')->headers());
    }

    public function testTheOtherHeadersStay(): void
    {
        $headers = JsonResponse::ok([])->headers();

        self::assertContains('Content-Type: application/json; charset=utf-8', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
    }

    public function testEmitWritesTheJsonBody(): void
    {
        ob_start();
        JsonResponse::ok(['text' => 'ě/á'])->emit();

        self::assertSame('{"text":"ě/á"}', (string) ob_get_clean());
    }
}
