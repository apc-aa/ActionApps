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
use Tests\Unit\Legacy\Fixtures\RecordingDb;

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
        require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';   // Cvarset of the Metabase do*()
    }

    /** AA::$metabase before a test replaced it; false = not replaced */
    private mixed $saved_metabase = false;

    protected function tearDown(): void
    {
        unset($_POST['sync']);
        $GLOBALS['spareDBs'] = [];
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

    /** A template that loaded nothing (unknown id, remote error) must not offer pre-checked
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

    /** a constant id - `constant` is the one synchronized table whose packed key is not the module column */
    private const CONSTANT_ID = '00112233445566778899aabbccddeeff';
    /** a constant two levels deep: the ids of its parent and grandparent, concatenated */
    private const ANCESTORS   = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaabbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** A `constant` row as AA_Definition_Module_Slice::loadForId() gives it; short_id is the installation's own */
    private static function constantRow(int $short_id): array
    {
        return ['id' => self::CONSTANT_ID, 'group_id' => 'yes_no', 'name' => 'Yes', 'value' => '1', 'class' => '', 'pri' => 100,
            'ancestors' => self::ANCESTORS, 'description' => '', 'short_id' => $short_id];
    }

    /** @return array<string, array{AA_Sync_Action, string}> action => the SQL it must send to the destination DB */
    public static function destinationWrites(): array
    {
        $dest   = '0x' . self::DEST_ID;
        $field  = new AA_Identifier(self::DEST_ID, 'field', 'headline........');
        $slice  = new AA_Identifier(self::DEST_ID, 'slice', self::DEST_ID);
        $const  = new AA_Identifier(self::DEST_ID, 'constant', self::CONSTANT_ID);
        return [
            'field UPDATE_ROW'    => [new AA_Sync_Action('UPDATE_ROW', $field, ['id' => 'headline........', 'slice_id' => self::TEMPLATE_ID, 'name' => 'Headline', 'input_pri' => 100]),
                "UPDATE `field` SET `name`='Headline', `input_pri`='100' WHERE id='headline........' AND slice_id=$dest"],
            'field UPDATE_VALUE'  => [new AA_Sync_Action('UPDATE_VALUE', new AA_Identifier(self::DEST_ID, 'field', 'headline........', 'name'), 'Headline'),
                "UPDATE `field` SET `name`='Headline' WHERE id='headline........' AND slice_id=$dest"],
            'field INSERT'        => [new AA_Sync_Action('INSERT', $field, ['id' => 'headline........', 'slice_id' => self::TEMPLATE_ID, 'name' => 'Headline', 'input_pri' => 100]),
                "INSERT INTO `field` (`id`,`slice_id`,`name`,`input_pri`) VALUES ('headline........',$dest,'Headline',100)"],
            // the new row gets its own number - the template's id may be taken on the destination
            'view INSERT'         => [new AA_Sync_Action('INSERT', new AA_Identifier(self::DEST_ID, 'view', '12'), ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => 'List']),
                "INSERT INTO `view` (`slice_id`,`name`) VALUES ($dest,'List')"],
            'email INSERT'        => [new AA_Sync_Action('INSERT', new AA_Identifier(self::DEST_ID, 'email', '7'), ['id' => 7, 'description' => 'Welcome', 'owner_module_id' => self::TEMPLATE_ID]),
                "INSERT INTO `email` (`description`,`owner_module_id`) VALUES ('Welcome',$dest)"],
            'field DELETE'        => [new AA_Sync_Action('DELETE', $field),
                "DELETE FROM `field` WHERE id='headline........' AND slice_id=$dest"],
            'view UPDATE_ROW'     => [new AA_Sync_Action('UPDATE_ROW', new AA_Identifier(self::DEST_ID, 'view', '12'), ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => 'List']),
                "UPDATE `view` SET `slice_id`=$dest, `name`='List' WHERE id='12'"],
            'slice UPDATE_ROW'    => [new AA_Sync_Action('UPDATE_ROW', $slice, ['id' => self::TEMPLATE_ID, 'name' => 'News']),
                "UPDATE `slice` SET `name`='News' WHERE id=$dest"],
            // as an older Central (or an older queued task) sends it
            'slice UPDATE_ROW, old Central' => [new AA_Sync_Action('UPDATE_ROW', $slice, ['id' => self::TEMPLATE_ID, 'name' => 'News', 'owner' => self::TEMPLATE_OWNER,
                'deleted' => 1, 'slice_url' => 'https://template.example.org/', 'reading_password' => '', 'created_by' => 'user1']),
                "UPDATE `slice` SET `name`='News' WHERE id=$dest"],
            'slice INSERT'        => [new AA_Sync_Action('INSERT', $slice, ['id' => self::TEMPLATE_ID, 'name' => 'News', 'deleted' => 1]),
                "INSERT INTO `slice` (`id`,`name`,`deleted`) VALUES ($dest,'News',0)"],
            'module INSERT'       => [new AA_Sync_Action('INSERT', new AA_Identifier(self::DEST_ID, 'module', self::DEST_ID), ['id' => self::TEMPLATE_ID, 'name' => 'News', 'deleted' => 1, 'type' => 'S']),
                "INSERT INTO `module` (`id`,`name`,`deleted`,`type`) VALUES ($dest,'News',0,'S')"],
            'slice DELETE'        => [new AA_Sync_Action('DELETE', $slice),
                "DELETE FROM `slice` WHERE id=$dest"],
            'constant UPDATE_ROW' => [new AA_Sync_Action('UPDATE_ROW', $const, self::constantRow(57)),
                "UPDATE `constant` SET `group_id`='yes_no', `name`='Yes', `value`='1', `class`='', `pri`='100', `ancestors`=0x" . self::ANCESTORS . ", `description`='' WHERE id=0x" . self::CONSTANT_ID],
            'constant INSERT'     => [new AA_Sync_Action('INSERT', $const, self::constantRow(57)),
                "INSERT INTO `constant` (`id`,`group_id`,`name`,`value`,`class`,`pri`,`ancestors`,`description`) VALUES (0x" . self::CONSTANT_ID . ",'yes_no','Yes','1','',100,0x" . self::ANCESTORS . ",'')"],
            'constant DELETE'     => [new AA_Sync_Action('DELETE', $const),
                'DELETE FROM `constant` WHERE id=0x' . self::CONSTANT_ID],
        ];
    }

    /** The Metabase do*() pack the keys and the module column themselves - packed twice, every write
     *  would hit '' instead of the destination slice. */
    #[DataProvider('destinationWrites')]
    public function test_doAction_writes_the_destination_row(AA_Sync_Action $action, string $sql): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        $ret = $action->doAction();

        $this->assertSame([$sql], RecordingDb::$log);
        $this->assertStringNotContainsString('Error', $ret);
    }

    /** A failed write (DB error, key missing) is reported as failed; a DB error does not die() mid-batch. */
    public function test_doAction_reports_a_failed_write(): void
    {
        $property             = new \ReflectionProperty(\AA::class, 'metabase');
        $this->saved_metabase = $property->getValue();
        $metabase             = new class extends \AA_Metabase {
            /** @var list<array{string, string, ?string}> */
            public array $calls = [];

            public function __construct()
            {
                $this->tables = \AA_Metabase::singleton()->tables;
            }

            public function doUpdate($tablename, $data, ?string $nohalt = null): bool|int
            {
                $this->calls[] = ['update', $tablename, $nohalt];
                return false;
            }

            public function doInsert(string $tablename, array $data, ?string $nohalt = null): bool|int
            {
                $this->calls[] = ['insert', $tablename, $nohalt];
                return false;
            }

            public function doDelete(string $tablename, array $keydata, ?string $nohalt = null): bool|int
            {
                $this->calls[] = ['delete', $tablename, $nohalt];
                return false;
            }
        };
        $property->setValue(null, $metabase);

        $field = new AA_Identifier(self::DEST_ID, 'field', 'headline........');
        foreach (['UPDATE_ROW' => ['name' => 'Headline'], 'INSERT' => ['id' => 'headline........', 'name' => 'Headline'], 'DELETE' => null] as $type => $params) {
            $ret = (new AA_Sync_Action($type, $field, $params))->doAction();
            $this->assertStringStartsWith('Error', $ret, $type);
        }
        $this->assertSame([['update', 'field', 'nohalt'], ['insert', 'field', 'nohalt'], ['delete', 'field', 'nohalt']], $metabase->calls);
    }

    /** An old Central may still send an installation column alone - nothing is written. */
    public function test_doAction_skips_an_update_of_installation_column(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        $ret = (new AA_Sync_Action('UPDATE_VALUE', new AA_Identifier(self::DEST_ID, 'slice', self::DEST_ID, 'owner'), self::TEMPLATE_OWNER))->doAction();

        $this->assertSame([], RecordingDb::$log);
        $this->assertStringContainsString('nothing to update', $ret);
        $this->assertStringNotContainsString('Error', $ret);
    }

    /** doInsertMulti() builds its VALUES by getSQLData() - several ids concatenated are one 0x… literal
     *  there too, not the hex digits as text */
    public function test_getSQLData_writes_packed_columns_as_hex_literal(): void
    {
        $sql = \AA::Metabase()->tables['constant']->getSQLData(['id' => self::CONSTANT_ID, 'ancestors' => self::ANCESTORS, 'class' => 'abc']);

        $this->assertSame('0x' . self::CONSTANT_ID, $sql['id']);
        $this->assertSame('0x' . self::ANCESTORS, $sql['ancestors']);
        $this->assertSame("'abc'", $sql['class'], 'not a packed column');
    }

    public function test_doAction_reports_a_database_error(): void
    {
        RecordingDb::reset();
        RecordingDb::$fail_on = 'UPDATE `field`';
        $GLOBALS['spareDBs']  = [new RecordingDb()];

        $ret = (new AA_Sync_Action('UPDATE_VALUE', new AA_Identifier(self::DEST_ID, 'field', 'headline........', 'name'), 'Headline'))->doAction();

        $this->assertStringStartsWith('Error', $ret);
    }

    private const TEMPLATE_OWNER = '11111111111111111111111111111111';
    private const DEST_OWNER     = '22222222222222222222222222222222';

    /** module, slice and constant rows of one slice as two installations hold them - the same
     *  definition, but each with its own owner, URLs, creator, view ids, numbering */
    private static function installationDef(string $id, string $owner, string $url, int $short_id, string $name): AA_Definition_Module_Slice
    {
        $created = ['created_at' => $short_id * 1000, 'created_by' => "user$short_id"];
        return self::sliceDef($id, [
            'module'   => [$id => ['id' => $id, 'name' => $name, 'deleted' => 0, 'type' => 'S', 'slice_url' => $url, 'lang_file' => 'en_news_lang.php',
                'owner' => $owner, 'app_id' => '', 'priority' => $short_id, 'flag' => 0] + $created],
            'slice'    => [$id => ['id' => $id, 'name' => $name, 'owner' => $owner, 'deleted' => 0, 'slice_url' => $url, 'vid' => $short_id,
                'mlxctrl' => $owner, 'fileman_dir' => "dir$short_id", 'reading_password' => "secret$short_id", 'd_listlen' => 10, 'lang_file' => 'en_news_lang.php'] + $created],
            'constant' => [self::CONSTANT_ID => self::constantRow($short_id)],
        ]);
    }

    /** A pre-checked UPDATE_ROW must not overwrite what belongs to the destination installation:
     *  its owner, creator, URLs, view and MLX slice ids, menu order, deleted state, and the
     *  auto_increment constant.short_id. Only the definition (here the name) is synchronized. */
    public function test_compareWith_leaves_installation_columns_alone(): void
    {
        $template = self::installationDef(self::TEMPLATE_ID, self::TEMPLATE_OWNER, 'https://template.example.org/news/', 57, 'News');
        $dest     = self::installationDef(self::DEST_ID, self::DEST_OWNER, 'https://dest.example.org/zpravy/', 1204, 'Old news');

        $actions = [];
        foreach ($template->compareWith($dest) as $diff) {
            foreach ((new \ReflectionProperty($diff, 'actions'))->getValue($diff) as $action) {
                $actions[] = $action;
            }
        }
        $this->assertSame(['UPDATE_ROW', 'UPDATE_VALUE', 'UPDATE_ROW', 'UPDATE_VALUE'], array_map(static fn($a) => (new \ReflectionProperty($a, 'type'))->getValue($a), $actions), 'module and slice: the name only');

        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        foreach ($actions as $action) {
            $action->doAction();
        }
        $this->assertSame([
            "UPDATE `module` SET `name`='News', `type`='S', `lang_file`='en_news_lang.php', `app_id`='', `flag`='0' WHERE id=0x" . self::DEST_ID,
            "UPDATE `module` SET `name`='News' WHERE id=0x" . self::DEST_ID,
            "UPDATE `slice` SET `name`='News', `d_listlen`='10', `lang_file`='en_news_lang.php' WHERE id=0x" . self::DEST_ID,
            "UPDATE `slice` SET `name`='News' WHERE id=0x" . self::DEST_ID,
        ], RecordingDb::$log);
    }

    /** template and destination slice: the module renamed, the slice changed otherwise, a field
     *  renamed, another view on each side, an email in the template only */
    private static function renamedSlice(): array
    {
        $template = self::sliceDef(self::TEMPLATE_ID, [
            'module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News', 'type' => 'S']],
            'slice'  => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'Old news', 'd_listlen' => 10]],
            'field'  => ['headline........' => ['id' => 'headline........', 'slice_id' => self::TEMPLATE_ID, 'name' => 'Headline']],
            'view'   => [12 => ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => 'List']],
            'email'  => [7 => ['id' => 7, 'owner_module_id' => self::TEMPLATE_ID, 'description' => 'Welcome']],
        ]);
        $dest     = self::sliceDef(self::DEST_ID, [
            'module' => [self::DEST_ID => ['id' => self::DEST_ID, 'name' => 'Old news', 'type' => 'S']],
            'slice'  => [self::DEST_ID => ['id' => self::DEST_ID, 'name' => 'Old news', 'd_listlen' => 20]],
            'field'  => ['headline........' => ['id' => 'headline........', 'slice_id' => self::DEST_ID, 'name' => 'Title']],
            'view'   => [30 => ['id' => 30, 'slice_id' => self::DEST_ID, 'name' => 'Archive']],
            'email'  => [],
        ]);
        return $template->compareWith($dest);
    }

    /** Deleting a row, renaming the destination slice and inserting a view or an email (new to
     *  this slice) are offered, not pre-checked. */
    public function test_compareWith_prechecks_only_safe_actions(): void
    {
        $states = [];
        foreach (self::renamedSlice() as $diff) {
            foreach ((new \ReflectionProperty($diff, 'actions'))->getValue($diff) as $action) {
                $idf      = (new \ReflectionProperty($action, 'identifier'))->getValue($action);
                $path     = implode('->', array_filter([$idf->getTable(), $idf->getRow(), $idf->getColumn()]));
                $states[] = (new \ReflectionProperty($action, 'type'))->getValue($action) . " $path" . (str_contains(self::render([$diff]), ' checked>') ? ' checked' : '');
            }
        }
        $this->assertSame([
            'UPDATE_ROW module->' . self::DEST_ID,                 // renames the destination
            'UPDATE_VALUE module->' . self::DEST_ID . '->name',
            'UPDATE_ROW slice->' . self::DEST_ID . ' checked',     // the same name
            'UPDATE_VALUE slice->' . self::DEST_ID . '->d_listlen',
            'UPDATE_ROW field->headline........ checked',          // a field name is not the slice's
            'UPDATE_VALUE field->headline........->name',
            'INSERT view->12',
            'DELETE view->30',
            'INSERT email->7',
        ], $states);
    }

    /** a re-posted form shows the user's choice, also where the default is unchecked */
    public function test_compareWith_posted_choice_wins_over_the_default(): void
    {
        $diffs = self::renamedSlice();
        preg_match_all('/name="sync\[\]"[^>]*value="([^"]*)"/', self::render($diffs), $m);
        $_POST['sync'] = array_map(static fn($v) => html_entity_decode($v, ENT_QUOTES | ENT_HTML5), $m[1]);

        $this->assertStringContainsString(' checked>', self::render([$diffs[0]]), 'the rename UPDATE_ROW');
        $this->assertSame(count($m[1]), substr_count(self::render($diffs), ' checked>'));
    }

    public function test_doAction_reports_the_number_of_an_inserted_view(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];   // its last_insert_id() is 1

        $ret = (new AA_Sync_Action('INSERT', new AA_Identifier(self::DEST_ID, 'view', '12'), ['id' => 12, 'name' => 'List']))->doAction();

        $this->assertSame('view 12 inserted into slice ' . self::DEST_ID . ' as 1', $ret);
    }

    public function test_doAction_reports_an_inserted_field_without_number(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        $ret = (new AA_Sync_Action('INSERT', new AA_Identifier(self::DEST_ID, 'field', 'headline........'), ['id' => 'headline........', 'name' => 'Headline']))->doAction();

        $this->assertSame('field headline........ inserted into slice ' . self::DEST_ID, $ret);
    }

    /** loadForId() adds the lt_groupNames rows of the used constant groups; their `ancestors` are
     *  unpacked like those of the other constant rows (and the do*() writes). */
    public function test_loadForId_unpacks_the_constant_group_names_like_other_constants(): void
    {
        $group_id = '33333333333333333333333333333333';
        $packed   = static fn(array $row): array => ['id' => pack_id($row['id']), 'ancestors' => pack_id($row['ancestors'])] + $row;
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_contains($sql, "group_id='lt_groupNames'")        => [$packed(['id' => $group_id, 'group_id' => 'lt_groupNames', 'name' => 'yes_no', 'value' => 'yes_no', 'ancestors' => self::ANCESTORS])],
            str_starts_with($sql, 'SELECT constant.* FROM constant') => [$packed(self::constantRow(57))],
            default                                                => [],
        };
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        $def = new AA_Definition_Module_Slice();
        $def->loadForId(self::TEMPLATE_ID, ['definitions' => true]);

        $constants = (new \ReflectionProperty($def, 'data'))->getValue($def)['constant'];
        $this->assertSame([self::CONSTANT_ID, $group_id], array_column($constants, 'id'));
        $this->assertSame([self::ANCESTORS, self::ANCESTORS], array_column($constants, 'ancestors'));
    }
}
