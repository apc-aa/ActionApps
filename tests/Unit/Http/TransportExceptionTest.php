<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use AA\Http\Client;
use AA\Http\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TransportExceptionTest extends TestCase
{
    #[DataProvider('chains')]
    public function test_malformedAddress_byCauseInChain(?\Throwable $cause, bool $expected): void
    {
        self::assertSame($expected, (new TransportException('No answer', 0, $cause))->malformedAddress());
    }

    public static function chains(): array
    {
        return [
            'no cause'               => [null, false],
            'timeout'                => [new \RuntimeException('Timeout reached'), false],
            'invalid argument'       => [new \InvalidArgumentException('Malformed URL'), true],
            'type error'             => [new \TypeError('file:// is no http'), true],
            'deeper in the chain'    => [new \RuntimeException('wrapped', 0, new \InvalidArgumentException('Malformed URL')), true],
            'logic error is no sign' => [new \LogicException('bug'), false],
        ];
    }

    // the library rejects the scheme before any request and wraps the reason
    public function test_malformedAddress_unsupportedSchemeFromClient(): void
    {
        try {
            (new Client(http: new MockHttpClient([new MockResponse('a')])))->request('GET', 'ftp://example.invalid/a');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertTrue($e->malformedAddress());
        }
    }

    #[DataProvider('blockChains')]
    public function test_blockedHost_byCauseInChain(?\Throwable $cause, ?string $expected): void
    {
        self::assertSame($expected, (new TransportException('No answer', 0, $cause))->blockedHost());
    }

    public static function blockChains(): array
    {
        return [
            'no cause'            => [null, null],
            'timeout'             => [new \RuntimeException('Timeout reached'), null],
            'ip'                  => [new \RuntimeException('IP "127.0.0.1" is blocked for "http://127.0.0.1/".'), '127.0.0.1'],
            'host'                => [new \RuntimeException('Host "intranet" is blocked for "http://intranet/".'), 'intranet'],
            'deeper in the chain' => [new \RuntimeException('wrapped', 0, new \RuntimeException('IP "::1" is blocked for "http://[::1]/".')), '::1'],
            'only at the start'   => [new \RuntimeException('Why IP "10.0.0.1" is blocked for "x"'), null],
        ];
    }

    public function test_malformedAddress_noAnswerIsNot(): void
    {
        try {
            (new Client(http: new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])])))->request('GET', 'https://example.invalid/a');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertFalse($e->malformedAddress());
        }
    }

    /** the kind is one of a closed set, read from the library's causes - never from the message of
     *  the exception itself, which carries the URL */
    #[DataProvider('kinds')]
    public function test_kind_byCause(?\Throwable $cause, string $expected): void
    {
        self::assertSame($expected, (new TransportException('No answer from https://dns.timeout.example/?k=tls', 0, $cause))->kind());
    }

    public static function kinds(): array
    {
        return [
            'no cause'          => [null, ''],
            'curl dns'          => [new \RuntimeException('Could not resolve host: data.example for "https://data.example/"'), TransportException::DNS],
            'getaddrinfo'       => [new \RuntimeException('getaddrinfo failed for "data.example"'), TransportException::DNS],
            'php stream lookup' => [new \RuntimeException('php_network_getaddresses: getaddrinfo for data.example failed'), TransportException::DNS],
            // curl 8.18 wording
            'curl 8 dns'           => [new \RuntimeException('Could not resolve host: data.example'), TransportException::DNS],
            'curl 8 refused'       => [new \RuntimeException('Failed to connect to 1.1.1.1 port 1 after 0 ms: Could not connect to server'), TransportException::REFUSED],
            'curl 8 timeout'       => [new \RuntimeException('Connection timed out after 2000 milliseconds'), TransportException::TIMEOUT],
            'curl 8 connect timeout' => [new \RuntimeException('Failed to connect to 1.1.1.1 port 443 after 2000 ms: Timeout was reached'), TransportException::TIMEOUT],
            'ssl timeout'          => [new \RuntimeException('SSL connection timeout'), TransportException::TIMEOUT],
            'curl 8 tls'           => [new \RuntimeException('TLS connect error: error:0A000410:SSL routines::ssl/tls alert handshake failure'), TransportException::TLS],
            'curl 8 verify'        => [new \RuntimeException('SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)'), TransportException::TLS],
            'curl 8 name mismatch' => [new \RuntimeException("SSL: no alternative certificate subject name matches target hostname 'data.example'"), TransportException::TLS],
            // a host named like a phrase of another kind
            'refused by getaddrinfo host' => [new \RuntimeException('Failed to connect to getaddrinfo.example port 1 after 0 ms: Could not connect to server'), TransportException::REFUSED],
            'tls of getaddrinfo host'     => [new \RuntimeException("SSL: no alternative certificate subject name matches target hostname 'getaddrinfo.example'"), TransportException::TLS],
            'quoted host says timeout'    => [new \RuntimeException("Connection refused to 'timed out.example'"), TransportException::REFUSED],
            'apostrophe is no quote'      => [new \RuntimeException("Couldn't connect to server"), TransportException::REFUSED],
            'idle timeout'      => [new \Symfony\Component\HttpClient\Exception\TimeoutException('Idle timeout reached'), TransportException::TIMEOUT],
            'curl timeout'      => [new \RuntimeException('Operation timed out after 30001 milliseconds'), TransportException::TIMEOUT],
            'max duration'      => [new \RuntimeException('Max duration was reached for "https://1.1.1.1/"'), TransportException::TIMEOUT],
            'certificate'       => [new \RuntimeException('SSL certificate problem: unable to get local issuer certificate'), TransportException::TLS],
            'refused'           => [new \RuntimeException('Failed to connect to 1.1.1.1 port 443: Connection refused'), TransportException::REFUSED],
            'reset'             => [new \RuntimeException('Recv failure: Connection reset by peer'), TransportException::REFUSED],
            'something else'    => [new \RuntimeException('Weird failure'), ''],
            // this server's own certificate, before the handshake: nothing left
            'curl, weak key'          => [new \RuntimeException('Could not load PEM client certificate from /etc/aa/c.pem, OpenSSL error error:0A00018F:SSL routines::ee key too small, (no key found, wrong passphrase, or wrong file format?) for "https://ws1c.datovka.gov.cz/cert/DS/dz".'), TransportException::CLIENT_CERTIFICATE],
            'curl, key file'          => [new \RuntimeException("unable to set private key file: '/etc/aa/c.key' type PEM"), TransportException::CLIENT_CERTIFICATE],
            'curl, no key'            => [new \RuntimeException('unable to use client certificate (no key found or wrong pass phrase?)'), TransportException::CLIENT_CERTIFICATE],
            'curl, another key'       => [new \RuntimeException('Private key does not match the certificate public key'), TransportException::CLIENT_CERTIFICATE],
            'curl error 58'           => [new \RuntimeException('Problem with the local SSL certificate'), TransportException::CLIENT_CERTIFICATE],
            'php streams, chain'      => [new \RuntimeException("fopen(): Unable to set local cert chain file `/etc/aa/c.pem'; Check that your cafile/capath settings include details of your certificate and its issuer"), TransportException::CLIENT_CERTIFICATE],
            'php streams, key file'   => [new \RuntimeException("fopen(): Unable to set private key file `/etc/aa/c.key'"), TransportException::CLIENT_CERTIFICATE],
            'the server certificate'  => [new \RuntimeException('SSL certificate problem: unable to get local issuer certificate'), TransportException::TLS],
            'deeper in a chain' => [new \RuntimeException('wrapped', 0, new \RuntimeException('Could not resolve host: x')), TransportException::DNS],
            // the URL in quotes is the source's (a redirect picks it): its words say nothing of the failure
            'refused, timeout in the URL'   => [new \RuntimeException('Failed to connect to 1.1.1.1 port 443: Connection refused for "https://1.1.1.1/data?timeout=30".'), TransportException::REFUSED],
            'refused, certificate in path'  => [new \RuntimeException('Connection refused for "https://1.1.1.1/certificates/list".'), TransportException::REFUSED],
            'host named timeout'            => [new \RuntimeException('Could not resolve host: timeout.example for "https://timeout.example/".'), TransportException::DNS],
            'only the URL speaks'           => [new \RuntimeException('Weird failure for "https://1.1.1.1/ssl/timeout"'), ''],
        ];
    }

    public function test_kind_malformedAddressIsAddress(): void
    {
        self::assertSame(TransportException::ADDRESS, (new TransportException('No answer', 0, new \InvalidArgumentException('Unsupported scheme')))->kind());
    }

    public function test_kind_ofClientFailure(): void
    {
        try {
            (new Client(http: new MockHttpClient([new MockResponse('', ['error' => 'Could not resolve host: example.invalid'])])))->request('GET', 'https://example.invalid/a');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertSame(TransportException::DNS, $e->kind());
        }
    }
}
