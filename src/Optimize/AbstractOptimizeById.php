<?php

namespace AA\Optimize;

use AA\Optimize\AbstractOptimize;

/** For ID based repairs
 *  you need to implement just
 *   - name() - which returns name of the action
 *   - description() - which returns description of the action
 *   - get_bad_ids() - which returns array of bad ids
 *   - repair4ids($ids) - which repairs the rows with specified ids
 *
 *
 */
class AbstractOptimizeById extends AbstractOptimize
{
    public static function actions(): array
    {
        return ['test', 'repair', 'repair4ids'];
    }  // full set is ['test', 'repair', 'revert', 'test4modules', 'repair4modules', 'repair4ids']

    /** Find bad record and return their ids with the description
     * get ['id' => ['some'=>'', 'fields'=>'', 'to_be_shown'=>'']] - id could be number, string or compound value
     *                                                                but must describe the row to fix/delete
     */
    protected function get_bad_ids(): array
    {
        return [];
    }

    public function test(): bool
    {
        $class = get_class_name(static::class);
        $bad_rows = $this->get_bad_ids();
        foreach ($bad_rows as $id => $row) {
            // mark id by 's' - we have two problems - missing relation in pagecache and missing id in pagecache_str2find
            $fixjs = "AA_Response('Optimize4id', { 'class': '$class', 'id': '$id' }, (responseText) => { this.outerHTML = responseText; } );";
            $row = array_map('safe', $row);
            $this->message(['fix' => "<button type=button onclick=\"$fixjs\">Fix</button>", ...$row]);
        }
        $this->message(_m('We found %1 problems', [count($bad_rows)]));
        return !count($bad_rows);
    }

    public function repair(): bool
    {
        return $this->repair4ids(array_keys($this->get_bad_ids())) !== false;
    }

    /** Filter ids to valid ids for repair
     *  It is recommended to use it in repair4ids() method for the security reasons
     */
    protected function filter_valid_ids(array $ids): array
    {
        $bad_ids = $this->get_bad_ids();
        return array_values(array_filter($ids, static fn($id) => isset($bad_ids[$id])));
    }
}
