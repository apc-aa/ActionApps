<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret;
use AA\Http\Response;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Account;
use AA\Isds\AddressBlockedException;
use AA\Isds\AuthException;
use AA\Isds\Client;
use AA\Isds\ConfigException;
use AA\Isds\File;
use AA\Isds\IsdsException;
use AA\Isds\Message;
use AA\Isds\Outcome;
use AA\Isds\Schema;
use AA\Isds\SendResult;
use AA\Isds\Soap;
use AA\Isds\TransportException;
use AA\Isds\UnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Fixtures\MemoryLatch;

/** What an attempt to send a message means for the message and for the run that sends it */
final class OutcomeTest extends TestCase
{
    private const PASSWORD = 'Pa55-word-never-shown';
    private const KEY      = '/srv/aa/certs/isds-nszm.key';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';     // the constants Secret::key() derives from
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
        require_once __DIR__ . '/fixtures/MemoryLatch.php';
    }

    /** @param list<string> $expected state, event, then, code, dmId, isdsCode, text */
    #[DataProvider('results')]
    public function test_of_matrix(SendResult|\Throwable $result, array $expected): void
    {
        $outcome = Outcome::of($result);

        self::assertSame($expected, [$outcome->state, $outcome->event, $outcome->then, $outcome->code, $outcome->dmId, $outcome->isdsCode, $outcome->text]);
    }

    public static function results(): array
    {
        $sent     = [Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, Outcome::NEXT, '', '1835603', '', ''];
        $login    = [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, Outcome::FAIL, Outcome::LOGIN, '', '', ''];
        $blocked  = [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, Outcome::FAIL, Outcome::BLOCKED, '', '', ''];
        $config   = [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, Outcome::FAIL, Outcome::CONFIG, '', '', ''];
        $wait     = static fn(string $code = ''): array => [Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, Outcome::PAUSE, Outcome::UNAVAILABLE, '', $code, ''];
        $answered = static fn(string $code = ''): array => [Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, Outcome::NEXT, '', '', $code, ''];
        $notLeft  = [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, Outcome::PAUSE, Outcome::UNAVAILABLE, '', '', ''];
        $refused  = static fn(string $code, string $text): array => [Schema::MESSAGE_STATE_FAILED, Schema::EVENT_FAILED, Outcome::NEXT, '', '', $code, $text];
        $unknown  = [Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, Outcome::RETHROW, '', '', '', ''];
        return [
            'accepted'                       => [new SendResult('1835603', '0000', 'Provedeno úspěšně.'), $sent],
            'login refused'                  => [new AuthException('ISDS refused the login (HTTP 401): Neplatné přihlášení'), $login],
            // a status does not make it a refusal of the message
            'login refused with a status'    => [new AuthException('Přihlášení se nezdařilo', '1201'), $login],
            // not the login: a new password would not lift it
            'address blocked'                => [new AddressBlockedException('ISDS blocked logins from the address of this server'), $blocked],
            'certificate unusable'           => [new ConfigException('This server could not use the certificate of the ISDS account'), $config],
            'come back later'                => [new UnavailableException('ISDS asks to come back later (HTTP 503)'), $wait()],
            'too many calls'                 => [new UnavailableException('Příliš mnoho volání', '3008'), $wait('3008')],
            'no answer'                      => [TransportException::fromHttp(new HttpTransportException('No answer', 0, new TimeoutException('Idle timeout reached'))), $wait()],
            'server error answered'          => [TransportException::uncertain('ISDS answered HTTP 500: Internal Server Error'), $answered()],
            'internal error of ISDS'         => [TransportException::uncertain('Interní chyba', '9001'), $answered('9001')],
            'fault of the server'            => [TransportException::uncertain('ISDS failed: Server busy', 'Server'), $answered('Server')],
            'never left'                     => [TransportException::fromHttp(new HttpTransportException('No answer', 0, new \RuntimeException('Could not resolve host: ws1.datovka-test.gov.cz'))), $notLeft],
            'refused'                        => [new IsdsException('Odesilatel ani příjemce datové zprávy není OVM', '1233'), $refused('1233', 'Odesilatel ani příjemce datové zprávy není OVM')],
            'refused by a fault'             => [new IsdsException('ISDS refused the request: Invalid sender', 'Client'), $refused('Client', 'ISDS refused the request: Invalid sender')],
            // the flag decides, not the class - a refused login that may have sent is never queued again
            'a login refusal that may have sent'       => [new class ('Odpověď bez stavu') extends AuthException {
                public function mayHaveBeenSent(): bool
                {
                    return true;
                }
            }, $wait()],
            'another IsdsException that may have sent' => [new class ('Odpověď bez stavu') extends IsdsException {
                public function mayHaveBeenSent(): bool
                {
                    return true;
                }
            }, $wait()],
            // a refusal only from IsdsException itself: what a subclass means AA does not know yet
            'another IsdsException that sent nothing'  => [new class ('Odmítnuto jinak', '1214') extends IsdsException {}, $unknown],
            'unknown error'                  => [new \RuntimeException('Lock wait timeout exceeded'), $unknown],
            'error of PHP'                   => [new \TypeError('Argument #1 must be of type string'), $unknown],
        ];
    }

    // whatever AA does not know may have come after the request left: never sent again by itself
    #[DataProvider('unknownErrors')]
    public function test_of_unknownErrorIsUncertain(\Throwable $error): void
    {
        $outcome = Outcome::of($error);

        self::assertSame([Schema::MESSAGE_STATE_UNCERTAIN, Outcome::RETHROW], [$outcome->state, $outcome->then]);
    }

    public static function unknownErrors(): array
    {
        return [
            'runtime'  => [new \RuntimeException('MySQL server has gone away')],
            'logic'    => [new \LogicException('An ISDS call needs a time limit')],
            'argument' => [new \InvalidArgumentException('The list of sent messages starts at 1')],
            'error'    => [new \Error('Call to a member function on null')],
        ];
    }

    // only ISDS's words of a refusal are kept: other messages come from curl, PHP or TLS and name files
    #[DataProvider('secretCarriers')]
    public function test_of_textWithoutPathOrPassword(\Throwable $error, string $text): void
    {
        $outcome = Outcome::of($error);

        self::assertSame($text, $outcome->text);
        self::assertStringNotContainsString(self::KEY, $outcome->text);
        self::assertStringNotContainsString(self::PASSWORD, $outcome->text);
    }

    public static function secretCarriers(): array
    {
        $curl = new \RuntimeException('could not load PEM client certificate from ' . self::KEY . ', OpenSSL error');
        return [
            'curl names the key'         => [TransportException::fromHttp(new HttpTransportException('No answer from ' . self::KEY, 0, $curl)), ''],
            'timeout quotes everything'  => [TransportException::fromHttp(new HttpTransportException('SSL read: ' . self::KEY . ' ' . self::PASSWORD, 0, new TimeoutException('Idle timeout reached'))), ''],
            'certificate refused'        => [new ConfigException('This server could not use the certificate of the ISDS account', 0, $curl), ''],
            'login refused'              => [new AuthException('ISDS refused the login (HTTP 401): ' . self::PASSWORD), ''],
            'address blocked'            => [new AddressBlockedException('ISDS blocked logins from ' . self::KEY . ' ' . self::PASSWORD), ''],
            'unavailable'                => [new UnavailableException('ISDS asks to come back later: ' . self::KEY), ''],
            'unknown error'              => [new \RuntimeException('fopen(' . self::KEY . '): Failed to open stream, ' . self::PASSWORD), ''],
            'refusal caused by the file' => [new IsdsException('ISDS refused the request: Invalid sender', 'Client', $curl), 'ISDS refused the request: Invalid sender'],
            'refusal on lines'           => [new IsdsException("Zpráva\n\t odmítnuta", '1214'), 'Zpráva odmítnuta'],
            'unclear answer'             => [TransportException::uncertain('ISDS answered HTTP 502: ' . self::KEY . ' ' . self::PASSWORD, '9001'), ''],
        ];
    }

    // a refusal as the client throws it: ISDS echoing the password does not bring it into the item
    public function test_of_refusalFromClientWithoutPassword(): void
    {
        $xml    = (string) file_get_contents(__DIR__ . '/fixtures/create-message-1233.xml');
        $xml    = str_replace('není OVM', 'není OVM (heslo ' . self::PASSWORD . ')', $xml);
        $client = new Client(new Account(Account::TEST, 'user01', new Secret(self::PASSWORD)), new FakeHttp([new Response(200, $xml)]), null, new MemoryLatch());
        try {
            $client->createMessage(new Message('kv62bqf', 'Pozvánka', [new File('Dopis.html', 'text/html', File::MAIN, '<p>Dobrý den</p>')]));
            self::fail('IsdsException expected');
        } catch (IsdsException $e) {
            $outcome = Outcome::of($e);
        }

        self::assertSame([Schema::MESSAGE_STATE_FAILED, '1233'], [$outcome->state, $outcome->isdsCode]);
        self::assertStringContainsString('není OVM (heslo ***)', $outcome->text);
        self::assertStringNotContainsString(self::PASSWORD, $outcome->text);
    }

    public function test_of_refusalTextIsCut(): void
    {
        $message = str_repeat('odmítnuto ', 100);

        $outcome = Outcome::of(new IsdsException($message, '1214'));

        self::assertSame(Soap::quote($message), $outcome->text);
    }
}
