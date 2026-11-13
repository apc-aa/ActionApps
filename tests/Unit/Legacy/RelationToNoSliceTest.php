<?php

declare(strict_types=1);

/**
 * A related-item field whose `#sLiCe-<id>` names a module that is no slice - the field settings offer
 * Alerts modules next to slices - lists no items: such a module has neither items nor fields.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Inputfield;
    use AA_Module;
    use AA_Moduleconfig;
    use Closure;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class RelationToNoSliceTest extends TestCase
    {
        /** an Alerts module, say */
        private const NOT_SLICE = '0d0d0d0d0d0d0d0d0d0d0d0d0d0d0d0d';
        /** an item the field already points to */
        private const RELATED = '0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/formutil.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['slice_info'] = $GLOBALS['vid'] = $GLOBALS['view_info'] = null;   // read by the SQL comment
            new class (self::NOT_SLICE) extends AA_Module {
                public function __construct(string $id)
                {
                    $this->module_id = $id;
                    AA_Module::$_modules[$id] = $this;
                }

                public static function getModuleconfig(): AA_Moduleconfig
                {
                    throw new \LogicException('not used');
                }
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info']);
            RecordingDb::reset();
            $id = self::NOT_SLICE;
            Closure::bind(static function () use ($id): void {
                unset(AA_Module::$_modules[$id]);
            }, null, AA_Module::class)();
        }

        public function test_QueryZids_noSlice_findsNothing(): void
        {
            self::assertTrue(QueryZids([self::NOT_SLICE], '', '', 'ACTIVE')->isEmpty());
        }

        public function test_QueryZids_noSliceWithConds_findsNothing(): void
        {
            self::assertTrue(QueryZids([self::NOT_SLICE], [['headline........' => 1, 'operator' => '==', 'value' => 'x']], '', 'ACTIVE')->isEmpty());
        }

        /** the item form (iso, sel, mse... widgets of admin/itemedit.php) */
        public function test_fillConstArr_noSlice_noOptions(): void
        {
            $input = new AA_Inputfield([['value' => self::RELATED]], false, 'normal', 'v', 'V');
            (new ReflectionProperty(AA_Inputfield::class, 'param'))->setValue($input, ['#sLiCe-' . self::NOT_SLICE]);

            self::assertSame(self::NOT_SLICE, $input->fill_const_arr('', false, false, AA_BIN_ALL, [['value' => self::RELATED]]));
            self::assertSame([], (new ReflectionProperty(AA_Inputfield::class, 'const_arr'))->getValue($input));
        }
    }
}
