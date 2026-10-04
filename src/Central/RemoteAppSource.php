<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use AA_Request;

/** An app of another AA, read through its Get_App (AppRequest there) with the short token of its
 *  superadmin. Gives what AppExport gives on that AA - the same XML - but not the export's warnings:
 *  Get_App sends only the errors. */
final class RemoteAppSource implements AppSource
{
    /** responder.php: isPerm() refused - for Get_App no Bearer login */
    private const NO_PERMISSION = 101;
    /** export errors taken from an answer - an export fails at its first ones */
    private const MAX_FINDINGS = 50;
    /** bytes of a path or ref of such an error */
    private const MAX_TEXT = 255;
    /** the source gave no echo of the filters: an older AA answers for all the items */
    private const CANNOT_FILTER = 'The source AA cannot filter items - update it first';

    private readonly string $ownAa;
    /** the source reported the AA_ID of this AA with the definitions */
    private bool $same = false;

    /** @param ?string $ownAa AA_ID of this AA; null = AA_ID */
    public function __construct(private readonly RemoteSource $remote, private readonly string $token, ?string $ownAa = null)
    {
        $this->ownAa = $ownAa ?? (string) constant('AA_ID');
    }

    public function sameAa(): bool
    {
        return $this->same;
    }

    public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
    {
        $ids = $this->get('item_ids', ['slice' => $slice, 'after' => $after], true, $allBins, filters: ItemFilter::ofSlices($filters, [$slice]));
        $bad = !is_array($ids) || !array_is_list($ids) || (count($ids) > self::ID_PAGE)
            || array_filter($ids, static fn(mixed $id): bool => !is_string($id) || !preg_match('/^[0-9a-f]{32}\z/', $id) || (strcmp($id, $after) <= 0));
        return $bad ? throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no list of item ids - update the source AA') : $ids;
    }

    public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
    {
        $answer = $this->get('definitions', ['modules' => $modules, 'started' => $started], $transfer, $allBins, RemoteSource::MAX_BYTES);
        if (!is_array($answer) || !is_int($answer['now'] ?? null) || !is_string($answer['aa'] ?? null)) {
            throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no package - update the source AA');
        }
        $this->same = hash_equals($this->ownAa, $answer['aa']);
        return [self::xml($answer['package'] ?? null), [], $answer['now']];
    }

