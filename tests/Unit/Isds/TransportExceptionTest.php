<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;

final class TransportExceptionTest extends TestCase
{
    private const URL = '"https://ws1.datovka-test.gov.cz/DS/dz"';

    #[DataProvider('causes')]
    public function test_fromHttp_mayHaveBeenSent(\Throwable $cause, bool $sent): void
    {
        $http = new HttpTransportException('No answer from https://ws1.datovka-test.gov.cz/DS/dz', 0, $cause);
        $e    = TransportException::fromHttp($http);

        self::assertSame($sent, $e->mayHaveBeenSent());
        self::assertFalse($e->answered());
        self::assertSame('', $e->isdsCode());
        self::assertSame($http, $e->getPrevious());
        self::assertStringStartsWith('ISDS gave no answer: ', $e->getMessage());
    }

    /** texts as curl and Symfony word them: only a request that cannot have left is "not sent" */
    public static function causes(): array
    {
        $runtime = static fn(string $text): \RuntimeException => new \RuntimeException($text . ' for ' . self::URL . '.');
        return [
            'dns'               => [$runtime('Could not resolve host: ws1.datovka-test.gov.cz'), false],
            'malformed address' => [new \InvalidArgumentException('Malformed URL'), false],
            'refused'           => [$runtime('Failed to connect to ws1.datovka-test.gov.cz port 443 after 3 ms: Connection refused'), true],
            'connect timeout'   => [$runtime('Connection timed out after 10001 milliseconds'), true],
            'certificate'       => [$runtime('SSL certificate problem: unable to get local issuer certificate'), true],
            'our certificate'   => [$runtime('Could not load PEM client certificate from /etc/aa-isds/nszm.pem, OpenSSL error error:0A00018F:SSL routines::ee key too small'), false],
            'idle timeout'      => [new TimeoutException('Idle timeout reached for ' . self::URL . '.'), true],
            'reset'             => [$runtime('Recv failure: Connection reset by peer'), true],
            'empty reply'       => [$runtime('Empty reply from server'), true],
            'unknown'           => [$runtime('Something else'), true],
        ];
    }

    public function test_uncertain_mayHaveBeenSent(): void
    {
        $previous = new \UnexpectedValueException('not XML');
        $e        = TransportException::uncertain('ISDS answered something else', '9200', $previous);

        self::assertSame([true, true, '9200', $previous], [$e->mayHaveBeenSent(), $e->answered(), $e->isdsCode(), $e->getPrevious()]);
    }

    // an answer over the limit of the client: a question over a shorter time may fit, any other failure says nothing of it
    public function test_too_large_from_previous(): void
    {
        $tooLarge = TransportException::uncertain('The answer of ISDS to GetMessageStateChanges is too large', '', new ResponseTooLargeException('Response body exceeds 8388608 bytes'));

        self::assertTrue($tooLarge->tooLarge());
        self::assertFalse(TransportException::uncertain('ISDS answered something else', '', new \UnexpectedValueException('not XML'))->tooLarge());
        self::assertFalse(TransportException::fromHttp(new HttpTransportException('No answer', 0, new TimeoutException('Idle timeout reached')))->tooLarge());
    }
}
