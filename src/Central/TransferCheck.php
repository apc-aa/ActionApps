<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Source;
use AA\IO\DB\DB_AA;

/** What the end of a transfer checks: the items of each slice on the source and on the target, the items
 *  the source changed since the run started (the source is not frozen during a transfer) and the
 *  relations of the new items that point nowhere. Only reads - it may run again for the report. */
final class TransferCheck
{
    /** W: the slice has another number of items on the target than on the source; ref = "source => target" */
    public const COUNT_DIFFERS = 'count-differs';
    /** W: items of the source edited after the run started - their change may have missed its batch; ref = the count */
    public const EDITED_DURING = 'edited-during-transfer';
    /** W: relation values of the new items naming no item of this AA; ref = the count */
    public const DANGLING = 'relation-dangling';

    /** input_show_func of a field that selects items (AA_Field::getRelation()): its constants are `#sLiCe-<slice id>` */
    private const RELATION_MARK = '#sLiCe-';

    public function __construct(private readonly Source $source) {}

    /** @return array{slices: list<array{source: string, target: string, written: int, sourceCount: int, targetCount: int}>,
     *                findings: list<array{string, LintFinding}>} */
    public function check(InstallRun $run): array
    {
        $map     = $run->idMap();
        $written = $run->written();
        $slices  = $findings = [];
        $edited  = 0;
        foreach ($run->slices() as $slice) {
            $target   = (string) $map->module($slice);
            $counts   = [$this->source->itemCount($slice, $run->allBins()), ($target === '') ? 0 : DB_AA::count('item', [['slice_id', $target, 'l']])];
            $slices[] = ['source' => $slice, 'target' => $target, 'written' => (int) ($written[$slice] ?? 0), 'sourceCount' => $counts[0], 'targetCount' => $counts[1]];
            if ($counts[0] !== $counts[1]) {
                $findings[] = [Module::SLICE . "[$slice]", new LintFinding(LintFinding::WARNING, self::COUNT_DIFFERS, "$counts[0] => $counts[1]", 0)];
            }
            $edited += $this->source->itemCount($slice, $run->allBins(), $run->started());
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
        return (int) DB_AA::select1('cnt', $sql, [['item.slice_id', $slices, 'l'], ['field.input_show_func', self::RELATION_MARK, 'CONTAIN'], ['content.text', '', 'FILLED'], ['related.id', '', 'ISNULL']]);
    }
}
