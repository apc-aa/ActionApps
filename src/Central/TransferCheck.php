<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\ReferenceRewriter;
use AA\IO\DB\DB_AA;

/** What the end of a transfer checks: the items of each slice on the source and on the target, the items
 *  the source changed since the run started (the source is not frozen during a transfer) and the
 *  relations of the new items that point nowhere. On the source only the items the filters of the run
 *  select count. Only reads - it may run again for the report. */
final class TransferCheck
{
    /** W: the slice has another number of items on the target than on the source - an existing slice the
     *  package went into: another number written; ref = "source => target" */
    public const COUNT_DIFFERS = 'count-differs';
    /** W: items of the source edited after the run started - their change may have missed its batch; ref = the count */
    public const EDITED_DURING = 'edited-during-transfer';
    /** W: relation values of the new items naming no item of this AA; ref = the count */
    public const DANGLING = 'relation-dangling';
    /** W: the source did not tell its counts (unreachable, its login expired); ref = why */
    public const SOURCE_UNKNOWN = 'source-count-unknown';

    /** @param ?AppSource $source null = it cannot be asked any more (its Central row is gone) */
    public function __construct(private readonly ?AppSource $source) {}

    /** @return array{slices: list<array{source: string, target: string, written: int, sourceCount: ?int, targetCount: int}>,
     *                findings: list<array{string, LintFinding}>} */
    public function check(InstallRun $run): array
    {
        $map     = $run->idMap();
        $written = $run->written();
        $slices  = $findings = [];
        try {
            $onSource = ($this->source ?? throw new SourceFailed(SourceFailed::UNREACHABLE, 'no source'))->itemCounts($run->slices(), $run->allBins(), 0, $run->filters());
            $edited   = array_sum($this->source->itemCounts($run->slices(), $run->allBins(), $run->sourceStarted(), $run->filters()));
        } catch (SourceFailed|ExportRefused $e) {        // the items are written - the run finishes, the counts stay unknown
            $onSource   = null;
            $edited     = 0;
            $findings[] = ['source', new LintFinding(LintFinding::WARNING, self::SOURCE_UNKNOWN, ($e instanceof SourceFailed) ? $e->reason : InstallRun::SOURCE_DENIED, 0)];
        }
        $into    = $run->targets();
        foreach ($run->slices() as $slice) {
            $target   = (string) $map->module($slice);
            $counts   = [($onSource === null) ? null : (int) ($onSource[$slice] ?? 0), ($target === '') ? 0 : DB_AA::count('item', [['slice_id', $target, 'l']])];
            $slices[] = ['source' => $slice, 'target' => $target, 'written' => (int) ($written[$slice] ?? 0), 'sourceCount' => $counts[0], 'targetCount' => $counts[1]];
            $arrived  = isset($into[$slice]) ? (int) ($written[$slice] ?? 0) : $counts[1];       // an existing slice had items before
            if (($counts[0] !== null) && ($counts[0] !== $arrived)) {
                $findings[] = [Module::SLICE . "[$slice]", new LintFinding(LintFinding::WARNING, self::COUNT_DIFFERS, "$counts[0] => $arrived", 0)];
            }
        }
        if ($edited) {
            $findings[] = ['source', new LintFinding(LintFinding::WARNING, self::EDITED_DURING, (string) $edited, 0)];
        }
        $dangling = self::dangling(array_values(array_filter(array_column($slices, 'target'))));
        if ($dangling) {
            $findings[] = ['target', new LintFinding(LintFinding::WARNING, self::DANGLING, (string) $dangling, 0)];
        }
        return ['slices' => $slices, 'findings' => $findings];
    }

    /** Values of the relation fields of the slices' items naming no item - one query. A value that is no
     *  id counts too.
     *  @param list<string> $slices target slice ids */
    public static function dangling(array $slices): int
    {
        if (!$slices) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) AS cnt FROM `content` INNER JOIN `item` ON `item`.`id` = `content`.`item_id`'
            . ' INNER JOIN `field` ON `field`.`slice_id` = `item`.`slice_id` AND `field`.`id` = `content`.`field_id`'
            . ' LEFT JOIN `item` AS `related` ON `related`.`id` = UNHEX(`content`.`text`)';
        return (int) DB_AA::select1('cnt', $sql, [['item.slice_id', $slices, 'l'], ['field.input_show_func', ReferenceRewriter::RELATION_MARK, 'CONTAIN'], ['content.text', '', 'FILLED'], ['related.id', '', 'ISNULL']]);
    }
}
