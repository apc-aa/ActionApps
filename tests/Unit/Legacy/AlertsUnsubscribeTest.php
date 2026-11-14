<?php

declare(strict_types=1);

// modules/alerts/util.php and the item store need the constants include/config.php normally provides.

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
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
        'CACHE_TTL'       => 600,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Field;
    use PHPUnit\Framework\Attributes\WithoutErrorHandler;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * unsubscribe_reader() (modules/alerts/util.php) - the unsubscribe link of an Alerts mail.
     * The reader is found by the access code; the frequency field of the collection gets
     * "unsubscribed:<date>:au" through the item store, so the in-memory `content` table of
     * RecordingDb shows what the store left behind.
     */
    final class AlertsUnsubscribeTest extends TestCase
    {
        private const SLICE      = '22222222222222222222222222222222';
        private const READER     = 'dddddddddddddddddddddddddddddddd';
        private const COLLECTION = 'sQjwa';
        private const FREQUENCY  = 'alerts1....sQjwa';
        private const CODE       = 'Abc12';

        private static FakeSlice $slice;

        /** the readers whose access code is CODE */
        private array $readers = [self::READER];

        /** the collections whose reader slice is SLICE */
        private array $collections = [self::COLLECTION];

        /** the SQL of the look-ups in alerts_collection */
        private array $collectionQueries = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/alerts/util.php';
            self::$slice = new FakeSlice(self::SLICE);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['err']      = [];
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field(self::FREQUENCY)]));

            RecordingDb::$answer = function (string $sql): array {
                if (str_contains($sql, 'alerts_collection')) {
                    $this->collectionQueries[] = $sql;
                    $own = str_contains($sql, self::SLICE) && array_filter($this->collections, static fn($c) => str_contains($sql, "'$c'"));
                    return $own ? [['id' => self::COLLECTION]] : [];
                }
                if (str_contains($sql, 'LOWER(HEX(item.id))')) {
                    return array_map(static fn($id) => ['iid' => $id], $this->readers);
                }
                if (str_starts_with($sql, 'SELECT `text` FROM `content`')) {
                    $rows = array_filter(RecordingDb::$content, static fn($r) => $r['item'] === self::READER && $r['field'] === self::FREQUENCY);
                    return array_map(static fn($r) => ['text' => $r['text']], array_values($rows));
                }
                if (str_starts_with($sql, 'SELECT * FROM item ')) {
                    return [['id' => pack_id(self::READER), 'slice_id' => pack_id(self::SLICE), 'status_code' => 1]];
                }
                return [];
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private function field(string $id): AA_Field
        {
            return new AA_Field([
                'id'                => $id,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => 'qte',
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ]);
        }

        private function frequencyRow(string $text): array
        {
            return ['item' => self::READER, 'field' => self::FREQUENCY, 'number' => 0, 'text' => $text, 'flag' => 64];
        }

        /** the texts of the frequency field in the `content` table */
        private function frequencies(): array
        {
            $rows = array_filter(RecordingDb::$content, static fn($r) => $r['item'] === self::READER && $r['field'] === self::FREQUENCY);
            return array_column(array_values($rows), 'text');
        }

        private function writes(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\s/', $s)));
        }

        #[WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_unsubscribe_readerWithValue_writesUnsubscribed(): void
        {
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertTrue(unsubscribe_reader(self::SLICE, self::CODE, self::COLLECTION));

            $this->assertCount(1, $texts = $this->frequencies());
            $this->assertMatchesRegularExpression('/^unsubscribed:\d{4}-\d\d-\d\d \d\d:\d\d:au$/', $texts[0]);
        }

        public function test_unsubscribe_alreadyUnsubscribed_keepsTheOldValue(): void
        {
            RecordingDb::$content = [$this->frequencyRow('unsubscribed:2020-01-01 10:00:au')];

            $this->assertTrue(unsubscribe_reader(self::SLICE, self::CODE, self::COLLECTION));

            $this->assertSame(['unsubscribed:2020-01-01 10:00:au'], $this->frequencies());
            $this->assertSame([], $this->writes());
        }

        public static function unknownCodes(): array
        {
            return [
                'no reader'   => [[]],
                'two readers' => [[self::READER, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee']],
            ];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('unknownCodes')]
        public function test_unsubscribe_unknownCode_isFalse(array $readers): void
        {
            $this->readers        = $readers;
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertFalse(@unsubscribe_reader(self::SLICE, self::CODE, self::COLLECTION));   // warn() about the count

            $this->assertSame(['weekly'], $this->frequencies());
            $this->assertSame([], $this->writes());
        }

        /** most confirmed readers never chose a frequency - the link must work for them too */
        #[WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_unsubscribe_readerWithoutTheFieldRow_writesUnsubscribed(): void
        {
            $this->assertTrue(unsubscribe_reader(self::SLICE, self::CODE, self::COLLECTION));

            $this->assertCount(1, $texts = $this->frequencies(), 'the missing row is created');
            $this->assertMatchesRegularExpression('/^unsubscribed:\d{4}-\d\d-\d\d \d\d:\d\d:au$/', $texts[0]);
        }

        /** the store skips a field the slice does not define, so there is nothing to write into */
        public function test_unsubscribe_fieldNotDefinedInTheSlice_isFalse(): void
        {
            self::$slice->setFields(new FakeFields(self::SLICE, []));

            $this->assertFalse(unsubscribe_reader(self::SLICE, self::CODE, self::COLLECTION));

            $this->assertSame([], RecordingDb::$content);
            $this->assertSame([], $this->writes());
        }

        public function test_unsubscribe_collectionOfAnotherSlice_isFalse(): void
        {
            $this->collections    = [];
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertFalse(@unsubscribe_reader(self::SLICE, self::CODE, self::COLLECTION));   // warn() about the collection

            $this->assertCount(1, $this->collectionQueries, 'the collection is checked against the reader slice');
            $this->assertSame(['weekly'], $this->frequencies());
            $this->assertSame([], $this->writes());
        }

        /** a 16 characters long c would be the whole field id - here the confirmation flag of the reader */
        public function test_unsubscribe_cNamingAnotherField_isFalse(): void
        {
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field(self::FREQUENCY), $this->field('switch..........')]));

            $this->assertFalse(@unsubscribe_reader(self::SLICE, self::CODE, 'switch..........'));

            $this->assertSame([], RecordingDb::$content);
            $this->assertSame([], $this->writes());
        }

        private const GET       = ['REQUEST_METHOD' => 'GET'];
        private const POST      = ['REQUEST_METHOD' => 'POST'];
        private const ONE_CLICK = ['List-Unsubscribe' => 'One-Click'];

        /** the link in the mail body works as it always did, whatever the method */
        #[WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_unsubscribe_request_bodyLinkByGet_unsubscribes(): void
        {
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertTrue(unsubscribe_request(self::SLICE, self::CODE, self::COLLECTION, '', self::GET, []));

            $this->assertStringStartsWith('unsubscribed:', $this->frequencies()[0]);
        }

        /** mail services and link scanners open the header link by GET - that must not unsubscribe */
        public function test_unsubscribe_request_headerLinkByGet_asks(): void
        {
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertNull(unsubscribe_request(self::SLICE, self::CODE, self::COLLECTION, '1', self::GET, []));

            $this->assertSame(['weekly'], $this->frequencies());
            $this->assertSame([], RecordingDb::$log, 'not even the reader is looked up');
        }

        /** RFC 8058: the mail service posts List-Unsubscribe=One-Click with no cookies */
        #[WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_unsubscribe_request_headerLinkOneClickPost_unsubscribes(): void
        {
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertTrue(unsubscribe_request(self::SLICE, self::CODE, self::COLLECTION, '1', self::POST, self::ONE_CLICK));

            $this->assertStringStartsWith('unsubscribed:', $this->frequencies()[0]);
        }

        public function test_unsubscribe_request_headerLinkPostWithoutTheBody_asks(): void
        {
            RecordingDb::$content = [$this->frequencyRow('weekly')];

            $this->assertNull(unsubscribe_request(self::SLICE, self::CODE, self::COLLECTION, '1', self::POST, []));

            $this->assertSame(['weekly'], $this->frequencies());
        }

        public function test_unsubscribe_request_headerLinkOneClickPostUnknownCode_isFalse(): void
        {
            $this->readers = [];

            $this->assertFalse(@unsubscribe_request(self::SLICE, self::CODE, self::COLLECTION, '1', self::POST, self::ONE_CLICK));   // warn() about the count
        }

        /** the button posts the RFC 8058 body to the very address it was opened at */
        public function test_unsubscribe_ask_postsTheOneClickBodyToTheSameAddress(): void
        {
            $html = unsubscribe_ask();

            $this->assertMatchesRegularExpression('~<form method="post"[^>]*>~', $html);
            $this->assertStringNotContainsString('action=', $html, 'no action - the form posts to the URL of the page, au and c included');
            $this->assertStringContainsString('<input type="hidden" name="List-Unsubscribe" value="One-Click">', $html);
            $this->assertStringContainsString('<button type="submit">', $html);
        }
    }
}
