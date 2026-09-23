<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Response;
use PHPUnit\Framework\TestCase;

/**
 * AA_Response::respond() / contentType() (include/request.class.php).
 * central/responder.php answers unauthenticated requests with an error that
 * reflects the command name: in 'html' mode the error text must be escaped
 * while a successful response (widget HTML, diff fragments) stays raw; in
 * 'serialize' mode the body stays byte-exact and is sent as text/plain.
 */
final class ResponseRespondTest extends TestCase
{
    private mixed $type_backup;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../include/request.class.php';
        $this->type_backup = AA_Response::$Response_type;
    }

    protected function tearDown(): void
    {
        AA_Response::$Response_type = $this->type_backup;
    }

    public function test_respond_html_error_is_escaped(): void
    {
        AA_Response::$Response_type = 'html';
        $this->expectOutputString('Error 103: &lt;b&gt;x&lt;/b&gt; &quot;q&quot; &apos;a&apos;');

        (new AA_Response('<b>x</b> "q" \'a\'', 103))->respond();
    }

    public function test_respond_html_success_is_raw(): void
    {
        AA_Response::$Response_type = 'html';
        $html = '<div class="w">ok</div>';
        $this->expectOutputString($html);

        (new AA_Response($html))->respond();
    }

    public function test_respond_serialize_body_is_the_plain_serialized_object(): void
    {
        // the PHP client (AA_Request::ask()) unserializes the raw body; browsers are kept off by contentType()
        AA_Response::$Response_type = 'serialize';
        $response = new AA_Response(['a' => 1], 0);
        $this->expectOutputString(serialize($response));

        $response->respond();
    }

    public function test_contentType_serialize_is_plain_text(): void
    {
        AA_Response::$Response_type = 'serialize';

        self::assertSame('text/plain; charset=utf-8', AA_Response::contentType());
    }

    public function test_contentType_html_keeps_default(): void
    {
        AA_Response::$Response_type = 'html';

        self::assertNull(AA_Response::contentType());
    }
}
