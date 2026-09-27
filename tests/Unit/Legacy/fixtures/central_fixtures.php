<?php

/**
 * Helpers shared by the Central characterization tests (tests/Unit/Legacy/Central*Test.php).
 */

declare(strict_types=1);

namespace Tests\Unit\Legacy\Fixtures;

trait CentralTestHelpers
{
    /** Runs $fn with warnings/notices/deprecations collected instead of reported.
     *  @param list<string> $warnings */
    private static function collectingWarnings(callable $fn, ?array &$warnings = null): mixed
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}

/** An AA whose responder is a stub behind the real RemoteSource: each connection is recorded and
 *  answered with the next body (the last one repeats). */
final class StubbedActionapps extends \AA\Central\Actionapps
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $opened = [];

    /** @var list<string> the status line and the header lines of every answer */
    public array $headers = ['HTTP/1.1 200 OK'];
    /** thrown instead of answering - as RemoteSource's own connection fails */
    public ?\AA\Central\SourceFailed $refuse = null;

    /** @param list<string> $bodies raw answers, serialized AA_Response as a rule */
    public function __construct(\ItemContent $content, private array $bodies)
    {
        parent::__construct($content);
    }

    /** @param list<\AA_Response> $answers */
    public static function answering(\ItemContent $content, array $answers): self
    {
        return new self($content, array_map('serialize', $answers));
    }

    protected function remote(): \AA\Central\RemoteSource
    {
        return new \AA\Central\RemoteSource($this->getComunicatorUrl(), transport: new \Symfony\Component\HttpClient\MockHttpClient(\Closure::fromCallable([$this, 'answer'])));
    }

    /** the POST fields of the last request
     *  @return array<string, string> */
    public function posted(): array
    {
        parse_str((string) (end($this->opened)['options']['body'] ?? ''), $fields);
        return $fields;
    }

    /** the request header lines of the request $n */
    public function sentHeaders(int $n = 0): string
    {
        return implode("\n", $this->opened[$n]['options']['headers'] ?? []);
    }

    /** @param array<string, mixed> $options */
    private function answer(string $method, string $url, array $options): \Symfony\Component\HttpClient\Response\MockResponse
    {
        $this->opened[] = ['method' => $method, 'url' => $url, 'options' => $options];
        if ($this->refuse) {
            throw $this->refuse;
        }
        $body   = (count($this->bodies) > 1) ? array_shift($this->bodies) : $this->bodies[0];
        $status = preg_match('~^HTTP/\S+\s+(\d{3})~', $this->headers[0] ?? '', $m) ? (int) $m[1] : 200;
        return new \Symfony\Component\HttpClient\Response\MockResponse($body, ['http_code' => $status, 'response_headers' => array_slice($this->headers, 1)]);
    }
}

/** central_conf for RecordingDb: the rows as SELECT gives them, the UPDATEs recorded */
final class CentralConfDb
{
    /** @param list<array<string, string>> $rows */
    public static function install(array $rows): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_starts_with($sql, 'SELECT id FROM central_conf') => array_map(static fn(array $r) => ['id' => $r['id']], $rows),
            str_starts_with($sql, 'SELECT * FROM central_conf')  => $rows,
            default                                              => [],
        };
        $GLOBALS['spareDBs'] = array_map(static fn() => new RecordingDb(), range(1, 30));
    }

    /** the UPDATE statements so far @return list<string> */
    public static function updates(): array
    {
        return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'UPDATE')));
    }
}
