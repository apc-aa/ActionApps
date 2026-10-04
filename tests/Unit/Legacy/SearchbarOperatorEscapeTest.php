<?php

declare(strict_types=1);

/**
 * The search operator comes from the URL (srchbr_oper[]) and goes into the inline script of the
 * searchbar - a link of another site must not break out of the JS string or the <script>.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA\Util\Searchfields;
use AA_Property;
use AA_Searchbar;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

final class SearchbarOperatorEscapeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/fixtures/object_form_fixtures.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        $_GET = [];
        unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        AA::$module_id = null;
    }

    public function test_getBar_operatorFromUrlStaysInsideTheJsString(): void
    {
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        AA::$module_id = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [AA::$module_id => '']];
        $fields    = Searchfields::factoryFromProperties(['aa_name' => new AA_Property('aa_name', 'Name', 'text')]);
        $searchbar = new AA_Searchbar(null, $fields, 'filterform', 1, 0, 0);   // one search row, no order row
        $_GET      = ['srchbr_akce' => '1', 'srchbr_field' => ['aa_name'], 'srchbr_oper' => ["x');evil(1);//</script><b>"], 'srchbr_value' => ['v']];
        $searchbar->update(false);

        $bar = $searchbar->getBar();

        $this->assertStringNotContainsString("x');evil", $bar);
        $this->assertStringNotContainsString('</script><b>', $bar);
        $this->assertStringContainsString("ChangeOperators('filterform','0',\"x\\u0027);evil(1);\\/\\/\\u003C\\/script\\u003E\\u003Cb\\u003E\");", $bar);
    }
}
