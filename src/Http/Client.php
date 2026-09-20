<?php

declare(strict_types=1);

namespace AA\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * AA's HTTP client, on symfony/http-client.
 *
 * Not hand-written curl and not the old AA_Http (file_get_contents, no timeout, and false for
 * anything that is not 200 - which loses the difference between a bad key, an empty credit and
 * a service that is briefly down). The library brings what we would otherwise write ourselves
 * and get wrong: timeouts, decompression, and - when the crawler arrives - NoPrivateNetworkHttpClient
 * against SSRF, RetryableHttpClient and ThrottlingHttpClient.
 *
 * Redirects are off: an API that sends us somewhere else is not an API answer.
 */
final class Client implements ClientInterface
{
    private readonly HttpClientInterface $http;

    /**
     * @param int $timeout      whole call, seconds - a nightly run must not hang on one service
     * @param int $connect      reaching the host at all
     */
    public function __construct(int $timeout = 30, int $connect = 10, string $agent = 'ActionApps SEO', ?HttpClientInterface $http = null)
    {
        $this->http = $http ?? HttpClient::create([
            'max_duration' => $timeout,
            'timeout'      => $connect,
            'max_redirects' => 0,
            'headers'      => ['User-Agent' => $agent],
        ]);
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $options = ['headers' => $headers];
        if (!is_null($body)) {
            $options['body'] = $body;
        }

        try {
            $answer  = $this->http->request(strtoupper($method), $url, $options);
            $status  = $answer->getStatusCode();
            // false: do not let the library throw on 4xx/5xx - the status is the answer we want
            $content = $answer->getContent(false);
            $out     = [];
            foreach ($answer->getHeaders(false) as $name => $values) {
                $out[strtolower($name)] = (string) ($values[0] ?? '');
            }
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('No answer from ' . $url . ' - ' . $e->getMessage(), 0, $e);
        }

        return new Response($status, $content, $out);
    }
}