    public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = []): array
    {
        return [self::xml($this->get('batch', ['slice' => $slice, 'after' => $after, 'started' => $started], true, $allBins, RemoteSource::MAX_BYTES, ItemFilter::ofSlices($filters, [$slice]))), []];
    }

    public function logins(array $slices, bool $allBins, array $filters = []): array
    {
        $logins = $this->get('logins', ['slices' => $slices], true, $allBins, filters: ItemFilter::ofSlices($filters, $slices));
        if (!is_array($logins) || !array_is_list($logins) || array_filter($logins, static fn(mixed $l): bool => !is_string($l))) {
            throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no list of logins');
        }
        return $logins;
    }

    public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
    {
        $counts = $this->get('counts', ['slices' => $slices, 'edited_after' => $editedAfter], false, $allBins, filters: ItemFilter::ofSlices($filters, $slices));
        return self::counts($counts, $slices) ?? throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no item counts');
    }

    /** without filters nothing is left out - no question to the source, which may be older */
    public function leftOut(array $filters, array $slices, bool $allBins): array
    {
        $filters = ItemFilter::ofSlices($filters, $slices);
        if (!$filters) {
            return array_fill_keys($slices, 0);
        }
        $counts = $this->get('left_out', ['slices' => $slices], true, $allBins, filters: $filters);
        return self::counts($counts, $slices) ?? throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no counts of the links left out');
    }

    /** The modules of the source for the choice of the app - by the same short token as the app itself.
     *  @param list<string> $types module types (W, S, Alerts) @return array<string, string> id => name
     *  @throws SourceFailed */
    public function modules(array $types): array
    {
        $response = $this->remote->ask(new AA_Request('Get_Modules', ['types' => $types]), [], $this->token);
        if ($response->isError()) {
            throw self::failed($response->getError());
        }
        $modules = $response->getResponse();
        $valid   = is_array($modules) && !array_filter($modules, static fn(mixed $name, int|string $id): bool => !is_string($name) || !preg_match('/^[0-9a-f]{32}\z/', (string) $id), ARRAY_FILTER_USE_BOTH);
        return $valid ? $modules : throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no module list');
    }

    /** The apps the source lists for the token (Get_Apps, AppStore::apps()).
     *  @return list<array{id: string, name: string, description: string, version: string, published: bool, slices: int, alerts: int}>
     *  @throws SourceFailed */
    public function apps(): array
    {
        $response = $this->remote->ask(new AA_Request('Get_Apps', []), [], $this->token);
        if ($response->isError()) {
            throw self::failed($response->getError());
        }
        $apps  = $response->getResponse();
        $types = ['id' => 'string', 'name' => 'string', 'description' => 'string', 'version' => 'string', 'published' => 'boolean', 'slices' => 'integer', 'alerts' => 'integer'];
        if (!is_array($apps) || !array_is_list($apps)) {
            throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no list of apps');
        }
        $known = [];
        foreach ($apps as $app) {
            // a newer store may tell more - only the keys this AA knows are kept, each of its type
            if (!is_array($app) || array_diff_key($types, $app)) {
                throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no list of apps');
            }
            $app = array_merge($types, array_intersect_key($app, $types));     // in the order of $types
            if ((array_map('gettype', $app) !== $types) || !preg_match('/^[0-9a-f]{32}\z/', $app['id'])) {
                throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no list of apps');
            }
            $known[] = $app;
        }
        return $known;
    }

    /** an error answer the page can tell */
    private static function failed(int $code): SourceFailed
    {
        return match ($code) {
            ResponderPolicy::ERR_TOKEN_REFUSED, self::NO_PERMISSION => new SourceFailed(SourceFailed::LOGIN, 'The source AA refused the login token - log in to it again'),
            ResponderPolicy::ERR_UNKNOWN_COMMAND => new SourceFailed(SourceFailed::FAILED, 'The source AA cannot give apps - update it first'),
            ResponderPolicy::ERR_PUBLIC_ACCOUNT  => new SourceFailed(SourceFailed::FAILED, 'The app store account may not do this'),
            ResponderPolicy::ERR_RATE_LIMIT      => new SourceFailed(SourceFailed::FAILED, 'The app store is busy - try again later'),
            default => new SourceFailed(SourceFailed::FAILED, "The source AA answered error $code"),
        };
    }

    /** The answer of a part. Narrowed by filters, it must echo them (AppRequest) - else the source answered
     *  for all the items.
     *  @param array<string, mixed> $params @param array<string, string> $filters of the part's slices only
     *  @throws ExportRefused|SourceFailed */
    private function get(string $part, array $params, bool $transfer, bool $allBins, int $maxBytes = RemoteSource::SMALL_BYTES, array $filters = []): mixed
    {
        $params   = ['part' => $part, 'mode' => $transfer ? 'transfer' : 'install', 'bins' => $allBins ? 'all' : 'active'] + $params + ($filters ? ['filters' => $filters] : []);
        $response = $this->remote->ask(new AA_Request('Get_App', $params), [], $this->token, $maxBytes);
        if (!$response->isError()) {
            $answer = $response->getResponse();
            if (!$filters) {
                return $answer;
            }
            $echoed = is_array($answer) && is_string($answer['filters'] ?? null) && hash_equals(ItemFilter::digest($filters), $answer['filters']) && array_key_exists('answer', $answer);
            return $echoed ? $answer['answer'] : throw new SourceFailed(SourceFailed::FAILED, self::CANNOT_FILTER);
        }
        throw match ($response->getError()) {
            ResponderPolicy::ERR_APP_DENIED   => ExportRefused::denied(),
            ResponderPolicy::ERR_APP_EXPORT   => ExportRefused::failed(self::findings($response->getResponse())),
            // an older source knows no such part
            ResponderPolicy::ERR_APP_REQUEST  => ($part === 'left_out') ? new SourceFailed(SourceFailed::FAILED, self::CANNOT_FILTER) : self::failed(ResponderPolicy::ERR_APP_REQUEST),
            default => self::failed($response->getError()),
        };
    }

    /** a count per asked slice, nothing else; null for anything else
     *  @param list<string> $slices @return array<string, int>|null */
    private static function counts(mixed $counts, array $slices): ?array
    {
        $keys  = is_array($counts) ? array_map('strval', array_keys($counts)) : null;
        $valid = ($keys !== null) && !array_diff($keys, $slices) && !array_diff($slices, $keys) && !array_filter($counts, static fn(mixed $n): bool => !is_int($n) || ($n < 0));
        return $valid ? $counts : null;
    }

    /** @throws SourceFailed */
    private static function xml(mixed $xml): string
    {
        return (is_string($xml) && ($xml !== '')) ? $xml : throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no package');
    }

    /** the "code path ref" lines of AppRequest as findings of level E; source text - escape it
     *  @return list<array{string, LintFinding}> */
    private static function findings(mixed $lines): array
    {
        $findings = [];
        foreach (is_array($lines) ? array_slice($lines, 0, self::MAX_FINDINGS) : [] as $line) {
            $parts = is_string($line) ? explode(' ', $line, 3) : [];
            // a code the run can keep as its error code (Importer::errorCode())
            if (preg_match('/^[a-z][a-z0-9-]{0,63}\z/', $parts[0] ?? '') && isset($parts[1])) {
                $findings[] = [mb_strcut($parts[1], 0, self::MAX_TEXT), new LintFinding(LintFinding::ERROR, $parts[0], mb_strcut($parts[2] ?? '', 0, self::MAX_TEXT), 0)];
            }
        }
        return $findings ?: [['source', new LintFinding(LintFinding::ERROR, 'export-failed', '', 0)]];
    }
}
