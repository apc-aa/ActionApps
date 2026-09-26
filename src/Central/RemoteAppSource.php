<?php

declare(strict_types=1);

namespace AA\Central;

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

    public function itemIds(string $slice, bool $allBins, string $after): array
    {
        $ids = $this->get('item_ids', ['slice' => $slice, 'after' => $after], true, $allBins);
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

    public function batch(string $slice, string $after, bool $allBins, int $started): array
    {
        return [self::xml($this->get('batch', ['slice' => $slice, 'after' => $after, 'started' => $started], true, $allBins, RemoteSource::MAX_BYTES)), []];
    }

    public function logins(array $slices, bool $allBins): array
    {
        $logins = $this->get('logins', ['slices' => $slices], true, $allBins);
        if (!is_array($logins) || !array_is_list($logins) || array_filter($logins, static fn(mixed $l): bool => !is_string($l))) {
            throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no list of logins');
        }
        return $logins;
    }

    public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0): array
    {
        $counts = $this->get('counts', ['slices' => $slices, 'edited_after' => $editedAfter], false, $allBins);
        $keys   = is_array($counts) ? array_map('strval', array_keys($counts)) : null;
        $valid  = ($keys !== null) && !array_diff($keys, $slices) && !array_diff($slices, $keys) && !array_filter($counts, static fn(mixed $n): bool => !is_int($n) || ($n < 0));
        return $valid ? $counts : throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no item counts');
    }

    /** The modules of the source for the choice of the app - by the same short token as the app itself.
     *  @param list<string> $types module types (W, S, Alerts) @return array<string, string> id => name
     *  @throws SourceFailed */
    public function modules(array $types): array
    {
        $response = $this->remote->ask(new AA_Request('Get_Modules', ['types' => $types]), [], $this->token);
        if ($response->isError()) {
            throw in_array($response->getError(), [ResponderPolicy::ERR_TOKEN_REFUSED, self::NO_PERMISSION], true)
                ? new SourceFailed(SourceFailed::LOGIN, 'The source AA refused the login token - log in to it again')
                : new SourceFailed(SourceFailed::FAILED, 'The source AA answered error ' . $response->getError());
        }
        $modules = $response->getResponse();
        $valid   = is_array($modules) && !array_filter($modules, static fn(mixed $name, int|string $id): bool => !is_string($name) || !preg_match('/^[0-9a-f]{32}\z/', (string) $id), ARRAY_FILTER_USE_BOTH);
        return $valid ? $modules : throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave no module list');
    }

    /** @param array<string, mixed> $params @throws ExportRefused|SourceFailed */
    private function get(string $part, array $params, bool $transfer, bool $allBins, int $maxBytes = RemoteSource::SMALL_BYTES): mixed
    {
        $request  = new AA_Request('Get_App', ['part' => $part, 'mode' => $transfer ? 'transfer' : 'install', 'bins' => $allBins ? 'all' : 'active'] + $params);
        $response = $this->remote->ask($request, [], $this->token, $maxBytes);
        if (!$response->isError()) {
            return $response->getResponse();
        }
        throw match ($response->getError()) {
            ResponderPolicy::ERR_TOKEN_REFUSED, self::NO_PERMISSION => new SourceFailed(SourceFailed::LOGIN, 'The source AA refused the login token - log in to it again'),
            ResponderPolicy::ERR_APP_DENIED   => ExportRefused::denied(),
            ResponderPolicy::ERR_APP_EXPORT   => ExportRefused::failed(self::findings($response->getResponse())),
            ResponderPolicy::ERR_UNKNOWN_COMMAND => new SourceFailed(SourceFailed::FAILED, 'The source AA cannot give apps - update it first'),
            default => new SourceFailed(SourceFailed::FAILED, 'The source AA answered error ' . $response->getError()),
        };
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
