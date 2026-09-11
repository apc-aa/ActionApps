<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Txt;

/** Fix inconsistency in pagecache
 *  Delete non-existent keys in pagecache_str2find table
 *  and delete pagecache records without its pagecache_str2find counterpart
 */
class FixPagecache extends AbstractOptimizeById
{
    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Fix inconsistency in pagecache");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Delete non-existent keys in pagecache_str2find table and vice versa");
    }

    /** Find bad record and return their ids with the description
     * get ['id' => ['some'=>'', 'fields'=>'', 'to_be_shown'=>'']] - id could be number, string or compound value
     *                                                                but must describe the row to fix/delete
     */
    protected function get_bad_ids(): array
    {
        $bad_ids = [];

        // 1. pagecache_str2find records without pagecache
        $str2finds = DB_AA::select(['id' => []], "SELECT pagecache_str2find.* FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id", [['pagecache.stored', '', 'ISNULL']]);
        foreach ($str2finds as $id => $row) {
            $bad_ids["s:$id"] = ['table' => 'pagecache_str2find', ...$row];
        }

        // 2. pagecache records without pagecache_str2find
        $pagecaches = DB_AA::select(['id' => []], "SELECT pagecache.* FROM pagecache LEFT JOIN pagecache_str2find ON pagecache.id = pagecache_str2find.pagecache_id", [['pagecache_str2find.id', '', 'ISNULL']]);
        foreach ($pagecaches as $id => $row) {
            if (isset($row['content'])) {
                $row['content'] = Txt::substr($row['content'], 0, 200) . '...';
            }
            $bad_ids["p:$id"] = ['table' => 'pagecache', ...$row];
        }

        return $bad_ids;
    }


    public function repair4ids(array $ids): false|string
    {
        $ids = $this->filter_valid_ids($ids); // security check to not repair invalid ids
        $s_ids = [];
        $p_ids = [];
        foreach ($ids as $id) {
            if (str_starts_with($id, 's:')) {
                $s_ids[] = substr($id, 2);
            } elseif (str_starts_with($id, 'p:')) {
                $p_ids[] = substr($id, 2);
            } else {
                throw new \RuntimeException("Invalid id format: $id");
            }
        }

        $s_rows = 0;
        $p_rows = 0;
        if ($s_ids) {
            $s_rows = DB_AA::delete("pagecache_str2find", [['id', $s_ids, 'i']]);
            $this->message(_m('%1 inconsistent rows in pagecache_str2find removed', [$del_rows]));
        }
        if ($p_ids) {
            $p_rows = DB_AA::delete("pagecache", [['id', $p_ids, 's']]);
            $this->message(_m('%1 inconsistent rows in pagecache removed', [$del_rows]));
        }
        return "del $s_rows + $p_rows";
    }
}
