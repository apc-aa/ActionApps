<?php

declare(strict_types=1);

namespace AA\Api\Controller;

use AA\Api\Router;
use AA\Mailbulk\Tracker;
use AA\Mailbulk\TrackToken;

/**
 * The tracked address of a mail: the image of an open, the redirect of a click. Public and without
 * a session - it reads no cookie and sends none. Only a valid token redirects, so it is no open redirect.
 */
class MailHitController
{
    private Tracker $tracker;

    public function __construct(?Tracker $tracker = null)
    {
        $this->tracker = $tracker ?? new Tracker();
    }

    /** @param bool $record false for HEAD: link checkers get the answer of GET, nothing is counted
     *  @return array{status: int, headers: array<string, string>, body: string} what to send, not sent */
    public function answer(string $token, bool $record = true): array
    {
        $hit = TrackToken::parse($token);
        if ($hit === null) {
            $answer = ['status' => 404, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8'], 'body' => 'Not Found'];
        } else {
            if ($record) {
                try {
                    $this->tracker->record($hit);
                } catch (\Throwable $e) {
                    // the reader must get to the link whatever the statistics do
                    Router::logError('AA mail hit', $e);
                }
            }
            $answer = ($hit->kind === TrackToken::CLICK)
                ? ['status' => 302, 'headers' => ['Location' => self::headerUrl($hit->url)], 'body' => '']
                : ['status' => 200, 'headers' => ['Content-Type' => 'image/gif'], 'body' => (string) base64_decode(substr(NO_PICTURE_DATA, strpos(NO_PICTURE_DATA, ',') + 1))];
        }
        // a hit answered by a cache never counts
        $answer['headers']['Cache-Control'] = 'no-store';
        return $answer;
    }

    /** Sends the answer to the token of the request
     *  @param bool $record false for HEAD: headers only, nothing counted */
    public function respond(bool $record = true): void
    {
        $answer = $this->answer(is_string($_GET['t'] ?? null) ? $_GET['t'] : '', $record);
        http_response_code($answer['status']);
        foreach ($answer['headers'] as $name => $value) {
            header("$name: $value");
        }
        header('Content-Length: ' . strlen($answer['body']));
        if ($record) {
            echo $answer['body'];
        }
    }

    /** A header line holds only ASCII: spaces and non-ASCII bytes of an IRI go percent-encoded, the rest as it was */
    private static function headerUrl(string $url): string
    {
        return (string) preg_replace_callback('/[\x20\x80-\xff]/', static fn(array $m): string => rawurlencode($m[0]), $url);
    }
}
