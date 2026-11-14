<?php

declare(strict_types=1);

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
    /**
     * Every API answer is about the person asking - so it belongs in no cache but their browser's.
     * Refusals included: a shared proxy holding one person's 401 or 404 under the URL would hand
     * it to the next person through it.
     */
    public const NO_STORE = 'Cache-Control: private, no-store';

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

    /**
     * The headers this answer sends, in the order emit() sends them.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'Content-Type: application/json; charset=utf-8',
            'X-Content-Type-Options: nosniff',
            self::NO_STORE,
        ];
    }

    public function emit(): void
    {
        // http_response_code() is safe to call even after headers are sent —
        // AA's pageOpen() sends a session cookie before we run, so we MUST
        // set the status unconditionally or it stays at the pre-script default.
        http_response_code($this->status);
        if (!headers_sent()) {
            foreach ($this->headers() as $header) {
                header($header);
            }
        }
        // < and & escaped: a body that ends up rendered as HTML (output before the header) carries no
        // markup; an invalid byte of foreign data becomes U+FFFD instead of an empty body
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
