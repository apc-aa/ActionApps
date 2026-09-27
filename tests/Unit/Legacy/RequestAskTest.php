<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Http\Response;
use AA_Request;
use AA_Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\FakeHttp;

/**
 * How one AA install asks another one.
 *
 * This is Central's wire protocol: the command travels as a serialized AA_Request in a form field
 * called "request", and the answer comes back as a serialized AA_Response. Both ends of the
 * conversation may be running different AA versions, so none of it may drift - a Central that
 * cannot talk to an older install is a broken Central.
 *
 * Written against the old file_get_contents transport first (through an extracted copy), then
 * pointed at the real AA_Request once ask() could be handed a client.
 */
final class RequestAskTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/request.class.php';
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
    }

    private static function ask(FakeHttp $http, array $parameters = [], array $headers = []): mixed
    {
        return (new AA_Request('Get_Sessionid', ['a' => 1]))->ask('https://remote.example/central/index.php', $parameters, $headers, $http);
    }

    private static function answering(int $status = 200, mixed $body = null): FakeHttp
    {
        return new FakeHttp([new Response($status, is_null($body) ? serialize(new AA_Response('pong', 0)) : (string) $body)]);
    }

    /** the form-encoded body, parsed back into fields */
    private static function sentFields(FakeHttp $http): array
    {
        parse_str((string) $http->calls[0]['body'], $fields);

        return $fields;
    }

    public function test_theCommandTravelsSerializedInAFieldCalledRequest(): void
    {
        $http = self::answering();
        self::ask($http);

        self::assertSame('POST', $http->calls[0]['method'], 'the responder reads $_POST');
        self::assertSame('https://remote.example/central/index.php', $http->calls[0]['url']);

        $back = unserialize(self::sentFields($http)['request'], ['allowed_classes' => [AA_Request::class]]);
        self::assertSame('Get_Sessionid', $back->getCommand());
        self::assertSame(['a' => 1], $back->getParameters());
    }

    /**
     * PHP fills $_POST only for a form-encoded body, and central/responder.php reads
     * $_POST['request']. The old path never set this header - PHP's http wrapper added it by
     * itself, which the new client does not do for a string body.
     */
    public function test_theBodyIsMarkedAsAFormOrTheRemoteSideSeesNothing(): void
    {
        $http = self::answering();
        self::ask($http);

        self::assertSame('application/x-www-form-urlencoded', $http->calls[0]['headers']['Content-Type']);
    }

    public function test_extraParametersRideAlongsideTheCommand(): void
    {
        $http = self::answering();
        self::ask($http, ['sid' => 'abc', 'slice_id' => 'x']);

        $fields = self::sentFields($http);
        self::assertSame('abc', $fields['sid']);
        self::assertSame('x', $fields['slice_id']);
        self::assertArrayHasKey('request', $fields, 'the command is never displaced by a parameter');
    }

    public function test_headersArePassedOn(): void
    {
        $http = self::answering();
        self::ask($http, [], ['Authorization' => 'Bearer t']);

        self::assertSame('Bearer t', $http->calls[0]['headers']['Authorization']);
    }

    public function test_theAnswerComesBackAsTheResponseObject(): void
    {
        $answer = self::ask(self::answering());

        self::assertInstanceOf(AA_Response::class, $answer);
        self::assertSame('pong', $answer->getResponse());
        self::assertFalse($answer->isError());
    }

    public function test_noAnswerAtAllIsReportedAsErrorThree(): void
    {
        $silent = new class implements \AA\Http\ClientInterface {
            public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
            {
                throw new \AA\Http\TransportException('No answer from ' . $url . ' - Connection refused');
            }
        };
        $answer = (new AA_Request('Get_Sessionid'))->ask('https://remote.example/central/index.php', [], [], $silent);

        self::assertSame(3, $answer->getError());
        self::assertStringContainsString('No response received', (string) $answer->getResponse());
        self::assertStringContainsString('Connection refused', (string) $answer->getResponse(), 'the reason is carried to whoever shows it');
    }

    /** A proxy or a maintenance page used to be unserialized as garbage; now it says what happened */
    public function test_anErrorFromTheRemoteSideSaysWhichOne(): void
    {
        $answer = self::ask(self::answering(500, '<html>Internal Server Error</html>'));

        self::assertSame(3, $answer->getError());
        self::assertStringContainsString('Remote AA answered HTTP 500', (string) $answer->getResponse());
    }

    /**
     * Central_Optimize joins these messages with <br> and the Manager renders them as HTML in a
     * superadmin's page, so nothing the remote end wrote may travel in them.
     */
    public function test_theRemoteBodyNeverReachesTheCentralAdminPage(): void
    {
        $answer = self::ask(self::answering(502, '<script>alert(document.cookie)</script>'));

        self::assertSame('Remote AA answered HTTP 502', $answer->getResponse());
    }

    /** A redirect is the one case where the admin needs the target: it is the url to configure */
    public function test_aRedirectSaysWhereTheCentralActuallyLives(): void
    {
        $http   = new FakeHttp([new Response(301, 'Moved', [], 'https://remote.example/central/index.php')]);
        $answer = self::ask($http);

        self::assertSame(3, $answer->getError());
        self::assertStringContainsString('it points at https://remote.example/central/index.php', (string) $answer->getResponse());
    }

    public function test_anAnswerThatIsNotAResponseIsReportedAsErrorThree(): void
    {
        // @: unserialize() warns about the garbage, which is the very case being pinned
        $answer = @self::ask(self::answering(200, '<html>proxy rewrote it</html>'));

        self::assertSame(3, $answer->getError());
        self::assertSame('Bad response', $answer->getResponse());
    }
}
