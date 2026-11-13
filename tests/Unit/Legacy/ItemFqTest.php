<?php

declare(strict_types=1);

/**
 * AA_Item::f_q() (include/item.php) - the blurb alias reads a field of another item straight from the
 * content table, so it must refuse the fields AA never shows (password, its copy, 2FA secret).
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

    use AA_Field;
    use AA_Item;
    use AA_Module;
    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ItemFqTest extends TestCase
    {
        /** readers - text...........2 holds the copy of the password, text...........5 the 2FA secret */
        private const READERS = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';
        /** a reader item */
        private const READER = '1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b';
        private const VALUE  = 'stored value';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            (new FakeSlice(self::READERS))->setFields(new FakeFields(self::READERS, [
                self::field('headline........'),
                self::field('password........', 'pwd:text...........2:phrase'),
                self::field('text...........2'),
                self::field('text...........5', '2fa'),
                self::field('secret..........'),
            ]));
            RecordingDb::$answer = static function (string $sql): array {
                if (str_contains($sql, 'LOWER(HEX(`slice_id`)) as usid FROM item')) {
                    return str_contains($sql, self::READER) ? [['usid' => self::READERS]] : [];
                }
                return str_starts_with(ltrim($sql), 'SELECT c2.text') ? [['text' => self::VALUE]] : [];
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            $id = self::READERS;
            Closure::bind(static function () use ($id): void {
                unset(AA_Module::$_modules[$id]);
            }, null, AA_Module::class)();
        }

        private static function field(string $id, string $insert = ''): AA_Field
        {
            return new AA_Field(['id' => $id, 'slice_id' => pack_id(self::READERS), 'text_stored' => 1, 'input_insert_func' => $insert] + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        private static function fq(string $param): string
        {
            return (new AA_Item(['headline........' => [['value' => 'x']]]))->f_q('headline........', $param);
        }

        /** @return array<string, array{string}> */
        public static function protectedFields(): array
        {
            return [
                'password'      => ['password........'],
                'password copy' => ['text...........2'],
                '2fa secret'    => ['text...........5'],
                'secret type'   => ['secret..........'],
                'secret type not defined in the slice' => ['secret.........1'],
            ];
        }

        #[DataProvider('protectedFields')]
        public function test_fq_byItemId_protectedField_empty(string $field): void
        {
            self::assertSame('', self::fq(self::READER . '::id..............:' . $field));
        }

        #[DataProvider('protectedFields')]
        public function test_fq_byBlurbSlice_protectedFieldReturned_empty(string $field): void
        {
            self::assertSame('', self::fq('Some topic:' . self::READERS . ':headline........:' . $field));
            self::assertSame('', self::fq('12:' . self::READERS . ':short_id........:' . $field));
        }

        #[DataProvider('protectedFields')]
        public function test_fq_byBlurbSlice_protectedFieldMatched_empty(string $field): void
        {
            self::assertSame('', self::fq('Some topic:' . self::READERS . ':' . $field . ':headline........'));
        }

        public function test_fq_ordinaryField_returnsValue(): void
        {
            self::assertSame(self::VALUE, self::fq(self::READER . '::id..............:headline........'));
            self::assertSame(self::VALUE, self::fq('Some topic:' . self::READERS . ':headline........:headline........'));
            self::assertSame(self::VALUE, self::fq('12:' . self::READERS . ':short_id........:headline........'));
        }
    }
}
