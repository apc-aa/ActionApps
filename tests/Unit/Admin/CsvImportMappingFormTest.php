<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** A loaded CSV import setting has no mapping for a field added to the slice since. The form must offer
 *  no source and the default action for it - the import then leaves the field alone. With nothing
 *  selected the browser would send the first options (a CSV column, "expression") and Update would
 *  empty the field. The page needs a session and the database, so the test reads its source. */
final class CsvImportMappingFormTest extends TestCase
{
    public function test_field_without_mapping_gets_no_source_and_the_default_action(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/se_csv_import2.php');
        $this->assertStringContainsString('FrmSelectEasy("mapping[$f_id]", $inFields, $set_default ? findNearestText($f_id, $f_name, $inFields) : ($this->mapping[$f_id] ?? \'__empty__\'));', $src);
        $this->assertStringContainsString('FrmSelectEasy("actions[$f_id]", $actionList, $set_default ? "default" : ($this->actions[$f_id] ?? \'default\'));', $src);
    }
}
