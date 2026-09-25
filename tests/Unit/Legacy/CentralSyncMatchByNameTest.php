<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Central\Difference;
use AA_Definition_Module_Slice;
use AA_Sync_Action;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';

/**
 * Central Synchronize pairs views and e-mails by name, not by their auto_increment number, which
 * means nothing between two slices. A row without a unique name is left alone.
 */
final class CentralSyncMatchByNameTest extends TestCase
{
    private const TEMPLATE_ID = '0123456789abcdef0123456789abcdef';
    private const DEST_ID     = 'fedcba9876543210fedcba9876543210';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
    }

    private static function view(int $id, string $module, string $field3, string $name, string $body = 'x'): array
    {
        return [$id => ['id' => $id, 'slice_id' => $module, 'field3' => $field3, 'name' => $name, 'odd' => $body]];
    }

    private static function email(int $id, string $module, string $description, string $subject): array
    {
        return [$id => ['id' => $id, 'owner_module_id' => $module, 'description' => $description, 'subject' => $subject]];
    }

    /** @return Difference[] template rows against destination rows of one table */
    private static function compare(string $table, ?array $template_rows, ?array $dest_rows): array
    {
        $base = static fn(string $id): array => [
            'module' => [$id => ['id' => $id, 'name' => 'News']],
            'slice'  => [$id => ['id' => $id, 'name' => 'News']],
        ];
        $dest = new AA_Definition_Module_Slice();
        (new \ReflectionProperty($dest, 'module_id'))->setValue($dest, self::DEST_ID);
        (new \ReflectionProperty($dest, 'data'))->setValue($dest, $base(self::DEST_ID) + [$table => $dest_rows]);   // null: the table is missing

        return Difference::compareSlice($base(self::TEMPLATE_ID) + [$table => $template_rows], self::TEMPLATE_ID, $dest);
    }

    /** @param Difference[] $diffs
     *  @return list<string> the offered actions like "UPDATE_ROW view->30" */
    private static function actions(array $diffs): array
    {
        $out = [];
        foreach ($diffs as $diff) {
            foreach ((new \ReflectionProperty($diff, 'actions'))->getValue($diff) as $action) {
                $idf   = (new \ReflectionProperty($action, 'identifier'))->getValue($action);
                $out[] = (new \ReflectionProperty($action, 'type'))->getValue($action) . ' ' . implode('->', array_filter([$idf->getTable(), $idf->getRow(), $idf->getColumn()]));
            }
        }
        return $out;
    }

    /** @param Difference[] $diffs */
    private static function render(array $diffs): string
    {
        ob_start();
        foreach ($diffs as $diff) {
            $diff->printOut();
        }
        return (string) ob_get_clean();
    }

    public function test_view_matched_by_named_view_despite_other_numbers(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, 'detail', 'Detail'), self::view(30, self::DEST_ID, 'detail', 'Old detail'));

        $this->assertSame(['UPDATE_ROW view->30', 'UPDATE_VALUE view->30->name'], self::actions($diffs));
    }

    public function test_view_without_named_view_matched_by_name(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, '', 'List', 'new'), self::view(30, self::DEST_ID, '', 'List', 'old'));

        $this->assertSame(['UPDATE_ROW view->30', 'UPDATE_VALUE view->30->odd'], self::actions($diffs));
    }

    public function test_identical_views_under_other_numbers_offer_nothing(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, 'detail', 'Detail'), self::view(30, self::DEST_ID, 'detail', 'Detail'));

        $this->assertSame([], self::actions($diffs));
    }

    /** the same number, another view - not an update of it */
    public function test_destination_view_with_the_same_number_and_another_name_is_not_updated(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, '', 'List'), self::view(12, self::DEST_ID, '', 'Archive'));

        $this->assertSame(['INSERT view->12', 'DELETE view->12'], self::actions($diffs));
        $this->assertStringNotContainsString(' checked>', self::render($diffs), 'neither is pre-checked');
    }

    /** two template views named List: which one is the destination's List is unknown */
    public function test_duplicate_name_is_not_compared_and_says_why(): void
    {
        $template = self::view(12, self::TEMPLATE_ID, '', 'List') + self::view(13, self::TEMPLATE_ID, '', 'List') + self::view(14, self::TEMPLATE_ID, '', '');
        $diffs    = self::compare('view', $template, self::view(30, self::DEST_ID, '', 'List', 'other'));

        $this->assertSame([], self::actions($diffs));
        $html = self::render($diffs);
        $this->assertStringContainsString("view 12 'name:List' not compared - no unique named view or name", $html);
        $this->assertStringContainsString("view 13 'name:List' not compared", $html);
        $this->assertStringContainsString("view 14 '' not compared", $html);
        $this->assertStringContainsString("view 30 'name:List' not compared", $html);
    }

    /** a view the destination finds by {view.php?vid=detail} must not lose its field3 to a view
     *  of the template which is only named Detail */
    public function test_named_view_does_not_pair_with_a_view_named_the_same(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, '', 'detail'), self::view(30, self::DEST_ID, 'detail', 'detail'));

        $this->assertSame(['INSERT view->12', 'DELETE view->30'], self::actions($diffs));
        $this->assertStringNotContainsString(' checked>', self::render($diffs));
    }

    public function test_names_are_case_sensitive(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, 'Detail', 'x'), self::view(30, self::DEST_ID, 'detail', 'x'));

        $this->assertSame(['INSERT view->12', 'DELETE view->30'], self::actions($diffs));
    }

    public function test_duplicate_name_on_the_destination_side_only_is_not_compared(): void
    {
        $diffs = self::compare('view', self::view(12, self::TEMPLATE_ID, '', 'List'), self::view(30, self::DEST_ID, '', 'List') + self::view(31, self::DEST_ID, '', 'List'));

        $this->assertSame([], self::actions($diffs));
        $html = self::render($diffs);
        $this->assertStringContainsString("Template: view 12 'name:List' not compared", $html);
        $this->assertStringContainsString("Destination: view 30 'name:List' not compared", $html);
        $this->assertStringContainsString("Destination: view 31 'name:List' not compared", $html);
    }

    public function test_hostile_row_id_is_escaped(): void
    {
        $rows = ['<b>1</b>' => ['id' => 1, 'name' => 'List'], '<i>2</i>' => ['id' => 2, 'name' => 'List']];
        $html = self::render(self::compare('view', $rows, []));

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString("view &lt;b&gt;1&lt;/b&gt; 'name:List' not compared", $html);
    }

    /** a failed template load must be visible, not just a list of DELETEs */
    public function test_template_without_views_says_so(): void
    {
        $diffs = self::compare('view', [], self::view(30, self::DEST_ID, '', 'List'));   // an empty set - just a DELETE
        $this->assertSame(['DELETE view->30'], self::actions($diffs));
        $this->assertStringNotContainsString('sent no', self::render($diffs));

        $diffs = self::compare('view', null, self::view(30, self::DEST_ID, '', 'List'));
        $this->assertSame(['DELETE view->30'], self::actions($diffs));
        $this->assertStringContainsString('The template sent no view rows', self::render($diffs));
    }

    public function test_destination_without_views_gets_inserts(): void
    {
        foreach ([[], null] as $dest) {
            $this->assertSame(['INSERT view->12'], self::actions(self::compare('view', self::view(12, self::TEMPLATE_ID, '', 'List'), $dest)));
        }
    }

    /** "identical" only for two real sets */
    public function test_missing_or_empty_sets_are_described(): void
    {
        $info = static fn(?array $t, ?array $d): string => self::render(array_slice(self::compare('view', $t, $d), 3, 1));   // module, slice, field, view

        $this->assertStringContainsString('-&gt;view is not defined for both AAs', $info(null, null));
        $this->assertStringContainsString('-&gt;view are identical', $info([], []));
        $this->assertStringContainsString('-&gt;view is not array in destination slice', $info([], null));
        $this->assertStringContainsString('The template sent no view rows', $info(null, []));
    }

    public function test_skipped_view_name_is_escaped(): void
    {
        $name = '<img src=x onerror=alert(1)>';
        $html = self::render(self::compare('view', self::view(12, self::TEMPLATE_ID, '', $name) + self::view(13, self::TEMPLATE_ID, '', $name), []));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /** the update writes the destination's row and keeps its number */
    public function test_update_addresses_the_destination_view(): void
    {
        $diffs  = self::compare('view', self::view(12, self::TEMPLATE_ID, 'detail', 'Detail'), self::view(30, self::DEST_ID, 'detail', 'Old detail'));
        $rows   = array_filter($diffs, static fn($d) => (new \ReflectionProperty($d, 'type'))->getValue($d) === 'DIFFERENT_ROW');
        $action = (new \ReflectionProperty(reset($rows), 'actions'))->getValue(reset($rows))[0];
        $this->assertInstanceOf(AA_Sync_Action::class, $action);

        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $action->doAction();

        $this->assertSame(["UPDATE `view` SET `slice_id`=0x" . self::DEST_ID . ", `name`='Detail', `odd`='x', `field3`='detail' WHERE id='30'"], RecordingDb::$log);
    }

    public function test_email_matched_by_description(): void
    {
        $template = self::email(7, self::TEMPLATE_ID, 'Welcome', 'Hi') + self::email(8, self::TEMPLATE_ID, '', 'No description') + self::email(9, self::TEMPLATE_ID, 'Bye', 'Bye');
        $dest     = self::email(3, self::DEST_ID, 'Welcome', 'Hello') + self::email(7, self::DEST_ID, 'Reminder', 'Remember');
        $diffs    = self::compare('email', $template, $dest);

        $this->assertSame(['UPDATE_ROW email->3', 'UPDATE_VALUE email->3->subject', 'INSERT email->9', 'DELETE email->7'], self::actions($diffs));
        $this->assertStringContainsString("email 8 '' not compared - no unique description", self::render($diffs));
    }
}
