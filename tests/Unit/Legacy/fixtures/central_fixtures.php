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
    /** @var list<array{url: string, options: array<string, array<string, mixed>>}> */
    public array $opened = [];

    /** @var list<string> the response header lines of every answer */
    public array $headers = ['HTTP/1.1 200 OK'];
    /** thrown instead of connecting - as RemoteSource's own connection fails */
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
        return new \AA\Central\RemoteSource($this->getComunicatorUrl(), open: \Closure::fromCallable([$this, 'open']));
    }

    /** the POST fields of the last request
     *  @return array<string, string> */
    public function posted(): array
    {
        parse_str((string) (end($this->opened)['options']['http']['content'] ?? ''), $fields);
        return $fields;
    }

    /** @return array{resource, list<string>} */
    private function open(string $url, $context): array
    {
        $this->opened[] = ['url' => $url, 'options' => stream_context_get_options($context)];
        if ($this->refuse) {
            throw $this->refuse;
        }
        $body   = (count($this->bodies) > 1) ? array_shift($this->bodies) : $this->bodies[0];
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        return [$stream, $this->headers];
    }
}

/** central_conf for RecordingDb: the rows, the width of AA_ADMIN_PWD, and AA_ADMIN_PWD read back as the
 *  UPDATEs so far left it - cut to the real width of the column, as MySQL does without strict mode. */
final class CentralConfDb
{
    /** @param list<array<string, string>> $rows @param \Closure(): ?int $width what information_schema says, null = unreadable
     *  @param ?int $real the width the values are cut to, null = $width */
    public static function install(array $rows, \Closure $width, ?int $real = null): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static function (string $sql) use ($rows, $width, $real): array {
            return match (true) {
                str_contains($sql, 'information_schema.COLUMNS')          => [['w' => (string) $width()]],
                (bool) preg_match('/^SELECT AA_ADMIN_PWD FROM `?central_conf`? WHERE id = (\d+)/', $sql, $m) => [['AA_ADMIN_PWD' => self::current($rows, $m[1], $real ?? $width())]],
                str_starts_with($sql, 'SELECT id FROM central_conf')       => array_map(static fn(array $r) => ['id' => $r['id']], $rows),
                str_starts_with($sql, 'SELECT * FROM central_conf')        => $rows,
                default                                                    => [],
            };
        };
        $GLOBALS['spareDBs'] = array_map(static fn() => new RecordingDb(), range(1, 30));
    }

    /** the UPDATE statements so far @return list<string> */
    public static function updates(): array
    {
        return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'UPDATE')));
    }

    /** @param list<array<string, string>> $rows */
    private static function current(array $rows, string $id, ?int $width): string
    {
        $value = '';
        foreach ($rows as $row) {
            $value = ($row['id'] === $id) ? (string) ($row['AA_ADMIN_PWD'] ?? '') : $value;
        }
        foreach (self::updates() as $sql) {
            if (preg_match("/SET  AA_ADMIN_PWD = '([^']*)' WHERE id = $id(?: AND AA_ADMIN_PWD = '([^']*)')?$/", $sql, $m) && (!isset($m[2]) || ($m[2] === $value))) {
                $value = ($width === null) ? $m[1] : substr($m[1], 0, $width);
            }
        }
        return $value;
    }
}
