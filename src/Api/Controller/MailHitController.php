<?php

declare(strict_types=1);

namespace AA\Api\Controller;

use AA\Mailbulk\Tracker;
use AA\Mailbulk\TrackToken;

/**
 * The tracked address of a mail: the image of an open, the redirect of a click. Public and without
 * a session - it reads no cookie and sends none. Only a valid token redirects, so it is no open redirect.
 */
class MailHitController
{
    /** a transparent 1x1 GIF */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private Tracker $tracker;

    public function __construct(?Tracker $tracker = null)
    {
        $this->tracker = $tracker ?? new Tracker();
    }

    /** @return array{status: int, headers: array<string, string>, body: string} what to send, not sent */
    public function answer(string $token): array
    {
        $hit = TrackToken::parse($token);
        if ($hit === null) {
            return ['status' => 404, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => 'Not Found'];
        }
        try {
            $this->tracker->record($hit);
        } catch (\Throwable $e) {
            // the reader must get to the link whatever the statistics do
            error_log('AA mail hit: ' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()));
        }
        // no-store: a hit answered by a cache never counts
        if ($hit->kind === TrackToken::CLICK) {
            return ['status' => 302, 'headers' => ['Location' => $hit->url, 'Cache-Control' => 'no-store'], 'body' => ''];
        }
        return ['status' => 200, 'headers' => ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store'], 'body' => (string) base64_decode(self::PIXEL)];
    }

    /** Sends the answer to the token of the request */
    public function respond(): void
    {
        $answer = $this->answer(is_string($_GET['t'] ?? null) ? $_GET['t'] : '');
        http_response_code($answer['status']);
        foreach ($answer['headers'] as $name => $value) {
            header("$name: $value");
        }
        header('Content-Length: ' . strlen($answer['body']));
        echo $answer['body'];
    }
}
