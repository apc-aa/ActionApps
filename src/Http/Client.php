<?php

declare(strict_types=1);

namespace AA\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * AA's HTTP client, on symfony/http-client.
 *
 * Not hand-written curl and not the old AA_Http (file_get_contents, no timeout, and false for
 * anything that is not 200 - which loses the difference between a bad key, an empty credit and
 * a service that is briefly down). The library brings what we would otherwise write ourselves
 * and get wrong: timeouts, decompression, and - when the crawler arrives - NoPrivateNetworkHttpClient
 * against SSRF, RetryableHttpClient and ThrottlingHttpClient.
 *
 * Redirects are off by default: an API that sends us somewhere else is not an API answer. The
 * callers that do have to follow - a template including a page, a link checker - say so.
 */
final class Client implements ClientInterface
{
    private readonly HttpClientInterface $http;

    /**
     * How AA introduces itself: one name and one contact address, so a site owner reading their
     * access log can tell who is fetching them and a bot-filter rule written against one of AA's
     * fetchers covers them all.
     *
     * No version. It would help whoever we are fetching diagnose us, but a template can include
     * any address, so it would announce the exact release to anyone who asks for it.
     */
    public static function agent(string $purpose = ''): string
    {
        return 'ActionApps (+https://actionapps.org' . ($purpose === '' ? '' : '; ' . $purpose) . ')';
    }

    /**
     * @param int $timeout   whole call, seconds - a nightly run must not hang on one service
     * @param int $idle      seconds of silence before giving up; this is what the library calls
     *                       "timeout", and it restarts with every byte that arrives, so it is not
     *                       a ceiling on the call - $timeout is
     * @param string $agent  '' takes AA's own name. There is no way to send none: left out, the
     *                       library fills in its own name
     * @param int $redirects how many 3xx hops to follow; 0 means the 3xx itself is the answer
     * @param int $maxBytes  largest body accepted, 0 = no limit; over it ResponseTooLargeException,
     *                       decided by Content-Length when sent, else while the body arrives
     */
    public function __construct(int $timeout = 30, int $idle = 10, string $agent = '', int $redirects = 0, ?HttpClientInterface $http = null, private readonly int $maxBytes = 0)
    {
        $options = [
            'max_duration'  => $timeout,
            'timeout'       => $idle,
            'max_redirects' => $redirects,
            'headers'       => ['User-Agent' => $agent === '' ? self::agent() : $agent],
        ];
        // the policy is AA's, not the transport's: a caller who hands us a client - a test with a
        // mock, later a throttled or SSRF-guarded one - still gets our timeouts and redirect rule
        $this->http = is_null($http) ? HttpClient::create($options) : $http->withOptions($options);
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
            $out     = [];
            foreach ($answer->getHeaders(false) as $name => $values) {
                $out[strtolower($name)] = (string) ($values[0] ?? '');
            }
            // false: do not let the library throw on 4xx/5xx - the status is the answer we want
            $content = ($this->maxBytes > 0) ? $this->limitedContent($answer, $out, $url, strtoupper($method) !== 'HEAD' && !in_array($status, [204, 304], true)) : $answer->getContent(false);
            // where a 3xx we did not follow points, resolved against the request - the link
            // checker records the whole chain and State Check compares the target
            $redirect = (string) ($answer->getInfo('redirect_url') ?? '');
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('No answer from ' . $url . ' - ' . $e->getMessage(), 0, $e);
        }

        return new Response($status, $content, $out, $redirect);
    }

    /**
     * @param array<string, string> $headers
     * @param bool $has_body HEAD, 204 and 304 carry the length of a body they do not send
     */
    private function limitedContent(ResponseInterface $answer, array $headers, string $url, bool $has_body): string
    {
        if ($has_body && ((int) ($headers['content-length'] ?? 0) > $this->maxBytes)) {
            $answer->cancel();
            throw new ResponseTooLargeException("The answer from $url is over {$this->maxBytes} bytes");
        }
        // Content-Length may be missing or false; count what really arrives (decoded)
        $content = '';
        foreach ($this->http->stream($answer) as $chunk) {
            $content .= $chunk->getContent();
            if (strlen($content) > $this->maxBytes) {
                $answer->cancel();
                throw new ResponseTooLargeException("The answer from $url is over {$this->maxBytes} bytes");
            }
        }
        return $content;
    }
}
