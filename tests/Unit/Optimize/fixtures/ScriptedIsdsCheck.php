<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Http\ClientInterface;
use AA\Http\TransportException;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\DatovkaConf;
use AA\Isds\LoginLatch;
use AA\Optimize\CheckIsds;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Fixtures\MemoryLatch;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/../../Isds/fixtures/Sending.php';

/** CheckIsds over the accounts in $accounts, the answers of $http and the refusals in $latch
 *  instead of datovka_conf.php, ISDS and the AA log, with the modules $here in this database. Unattended
 *  - the daily run - unless told. */
final class ScriptedIsdsCheck extends CheckIsds
{
    /** @var array<array-key, mixed> as DATOVKA_CONF['accounts'] */
    public array $accounts = [];

    /** the whole settings instead of $accounts - a file AA cannot use */
    public ?DatovkaConf $conf = null;

    /** @var ?list<string> the sites and slices of this database; null = all */
    public ?array $here = null;

    public ClientInterface $http;

    public MemoryLatch $latch;

    public bool $unattended = true;

    public int $now = 1790841600;

    public function __construct()
    {
        $this->http  = new FakeHttp();
        $this->latch = new MemoryLatch();
    }

    protected function conf(): DatovkaConf
    {
        return $this->conf ?? Sending::conf($this->accounts);
    }

    protected function modulesHere(array $ids): array
    {
        return is_null($this->here) ? $ids : array_values(array_intersect($ids, $this->here));
    }

    protected function latch(): LoginLatch
    {
        return $this->latch;
    }

    /** a certificate goes through the HTTP client of AA - over a transport that asks $http */
    protected function client(Account $account, LoginLatch $latch): Client
    {
        if (is_null($account->certificate)) {
            return new Client($account, $this->http, latch: $latch);
        }
        $http = $this->http;

        return new Client($account, transport: new MockHttpClient(static function (string $method, string $url, array $options) use ($http): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', (string) $line, 2) + [1 => ''];
                $headers[$name] = $value;
            }
            try {
                $response = $http->request($method, $url, is_string($options['body'] ?? null) ? $options['body'] : null, $headers);
            } catch (TransportException $e) {
                return new MockResponse('', ['error' => $e->getMessage()]);
            }

            return new MockResponse($response->body, ['http_code' => $response->status]);
        }), latch: $latch);
    }

    protected function unattended(): bool
    {
        return $this->unattended;
    }

    protected function now(): int
    {
        return $this->now;
    }
}
