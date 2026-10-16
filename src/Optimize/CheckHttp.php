<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\TransportException;

/**
 * Can this server fetch a page over HTTP at all, and does it know its own address?
 *
 * Both are invisible until something breaks far away from the cause: {include} of a local page
 * renders empty, Central cannot talk to its installs, the link checker reports everything dead.
 * The usual reasons are a server that cannot resolve its own hostname, a firewall that blocks
 * outgoing requests, or a missing CA bundle.
 *
 * Extended rather than basic: it makes a real request, so it is not something to run on every
 * admin page load.
 */
class CheckHttp extends AbstractOptimize
{
    /** @param ?ClientInterface $http tests hand in a fake; CheckRunner builds it with new CheckHttp() */
    public function __construct(private ?ClientInterface $http = null) {}

    public static function name(): string
    {
        return _m('Outgoing HTTP');
    }

    public static function description(): string
    {
        return _m('Fetches this installation\'s own address to check that PHP can make HTTP requests at all - {include} of local pages, Central and the link checker all need it.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'environment';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $url = (string) constant('AA_INSTAL_URL');   // config.php, so not visible to static analysis

        try {
            $answer = $this->http()->request('GET', $url);
        } catch (TransportException $e) {
            return [new Finding(
                Finding::ERROR,
                _m('PHP cannot fetch %1', [$url]),
                evidence: $this->evidence([_m('Reason') => $e->getMessage()]),
                advice: _m('Check that this server resolves its own hostname, that outgoing HTTP is not blocked by a firewall, and that PHP has a CA bundle for https. Until this works, {include} of local pages renders nothing and Central cannot reach its installations.'),
                key: 'unreachable',
            )];
        }

        if ($answer->redirect !== '') {
            return [new Finding(
                Finding::WARNING,
                _m('AA_INSTAL_URL redirects to %1', [$answer->redirect]),
                evidence: $this->evidence([_m('Configured') => $url, _m('Answered') => (string) $answer->status]),
                advice: _m('Set AA_INSTAL_URL in config.php to the address the server actually serves. Every extra hop is paid on each local request, and a redirect that drops the session cookie breaks included pages.'),
                key: 'redirect',
            )];
        }

        // an Apache with no index page in the AA directory and no directory listing answers
        // 403 or 404 to the bare address - a file AA ships tells whether it is still AA
        $file = rtrim($url, '/') . '/images/favicon.ico';
        if (in_array($answer->status, [403, 404], true) && $this->isImage($file)) {
            return [new Finding(
                Finding::OK,
                _m('The server reaches its own address (HTTP %1)', ['200']),
                evidence: $this->evidence([_m('Address') => $url, _m('Note') => _m('The address itself answers HTTP %1, %2 answers 200 - usual when the AA directory has no index page and the server lists no directories.', [(string) $answer->status, $file])]),
                key: 'ok',
            )];
        }

        if (!$answer->ok()) {
            return [new Finding(
                Finding::WARNING,
                _m('This installation\'s own address answers HTTP %1', [(string) $answer->status]),
                evidence: $this->evidence([_m('Address') => $url, _m('Answer') => $answer->excerpt(120)]),
                advice: _m('AA can reach the network, but AA_INSTAL_URL does not lead to this installation. Included local pages will render the error instead of the page.'),
                key: 'status',
            )];
        }

        return [new Finding(
            Finding::OK,
            _m('The server reaches its own address (HTTP %1)', [(string) $answer->status]),
            evidence: $this->evidence([_m('Address') => $url]),
            key: 'ok',
        )];
    }

    /** 200 with an image: an AA installation answers there, not a page catching every address */
    private function isImage(string $url): bool
    {
        try {
            $answer = $this->http()->request('GET', $url);
        } catch (TransportException) {
            return false;
        }
        return $answer->ok() && str_starts_with($answer->headers['content-type'] ?? '', 'image/');
    }

    /**
     * @param  array<string, string>     $rows
     * @return list<array<string,string>>
     */
    private function evidence(array $rows): array
    {
        if (function_exists('curl_version') && ($curl = curl_version())) {
            $rows[_m('curl')] = (string) ($curl['version'] ?? '');
        }

        // safe(), not htmlspecialchars() with our own flags: an excerpt of somebody else's error
        // page is the likeliest non-UTF-8 string in State Check, and the default flags would turn
        // it into an empty row - in the one check whose job is to explain a server that cannot fetch
        return [array_map(static fn(string $v): string => safe($v), $rows)];
    }

    private function http(): ClientInterface
    {
        // short: this is a check, and a server that needs more than ten seconds to answer itself
        // is the finding. No redirects - whether it redirects is what we want to know. The idle
        // timeout stays at the client's default: a loaded install can be quiet for several seconds
        // before the first byte, and reporting that as "PHP cannot fetch" would be a false alarm.
        return $this->http ??= new Client(timeout: 10, agent: Client::agent('State Check'));
    }
}
