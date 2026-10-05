<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\CollectOptions;
use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use AA_Response;
use Closure;
use InvalidArgumentException;
use Throwable;

/** Get_App of central/responder.php: an app of this AA for the AA that installs it. The target pulls, so
 *  this AA keeps no state; the target names its run's start (started), so the preview and the write get
 *  the same XML.
 *
 *  part definitions (modules: a site id, or slices and Alerts modules) | batch (slice, after) |
 *  logins (slices) | counts (slices, edited_after) | item_ids (slice, after) | left_out (slices);
 *  mode install | transfer; bins active | all.
 *
 *  filters (slice => ItemFilter text, slices of the part only) narrow batch, logins, counts, item_ids and
 *  left_out to the items they select. Such an answer is ['filters' => ItemFilter::digest(), 'answer' => …]:
 *  an older AA would ignore the parameter and answer for all the items - the target refuses an answer
 *  without the echo.
 *
 *  Answers plain data only - package XML as a string (with the source's time for the definitions),
 *  logins as strings, counts as integers - so the
 *  target unserializes no object. Errors are a code and a short text; the detail goes to the PHP error
 *  log. Every transfer export is logged: who, which modules, how many - never what.
 *
 *  The public account of the app store (a $store given) gets only the definitions of one published site
 *  for an install; publishing is read on every request, so a site taken back is refused at once. */
final class AppRequest
{
    private const PARTS = ['definitions', 'batch', 'logins', 'counts', 'item_ids', 'left_out'];
    private const MODES = ['install', 'transfer'];

    /** lines of an ERR_APP_EXPORT answer - an export fails at its first errors, so these are enough */
    private const MAX_ERRORS = 50;

    /** @var Closure(string, string): mixed */
    private readonly Closure $log;
    private readonly string $aa;

    /**
     * @param AppExport                     $export over this AA, with the rights of the user
     * @param (Closure(string, string): mixed)|null $log (selector, params) of a transfer export; null = AA_Log APP_EXPORT
     * @param ?string $aa AA_ID of this AA (public) - a target reached through a Central row naming itself
     *                    knows it copies within one installation; null = AA_ID
     * @param ?AppStore $store the request is the public account's; $export then may read the published
     *                    modules only (AppStore::publishedModules())
     */
    public function __construct(private readonly AppExport $export, private readonly string $user, ?Closure $log = null, ?string $aa = null, private readonly ?AppStore $store = null)
    {
        $this->log = $log ?? static fn(string $selector, string $params): bool => \AA_Log::write('APP_EXPORT', $selector, $params);
        $this->aa  = $aa ?? (string) constant('AA_ID');
    }

