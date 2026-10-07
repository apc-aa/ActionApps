<?php

/**
 * Small helper for typed JSON responses from AA API controllers.
 *
 * Usage:
 *   return JsonResponse::ok(['text' => $text]);
 *   return JsonResponse::error(401, 'Unauthorized');
 *
 * @package Api
 */

namespace AA\Api;

class JsonResponse
{
    private int   $status;
    private array $payload;

    private function __construct(int $status, array $payload)
    {
        $this->status  = $status;
        $this->payload = $payload;
    }

    public static function ok(array $data): self
    {
        return new self(200, $data);
    }

    public static function error(int $status, string $message, array $extra = []): self
    {
        return new self($status, ['error' => $message] + $extra);
    }

    public function emit(): void
    {
        // http_response_code() is safe to call even after headers are sent —
        // AA's pageOpen() sends a session cookie before we run, so we MUST
        // set the status unconditionally or it stays at the pre-script default.
        http_response_code($this->status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        // < and & escaped: a body that ends up rendered as HTML (output before the header) carries no
        // markup; an invalid byte of foreign data becomes U+FFFD instead of an empty body
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
