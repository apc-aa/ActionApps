<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Central\ResponderPolicy;
use AA_Definition_Module;
use AA_Definition_Module_Slice;
use AA_Difference;
use AA_Identifier;
use AA_Sync_Action;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// class declarations only - file level, since the data providers below build these objects
require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';

/**
 * Central Synchronize (central/synchronize3.php) without a DB: comparing slice definitions that
 * came back empty or not at all, and the sync[] checkbox payload the form posts back.
 */
final class CentralSynchronizeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';    // the real util.php, FrmChBoxEasy() included
    }

    /** AA::$metabase before a test replaced it; false = not replaced */
    private mixed $saved_metabase = false;

    protected function tearDown(): void
    {
        unset($_POST['sync']);
        if ($this->saved_metabase !== false) {
            (new \ReflectionProperty(\AA::class, 'metabase'))->setValue(null, $this->saved_metabase);
            $this->saved_metabase = false;
        }
    }

    /** Makes a write that got past the guard fail loudly. */
    private function failOnMetabaseWrite(): void
    {
        $property             = new \ReflectionProperty(\AA::class, 'metabase');
        $this->saved_metabase = $property->getValue();
        $property->setValue(null, new class extends \AA_Metabase {
            public function fillKeys(&$data, $identifier): void
            {
                throw new \LogicException('sync action reached Metabase');
            }
        });
    }

    /** @return array<string, array{mixed, array<string, bool>}> */
    public static function limitedValues(): array
    {
        return [
            'legacy true = definitions' => [true, ['definitions' => true]],
            'false'                     => [false, []],
            'null'                      => [null, []],
            'string'                    => ['1', []],
            'empty array'               => [[], []],
            'definitions'               => [['definitions' => true], ['definitions' => true]],
            'items only'                => [['items' => true], ['items' => true]],
            'both, falsy dropped'       => [['definitions' => 1, 'items' => 0, 'x' => true], ['definitions' => true]],
        ];
    }

    #[DataProvider('limitedValues')]
    public function test_normalizeLimited_maps_to_known_flags(mixed $limited, array $expected): void
    {
        $this->assertSame($expected, AA_Definition_Module::normalizeLimited($limited));
    }

    private const TEMPLATE_ID = '0123456789abcdef0123456789abcdef';
    private const DEST_ID     = 'fedcba9876543210fedcba9876543210';

    /** A slice definition as loadForId() would fill it (it needs the DB, so set directly). */
    private static function sliceDef(string $id, array $data): AA_Definition_Module_Slice
    {
        $def = new AA_Definition_Module_Slice();
        (new \ReflectionProperty($def, 'module_id'))->setValue($def, $id);
        (new \ReflectionProperty($def, 'data'))->setValue($def, $data);
        return $def;
    }

    private static function fullTemplate(): AA_Definition_Module_Slice
    {
        return self::sliceDef(self::TEMPLATE_ID, [
            'module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News']],
            'slice'  => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News']],
            'field'  => ['headline........' => ['id' => 'headline........', 'name' => 'Headline']],
        ]);
    }

    /** @param AA_Difference[] $diffs
     *  @return list<string> types of all actions offered with the differences */
    private static function actionTypes(array $diffs): array
    {
        $types = [];
        foreach ($diffs as $diff) {
            foreach ((new \ReflectionProperty($diff, 'actions'))->getValue($diff) as $action) {
                $types[] = (new \ReflectionProperty($action, 'type'))->getValue($action);
            }
        }
        return $types;
    }

    /** @return array<string, array{mixed}> */
    public static function anyDestination(): array
    {
        return [
            'no destination'    => [null],
            'empty destination' => [new AA_Definition_Module_Slice()],
            'full destination'  => [self::sliceDef(self::DEST_ID, [
                'module' => [self::DEST_ID => ['id' => self::DEST_ID, 'name' => 'Old']],
                'slice'  => [self::DEST_ID => ['id' => self::DEST_ID, 'name' => 'Old']],
            ])],
        ];
    }

    /** A template that loaded nothing (unknown id, remote error) used to offer pre-checked
     *  DELETEs of the destination's module and slice rows. */
    #[DataProvider('anyDestination')]
    public function test_compareWith_empty_template_offers_no_action(mixed $dest): void
    {
        foreach ([new AA_Definition_Module_Slice(), self::sliceDef(self::TEMPLATE_ID, ['module' => [], 'slice' => []])] as $template) {
            $diffs = $template->compareWith($dest);

            $this->assertCount(1, $diffs);
            $this->assertStringStartsWith("\n<tr class=\"diff_info\">", self::render($diffs));
            $this->assertSame([], self::actionTypes($diffs));
        }
    }

    public function test_compareWith_missing_destination_is_one_info_row(): void
    {
        $diffs = self::fullTemplate()->compareWith(null);

        $this->assertCount(1, $diffs);
        $this->assertStringContainsString('Comparison slice (' . self::TEMPLATE_ID . ') does not exist', self::render($diffs));
        $this->assertSame([], self::actionTypes($diffs));
    }

    public function test_compareWith_empty_destination_offers_new_rows(): void
    {
        $dest = new AA_Definition_Module_Slice();
        (new \ReflectionProperty($dest, 'module_id'))->setValue($dest, self::DEST_ID);

        $types = self::actionTypes(self::fullTemplate()->compareWith($dest));

        $this->assertSame(['INSERT', 'INSERT', 'INSERT'], $types, 'module, slice and field rows are created, nothing deleted');
    }

    /** @param AA_Difference[] $diffs */
    private static function render(array $diffs): string
    {
        ob_start();
        foreach ($diffs as $diff) {
            $diff->printOut();
        }
        return (string) ob_get_clean();
    }

    private static function action(): AA_Sync_Action
    {
        return new AA_Sync_Action('UPDATE_ROW', new AA_Identifier('0123456789abcdef0123456789abcdef', 'field', 'headline........'), ['name' => "Head\0line <b>", 'input_pri' => 100]);
    }

    private static function formValue(AA_Sync_Action $action): string
    {
        ob_start();
        $action->printToForm();
        $html = (string) ob_get_clean();
        preg_match('/name="sync\[\]"[^>]*value="([^"]*)"/', $html, $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    public function test_printToForm_value_survives_the_browser_and_round_trips(): void
    {
        $action = self::action();
        $value  = self::formValue($action);

        $this->assertStringNotContainsString("\0", $value, 'private properties must not put NUL bytes into the form');
        $this->assertMatchesRegularExpression('~^[A-Za-z0-9+/=]+$~', $value);
        $decoded = AA_Sync_Action::fromFormValue($value);
        $this->assertEquals($action, $decoded);
        $this->assertSame(serialize($action), serialize($decoded), 'the remote AA gets the same serialized string as before');
    }

    public function test_printToForm_keeps_posted_choice(): void
    {
        $action        = self::action();
        $_POST['sync'] = [self::formValue($action)];
        ob_start();
        $action->printToForm();
        $this->assertStringContainsString(' checked>', (string) ob_get_clean());

        $_POST['sync'] = ['something else'];
        ob_start();
        $action->printToForm();
        $this->assertStringNotContainsString(' checked>', (string) ob_get_clean());
    }

    /** @return array<string, array{string}> */
    public static function invalidFormValues(): array
    {
        $action   = serialize(self::action());
        $identity = 'O:13:"AA_Identifier"';
        return [
            'empty'                  => [''],
            'not base64'             => ['@@@ not base64 @@@'],
            'raw serialized (old)'   => [$action],
            'base64 of garbage'      => [base64_encode('hello')],
            'foreign object'         => [base64_encode(serialize(new \ArrayObject([1])))],
            'identifier only'        => [base64_encode(serialize(new AA_Identifier('a', 'field', 'b')))],
            'foreign identifier'     => [base64_encode(str_replace($identity, 'O:11:"ArrayObject"', $action))],
            'identifier missing'     => [base64_encode(preg_replace('/^O:14:"AA_Sync_Action":3:\{(.*)s:26:"\0AA_Sync_Action\0identifier";O:13:"AA_Identifier":1:\{.*?\}\}(.*)$/s', 'O:14:"AA_Sync_Action":2:{$1$2', $action))],
        ];
    }

    #[DataProvider('invalidFormValues')]
    public function test_fromFormValue_rejects_anything_else(string $value): void
    {
        $this->assertNull(AA_Sync_Action::fromFormValue($value));
    }

    /** @return array<string, array{?string}> */
    public static function foreignTables(): array
    {
        return [
            'toexecute (unserialized later)' => ['toexecute'],
            'users'                          => ['users'],
            'central_conf'                   => ['central_conf'],
            'item'                           => ['item'],
            'upper case'                     => ['FIELD'],
            'no table'                       => [null],
        ];
    }

    #[DataProvider('foreignTables')]
    public function test_doAction_refuses_tables_compareWith_never_produces(?string $table): void
    {
        $this->failOnMetabaseWrite();
        foreach (['UPDATE_ROW', 'UPDATE_VALUE', 'INSERT', 'DELETE'] as $type) {
            $ret = (new AA_Sync_Action($type, new AA_Identifier('0123456789abcdef0123456789abcdef', $table, 'row1', 'col')))->doAction();
            $this->assertIsString($ret);
            $this->assertStringStartsWith('Wrong command', $ret);
            if ($table !== null) {
                $this->assertStringNotContainsString($table, $ret, 'the table name is not echoed');
            }
        }
    }

    public function test_task_sync_logs_the_remote_report_lines(): void
    {
        $aa = new class extends \AA_Actionapps {
            public function __construct() {}

            public function synchronize($sync_commands)
            {
                return ['field x updated', ['nested'], 'view y inserted'];
            }
        };
        $this->assertSame("field x updated\nview y inserted", (new \AA_Task_Sync('cmd', $aa))->toexecutelater());
    }

    public function test_invalid_fixture_identifier_missing_is_still_an_action(): void
    {
        // guards the fixture above: without the identifier it still unserializes to AA_Sync_Action
        $value = self::invalidFormValues()['identifier missing'][0];
        $this->assertInstanceOf(AA_Sync_Action::class, unserialize(base64_decode($value), ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]));
    }
}
