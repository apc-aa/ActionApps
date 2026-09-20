<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Http;
use PHPUnit\Framework\TestCase;

/**
 * What AA_Http::request() does today, pinned before it is replaced by AA\Http\Client.
 *
 * The contract that matters is not the transport but what the caller is handed back: false for
 * "no answer", the $err template with _#status/_#errmsg filled in, and the error left in
 * lastErr() for whoever asks. Templates depend on this through {include:...:...:...:...:<err>}.
 *
 * No network here - the stream wrapper reads a local file just as happily as a URL, which is
 * exactly how this class could reach a path and a URL through one call all these years. The @ on
 * the failing calls silences PHP's own warning about the missing file while leaving
 * error_get_last() filled, which is what _#errmsg is built from.
 *
 * Deleted together with AA_Http; AA\Expand\HttpInclude carries the contract from then on.
 */
final class RequestClassHttpTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../include/request.class.php';
        $this->file = (string) tempnam(sys_get_temp_dir(), 'aa-http-test-');
        file_put_contents($this->file, 'hello from a file');
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function test_whatCameBackIsReturnedAsIs(): void
    {
        self::assertSame('hello from a file', AA_Http::request($this->file));
    }

    public function test_noAnswerIsFalseAndTheReasonIsLeftInLastErr(): void
    {
        self::assertFalse(@AA_Http::request('file:///nonexistent/aa-http-test'));
        self::assertSame(2, AA_Http::lastErr());
        self::assertStringContainsString('Problem reading data from url', AA_Http::lastErrMsg());
    }

    /** The template contract: a placeholder text gets the status and the reason substituted */
    public function test_anErrorTemplateWithPlaceholdersIsFilledIn(): void
    {
        $ret = @AA_Http::request('file:///nonexistent/aa-http-test', [], [], '', 'E _#status|_#errmsg');

        self::assertIsString($ret);
        self::assertStringStartsWith('E |', $ret, 'a file has no HTTP status, so _#status is empty');
        self::assertStringContainsString('Failed to open stream', $ret);
    }

    public function test_anErrorTemplateWithoutPlaceholdersIsReturnedVerbatim(): void
    {
        self::assertSame('sorry, unavailable', @AA_Http::request('file:///nonexistent/aa-http-test', [], [], '', 'sorry, unavailable'));
    }

    /**
     * The accident this rewrite fixes: an empty method plus anything else means POST, so a
     * template that only wanted to send a header silently POSTs - and every {include} of a local
     * path does, because a Cookie header is added for local URLs. Pinned so that the change of
     * behaviour in AA\Expand\HttpInclude is a decision on the record, not a surprise.
     */
    public function test_aHeaderAloneTurnsTheRequestIntoAPost(): void
    {
        self::assertSame('POST', self::methodUsedFor([], ['X-Key' => 'k'], ''), 'nobody asked for a POST here');
        self::assertSame('POST', self::methodUsedFor(['a' => 1], [], ''), 'a body with no verb is a POST, which is at least defensible');
        self::assertSame('PUT', self::methodUsedFor([], [], 'PUT'), 'an explicit verb is honoured');
        // a bare GET and no arguments at all both end up without a context, which the wrapper
        // serves as a plain GET - same result, two different ways of saying nothing
        self::assertNull(self::methodUsedFor([], [], 'GET'));
        self::assertNull(self::methodUsedFor([], [], ''));
    }

    /** What request() actually put on the wire, read out of the stream context it built */
    private static function methodUsedFor(string|array $data, array $headers, string $method): ?string
    {
        RecordingStream::$options = null;
        stream_wrapper_register('aatest', RecordingStream::class);

        try {
            AA_Http::request('aatest://x', $data, $headers, $method);
        } finally {
            stream_wrapper_unregister('aatest');
        }

        return RecordingStream::$options['http']['method'] ?? null;
    }
}

/** A stream wrapper that answers nothing and remembers the context request() handed it */
final class RecordingStream
{
    public static ?array $options = null;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $flags, ?string &$opened): bool
    {
        self::$options = is_null($this->context) ? [] : stream_context_get_options($this->context);

        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
