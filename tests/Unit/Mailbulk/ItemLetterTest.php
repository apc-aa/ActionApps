<?php

declare(strict_types=1);

// AA_Item, the template engine and AA_Collection need the code include/locsess.php loads, and that
// needs the constants include/config.php normally provides.

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
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Mailbulk {

    use AA;
    use AA\Mailbulk\ItemLetter;
    use AA\Mailbulk\Message;
    use AA_Collection;
    use AA_Item;
    use AA_Items;
    use AA_Slice;
    use ItemContent;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use zids;

    /** ItemLetter over items and aliases the test gives - no database */
    final class TestItemLetter extends ItemLetter
    {
        /** @var array<string, array<string, mixed>> item id => field => value */
        public static array $items = [];
        /** @var array<string, array<string, array<string, string>>> slice id => alias => definition */
        public static array $aliases = [];
        /** @var list<array{list<string>, list<string>}> the reads: ids, fields */
        public static array $reads = [];

        protected function contents(array $ids, array $fields = []): array
        {
            self::$reads[] = [$ids, $fields];
            $result = [];
            foreach ($ids as $id) {
                if (isset(self::$items[$id])) {
                    $wanted      = ($fields === []) ? self::$items[$id] : array_intersect_key(self::$items[$id], array_flip([...$fields, 'unpacked_id.....']));
                    $result[$id] = new ItemContent(ItemLetterTest::content4id($wanted));
                }
            }
            return $result;
        }

        protected function aliasesOf(string $sliceId): array
        {
            return self::$aliases[$sliceId] ?? [];
        }

        protected function lang(): string
        {
            return 'en';
        }
    }

    /**
     * The mail of an item for each reader of a list: the item's aliases _#MAIL*, expanded over the item
     * with the reader's id and links added - each reader their own, also within one process.
     */
    final class ItemLetterTest extends TestCase
    {
        private const ITEM       = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';
        private const ITEM_SLICE = 'e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2';
        private const READERS    = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
        private const ANNA       = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const BOB        = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const CYRIL      = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const NAME       = 'text...........1';
        private const URL        = 'https://example.org/news';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/alerts/alerts_sending.php';
        }

        protected function setUp(): void
        {
            // anything that would still ask the database gets an empty answer
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            AA::Contentcache()->clear();
            AA_Items::singleton()->doInvalidate();
            TestItemLetter::$reads   = [];
            TestItemLetter::$items   = [self::ITEM => self::item()];
            TestItemLetter::$aliases = [self::ITEM_SLICE => self::itemAliases([
                '_#MAILSUBJ' => '_#HEADLINE',
                '_#MAILBODY' => '<p>Hi {item:{_#MAILTOID}:_#JMENO___}</p><a href="_#MAILUNSB">out</a> <a href="_#MAILPREF">form</a>',
                '_#MAILFROM' => 'news@example.org',
            ])];
            foreach ([self::ANNA => 'Anna', self::BOB => 'Bob', self::CYRIL => 'Cyril'] as $id => $name) {
                $this->addReader($id, $name);
            }
        }

        protected function tearDown(): void
        {
            AA::Contentcache()->clear();
            AA_Items::singleton()->doInvalidate();
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** the newsletter item, active unless $changes say otherwise */
        private static function item(array $changes = []): array
        {
            return array_merge([
                'unpacked_id.....' => self::ITEM,
                'u_slice_id......' => self::ITEM_SLICE,
                'status_code.....' => 1,
                'publish_date....' => time() - 86400,
                'expiry_date.....' => time() + 86400,
                'headline........' => 'Spring news',
                'text...........5' => 'anna@example.org, nobody, test@example.org',
            ], $changes);
        }

        /** the slice's own aliases plus $templates as f_t aliases
         *  @param array<string, string> $templates alias => template */
        private static function itemAliases(array $templates): array
        {
            $aliases = ['_#HEADLINE' => GetAliasDef('f_h', 'headline........'), '_#TESTMAIL' => GetAliasDef('f_h', 'text...........5')];
            foreach ($templates as $alias => $template) {
                $aliases[$alias] = GetAliasDef('f_t:' . $template, 'headline........');
            }
            return $aliases;
        }

        /** a valid reader, also in the item cache the template's {item} reads from */
        private function addReader(string $id, string $name, string $email = ''): void
        {
            $values = [
                'unpacked_id.....'   => $id,
                'short_id........'   => hexdec(substr($id, 0, 4)),
                'u_slice_id......'   => self::READERS,
                'status_code.....'   => 1,
                'publish_date....'   => time() - 86400,
                'expiry_date.....'   => time() + 86400,
                FIELDID_EMAIL        => ($email === '') ? strtolower($name) . '@example.org' : $email,
                FIELDID_ACCESS_CODE  => 'code' . $name,
                self::NAME           => $name,
            ];
            TestItemLetter::$items[$id] = $values;
            AA_Items::singleton()->_store(new AA_Item(self::content4id($values), ['_#JMENO___' => GetAliasDef('f_h', self::NAME)]));
        }

        /** field => value as GetItemContent() gives it
         *  @param array<string, mixed> $values */
        public static function content4id(array $values): array
        {
            return array_map(static fn(mixed $value): array => [['value' => $value, 'flag' => 0]], $values);
        }

        /** a list whose valid readers are $valid, $invalid fail isValidReader()
         *  @param list<string> $valid
         *  @param list<string> $invalid
         *  @param list<string> $unknown what unknownCondsFields() names */
        private function list(array $valid = [self::ANNA, self::BOB, self::CYRIL], array $invalid = [], array $unknown = [], string $url = self::URL, bool $readerSlice = true): AA_Collection&MockObject
        {
            $list = $this->createMock(AA_Collection::class);
            $list->method('getId')->willReturn('abcde');
            $list->method('getReaderSlice')->willReturn($readerSlice ? $this->createStub(AA_Slice::class) : null);
            $list->method('validReaders')->willReturn(new zids($valid, 'l'));
            $list->method('unknownCondsFields')->willReturn($unknown);
            $list->method('isValidReader')->willReturnCallback(static fn(ItemContent $r): bool => !in_array($r->getItemID(), $invalid, true));
            $list->method('readerLinks')->willReturnCallback(static fn(string $code): array => ['unsubscribe' => $url . '?au=' . $code . '&c=abcde', 'form' => $url . '?ac=' . $code]);
            return $list;
        }

        private static function letter(AA_Collection $list): ItemLetter
        {
            return new TestItemLetter($list, self::ITEM);
        }

        public function test_problem_none(): void
        {
            self::assertNull(self::letter($this->list())->problem());
        }

        public function test_problem_namesTheMissingAliases(): void
        {
            unset(TestItemLetter::$aliases[self::ITEM_SLICE]['_#MAILBODY'], TestItemLetter::$aliases[self::ITEM_SLICE]['_#MAILFROM']);

            $problem = self::letter($this->list())->problem();

            self::assertNotNull($problem);
            self::assertStringContainsString('_#MAILBODY', $problem);
            self::assertStringContainsString('_#MAILFROM', $problem);
            self::assertStringNotContainsString('_#MAILSUBJ', $problem);
        }

        public function test_problem_trashedItem(): void
        {
            TestItemLetter::$items[self::ITEM] = self::item(['status_code.....' => 3]);

            self::assertNotNull(self::letter($this->list())->problem());
        }

        public function test_problem_missingItem(): void
        {
            unset(TestItemLetter::$items[self::ITEM]);

            self::assertNotNull(self::letter($this->list())->problem());
        }

        public function test_problem_listWithoutReaderSlice(): void
        {
            self::assertNotNull(self::letter($this->list(readerSlice: false))->problem());
        }

        /** validReaders() would find nobody, silently */
        public function test_problem_mailtoOnFieldsTheReadersLack_namesThem(): void
        {
            TestItemLetter::$aliases[self::ITEM_SLICE] += self::itemAliases(['_#MAILTO__' => 'd-text...........9-==-x']);
            $list = $this->list(unknown: ['text...........9', 'headline........@relation........']);
            $list->expects(self::once())->method('unknownCondsFields')->with('d-text...........9-==-x');

            $problem = self::letter($list)->problem();

            self::assertNotNull($problem);
            self::assertStringContainsString('text...........9', $problem);
            self::assertStringContainsString('headline........@relation........', $problem);
        }

        public function test_title_isTheSubjectWithoutAReader(): void
        {
            TestItemLetter::$aliases[self::ITEM_SLICE]['_#MAILSUBJ'] = self::itemAliases(['_#MAILSUBJ' => '<b>_#HEADLINE</b> [{_#MAILTOID}] ' . str_repeat('x', 300)])['_#MAILSUBJ'];

            $title = self::letter($this->list())->title();

            self::assertStringStartsWith('Spring news [] x', $title);
            self::assertSame(250, mb_strlen($title));
        }

        public function test_selection_usesMailtoConds(): void
        {
            TestItemLetter::$aliases[self::ITEM_SLICE] += self::itemAliases(['_#MAILTO__' => 'd-' . self::NAME . '-==-{_#HEADLINE}']);
            $list = $this->list([self::BOB]);
            $list->expects(self::once())->method('validReaders')->with('d-' . self::NAME . '-==-Spring news');

            self::assertSame([self::BOB], self::letter($list)->selection());
        }

        public function test_selection_noMailto_isAllValidReaders(): void
        {
            $list = $this->list();
            $list->expects(self::once())->method('validReaders')->with('');

            self::assertSame([self::ANNA, self::BOB, self::CYRIL], self::letter($list)->selection());
            $last = end(TestItemLetter::$reads);
            self::assertSame([[self::ANNA, self::BOB, self::CYRIL], [FIELDID_EMAIL]], $last, 'the addresses only, in one read');
        }

        public function test_selection_sameAddressTwice_onlyFirst(): void
        {
            $this->addReader(self::CYRIL, 'Cyril', ' ANNA@example.org');

            self::assertSame([self::ANNA, self::BOB], self::letter($this->list())->selection());
        }

        public function test_messageFor_twoReadersInOneProcess_eachHasOwnLinksAndName(): void
        {
            $letter = self::letter($this->list());

            $anna = $letter->messageFor(self::ANNA);
            $bob  = $letter->messageFor(self::BOB);

            self::assertInstanceOf(Message::class, $anna);
            self::assertInstanceOf(Message::class, $bob);
            self::assertSame('anna@example.org', $anna->to);
            self::assertSame('bob@example.org', $bob->to);
            self::assertSame('<p>Hi Anna</p><a href="' . self::URL . '?au=codeAnna&c=abcde">out</a> <a href="' . self::URL . '?ac=codeAnna">form</a>', $anna->html);
            self::assertSame('<p>Hi Bob</p><a href="' . self::URL . '?au=codeBob&c=abcde">out</a> <a href="' . self::URL . '?ac=codeBob">form</a>', $bob->html);
        }

        public function test_messageFor_itemAliasesExpand(): void
        {
            $before = $GLOBALS['apc_state']['xlang'] ?? null;

            $message = self::letter($this->list())->messageFor(self::ANNA);

            self::assertNotNull($message);
            self::assertSame('Spring news', $message->subject);
            self::assertSame('news@example.org', $message->from);
            self::assertSame($before, $GLOBALS['apc_state']['xlang'] ?? null, 'the language of the list only while expanding');
        }

        public function test_messageFor_readerNoLongerValid_isNull(): void
        {
            self::assertNull(self::letter($this->list(invalid: [self::BOB]))->messageFor(self::BOB));
        }

        public function test_messageFor_readerGone_isNull(): void
        {
            unset(TestItemLetter::$items[self::BOB]);

            self::assertNull(self::letter($this->list())->messageFor(self::BOB));
        }

        public function test_messageFor_test_dummyCodeAndNoHeaders(): void
        {
            $message = self::letter($this->list())->messageFor(self::ANNA, true);

            self::assertNotNull($message);
            self::assertStringContainsString('?au=ABCDE&c=abcde', $message->html);
            self::assertStringNotContainsString('codeAnna', $message->html);
            self::assertSame([], $message->headers);
        }

        public function test_messageFor_httpsList_hasUnsubscribeHeaders(): void
        {
            $message = self::letter($this->list())->messageFor(self::ANNA);

            self::assertNotNull($message);
            self::assertSame(['List-Unsubscribe' => '<' . self::URL . '?au=codeAnna&c=abcde>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], $message->headers);
        }

        public function test_messageFor_httpList_noHeaders(): void
        {
            $message = self::letter($this->list(url: 'http://example.org/news'))->messageFor(self::ANNA);

            self::assertNotNull($message);
            self::assertSame([], $message->headers);
        }

        public function test_testAddresses_fromAlias_andFallback(): void
        {
            TestItemLetter::$aliases[self::ITEM_SLICE] += self::itemAliases(['_#MAILTEST' => '_#TESTMAIL']);
            self::assertSame(['anna@example.org', 'test@example.org'], self::letter($this->list())->testAddresses('me@example.org'));

            unset(TestItemLetter::$aliases[self::ITEM_SLICE]['_#MAILTEST']);
            self::assertSame(['me@example.org'], self::letter($this->list())->testAddresses('me@example.org'));
        }

        public function test_testReader_matchingAddressElseFirst(): void
        {
            $letter = self::letter($this->list());

            self::assertSame(self::BOB, $letter->testReader('BOB@example.org'));
            self::assertSame(self::ANNA, $letter->testReader('stranger@example.org'));
            self::assertNull(self::letter($this->list([]))->testReader('bob@example.org'));
        }
    }
}
