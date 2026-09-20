<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Response;
use PHPUnit\Framework\TestCase;

/**
 * How one AA install asks another one, pinned before ask() moves onto AA\Http\Client.
 *
 * This is Central's wire protocol: the command travels as a serialized AA_Request in a form field
 * called "request", and the answer comes back as a serialized AA_Response. Both ends of the
 * conversation may be running different AA versions, so none of it may drift - a Central that
 * cannot talk to an older install is a broken Central.
 *
 * AA_Request is copied out of the real file under a test name with the transport swapped, so what
 * is recorded is exactly what ask() handed it. The rest of include/request.class.php is loaded
 * normally - AA_Response comes back through unserialize() and has to be the real class.
 */
final class RequestAskTest extends TestCase
{
    private const CLS = 'AA_Test_Request';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/request.class.php';
        if (class_exists(self::CLS, false)) {
            return;
        }
        eval('
            class AA_Test_Request_Http {
                public static array $calls = [];
                public static string|false $answer = "";
                public static function request($url, $data = [], $headers = [], $method = "", $err = "") {
                    self::$calls[] = ["url" => $url, "data" => $data, "headers" => $headers, "method" => $method];
                    return self::$answer;
                }
                public static function lastErr(): int { return 2; }
                public static function lastErrMsg(): string { return "connection refused"; }
            }
        ');
        $source = (string) file_get_contents(__DIR__ . '/../../../include/request.class.php');
        eval(strtr(self::extractClass($source, 'AA_Request'), [
            'class AA_Request' => 'class AA_Test_Request',
            'AA_Http::'        => 'AA_Test_Request_Http::',
            'AA_Request::class' => 'AA_Test_Request::class',
        ]));
    }

    protected function setUp(): void
    {
        \AA_Test_Request_Http::$calls  = [];
        \AA_Test_Request_Http::$answer = serialize(new AA_Response('pong', 0));
    }

    /** Source of one class, from "class X" to its closing brace (comments/strings safe) */
    private static function extractClass(string $source, string $name): string
    {
        $tokens = token_get_all($source);
        $start  = null;
        $depth  = 0;
        $out    = '';
        foreach ($tokens as $i => $token) {
            if (is_null($start)) {
                if (is_array($token) && $token[0] === T_CLASS && ($tokens[$i + 2][1] ?? '') === $name) {
                    $start = $i;
                } else {
                    continue;
                }
            }
            $text = is_array($token) ? $token[1] : $token;
            $out .= $text;
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                return $out;
            }
        }
        self::fail("class $name not found in include/request.class.php");
    }

    private static function ask(array $parameters = [], array $headers = []): mixed
    {
        $cls = self::CLS;

        return (new $cls('Get_Sessionid', ['a' => 1]))->ask('https://remote.example/central/index.php', $parameters, $headers);
    }

    /** @return array<string, mixed> */
    private static function lastCall(): array
    {
        self::assertNotEmpty(\AA_Test_Request_Http::$calls, 'nothing was sent at all');

        return \AA_Test_Request_Http::$calls[array_key_last(\AA_Test_Request_Http::$calls)];
    }

    public function test_theCommandTravelsSerializedInAFieldCalledRequest(): void
    {
        self::ask();

        $sent = self::lastCall();
        self::assertSame('https://remote.example/central/index.php', $sent['url']);
        self::assertArrayHasKey('request', $sent['data']);

        $back = unserialize($sent['data']['request'], ['allowed_classes' => [self::CLS]]);
        self::assertSame('Get_Sessionid', $back->getCommand());
        self::assertSame(['a' => 1], $back->getParameters());
    }

    public function test_extraParametersRideAlongsideTheCommand(): void
    {
        self::ask(['sid' => 'abc', 'slice_id' => 'x']);

        $sent = self::lastCall();
        self::assertSame('abc', $sent['data']['sid']);
        self::assertSame('x', $sent['data']['slice_id']);
        self::assertArrayHasKey('request', $sent['data'], 'the command is never displaced by a parameter');
    }

    public function test_headersArePassedOn(): void
    {
        self::ask([], ['X-AA-Auth' => 'token']);

        self::assertSame(['X-AA-Auth' => 'token'], self::lastCall()['headers']);
    }

    public function test_theAnswerComesBackAsTheResponseObject(): void
    {
        $answer = self::ask();

        self::assertInstanceOf(AA_Response::class, $answer);
        self::assertSame('pong', $answer->getResponse());
        self::assertFalse($answer->isError());
    }

    public function test_noAnswerAtAllIsReportedAsErrorThree(): void
    {
        \AA_Test_Request_Http::$answer = false;

        $answer = self::ask();

        self::assertSame(3, $answer->getError());
        self::assertStringContainsString('No response', (string) $answer->getResponse());
        self::assertStringContainsString('connection refused', (string) $answer->getResponse(), 'the reason is carried to whoever shows it');
    }

    public function test_anAnswerThatIsNotAResponseIsReportedAsErrorThree(): void
    {
        \AA_Test_Request_Http::$answer = '<html>proxy error</html>';

        // @: unserialize() warns about the garbage, which is the very case being pinned
        $answer = @self::ask();

        self::assertSame(3, $answer->getError());
        self::assertSame('Bad response', $answer->getResponse());
    }
}
