<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\DB\DB_AA;
use AA_Content;
use AA_Validate_Id;

/**
 * The validator of a source setting that names a slice: new AA_Property('slice', _m('Slice'), 'string',
 * false, true, new SliceIdSetting()). A step reads that slice without asking, so Service lets only a
 * user who manages its feeding set it; the value may go into an expression, so only the bare id passes.
 */
final class SliceIdSetting extends AA_Validate_Id
{
    public function validate(?string &$var, ?AA_Content $content_context = null, string $var_id = ''): bool
    {
        $id = trim((string) $var);
        if ((preg_match('/^[0-9a-f]{32}\z/', $id) !== 1) || !DB_AA::test('module', [['id', $id, 'l'], ['type', 'S']])) {
            return self::bad(VALIDATE_ERROR_BAD_TYPE, _m('No slice ID'), $var_id);
        }
        $var = $id;
        return true;
    }
}
