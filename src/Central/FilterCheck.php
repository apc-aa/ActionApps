<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\FilterInvalid;
use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;

/** The preview of a transfer whose slices take only the items their filter selects (ImportChoices): the
 *  items of each slice and how many of them the filter selects, and the links the transfer breaks. A
 *  filter is read here first and then by the source, which knows the fields and the charset of the slice
 *  - E filter-invalid either way, and no other question goes to the source with such a filter. A source
 *  that cannot answer about the filters (an older AA, a failed request) makes them E as well: the write
 *  waits for a preview that checked them. */
final class FilterCheck
{
    /** W: the filter selects every item of the slice - it narrows nothing; ref = the count */
    public const MATCHES_ALL = 'filter-matches-all';
    /** W: relation values of the transferred items of the slice naming an item of a filtered slice the
     *  transfer leaves out; ref = the count */
    public const LEFT_OUT = 'relation-left-out';
    /** the filter-invalid reason of a filter the source did not answer about */
    public const UNCHECKED = 'source';

    public function __construct(private readonly AppSource $source) {}

    /** @param list<string> $slices the transferred slices @param array<string, string> $filters source slice => text
     *  @return array{findings: list<array{string, LintFinding}>, counts: array<string, int>, matching: array<string, int>, usable: bool}
     *          counts: the items of each slice, [] when the source cannot tell; matching: the items each filter
     *          selects; usable: no E - the filters may go to the source with the other questions of the preview
     *  @throws ExportRefused|SourceFailed the source refused the user or the login */
    public function check(array $slices, bool $allBins, array $filters): array
    {
        $filters  = ItemFilter::ofSlices($filters, $slices);
        $findings = [];
        foreach ($filters as $slice => $text) {
            try {
                ItemFilter::parse($text);
            } catch (FilterInvalid $e) {
                $findings[] = self::invalid($slice, $e->reason);
            }
        }
        $out = ['findings' => $findings, 'counts' => $this->counts($slices, $allBins), 'matching' => [], 'usable' => !$findings];
        if (!$filters || $findings) {
            return $out;
        }
        try {
            $out['matching'] = $this->source->itemCounts(array_keys($filters), $allBins, 0, $filters);
            $leftOut         = $this->source->leftOut($filters, $slices, $allBins);
        } catch (ExportRefused $e) {
            if ($e->isDenied()) {
                throw $e;
            }
            $read = $e->findings && !array_filter($e->findings, static fn(array $f): bool => $f[1]->code !== ItemFilter::INVALID);
            return ['findings' => $read ? $e->findings : self::unchecked($filters, $e), 'matching' => [], 'usable' => false] + $out;
        } catch (SourceFailed $e) {
            if ($e->reason === SourceFailed::LOGIN) {
                throw $e;
            }
            return ['findings' => self::unchecked($filters, $e), 'matching' => [], 'usable' => false] + $out;
        }
        foreach ($out['matching'] as $slice => $n) {
            if ($n && ($n === ($out['counts'][$slice] ?? null))) {
                $out['findings'][] = [Module::SLICE . "[$slice]", new LintFinding(LintFinding::WARNING, self::MATCHES_ALL, (string) $n, 0)];
            }
        }
        foreach ($leftOut as $slice => $n) {
            if ($n) {
                $out['findings'][] = [Module::SLICE . "[$slice]", new LintFinding(LintFinding::WARNING, self::LEFT_OUT, (string) $n, 0)];
            }
        }
        return $out;
    }

    /** the items of all the slices - only shown, so a source failing here leaves them unknown
     *  @param list<string> $slices @return array<string, int> */
    private function counts(array $slices, bool $allBins): array
    {
        try {
            return $slices ? $this->source->itemCounts($slices, $allBins) : [];
        } catch (ExportRefused|SourceFailed $e) {
            self::log('the item counts', $e);
            return [];
        }
    }

    /** @param array<string, string> $filters @return list<array{string, LintFinding}> */
    private static function unchecked(array $filters, \Throwable $e): array
    {
        self::log('the item filters', $e);
        return array_map(static fn(string $slice): array => self::invalid($slice, self::UNCHECKED), array_keys($filters));
    }

    /** @return array{string, LintFinding} */
    private static function invalid(string $slice, string $reason): array
    {
        return [Module::SLICE . "[$slice]", new LintFinding(LintFinding::ERROR, ItemFilter::INVALID, $reason, 0)];
    }

    private static function log(string $what, \Throwable $e): void
    {
        error_log("AA Central: the source did not answer about $what: " . preg_replace('/[\x00-\x1F\x7F]+/', ' ', get_class($e) . ': ' . $e->getMessage()));
    }
}
