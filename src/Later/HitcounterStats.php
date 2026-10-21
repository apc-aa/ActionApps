<?php

namespace AA\Later;

use AA\IO\DB\DB_AA;

/** Updates the hit_x..... fields for all items from the slice based on the data
 *  in hit_archive table.
 */
class HitcounterStats implements LaterInterface
{
    /** items written in one transaction */
    private const WRITE_CHUNK = 500;

    /** For which slice we will count the hits */
    private string $slice_id;

    /** For which field we will cont the hits (hit_1...., hit_2...) */
    private string $field_id;

    public function __construct(string $slice_id, string $field_id)
    {
        $this->slice_id = $slice_id;
        $this->field_id = $field_id;
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        $dbgtime = [microtime(true)];

        $days = $this->getDays();
        $time = time() - ($days * 86400);
        $hits = DB_AA::select(['id' => 'count'], 'SELECT LOWER(HEX(item.id)) AS id, sum(hits) as count FROM hit_archive INNER JOIN item ON hit_archive.id=item.short_id', [['hit_archive.time', $time, '>'], ['item.slice_id', $this->slice_id, 'l']], null, ['hit_archive.id']);
        $dbgtime[] = microtime(true);
        $item_ids = DB_AA::select('id', 'SELECT LOWER(HEX(id)) AS id FROM item', [['slice_id', $this->slice_id, 'l']]);
        $field    = DB_AA::select1('', 'SELECT `id`,`text_stored` FROM `field`', [
            ['slice_id', $this->slice_id, 'l'],
            ['id', $this->field_id],
        ]);
        $dbgtime[] = microtime(true);

        // Most counts do not move between two runs, so only the changed ones are written, in
        // transactions of WRITE_CHUNK items - not one autocommit per item.
        $changed = 0;
        if ($item_ids && $field) {
            $text_stored = (bool) $field['text_stored'];
            foreach (array_chunk($item_ids, self::WRITE_CHUNK) as $chunk) {
                $content = GetItemContent(new \zids($chunk, 'l'), false, true, [$this->field_id]);
                $values  = self::changedValues($chunk, $hits, $content, $this->field_id);
                if (!$values) {
                    continue;
                }
                $changed += count($values);
                DB_AA::transaction(function () use ($values, $text_stored): void {
                    $writer = new \AA_Field_Writer('');
                    // all clears first: each one filters the rows queued so far
                    foreach (array_keys($values) as $id) {
                        $writer->clearField((string) $id, $this->field_id);
                    }
                    foreach ($values as $id => $value) {
                        $writer->addContentRow((string) $id, $this->field_id, $text_stored, $value, 0, 0);
                    }
                    $writer->flush();
                });
            }
        }
        $dbgtime[] = microtime(true);
        // for AA_Log
        return count($item_ids) . '-' . number_format($dbgtime[1] - $dbgtime[0], 2) . '-' . number_format($dbgtime[2] - $dbgtime[1], 2) . '-' . number_format($dbgtime[3] - $dbgtime[2], 2) . '-' . $changed;
    }

    /** The items whose stored count differs from the counted one - a missing value, an item without
     *  hits that still shows some, or more than one value count as different too.
     * @param array $item_ids long ids
     * @param array $hits     long id => hits counted; an item without hits is not there
     * @param array $content  GetItemContent() of the items, at least with the field
     * @return array<string, int> long id => count to store
     */
    public static function changedValues(array $item_ids, array $hits, array $content, string $field_id): array
    {
        $changed = [];
        foreach ($item_ids as $id) {
            $value = (int) ($hits[$id] ?? 0);
            $have  = $content[$id][$field_id] ?? [];
            if ((count($have) !== 1) || ((string) (reset($have)['value'] ?? '') !== (string) $value)) {
                $changed[$id] = $value;
            }
        }
        return $changed;
    }

    public static function getStats(array $slice_ids, int $from = 0, int $to = 0, $order = true, $limit = 0): array
    {
        $where = [['hit_archive.id', 'item.short_id', 'j']];
        if ($from) {
            $where[] = ['time', $from, '>='];
        }
        if ($to) {
            $where[] = ['time', $to, '<='];
        }
        if ($slice_ids) {
            $where[] = ['item.slice_id', $slice_ids, 'l'];
        }
        $order = $order ? ['count-'] : null;
        return DB_AA::select(['id' => 'count'], "SELECT LOWER(HEX(`item`.`id`)) as id, sum(`hits`) as count FROM `hit_archive`, `item`", $where, $order, ['hit_archive.id'], $limit);
    }

    public function getDays(): int
    {
        return (int) substr($this->field_id, 4);
    }

    /** used as identifier for AA_Log when run from toexecute */
    public function getId(): string
    {
        return $this->slice_id . "/" . $this->field_id;
    }
}
