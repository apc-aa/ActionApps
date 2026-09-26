<?php

declare(strict_types=1);

namespace AA\Central;

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
 *  logins (slices) | counts (slices, edited_after) | item_ids (slice, after); mode install | transfer;
 *  bins active | all.
 *
 *  Answers plain data only - package XML as a string (with the source's time for the definitions),
 *  logins as strings, counts as integers - so the
 *  target unserializes no object. Errors are a code and a short text; the detail goes to the PHP error
 *  log. Every transfer export is logged: who, which modules, how many - never what. */
final class AppRequest
{
    private const PARTS = ['definitions', 'batch', 'logins', 'counts', 'item_ids'];
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
     */
    public function __construct(private readonly AppExport $export, private readonly string $user, ?Closure $log = null, ?string $aa = null)
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
        try {
            return match ($part) {
                'definitions' => $this->definitions($param, $transfer, $allBins, $started),
                'batch'       => $this->batch($param, $transfer, $allBins, $started),
                'logins'      => $this->logins($param, $transfer, $allBins),
                'counts'      => $this->counts($param, $allBins),
                'item_ids'    => $this->itemIds($param, $transfer, $allBins),
            };
        } catch (ExportRefused $e) {
            return $e->isDenied() ? self::denied() : new AA_Response(self::lines($e->errors()), ResponderPolicy::ERR_APP_EXPORT);
        } catch (InvalidArgumentException) {        // no such module, or not of the type: answered as a denied one
            return self::denied();
        } catch (Throwable $e) {
            error_log('AA Central Get_App failed: ' . self::oneLine(get_class($e) . ': ' . $e->getMessage()));
            return new AA_Response('The export failed - details are in the PHP error log of this AA', ResponderPolicy::ERR_APP_FAILED);
        }
    }

    /** @param array<mixed> $param */
    private function definitions(array $param, bool $transfer, bool $allBins, int $started): AA_Response
    {
        $modules = self::ids($param['modules'] ?? null);
        if (!$modules) {
            return self::bad('modules');
        }
        [$xml, , $now] = $this->export->definitions($modules, $transfer, $allBins, $started);
        if ($transfer) {
            $this->logged('transfer definitions ' . implode(',', $modules), 'modules=' . count($modules));
        }
        return new AA_Response(['package' => $xml, 'now' => $now, 'aa' => $this->aa]);
    }

    /** @param array<mixed> $param */
    private function batch(array $param, bool $transfer, bool $allBins, int $started): AA_Response
    {
        $slice = self::ids([$param['slice'] ?? null]);
        $after = $param['after'] ?? '';
        if (!$transfer || !$slice || (($after !== '') && !self::ids([$after]))) {
            return self::bad('a transfer, slice and after');
        }
        [$xml, , $items] = $this->export->batchOf($slice[0], $after, $allBins, $started);
        $this->logged("transfer batch $slice[0]", "items=$items");
        return new AA_Response($xml);
    }

    /** @param array<mixed> $param */
    private function logins(array $param, bool $transfer, bool $allBins): AA_Response
    {
        $slices = self::ids($param['slices'] ?? null);
        if (!$transfer || !$slices) {
            return self::bad('a transfer and slices');
        }
        $logins = $this->export->logins($slices, $allBins);
        $this->logged('transfer logins ' . implode(',', $slices), 'logins=' . count($logins));
        return new AA_Response($logins);
    }

    /** @param array<mixed> $param */
    private function itemIds(array $param, bool $transfer, bool $allBins): AA_Response
    {
        $slice = self::ids([$param['slice'] ?? null]);
        $after = $param['after'] ?? '';
        if (!$transfer || !$slice || (($after !== '') && !self::ids([$after]))) {
            return self::bad('a transfer, slice and after');
        }
        return new AA_Response($this->export->itemIds($slice[0], $allBins, $after));
    }

    /** @param array<mixed> $param */
    private function counts(array $param, bool $allBins): AA_Response
    {
        $slices = self::ids($param['slices'] ?? null);
        $after  = self::time($param['edited_after'] ?? '0');
        if (!$slices || ($after === null)) {
            return self::bad('slices and edited_after');
        }
        return new AA_Response($this->export->itemCounts($slices, $allBins, $after));
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
