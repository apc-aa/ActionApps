<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\Controller\TranslateController;
use AA\Api\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fake controller that bypasses the real network call and the AA session, so the
 * request handling, guards and chunking can be tested without a server.
 */
class FakeTranslateController extends TranslateController
{
    /** Tiny chunk limit so tests can exercise splitting with short strings. */
    protected const CHUNK_BYTES = 20;

    /** @var array<int,array{text:string,from:string,to:string}> every provider call made */
    public array $calls = [];

    /** @var array{ok:true,text:string}|array{ok:false,status:int,message:string}|null
     *  Fixed answer for every chunk; null echoes the chunk back uppercased. */
    public ?array $fakeResponse = null;

    public bool $loggedIn = true;

    /** @var array<int,array{ok:true,text:string}|array{ok:false,status:int,message:string}>
     *  Answers handed out one per call, before $fakeResponse is consulted. */
    public array $fakeQueue = [];

    protected function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    protected function callProvider(string $text, string $from, string $to): array
    {
        $this->calls[] = compact('text', 'from', 'to');
        if ($this->fakeQueue !== []) {
            return array_shift($this->fakeQueue);
        }
        return $this->fakeResponse ?? ['ok' => true, 'text' => mb_strtoupper($text)];
    }
}

final class TranslateControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST']   = 'aa.example.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://aa.example.test';
        $_POST = ['text' => 'Ahoj světe', 'from' => 'cs', 'to' => 'uk'];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        $_POST = [];
    }

    /** Reads status + payload out of a JsonResponse without emitting it. */
    private function readResponse(JsonResponse $response): array
    {
        $ref     = new \ReflectionObject($response);
        $status  = $ref->getProperty('status');
        $status->setAccessible(true);
        $payload = $ref->getProperty('payload');
        $payload->setAccessible(true);
        return ['status' => $status->getValue($response), 'body' => $payload->getValue($response)];
    }

    public function test_rejects_cross_host_request(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $r = $this->readResponse((new FakeTranslateController())->translate());

        $this->assertSame(401, $r['status']);
        $this->assertSame('Unauthorized', $r['body']['error']);
    }

    public function test_rejects_anonymous_user(): void
    {
        $controller = new FakeTranslateController();
        $controller->loggedIn = false;

        $r = $this->readResponse($controller->translate());

        $this->assertSame(401, $r['status']);
        $this->assertStringContainsString('session', $r['body']['error']);
        $this->assertSame([], $controller->calls);
    }

    /** The bug this endpoint exists to kill: an empty source must never come back
     *  as a successful empty translation, because callers assign it to the field. */
    public function test_rejects_empty_text(): void
    {
        $_POST['text'] = "  \n ";
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(400, $r['status']);
        $this->assertSame([], $controller->calls);
    }

    public function test_rejects_missing_text(): void
    {
        unset($_POST['text']);
        $r = $this->readResponse((new FakeTranslateController())->translate());

        $this->assertSame(400, $r['status']);
    }

    #[DataProvider('badLangProvider')]
    public function test_rejects_malformed_language_code(string $from, string $to): void
    {
        $_POST['from'] = $from;
        $_POST['to']   = $to;
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(400, $r['status']);
        $this->assertSame([], $controller->calls);
    }

    public static function badLangProvider(): array
    {
        return [
            'empty target'   => ['cs', ''],
            'injection'      => ['cs', 'uk&dt=bd&q=x'],
            'path traversal' => ['../etc', 'uk'],
            'too long'       => ['cs', 'ukrainian'],
        ];
    }

    public function test_rejects_oversized_text(): void
    {
        $_POST['text'] = str_repeat('a', TranslateController::MAX_CHARS + 1);
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(413, $r['status']);
        $this->assertSame([], $controller->calls);
    }

    public function test_happy_path_returns_translated_text(): void
    {
        $_POST['text'] = 'Ahoj';
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(200, $r['status']);
        $this->assertSame('AHOJ', $r['body']['text']);
        $this->assertCount(1, $controller->calls);
        $this->assertSame(['text' => 'Ahoj', 'from' => 'cs', 'to' => 'uk'], $controller->calls[0]);
    }

    public function test_same_language_short_circuits_without_calling_provider(): void
    {
        $_POST['to'] = 'cs';
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(200, $r['status']);
        $this->assertSame('Ahoj světe', $r['body']['text']);
        $this->assertSame([], $controller->calls);
    }

    public function test_provider_failure_surfaces_as_502(): void
    {
        $controller = new FakeTranslateController();
        $controller->fakeResponse = ['ok' => false, 'status' => 502, 'message' => 'Translation service returned HTTP 503'];

        $r = $this->readResponse($controller->translate());

        $this->assertSame(502, $r['status']);
        $this->assertStringContainsString('503', $r['body']['error']);
    }

    /** A provider answer of "" must be an error, never a 200 with an empty string. */
    public function test_empty_provider_result_is_an_error_not_an_empty_success(): void
    {
        $controller = new FakeTranslateController();
        $controller->fakeResponse = ['ok' => true, 'text' => ''];

        $r = $this->readResponse($controller->translate());

        $this->assertSame(502, $r['status']);
        $this->assertArrayNotHasKey('text', $r['body']);
    }

    public function test_long_text_is_split_and_rejoined_in_order(): void
    {
        // CHUNK_BYTES is 20 in the fake; three paragraphs, each under the limit.
        $_POST['text'] = "prvni odstavec\n\ndruhy odstavec\n\ntreti odstavec";
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(200, $r['status']);
        $this->assertGreaterThan(1, count($controller->calls));
        $this->assertSame(mb_strtoupper($_POST['text']), $r['body']['text']);
        $this->assertSame($_POST['text'], implode('', array_column($controller->calls, 'text')));
    }

    /** Splitting must never cut an HTML tag in half. */
    public function test_chunking_does_not_split_inside_a_tag(): void
    {
        $_POST['text'] = '<p class="intro">Ahoj svete a jeste kus textu</p> <p>Druhy odstavec zde</p>';
        $controller = new FakeTranslateController();

        $this->readResponse($controller->translate());

        foreach ($controller->calls as $call) {
            $last_open  = strrpos($call['text'], '<');
            $last_close = strrpos($call['text'], '>');
            $this->assertFalse(
                $last_open !== false && ($last_close === false || $last_close < $last_open),
                'chunk ends inside an HTML tag: ' . $call['text'],
            );
        }
    }

    /** A later chunk coming back empty must fail loudly - otherwise the endpoint
     *  answers 200 with silently truncated text and the editor loses that part. */
    public function test_a_later_empty_chunk_is_an_error_not_a_truncated_success(): void
    {
        $_POST['text'] = "prvni odstavec\n\ndruhy odstavec";
        $controller = new FakeTranslateController();
        $controller->fakeQueue = [
            ['ok' => true, 'text' => 'PRVNI'],
            ['ok' => true, 'text' => ''],
        ];

        $r = $this->readResponse($controller->translate());

        $this->assertSame(502, $r['status']);
        $this->assertArrayNotHasKey('text', $r['body']);
        $this->assertCount(2, $controller->calls);
    }

    /** A chunk with nothing to translate is kept as it is, not sent and not dropped. */
    public function test_whitespace_only_chunk_is_kept_verbatim(): void
    {
        $_POST['text'] = str_repeat(' ', 25) . 'abc';
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(200, $r['status']);
        $this->assertCount(1, $controller->calls);
        $this->assertSame(str_repeat(' ', 20) . '     ABC', $r['body']['text']);
    }

    /** A tag that starts at the chunk boundary and outgrows the limit must not be
     *  torn in half - the chunk overshoots to just past its ">" instead. */
    public function test_tag_longer_than_the_limit_is_not_torn(): void
    {
        $_POST['text'] = '<img src="' . str_repeat('A', 36) . '"> a tady pokracuje text';
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(200, $r['status']);
        $this->assertSame($_POST['text'], implode('', array_column($controller->calls, 'text')));
        foreach ($controller->calls as $call) {
            $last_open  = strrpos($call['text'], '<');
            $last_close = strrpos($call['text'], '>');
            $this->assertFalse(
                $last_open !== false && ($last_close === false || $last_close < $last_open),
                'chunk ends inside an HTML tag: ' . $call['text'],
            );
        }
    }

    public function test_multibyte_text_survives_chunking(): void
    {
        $_POST['text'] = str_repeat('příliš žluťoučký kůň ', 20);
        $controller = new FakeTranslateController();

        $r = $this->readResponse($controller->translate());

        $this->assertSame(200, $r['status']);
        $this->assertSame($_POST['text'], implode('', array_column($controller->calls, 'text')));
        foreach ($controller->calls as $call) {
            $this->assertSame($call['text'], mb_convert_encoding($call['text'], 'UTF-8', 'UTF-8'));
        }
    }
}
