<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\FormToken;
use AA\Util\InputVars;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/** The nightly test (test4update) pushes its findings here: a POST to admin/aa_statecheck.php
 *  with the page's own form token, `nightly_import=1` and `payload` = JSON (run, mode,
 *  started, finished, checks_ran, findings). Superadmin only. Everything in the payload is
 *  checked here - it carries text taken from crawled pages - and stored by NightlyLog.
 *  The answer is JSON: {"ok":true,"stored":N}, or {"ok":false,"error":"…"} with a 4xx status,
 *  500 when storing failed.
 *
 *  @phpstan-import-type Push from NightlyLog
 */
final class NightlyImport
{
    /** the POST field that marks a push */
    public const FIELD = 'nightly_import';
    /** the purpose of the FormToken the page's own forms carry */
    private const TOKEN = 'aa_statecheck';

    public const MAX_BYTES    = 256 * 1024;
    public const MAX_FINDINGS = 1000;
    private const MAX_CHECKS  = 500;
    private const ID          = '/^[a-z0-9-]{1,40}$/';
    /** 20261014-0330, or a name given to a rerun by hand */
    private const RUN         = '/^[A-Za-z0-9][A-Za-z0-9-]{0,23}$/';
    private const SEVERITIES  = [Finding::ERROR, Finding::WARNING, Finding::INFO];
    /** characters */
    private const PAGE_MAX     = 200;
    private const EVIDENCE_MAX = 120;
    private const REASON_MAX   = 120;
    /** bytes of log.selector, which holds the page and the url */
    private const SELECTOR_MAX = 255;
    /** clock skew allowed between the test machine and the server */
    private const FUTURE_MAX = 3600;

    /** Answers the push and ends the request - nothing of the page may follow the JSON. */
    public static function respond(): never
    {
        ob_start();                     // a warning printed on the way would break the JSON
        [$status, $body] = self::answer();
        ob_end_clean();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @return array{0: int, 1: array<string, bool|int|string>}  HTTP status and the JSON body */
    private static function answer(): array
    {
        if (!IsSuperadmin()) {
            return self::error(403, 'superadmin only');
        }
        if (!FormToken::checkPost(self::TOKEN, InputVars::stringPOST('token'), $_SERVER)) {
            return self::error(403, 'missing or expired token - read it from the page in the same session');
        }
        try {
            $payload = InputVars::stringPOST('payload', true);
        } catch (RuntimeException) {
            return self::error(400, 'payload missing');
        }
        if (strlen($payload) > self::MAX_BYTES) {
            return self::error(413, 'payload over ' . self::MAX_BYTES . ' bytes');
        }
        $now = now();
        try {
            $push = self::validate($payload, (string) constant('AA_INSTAL_URL'), $now);
        } catch (InvalidArgumentException $e) {
            return self::error(400, $e->getMessage());
        }
        try {
            return [200, ['ok' => true, 'stored' => NightlyLog::import($push, $now)]];
        } catch (Throwable) {       // the log insert does not halt (AA_Log::writeSet()), it throws
            return self::error(500, 'the findings could not be stored');
        }
    }

    /** @return array{0: int, 1: array{ok: false, error: string}} */
    private static function error(int $status, string $message): array
    {
        return [$status, ['ok' => false, 'error' => $message]];
    }

    /** The payload, checked and normalized. The messages name the field, never echo its value.
     * @param string $install_url  AA_INSTAL_URL - a url must point to its origin
     * @return Push
     * @throws InvalidArgumentException
     */
    public static function validate(string $json, string $install_url, int $now): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('payload is not valid JSON');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('payload must be a JSON object');
        }
        $run = $data['run'] ?? null;
        if (!is_string($run) || !preg_match(self::RUN, $run)) {
            throw new InvalidArgumentException('run must be a run id like 20261014-0330');
        }
        $mode = $data['mode'] ?? null;
        if (($mode !== 'full') && ($mode !== 'only')) {
            throw new InvalidArgumentException('mode must be "full" or "only"');
        }
        $started  = $data['started'] ?? null;
        $finished = $data['finished'] ?? null;
        if (!is_int($started) || !is_int($finished) || ($started < 1_000_000_000) || ($finished < $started) || ($finished > $now + self::FUTURE_MAX)) {
            throw new InvalidArgumentException('started and finished must be unixtimes, started <= finished, not in the future');
        }

