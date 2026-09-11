<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\Controller\SpeechController;
use AA\Api\JsonResponse;
use PHPUnit\Framework\TestCase;

/**
 * Fake controller that bypasses the real curl call.
 * Captures the arguments so tests can assert on them.
 */
class FakeSpeechController extends SpeechController
{
    public array $lastCallArgs = [];

    /** @var array{ok:true,text:string}|array{ok:false,status:int,message:string} */
    public array $fakeResponse = ['ok' => true, 'text' => 'dobrý den'];

    public ?string $apiKeyOverride = 'sk-test-key';

    protected function getApiKey(): ?string
    {
        return $this->apiKeyOverride;
    }

    protected function callOpenAi(string $tmpPath, string $filename, string $mimeType, ?string $lang): array
    {
        $this->lastCallArgs = compact('tmpPath', 'filename', 'mimeType', 'lang');
        return $this->fakeResponse;
    }
}

final class SpeechControllerTest extends TestCase
{
    protected function setUp(): void
    {
        // The controller gates on a same-host request (Origin/Referer host must
        // equal HTTP_HOST), NOT on a logged-in user. Set up a valid same-host
        // request so tests reach the logic under test. (FakeSpeechController
        // overrides getApiKey(), so no AA_APIKEY_OPENAI define is needed.)
        $_SERVER['HTTP_HOST']   = 'aa.example.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://aa.example.test';
        $_FILES = [];
        $_POST  = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        $_FILES = [];
        $_POST  = [];
    }

    /** Reads the status code + JSON body from a JsonResponse by capturing its emit(). */
    private function readResponse(JsonResponse $response): array
    {
        // Emit into a buffer. http_response_code() at CLI returns 200 by default — we
        // assert the body content + inspect JsonResponse::$status via reflection.
        $ref = new \ReflectionObject($response);
        $status  = $ref->getProperty('status');
        $status->setAccessible(true);
        $payload = $ref->getProperty('payload');
        $payload->setAccessible(true);
        return ['status' => $status->getValue($response), 'body' => $payload->getValue($response)];
    }

    public function test_rejects_cross_host_request(): void
    {
        // Origin host differs from HTTP_HOST → same-host gate rejects with 401.
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $controller = new FakeSpeechController();

        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(401, $r['status']);
        $this->assertSame('Unauthorized', $r['body']['error']);
    }

    public function test_rejects_when_api_key_missing(): void
    {
        $controller = new FakeSpeechController();
        $controller->apiKeyOverride = null;

        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(503, $r['status']);
        $this->assertStringContainsString('not configured', $r['body']['error']);
    }

    public function test_rejects_missing_audio(): void
    {
        $controller = new FakeSpeechController();
        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('audio', $r['body']['error']);
    }

    public function test_rejects_upload_error(): void
    {
        $_FILES['audio'] = ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '', 'name' => '', 'type' => ''];
        $controller = new FakeSpeechController();
        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(400, $r['status']);
    }

    public function test_rejects_oversized_upload(): void
    {
        if (!defined('AA_SPEECH_MAX_SECONDS')) {
            define('AA_SPEECH_MAX_SECONDS', 10);  // 10 s × 128 KB/s = 1.28 MB cap
        }
        $_FILES['audio'] = [
            'error'    => UPLOAD_ERR_OK,
            'size'     => 5 * 1024 * 1024,
            'tmp_name' => '/tmp/fake',
            'name'     => 'too-big.webm',
            'type'     => 'audio/webm',
        ];
        $controller = new FakeSpeechController();
        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(413, $r['status']);
        $this->assertStringContainsString('max length', $r['body']['error']);
    }

    public function test_happy_path_calls_openai_and_returns_text(): void
    {
        $_FILES['audio'] = [
            'error'    => UPLOAD_ERR_OK,
            'size'     => 50 * 1024,
            'tmp_name' => '/tmp/ok.webm',
            'name'     => 'ok.webm',
            'type'     => 'audio/webm',
        ];
        $_POST['lang'] = 'cs';

        $controller = new FakeSpeechController();
        $controller->fakeResponse = ['ok' => true, 'text' => 'ahoj světe'];

        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(200, $r['status']);
        $this->assertSame('ahoj světe', $r['body']['text']);
        $this->assertSame('gpt-4o-transcribe', $r['body']['model']);
        $this->assertSame('cs', $controller->lastCallArgs['lang']);
        $this->assertSame('ok.webm', $controller->lastCallArgs['filename']);
    }

    public function test_malformed_lang_is_dropped(): void
    {
        $_FILES['audio'] = [
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1024,
            'tmp_name' => '/tmp/ok.webm',
            'name'     => 'ok.webm',
            'type'     => 'audio/webm',
        ];
        $_POST['lang'] = 'CZ; DROP TABLE users;';

        $controller = new FakeSpeechController();
        $this->readResponse($controller->transcribe());

        $this->assertNull($controller->lastCallArgs['lang']);
    }

    public function test_openai_failure_surfaces_502(): void
    {
        $_FILES['audio'] = [
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1024,
            'tmp_name' => '/tmp/ok.webm',
            'name'     => 'ok.webm',
            'type'     => 'audio/webm',
        ];
        $controller = new FakeSpeechController();
        $controller->fakeResponse = ['ok' => false, 'status' => 502, 'message' => 'OpenAI 429 rate limit'];

        $r = $this->readResponse($controller->transcribe());

        $this->assertSame(502, $r['status']);
        $this->assertStringContainsString('rate limit', $r['body']['error']);
    }
}