    /** @param array<mixed> $param the request parameters */
    public function answer(array $param): AA_Response
    {
        $part    = $param['part'] ?? null;
        $mode    = $param['mode'] ?? null;
        $started = self::time($param['started'] ?? '');
        if (!in_array($part, self::PARTS, true) || !in_array($mode, self::MODES, true) || ($started === null)) {
            return self::bad('part, mode or started');
        }
        $transfer = ($mode === 'transfer');
        $allBins  = (($param['bins'] ?? '') === 'all');
        $filters  = self::filters($param['filters'] ?? null, self::slicesOf($part, $param));
        if (($filters === null) || ($filters && ($part === 'definitions'))) {
            return self::bad('filters of the slices of the part');
        }
        if ($this->store !== null) {
            $modules = self::ids($param['modules'] ?? null);
            if (($part !== 'definitions') || $transfer) {
                return self::bad('the definitions of an install');
            }
            if (($modules === null) || (count($modules) !== 1) || !$this->store->published($modules[0])) {
                return self::denied();
            }
        }
        try {
            $response = match ($part) {
                'definitions' => $this->definitions($param, $transfer, $allBins, $started),
                'batch'       => $this->batch($param, $transfer, $allBins, $started, $filters),
                'logins'      => $this->logins($param, $transfer, $allBins, $filters),
                'counts'      => $this->counts($param, $allBins, $filters),
                'item_ids'    => $this->itemIds($param, $transfer, $allBins, $filters),
                'left_out'    => $this->leftOut($param, $transfer, $allBins, $filters),
            };
        } catch (ExportRefused $e) {
            if ($e->isDenied()) {
                return self::denied();
            }
            // the public account is anyone: what is wrong inside the app stays here, for its publisher
            return new AA_Response(($this->store === null) ? self::lines($e->errors()) : ['export-failed app'], ResponderPolicy::ERR_APP_EXPORT);
        } catch (InvalidArgumentException) {        // no such module, or not of the type: answered as a denied one
            return self::denied();
        } catch (Throwable $e) {
            error_log('AA Central Get_App failed: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            return new AA_Response('The export failed - details are in the PHP error log of this AA', ResponderPolicy::ERR_APP_FAILED);
        }
        return ($filters && !$response->isError()) ? new AA_Response(['filters' => ItemFilter::digest($filters), 'answer' => $response->getResponse()]) : $response;
    }

    /** @param array<mixed> $param */
    private function definitions(array $param, bool $transfer, bool $allBins, int $started): AA_Response
    {
        $modules = self::ids($param['modules'] ?? null);
        if (!$modules) {
            return self::bad('modules');
        }
        [$xml, , $now] = $this->export->definitions($modules, $transfer, $allBins, $started);
        if ($transfer || $this->export->carriesSettings($transfer)) {      // both hand out what may be secret
            $this->logged(($transfer ? 'transfer' : 'install with settings') . ' definitions ' . implode(',', $modules), 'modules=' . count($modules));
        }
        return new AA_Response(['package' => $xml, 'now' => $now, 'aa' => $this->aa]);
    }

    /** @param array<mixed> $param @param array<string, string> $filters */
    private function batch(array $param, bool $transfer, bool $allBins, int $started, array $filters): AA_Response
    {
        $slice = self::ids([$param['slice'] ?? null]);
        $after = $param['after'] ?? '';
        $limit = self::limit($param['items'] ?? null);
        if (!$transfer || !$slice || (($after !== '') && !self::ids([$after])) || ($limit === null)) {
            return self::bad('a transfer, slice, after and items');
        }
        [$xml, , $items] = $this->export->batchOf($slice[0], $after, $allBins, $started, $filters, $limit);
        $this->logged("transfer batch $slice[0]", "items=$items" . self::counted($filters));
        return new AA_Response($xml);
    }

    /** @param array<mixed> $param @param array<string, string> $filters */
    private function logins(array $param, bool $transfer, bool $allBins, array $filters): AA_Response
    {
        $slices = self::ids($param['slices'] ?? null);
        if (!$transfer || !$slices) {
            return self::bad('a transfer and slices');
        }
        $logins = $this->export->logins($slices, $allBins, $filters);
        $this->logged('transfer logins ' . implode(',', $slices), 'logins=' . count($logins) . self::counted($filters));
        return new AA_Response($logins);
    }

    /** @param array<mixed> $param @param array<string, string> $filters */
    private function itemIds(array $param, bool $transfer, bool $allBins, array $filters): AA_Response
    {
        $slice = self::ids([$param['slice'] ?? null]);
        $after = $param['after'] ?? '';
        if (!$transfer || !$slice || (($after !== '') && !self::ids([$after]))) {
            return self::bad('a transfer, slice and after');
        }
        $ids = $this->export->itemIds($slice[0], $allBins, $after, $filters);
        if ($filters) {             // which items match tells what they hold
            $this->logged("transfer item_ids $slice[0]", 'ids=' . count($ids) . self::counted($filters));
        }
        return new AA_Response($ids);
    }

    /** @param array<mixed> $param @param array<string, string> $filters */
    private function counts(array $param, bool $allBins, array $filters): AA_Response
    {
        $slices = self::ids($param['slices'] ?? null);
        $after  = self::time($param['edited_after'] ?? '0');
        if (!$slices || ($after === null)) {
            return self::bad('slices and edited_after');
        }
        $counts = $this->export->itemCounts($slices, $allBins, $after, $filters);
        if ($filters) {
            $this->logged('filtered counts ' . implode(',', $slices), 'slices=' . count($slices) . self::counted($filters));
        }
        return new AA_Response($counts);
    }

    /** @param array<mixed> $param @param array<string, string> $filters */
    private function leftOut(array $param, bool $transfer, bool $allBins, array $filters): AA_Response
    {
        $slices = self::ids($param['slices'] ?? null);
        if (!$transfer || !$slices) {
            return self::bad('a transfer and slices');
        }
        $counts = $this->export->leftOut($filters, $slices, $allBins);
        if ($filters) {
            $this->logged('transfer left_out ' . implode(',', $slices), 'values=' . array_sum($counts) . self::counted($filters));
        }
        return new AA_Response($counts);
    }

    /** the slices of a part - the only keys its filters may have
     *  @param array<mixed> $param @return list<string> */
    private static function slicesOf(string $part, array $param): array
    {
        return (in_array($part, ['batch', 'item_ids'], true) ? self::ids([$param['slice'] ?? null]) : self::ids($param['slices'] ?? null)) ?? [];
    }

    /** The filters of a request: slice => text, each slice one of the part's, each text a string - the export
     *  reads them after the rights on the slices; [] = none; null for anything else.
     *  @param list<string> $slices @return array<string, string>|null */
    private static function filters(mixed $value, array $slices): ?array
    {
        if (($value === null) || ($value === [])) {
            return [];
        }
        if (!is_array($value)) {
            return null;
        }
        $filters = [];
        foreach ($value as $slice => $text) {
            $slice = strtolower((string) $slice);
            if (!in_array($slice, $slices, true) || isset($filters[$slice]) || !is_string($text)) {
                return null;
            }
            $filters[$slice] = $text;
        }
        return $filters;
    }

    /** the log tells how many filters, never their texts @param array<string, string> $filters */
    private static function counted(array $filters): string
    {
        return $filters ? ' filters=' . count($filters) : '';
    }

    /** one log row: the selector names the modules, the params who and how many */
    private function logged(string $selector, string $params): void
    {
        ($this->log)(mb_strcut($selector, 0, 255), mb_strcut(self::oneLine("user=$this->user $params"), 0, 128));
    }

    /** a list of long ids, lower case; null for anything else
     *  @return list<string>|null */
    private static function ids(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        foreach ($value as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-fA-F]{32}\z/', $id)) {
                return null;
            }
        }
        return array_map('strtolower', $value);
    }

    /** the items of a batch the target asks for: none = a full one (an older target asks so) */
    private static function limit(mixed $value): ?int
    {
        $limit = match (true) {
            $value === null                                         => CollectOptions::MAX_ITEMS,
            is_int($value)                                          => $value,
            is_string($value) && (bool) preg_match('/^\d{1,3}\z/', $value) => (int) $value,
            default                                                 => 0,
        };
        return (($limit >= 1) && ($limit <= CollectOptions::MAX_ITEMS)) ? $limit : null;
    }

    /** a unixtime; '' = now; null for anything else */
    private static function time(mixed $value): ?int
    {
        return match (true) {
            $value === ''                                             => time(),
            is_int($value) && ($value >= 0)                           => $value,
            is_string($value) && (bool) preg_match('/^\d{1,10}\z/', $value) => (int) $value,
            default                                                   => null,
        };
    }

    /** the E findings as "code path ref" lines - plain strings, the target turns them back into findings
     *  @param list<array{string, LintFinding}> $errors @return list<string> */
    private static function lines(array $errors): array
    {
        return array_map(static fn(array $f): string => self::oneLine("{$f[1]->code} $f[0] {$f[1]->ref}"), array_slice($errors, 0, self::MAX_ERRORS));
    }

    private static function denied(): AA_Response
    {
        return new AA_Response('No such app, or you may not export it', ResponderPolicy::ERR_APP_DENIED);
    }

    private static function bad(string $what): AA_Response
    {
        return new AA_Response("Get_App needs $what of the right form", ResponderPolicy::ERR_APP_REQUEST);
    }

    /** no control characters - a log line or an answer line cannot be forged */
    private static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }
}