        $list = $data['checks_ran'] ?? null;
        if (!is_array($list) || !array_is_list($list) || !$list || (count($list) > self::MAX_CHECKS)) {
            throw new InvalidArgumentException('checks_ran must be a list of 1 to ' . self::MAX_CHECKS . ' {check, spec}');
        }
        $ran   = [];
        $specs = [];    // check => specs it ran in
        foreach ($list as $i => $entry) {
            $check = self::id($entry, 'check', "checks_ran[$i]");
            $spec  = self::id($entry, 'spec', "checks_ran[$i]");
            $ran[NightlyLog::pairKey($check, $spec)] = ['check' => $check, 'spec' => $spec];
            $specs[$check][$spec] = $spec;
        }

        $list = $data['findings'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('findings must be a list');
        }
        if (count($list) > self::MAX_FINDINGS) {
            throw new InvalidArgumentException('at most ' . self::MAX_FINDINGS . ' findings');
        }
        $findings = [];
        foreach ($list as $i => $finding) {
            $at = "findings[$i]";
            if (!is_array($finding) || array_is_list($finding)) {
                throw new InvalidArgumentException("$at must be an object");
            }
            $check = self::id($finding, 'check', $at);
            if (!isset($specs[$check])) {
                throw new InvalidArgumentException("$at.check is not in checks_ran");
            }
            if (isset($finding['spec'])) {
                $spec = self::id($finding, 'spec', $at);
                if (!isset($specs[$check][$spec])) {
                    throw new InvalidArgumentException("$at.spec did not run this check (checks_ran)");
                }
            } elseif (count($specs[$check]) === 1) {
                $spec = reset($specs[$check]);
            } else {
                throw new InvalidArgumentException("$at.spec is needed - the check ran in more than one spec");
            }
            $severity = $finding['severity'] ?? null;
            if (!in_array($severity, self::SEVERITIES, true)) {
                throw new InvalidArgumentException("$at.severity must be error, warning or info");
            }
            // the page is the finding's identity across runs, so it is cut the same way every time
            $page = mb_strcut(self::text($finding, 'page', $at, self::PAGE_MAX, true), 0, self::SELECTOR_MAX, 'UTF-8');
            if ($page === '') {
                throw new InvalidArgumentException("$at.page is empty");
            }
            $known = $finding['known'] ?? false;
            if (!is_bool($known)) {
                throw new InvalidArgumentException("$at.known must be true or false");
            }
            $findings[] = [
                'check'    => $check,
                'spec'     => $spec,
                'severity' => $severity,
                'page'     => $page,
                'url'      => self::url($finding['url'] ?? '', $install_url),
                'evidence' => self::text($finding, 'evidence', $at, self::EVIDENCE_MAX, false),
                'known'    => $known,
                'reason'   => $known ? self::text($finding, 'known_reason', $at, self::REASON_MAX, false) : '',
            ];
        }
        return ['run' => $run, 'mode' => $mode, 'started' => $started, 'finished' => $finished, 'ran' => $ran, 'findings' => $findings];
    }

    /** The url when it is on the install's own origin (scheme, host and port) and fits the log,
     *  '' otherwise - a link is dropped, never the finding. */
    public static function url(mixed $url, string $install_url): string
    {
        if (!is_string($url) || (strlen($url) > self::SELECTOR_MAX) || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return '';
        }
        $origin = self::origin($url);
        return (($origin !== '') && ($origin === self::origin($install_url))) ? $url : '';
    }

    /** scheme://host:port in lower case, the port filled in from the scheme; '' for anything but http(s) */
    private static function origin(string $url): string
    {
        $parts  = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? (($scheme === 'https') ? 443 : 80));
    }

    /** a check or spec id */
    private static function id(mixed $data, string $field, string $at): string
    {
        $value = is_array($data) ? ($data[$field] ?? null) : null;
        if (!is_string($value) || !preg_match(self::ID, $value)) {
            throw new InvalidArgumentException("$at.$field must match ^[a-z0-9-]{1,40}$");
        }
        return $value;
    }

    /** Plain text on one line, capped: a crawled error message spans lines and indents, and
     *  invisible format characters (bidi overrides, zero widths) could disguise what a row says.
     * @param array<mixed> $data */
    private static function text(array $data, string $field, string $at, int $max, bool $required): string
    {
        $value = $data[$field] ?? ($required ? null : '');
        if (!is_string($value)) {
            throw new InvalidArgumentException("$at.$field must be a string");
        }
        return mb_substr(trim((string) preg_replace('/[\x20\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value)), 0, $max, 'UTF-8');
    }
}
