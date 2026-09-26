<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Central\ResponderPolicy;
use AA\Later\Toexecute;
use AA_Difference;
use AA_Identifier;
use AA_Sync_Action;
use AA_Task_Sync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\CentralTestHelpers;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

// class declarations only - file level, since the data providers below build these objects
require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';
require_once __DIR__ . '/fixtures/central_fixtures.php';   // the trait must exist before the class

/**
 * Characterization of the small pieces of Central Synchronize (central/include/actionapps.class.php):
 * AA_Identifier (the "module->table->row->column" path), AA_Difference::_compareArray() (what
 * difference and which action each case gives), AA_Sync_Action outside doAction() (its form
 * checkbox and data array), and the tasks queued in toexecute (AA_Task_Sync) with the serialized
 * forms remote AAs and the queue depend on - and how the queue drops a task of a class AA no longer has.
 * CentralSynchronizeTest / CentralDifferenceTest cover doAction(), fromFormValue() and escaping;
 * this file does not repeat them.
 *
 * Pins today's behaviour; "pinned:" marks behaviour that is questionable rather than wanted.
 */
final class CentralSyncPrimitivesTest extends TestCase
{
    use CentralTestHelpers;

    private const MOD = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';    // _m(), safe(), FrmChBoxEasy()
        require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';   // Cvarset of Toexecute::later()
    }

    protected function setUp(): void
    {
        RecordingDb::reset();   // other tests leave their statements and answers behind
    }

    protected function tearDown(): void
    {
        unset($_POST['sync']);
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    private static function prop(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    // --- AA_Identifier ---------------------------------------------------------------------------

    /** @return array<string, array{array, string, array{?string, ?string, ?string, ?string}}> */
    public static function identifiers(): array
    {
        return [
            'full'                    => [['m', 'field', 'headline........', 'name'], 'm->field->headline........->name', ['m', 'field', 'headline........', 'name']],
            'row'                     => [['m', 'view', '12'], 'm->view->12', ['m', 'view', '12', null]],
            'table'                   => [['m', 'field'], 'm->field', ['m', 'field', null, null]],
            'module'                  => [['m'], 'm', ['m', null, null, null]],
            'nothing'                 => [[], '', [null, null, null, null]],
            'int row'                 => [['m', 'view', 12], 'm->view->12', ['m', 'view', 12, null]],
            // each part needs the one before - a gap drops the rest
            'column without row'      => [['m', 'field', null, 'name'], 'm->field', ['m', 'field', null, null]],
            'row without table'       => [['m', null, 'r', 'c'], 'm', ['m', null, null, null]],
            // pinned: truthiness, not null: a row (or module) "0" is dropped
            'row "0"'                 => [['m', 'view', '0', 'name'], 'm->view', ['m', 'view', null, null]],
            'int row 0'               => [['m', 'view', 0], 'm->view', ['m', 'view', null, null]],
            'module "0"'              => [['0', 'field', 'r'], '', [null, null, null, null]],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_identifier_constructor_getters_and_toString(array $args, string $string, array $parts): void
    {
        $idf = new AA_Identifier(...$args);

        $this->assertSame($string, $idf->toString());
        $this->assertSame($parts, [$idf->getModuleId(), $idf->getTable(), $idf->getRow(), $idf->getColumn()]);
    }

    public function test_identifier_sub_appends_one_level_and_clone_is_independent(): void
    {
        $row = new AA_Identifier('m', 'view', '12');
        $sub = clone $row;
        $sub->sub('name');
        $sub->sub(0);

        $this->assertSame('m->view->12', $row->toString());
        $this->assertSame('m->view->12->name->0', $sub->toString());
        $this->assertSame('name', $sub->getColumn(), 'the fourth level is the column, whatever follows');
    }

    public function test_identifier_sub_fills_the_next_free_level(): void
    {
        $table = new AA_Identifier('m', 'field');
        $table->sub('headline........');

        $this->assertSame('headline........', $table->getRow());
        $this->assertNull($table->getColumn());
    }

    /** @return array<string, array{string}> */
    public static function fullIdentifierStrings(): array
    {
        return [
            'four parts' => ['m->field->headline........->name'],
            'view row'   => [self::MOD . '->view->12->odd'],
        ];
    }

    #[DataProvider('fullIdentifierStrings')]
    public function test_factoryFromString_round_trips_a_full_identifier(string $string): void
    {
        $idf = (new AA_Identifier())->factoryFromString($string);

        $this->assertInstanceOf(AA_Identifier::class, $idf);
        $this->assertSame($string, $idf->toString());
    }

    public function test_factoryFromString_short_string_warns_and_leaves_the_rest_out(): void
    {
        $idf = self::collectingWarnings(static fn() => (new AA_Identifier())->factoryFromString('m->view->12'), $warnings);

        // pinned
        $this->assertSame('m->view->12', $idf->toString());
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Undefined array key', $warnings[0]);
    }

    public function test_factoryFromString_does_not_round_trip_everything(): void
    {
        // pinned: a fifth level is dropped, a row "0" loses row and column,
        // "->" inside a part splits it
        $this->assertSame('m->t->r->c', (new AA_Identifier())->factoryFromString('m->t->r->c->x')->toString());
        $this->assertSame('m->t', (new AA_Identifier())->factoryFromString('m->t->0->c')->toString());
        $arrow = new AA_Identifier('m', 't', 'a->b', 'c');
        $this->assertSame('m->t->a->b', $arrow->factoryFromString($arrow->toString())->toString());
    }

    // --- AA_Difference ---------------------------------------------------------------------------

    /** @param AA_Difference[] $diffs
     *  @return list<array{string, list<array{string, string, mixed}>}> [type, [[action type, identifier, params], ...]] */
    private static function summary(array $diffs): array
    {
        $ret = [];
        foreach ($diffs as $diff) {
            $actions = [];
            foreach (self::prop($diff, 'actions') as $action) {
                $actions[] = [self::prop($action, 'type'), self::prop($action, 'identifier')->toString(), self::prop($action, 'params')];
            }
            $ret[] = [self::prop($diff, 'type'), $actions];
        }
        return $ret;
    }

    private static function row(): AA_Identifier
    {
        return new AA_Identifier('m', 'field', 'r');
    }

    /** @return array<string, array{mixed, mixed, list<?string>, list<array{string, list<array{string, string, mixed}>}>}> */
    public static function comparisons(): array
    {
        return [
            'identical' => [['a' => '1', 'b' => 'x'], ['a' => '1', 'b' => 'x'], [],
                [['INFO', []]]],
            'both not array' => ['', null, [],
                [['INFO', []]]],
            'template not array - delete the record' => ['', ['a' => '1'], [],
                [['DELETED', [['DELETE', 'm->field->r', null]]]]],
            'destination not array - insert the record' => [['a' => '1'], null, [],
                [['NEW', [['INSERT', 'm->field->r', ['a' => '1']]]]]],
            'different value - whole row first, then the value' => [['a' => '1', 'b' => 'new'], ['a' => '1', 'b' => 'old'], [],
                [['DIFFERENT_ROW', [['UPDATE_ROW', 'm->field->r', ['a' => '1', 'b' => 'new']]]], ['DIFFERENT_VALUE', [['UPDATE_VALUE', 'm->field->r->b', 'new']]]]],
            // pinned: a missing column alone offers no whole-row update
            'key missing in destination' => [['a' => '1', 'b' => 'x'], ['a' => '1'], [],
                [['DIFFERENT_VALUE', [['UPDATE_VALUE', 'm->field->r->b', 'x']]]]],
            // pinned: "DELETED" for an extra column blanks it (UPDATE_VALUE ''), it is not dropped
            'extra key in destination' => [['a' => '1'], ['a' => '1', 'z' => 'extra'], [],
                [['DELETED', [['UPDATE_VALUE', 'm->field->r->z', '']]]]],
            'ignored keys are skipped and left out of the row' => [['slice_id' => 'T', 'a' => '1', 'b' => 'new'], ['slice_id' => 'D', 'b' => 'old'], ['slice_id', null, 'owner'],
                [['DIFFERENT_ROW', [['UPDATE_ROW', 'm->field->r', ['a' => '1', 'b' => 'new']]]], ['DIFFERENT_VALUE', [['UPDATE_VALUE', 'm->field->r->a', '1']]], ['DIFFERENT_VALUE', [['UPDATE_VALUE', 'm->field->r->b', 'new']]]]],
            // pinned: an ignored key is skipped only when the template has it;
            // in the destination alone it is offered for blanking (doAction() then refuses the installation columns)
            'ignored key only in destination' => [['a' => '1'], ['a' => '1', 'owner' => 'x'], ['owner'],
                [['DELETED', [['UPDATE_VALUE', 'm->field->r->owner', '']]]]],
        ];
    }

    #[DataProvider('comparisons')]
    public function test_compareArray_difference_and_action_per_case(mixed $template, mixed $destination, array $ignore, array $expected): void
    {
        $this->assertSame($expected, self::summary(AA_Difference::_compareArray($template, $destination, self::row(), $ignore)));
    }

    public function test_compareArray_of_a_table_recurses_into_the_rows(): void
    {
        $table    = new AA_Identifier('m', 'view');
        $template = ['1' => ['name' => 'A'], '2' => ['name' => 'B'], '3' => ['name' => 'C']];
        $dest     = ['1' => ['name' => 'A'], '2' => ['name' => 'X'], '4' => ['name' => 'D']];

        $diffs = self::collectingWarnings(static fn() => AA_Difference::_compareArray($template, $dest, $table, []), $warnings);

        $this->assertSame([
            ['INFO', []],                                                                 // 1 identical
            ['DIFFERENT_ROW', [['UPDATE_ROW', 'm->view->2', ['name' => 'B']]]],           // 2 different
            ['DIFFERENT_VALUE', [['UPDATE_VALUE', 'm->view->2->name', 'B']]],
            ['NEW', [['INSERT', 'm->view->3', ['name' => 'C']]]],                         // 3 only in template
            ['DELETED', [['DELETE', 'm->view->4', null]]],                                // 4 only in destination
        ], self::summary($diffs));
        $this->assertSame([], $warnings, 'a row missing in the destination is no warning');
    }

    /** @return array<string, array{mixed, mixed, bool}> template value, destination value, considered equal */
    public static function looseValues(): array
    {
        return [
            'same'                => ['abc', 'abc', true],
            'different'           => ['abc', 'abd', false],
            'case'                => ['abc', 'ABC', false],
            'int and string'      => [100, '100', true],
            // '' and 0 are considered equal, and so is anything empty()
            'empty and 0'         => ['', 0, true],
            'empty and null'      => ['', null, true],
            // pinned: "0" in the template is never synchronized over ''
            'string 0 and empty'  => ['0', '', true],
            // pinned: loose comparison: numeric strings compare as numbers
            'leading zero'        => ['1', '01', true],
            'float notation'      => ['10', '1e1', true],
            'trailing space'      => ['1', '1 ', true],
            'non numeric and 0'   => ['a', 0, false],
        ];
    }

    #[DataProvider('looseValues')]
    public function test_compareArray_compares_values_loosely(mixed $template, mixed $destination, bool $equal): void
    {
        $types = array_column(self::summary(AA_Difference::_compareArray(['v' => $template], ['v' => $destination], self::row(), [])), 0);

        $this->assertSame($equal ? ['INFO'] : ['DIFFERENT_ROW', 'DIFFERENT_VALUE'], $types);
    }

    public function test_difference_keeps_actions_as_a_list(): void
    {
        $action = new AA_Sync_Action('DELETE', self::row());

        $this->assertSame([], self::prop(new AA_Difference('INFO', 'x'), 'actions'));
        $this->assertSame([], self::prop(new AA_Difference('INFO', 'x', null), 'actions'));
        $this->assertSame([$action], self::prop(new AA_Difference('NEW', 'x', $action), 'actions'));
        $this->assertSame([$action, $action], self::prop(new AA_Difference('NEW', 'x', [$action, $action]), 'actions'));
    }

    public function test_difference_printOut_is_one_table_row(): void
    {
        ob_start();
        (new AA_Difference('DIFFERENT_VALUE', 'desc <i>as given</i>'))->printOut();
        $html = (string) ob_get_clean();

        // the description is written as it is - _compareArray() escapes what it puts in
        $this->assertSame("\n<tr class=\"diff_different_value\"><td>desc <i>as given</i></td><td></td></tr>", $html);
    }

    public function test_difference_printOut_puts_the_action_checkboxes_into_the_second_cell(): void
    {
        ob_start();
        (new AA_Difference('DELETED', 'd', [new AA_Sync_Action('DELETE', self::row()), new AA_Sync_Action('UPDATE_VALUE', self::row())]))->printOut();
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('~^\n<tr class="diff_deleted"><td>d</td><td><div><input [^>]+[^d]>Delete</div><div><input [^>]+[^d]>Update</div></td></tr>$~', $html);
    }

    // --- AA_Sync_Action --------------------------------------------------------------------------

    /** @return array<string, array{AA_Identifier, mixed, mixed}> */
    public static function dataArrays(): array
    {
        return [
            'column - one value'        => [new AA_Identifier('m', 'field', 'r', 'name'), 'Headline', ['name' => 'Headline']],
            'column - array value'      => [new AA_Identifier('m', 'field', 'r', 'name'), ['x'], ['name' => ['x']]],
            'row - the row as it is'    => [new AA_Identifier('m', 'field', 'r'), ['id' => 'r', 'name' => 'H'], ['id' => 'r', 'name' => 'H']],
            'row - scalar as it is'     => [new AA_Identifier('m', 'field', 'r'), 'x', 'x'],
            'row - no params'           => [new AA_Identifier('m', 'field', 'r'), null, null],
        ];
    }

    #[DataProvider('dataArrays')]
    public function test_getDataArray_wraps_the_params_in_the_column(AA_Identifier $idf, mixed $params, mixed $expected): void
    {
        $this->assertSame($expected, (new AA_Sync_Action('UPDATE_VALUE', $idf, $params))->_getDataArray());
    }

    private static function form(AA_Sync_Action $action): string
    {
        ob_start();
        $action->printToForm();
        return (string) ob_get_clean();
    }

    /** @return array<string, array{string, string, bool}> type => label, checked by default */
    public static function formTypes(): array
    {
        return [
            // deleting is the user's choice
            'DELETE'       => ['DELETE', 'Delete', false],
            'INSERT'       => ['INSERT', 'Create new', true],
            'UPDATE_ROW'   => ['UPDATE_ROW', 'Update', true],
            // a single value is not pre-checked - the whole row is
            'UPDATE_VALUE' => ['UPDATE_VALUE', 'Update', false],
            // pinned: an unknown type is pre-checked, with no label
            'unknown'      => ['RENAME', '', true],
        ];
    }

    #[DataProvider('formTypes')]
    public function test_printToForm_one_checkbox_with_label(string $type, string $label, bool $checked): void
    {
        $action = new AA_Sync_Action($type, self::row(), ['x' => 1]);
        $value  = base64_encode(serialize($action));

        $this->assertSame('<div><input type="checkbox" name="sync[]"  value="' . $value . '"' . ($checked ? ' checked' : '') . ">$label</div>", self::form($action));
    }

    public function test_printToForm_after_submit_checks_only_the_posted_values(): void
    {
        $insert = new AA_Sync_Action('INSERT', self::row(), ['x' => 1]);
        $update = new AA_Sync_Action('UPDATE_VALUE', new AA_Identifier('m', 'field', 'r', 'name'), 'x');

        $_POST['sync'] = [base64_encode(serialize($update))];
        $this->assertStringNotContainsString(' checked', self::form($insert), 'a pre-checked type unchecked by the user stays unchecked');
        $this->assertStringContainsString(' checked', self::form($update));

        $_POST['sync'] = base64_encode(serialize($insert));   // a single value, not an array
        $this->assertStringContainsString(' checked', self::form($insert));

        $_POST['sync'] = [];
        $this->assertStringNotContainsString(' checked', self::form($insert), 'posted but nothing chosen');
    }

    /** Do_Synchronize sends serialize($action) to the remote AA, which unserializes it with
     *  ResponderPolicy::syncAllowedClasses() - by class and private property names. */
    private const WIRE_SYNC_ACTION = "O:14:\"AA_Sync_Action\":3:{s:20:\"\0AA_Sync_Action\0type\";s:12:\"UPDATE_VALUE\";s:26:\"\0AA_Sync_Action\0identifier\";"
        . "O:13:\"AA_Identifier\":1:{s:19:\"\0AA_Identifier\0path\";a:4:{i:0;s:1:\"m\";i:1;s:5:\"field\";i:2;s:1:\"r\";i:3;s:1:\"c\";}}"
        . "s:22:\"\0AA_Sync_Action\0params\";s:1:\"v\";}";

    public function test_sync_action_serialized_form_is_the_wire_format(): void
    {
        $this->assertSame(self::WIRE_SYNC_ACTION, serialize(new AA_Sync_Action('UPDATE_VALUE', new AA_Identifier('m', 'field', 'r', 'c'), 'v')));
    }

    /** What an older (or newer) Central sends is read by the remote exactly as Do_Synchronize does */
    public function test_literal_sync_action_unserializes_to_a_working_action(): void
    {
        $action = unserialize(self::WIRE_SYNC_ACTION, ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]);

        $this->assertInstanceOf(AA_Sync_Action::class, $action);
        $this->assertSame(['c' => 'v'], $action->_getDataArray(), 'the identifier column is there');
        $this->assertStringEndsWith('>Update</div>', self::form($action));
        $this->assertEquals($action, AA_Sync_Action::fromFormValue(base64_encode(self::WIRE_SYNC_ACTION)));
    }

    // --- tasks queued in toexecute ---------------------------------------------------------------

    /** an AA as AA_Actionapps::getArray() loads it - with its central_conf id */
    private static function aa(string $pwd = 'jwt:TOKEN'): \AA_Actionapps
    {
        $content = new \ItemContent();
        $content->setValue('id', '12');
        $content->setValue('ORG_NAME', 'Remote');
        $content->setValue('AA_HTTP_DOMAIN', 'https://r.example.org/');
        $content->setValue('AA_BASE_DIR', 'aa/');
        $content->setValue('AA_ADMIN_USER', 'central');
        $content->setValue('AA_ADMIN_PWD', $pwd);
        return new \AA_Actionapps($content);
    }

    /** the object column of the row Toexecute stored */
    private static function queuedRow(object $task, string $selector): string
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        (new Toexecute())->userQueue($task, [], $selector);
        $rows = RecordingDb::inserts('toexecute');
        self::assertCount(1, $rows);
        self::assertSame($selector, $rows[0]['selector']);
        return $rows[0]['object'];
    }

    public function test_task_sync_queues_the_id_of_the_aa_not_its_login(): void
    {
        // as synchronize3.php queues it: the action serialized, the AA as an object
        $action = new AA_Sync_Action('UPDATE_ROW', new AA_Identifier(self::MOD, 'field', 'headline........'), ['name' => 'Headline']);
        $task   = new AA_Task_Sync(serialize($action), self::aa());

        $row  = self::queuedRow($task, 'AA_Task_Sync');   // the selector keeps the old name
        $copy = unserialize($row);                         // misc/toexecute.php: no class filter

        $this->assertStringNotContainsString('TOKEN', $row);
        $this->assertStringNotContainsString('Actionapps', $row, 'no AA object in the queue');
        $this->assertStringStartsWith('O:20:"AA\Central\Task\Sync":2:{', $row);
        $this->assertInstanceOf(AA_Task_Sync::class, $copy);
        $this->assertSame(serialize($action), $copy->sync_action);
        $this->assertSame(12, $copy->aa_id);
        $this->assertNull($copy->actionapps, 'read from central_conf when the task runs');
    }

    public function test_task_for_an_aa_removed_from_central_logs_an_error(): void
    {
        $task = unserialize(serialize(new AA_Task_Sync('cmd', 9902)));
        $GLOBALS['spareDBs'] = [new RecordingDb()];   // no central_conf row

        $ret = $task->toexecutelater();

        $this->assertStringStartsWith('Err: ', $ret);
        $this->assertStringContainsString('9902', $ret);
        $this->assertSame(["SELECT * FROM central_conf WHERE id   = '9902' "], RecordingDb::$log, 'nothing sent anywhere');
    }

    /** rows queued before the tasks carried aa_id embed the AA - one with its id is read again from central_conf */
    public function test_old_row_with_an_aa_with_id_reads_the_aa_again(): void
    {
        $row  = 'O:12:"AA_Task_Sync":2:{s:11:"sync_action";s:3:"cmd";s:10:"actionapps";' . serialize(self::aa('secret')) . '}';
        $task = unserialize($row);

        $this->assertInstanceOf(AA_Task_Sync::class, $task);
        $this->assertSame(12, $task->aa_id);
        $this->assertNull($task->actionapps);
        $this->assertStringNotContainsString('secret', serialize($task), 'queued again, it drops the login');
    }

    public function test_task_sync_sends_its_one_action_to_the_remote(): void
    {
        $aa = new class extends \AA_Actionapps {
            /** @var list<mixed> */
            public array $sent = [];

            public function __construct() {}

            public function synchronize($sync_commands)
            {
                $this->sent[] = $sync_commands;
                return ['done'];
            }
        };
        $task = new AA_Task_Sync('serialized action', $aa);

        $this->assertSame('done', $task->toexecutelater(['ignored']));
        $this->assertSame([['serialized action']], $aa->sent);
    }

    public function test_task_sync_with_the_error_code_logs_the_code(): void
    {
        $aa = new class extends \AA_Actionapps {
            public function __construct() {}

            public function synchronize($sync_commands)
            {
                return 3;   // what synchronize() returns on a failed request
            }
        };

        // pinned: the task log says "3", and the task counts as done
        $this->assertSame('3', (new AA_Task_Sync('cmd', $aa))->toexecutelater());
    }

    public function test_task_of_an_aa_without_central_conf_id_is_not_queued(): void
    {
        $task = new AA_Task_Sync('cmd', new \AA_Actionapps(new \ItemContent()));

        $this->expectException(\LogicException::class);   // it would run as "no central_conf row"
        serialize($task);
    }

    public function test_tasks_for_one_aa_read_its_central_conf_row_once(): void
    {
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        (new AA_Task_Sync('cmd', 9903))->toexecutelater();
        (new AA_Task_Sync('cmd', 9903))->toexecutelater();

        $this->assertCount(1, RecordingDb::$log, 'cached for the request - the id is unique to this test');
    }

    public function test_task_manager_log_keeps_the_old_task_names(): void
    {
        $this->assertSame('AA_Task_Sync', \AA_Log::selector(new AA_Task_Sync('cmd', 12))->cls);
    }

    /** Rows an older AA left waiting in toexecute (captured literally): the AA with a password login
     *  and the remote session fields Actionapps no longer has, the action / chunk to send; the import
     *  task is of the Copy Slice this AA no longer has */
    private const QUEUED_TASK_SYNC = "O:12:\"AA_Task_Sync\":2:{s:11:\"sync_action\";s:314:\"O:14:\"AA_Sync_Action\":3:{s:20:\"\0AA_Sync_Action\0type"
        . "\";s:10:\"UPDATE_ROW\";s:26:\"\0AA_Sync_Action\0identifier\";O:13:\"AA_Identifier\":1:{s:19:\"\0AA_Identifier\0p"
        . "ath\";a:3:{i:0;s:32:\"0123456789abcdef0123456789abcdef\";i:1;s:5:\"field\";i:2;s:16:\"headline........\";}}"
        . "s:22:\"\0AA_Sync_Action\0params\";a:1:{s:4:\"name\";s:8:\"Headline\";}}\";s:10:\"actionapps\";O:13:\"AA_Actionap"
        . "ps\":5:{s:25:\"\0AA_Actionapps\0local_data\";O:11:\"ItemContent\":3:{s:10:\"\0*\0content\";a:5:{s:8:\"ORG_NAME\";"
        . "a:1:{i:0;a:1:{s:5:\"value\";s:6:\"Remote\";}}s:14:\"AA_HTTP_DOMAIN\";a:1:{i:0;a:1:{s:5:\"value\";s:22:\"https"
        . "://r.example.org/\";}}s:11:\"AA_BASE_DIR\";a:1:{i:0;a:1:{s:5:\"value\";s:3:\"aa/\";}}s:13:\"AA_ADMIN_USER\";a"
        . ":1:{i:0;a:1:{s:5:\"value\";s:7:\"central\";}}s:12:\"AA_ADMIN_PWD\";a:1:{i:0;a:1:{s:5:\"value\";s:6:\"secret\";"
        . "}}}s:11:\"\0*\0id_field\";s:5:\"aa_id\";s:14:\"\0*\0owner_field\";s:8:\"aa_owner\";}s:25:\"\0AA_Actionapps\0last_er"
        . "ror\";N;s:33:\"\0AA_Actionapps\0_remote_session_id\";N;s:38:\"\0AA_Actionapps\0_remote_session_id_time\";i:0;"
        . "s:22:\"\0AA_Actionapps\0_cached\";a:0:{}}}";

    private const QUEUED_TASK_IMPORT = "O:27:\"AA_Task_Import_Module_Chunk\":2:{s:26:\"\0*\0module_definition_chunk\";O:26:\"AA_Definition_Module_C"
        . "hunk\":2:{s:12:\"\0*\0module_id\";s:32:\"0123456789abcdef0123456789abcdef\";s:7:\"\0*\0data\";a:1:{s:4:\"view\";a"
        . ":1:{i:12;a:3:{s:2:\"id\";i:12;s:8:\"slice_id\";s:32:\"0123456789abcdef0123456789abcdef\";s:4:\"name\";s:4:\"L"
        . "ist\";}}}}s:13:\"\0*\0actionapps\";O:13:\"AA_Actionapps\":5:{s:25:\"\0AA_Actionapps\0local_data\";O:11:\"ItemCon"
        . "tent\":3:{s:10:\"\0*\0content\";a:5:{s:8:\"ORG_NAME\";a:1:{i:0;a:1:{s:5:\"value\";s:6:\"Remote\";}}s:14:\"AA_HTT"
        . "P_DOMAIN\";a:1:{i:0;a:1:{s:5:\"value\";s:22:\"https://r.example.org/\";}}s:11:\"AA_BASE_DIR\";a:1:{i:0;a:1:"
        . "{s:5:\"value\";s:3:\"aa/\";}}s:13:\"AA_ADMIN_USER\";a:1:{i:0;a:1:{s:5:\"value\";s:7:\"central\";}}s:12:\"AA_ADM"
        . "IN_PWD\";a:1:{i:0;a:1:{s:5:\"value\";s:6:\"secret\";}}}s:11:\"\0*\0id_field\";s:5:\"aa_id\";s:14:\"\0*\0owner_fiel"
        . "d\";s:8:\"aa_owner\";}s:25:\"\0AA_Actionapps\0last_error\";N;s:33:\"\0AA_Actionapps\0_remote_session_id\";N;s:3"
        . "8:\"\0AA_Actionapps\0_remote_session_id_time\";i:0;s:22:\"\0AA_Actionapps\0_cached\";a:0:{}}}";

    /** Rows queued before a change of these classes must still run (class_alias would do for them -
     *  toexecute is local); a stray class unserializes to __PHP_Incomplete_Class and the task is lost. */
    public function test_literal_queued_task_sync_still_runs(): void
    {
        $task = unserialize(self::QUEUED_TASK_SYNC);   // misc/toexecute.php: no class filter

        $this->assertInstanceOf(AA_Task_Sync::class, $task);
        $this->assertSame('Remote (https://r.example.org/aa/)', $task->actionapps->getName());
        // pinned: the login is stored in the queue
        $this->assertSame('secret', $task->actionapps->getAccessPassword());
        $this->assertFalse(property_exists($task->actionapps, '_remote_session_id'), 'the old session fields are dropped');
        $this->assertNull($task->actionapps->getLastError());

        $fake             = self::recordingAa();
        $task->actionapps = $fake;
        $this->assertSame('sent', $task->toexecutelater());
        $action = unserialize($fake->sent[0][0], ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]);
        $this->assertInstanceOf(AA_Sync_Action::class, $action);
        $this->assertSame(['name' => 'Headline'], $action->_getDataArray());
    }

    /** @return string[] the statements of one kind on toexecute */
    private static function toexecuteStatements(string $verb): array
    {
        return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, "$verb `toexecute`")));
    }

    /** runs the rows through _exeOne() as the queue does; @return list<int> what it returned for each */
    private static function runRows(Toexecute $toexecute, string $object, array $ids): array
    {
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        \AA\Util\LogOnce::reset();
        $times = [];
        $ret   = [];
        foreach ($ids as $id) {
            $row   = ['id' => $id, 'selector' => 'sel', 'priority' => 100, 'execute_after' => 1, 'object' => $object, 'params' => 'a:0:{}'];
            $ret[] = $toexecute->_exeOne($row, $times, microtime(true), 60.0);
        }
        \AA\Util\LogOnce::reset();
        return $ret;
    }

    /** such rows may still be queued: each is removed, the log gets one row a day for the class */
    public function test_queued_task_of_a_removed_class_is_dropped_and_logged_once(): void
    {
        $toexecute = new Toexecute();

        $this->assertSame([1, 1], self::runRows($toexecute, self::QUEUED_TASK_IMPORT, [7, 8]), 'done - the runner goes on');

        $deletes = self::toexecuteStatements('DELETE FROM');
        $this->assertCount(2, $deletes);
        $this->assertStringContainsString('7', $deletes[0]);
        $this->assertStringContainsString('8', $deletes[1]);
        $logs = RecordingDb::inserts('log');
        $this->assertCount(1, $logs, 'one row for the class, no TOEXECUTE row per task');
        $this->assertSame(Toexecute::LOG_CLASS_GONE, $logs[0]['type']);
        $this->assertSame('AA_Task_Import_Module_Chunk', $logs[0]['selector']);
        $this->assertStringContainsString('AA_Task_Import_Module_Chunk no longer exists', $toexecute->report());
    }

    public function test_queued_task_of_the_namespaced_removed_class_is_dropped(): void
    {
        $this->assertSame([1], self::runRows(new Toexecute(), 'O:33:"AA\\Central\\Task\\ImportModuleChunk":0:{}', [7]));
        $this->assertCount(1, self::toexecuteStatements('DELETE FROM'));
    }

    /** a live class may be missing for a moment (a deploy, a stale classmap) - its tasks must survive it */
    public function test_queued_task_of_an_unknown_class_stays_and_is_logged_once(): void
    {
        $toexecute = new Toexecute();

        $this->assertSame([-1, -1], self::runRows($toexecute, 'O:15:"AA_Task_Unknown":0:{}', [7, 8]), 'skipped - the runner goes on');

        $this->assertSame([], self::toexecuteStatements('DELETE FROM'));
        $updates = self::toexecuteStatements('UPDATE');
        $this->assertCount(2, $updates, 'only the claims - the priority sinks by one, so the row retires after ~100 leases');
        $this->assertMatchesRegularExpression('/priority[`"]?\\s*=\\s*\'?99\\b/', $updates[0]);
        $logs = RecordingDb::inserts('log');
        $this->assertCount(1, $logs);
        $this->assertSame(Toexecute::LOG_CLASS_MISSING, $logs[0]['type']);
        $this->assertSame('AA_Task_Unknown', $logs[0]['selector']);
        $this->assertStringContainsString('AA_Task_Unknown is missing', $toexecute->report());
    }

    /** an AA which keeps what synchronize() would send */
    private static function recordingAa(): \AA_Actionapps
    {
        return new class extends \AA_Actionapps {
            /** @var list<mixed> */
            public array $sent = [];

            public function __construct() {}

            public function synchronize($sync_commands)
            {
                $this->sent[] = $sync_commands;
                return ['sent'];
            }
        };
    }
}
