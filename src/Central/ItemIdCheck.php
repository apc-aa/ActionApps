<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\ImportTarget;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;

/** The preview of a transfer from another AA: it keeps the item ids, so an id this AA already has (an
 *  earlier transfer of the same items, not yet deleted) would stop a batch halfway. The source's ids are
 *  read a page a request and counted against this AA's items - before anything is written. A copy in the
 *  same AA gets new ids (AppSource::sameAa()) and needs no check. */
final class ItemIdCheck
{
    /** E: items of the source slice whose ids this AA has; ref = their count */
    public const ITEM_ID_EXISTS = 'item-id-exists';

    public function __construct(private readonly AppSource $source, private readonly ImportTarget $target) {}

    /** @param list<string> $slices source slice ids @return list<array{string, LintFinding}>
     *  @throws ExportRefused|SourceFailed */
    public function check(array $slices, bool $allBins): array
    {
        $findings = [];
        $counts   = $this->source->itemCounts($slices, $allBins);
        foreach ($slices as $slice) {
            [$after, $found, $pages] = ['', 0, intdiv($counts[$slice] + AppSource::ID_PAGE - 1, AppSource::ID_PAGE) + 1];
            do {
                // the source adds items meanwhile, but pages without an end are its error
                if (--$pages < 0) {
                    throw new SourceFailed(SourceFailed::FAILED, "The source AA lists more item ids of the slice $slice than it has items.");
                }
                $ids    = $this->source->itemIds($slice, $allBins, $after);
                $found += $this->target->existingItems($ids);
                $after  = (string) end($ids);
            } while (count($ids) === AppSource::ID_PAGE);
            if ($found) {
                $findings[] = [Module::SLICE . "[$slice]", new LintFinding(LintFinding::ERROR, self::ITEM_ID_EXISTS, (string) $found, 0)];
            }
        }
        return $findings;
    }
}
