<?php

declare(strict_types=1);

// AA_Collection and _m() need the constants include/config.php normally provides

namespace {
    foreach ([
        'AA_INSTAL_URL'        => 'http://localhost/aa/',
        'AA_INSTAL_PATH'       => '/aa/',
        'IMG_UPLOAD_PATH'      => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'         => dirname(__DIR__, 3) . '/',
        'PERM_LIB'             => 'sql',
        'DB_HOST'              => 'localhost',
        'DB_NAME'              => 'aa_test',
        'DB_USER'              => 'aa',
        'DB_PASSWORD'          => '',
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Mailbulk {

    use AA;
    use AA\IO\DB\DB_AA;
    use AA\Mailbulk\Campaign;
    use AA\Mailbulk\Gate;
    use AA\Mailbulk\ItemLetter;
    use AA\Mailbulk\Mailer;
    use AA\Mailbulk\Message;
    use AA\Mailbulk\Outcome;
    use AA\Mailbulk\Recipients;
    use AA\Mailbulk\Run;
    use AA_Collection;
    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;
    use Tests\Unit\Later\Fixtures\BatchRunTables;
    use Tests\Unit\Mailbulk\Fixtures\ScriptedMailer;
    use Tests\Unit\Mailbulk\Fixtures\TestCampaign;
    use Tests\Unit\Mailbulk\Fixtures\TestRun;

    require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
    require_once __DIR__ . '/Fixtures/FakeLetter.php';
    require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
    require_once __DIR__ . '/Fixtures/TestRun.php';
    require_once __DIR__ . '/Fixtures/TestCampaign.php';
    require_once __DIR__ . '/../../../modules/alerts/alerts_sending.php';

    /** An ItemLetter whose answers the test sets. */
    final class GateLetter extends ItemLetter
    {
        public static ?string $problem = null;
        public static ?\Throwable $problemThrows = null;
        public static string $subject = 'Spring news';
        /** @var list<string> */
        public static array $selection = [];
        /** @var list<string> */
        public static array $testAddresses = [];
        /** @var list<array{string, bool}> messageFor() calls: reader, test */
        public static array $asked = [];

        public static function reset(): void
        {
            self::$problem       = null;
            self::$problemThrows = null;
            self::$subject       = 'Spring news';
            self::$selection     = [];
            self::$testAddresses = [];
            self::$asked         = [];
        }

        public function problem(): ?string
        {
            if (self::$problemThrows !== null) {
                throw self::$problemThrows;
            }
            return self::$problem;
        }

        public function title(): string
        {
            return 'Newsletter';
        }

        public function selection(): array
        {
            return self::$selection;
        }

        public function messageFor(string $readerId, bool $test = false): ?Message
        {
            self::$asked[] = [$readerId, $test];
            return new Message("$readerId@example.org", self::$subject, '<p>Hello</p>', 'news@example.org', $test ? [] : ['List-Unsubscribe' => '<https://example.org/u>']);
        }

        public function testAddresses(string $userEmail): array
        {
            return self::$testAddresses ?: [$userEmail];
        }

        public function testReader(string $address): ?string
        {
            return self::$selection[0] ?? null;
        }
    }

    /** A Gate over the test's rights, runs, letter and mailer. */
    final class TestGate extends Gate
    {
        public static ?string $itemSlice = TestRun::SLICE;
        public static bool $mayEdit = true;
        public static bool $relates = true;
        public static bool $listExists = true;
        /** @var list<array{string, string, string}> */
        public static array $refusals = [];
        /** @var list<string> the class of each failure logged */
        public static array $failures = [];
        public static int $noStores = 0;
        public static ?ScriptedMailer $mailer = null;
        /** @var list<string> what each call opened: item, or readers (and the item) */
        public static array $opened = [];

        public static function reset(): void
        {
            self::$itemSlice  = TestRun::SLICE;
            self::$mayEdit    = true;
            self::$relates    = true;
            self::$listExists = true;
            self::$refusals   = [];
            self::$failures   = [];
            self::$noStores   = 0;
            self::$mailer     = new ScriptedMailer();
            self::$opened     = [];
        }

        protected function itemSlice(string $itemId): ?string
        {
            return self::$itemSlice;
        }

        protected function listExists(string $list): bool
        {
            return self::$listExists;
        }

        protected function mayControl(string $list, ?string $slice, bool $needsRelation): bool
        {
            return ($slice !== null) && self::$mayEdit && (!$needsRelation || self::$relates);
        }

        protected function logRefusal(string $list, string $slice, string $itemId): void
        {
            self::$refusals[] = [$list, $slice, $itemId];
        }

        protected function logFailure(string $list, string $itemId, \Throwable $e): void
        {
            self::$failures[] = $e::class;
        }

        protected function campaign(string $list, string $itemId): Campaign
        {
            return TestCampaign::of($list, $itemId);
        }

        protected function letter(string $list, string $itemId): ItemLetter
        {
            return new GateLetter(new AA_Collection($list), $itemId);
        }

        protected function mailer(): Mailer
        {
            return self::$mailer ?? throw new \LogicException('No mailer');
        }

        protected function withSlices(string $list, string $itemId, bool $readers, Closure $fn): mixed
        {
            self::$opened[] = $readers ? 'readers' : 'item';
            return $fn();
        }

        protected function uid(): string
        {
            return GateTest::USER_ID;
        }

        protected function userEmail(): string
        {
            return 'me@example.org';
        }

        protected function noStore(): void
        {
            ++self::$noStores;
        }
    }

    /** The control of a sending and its actions, over TableDb's object_text - no mail goes out. */
    final class GateTest extends TestCase
    {
        use BatchRunTables {
            setUp as private tablesSetUp;
            tearDown as private tablesTearDown;
        }

        public const USER_ID = 'uid-1';
        private const ITEM = 'cafe0000000000000000000000000001';
        private const LIST = 'abcde';

        private mixed $perm = null;

        protected function setUp(): void
        {
            $this->tablesSetUp();
            TestRun::reset();
            TestCampaign::reset();
            TestGate::reset();
            GateLetter::reset();
            GateLetter::$selection = [self::reader(0), self::reader(1), self::reader(2)];
            TestRun::$now          = self::NOW;
            TestRun::$microNow     = (float) self::NOW;
            // Run::end() and resume() ask the run whether the user may control it
            class_exists(\AA_Perm::class);
            $this->perm = AA::$perm;
            AA::$perm   = new class {
                public function isSuperadminUser(string $uid): bool
                {
                    return false;
                }

                public function hasSlicePerm(string $uid, string $slice, string $perm): bool
                {
                    return $uid === GateTest::USER_ID;
                }
            };
        }

        protected function tearDown(): void
        {
            AA::$perm = $this->perm;
            TestRun::reset();
            TestCampaign::reset();
            $this->tablesTearDown();
        }

        private static function reader(int $i): string
        {
            return sprintf('a%031x', $i);
        }

        /** a run of the pair to $n readers, in $state, with $props; its id */
        private static function sending(int $n, string $state, array $props = []): string
        {
            // the core sets finished itself, not start()
            $finished = $props['finished'] ?? null;
            unset($props['finished']);
            $id = DB_AA::transaction(static fn(): string => TestRun::start($props + [
                'subject'    => self::ITEM,
                'user'       => self::USER,
                'position'   => ['i' => 0],
                'list'       => self::LIST,
                'title'      => 'Newsletter',
                'recipients' => Recipients::freeze(self::ITEM, array_map(self::reader(...), range(0, $n - 1))),
                'total'      => (string) $n,
                'cursor'     => '0',
            ])->getId());
            self::storedSet($id, 'state', $state);
            if ($finished !== null) {
                self::storedSet($id, 'finished', $finished);
            }
            return $id;
        }

        /** @return list<string> the data-action of each button */
        private static function actions(string $html): array
        {
            preg_match_all('/<button type="button" data-action="([a-z]+)">/', $html, $m);
            return $m[1];
        }

        /** @return list<string> ids of the runs of the pair */
        private static function runIds(): array
        {
            return array_map(static fn(Run $r): string => (string) $r->getId(), TestCampaign::of(self::LIST, self::ITEM)->runs());
        }

        public function test_control_withoutRight_isEmpty(): void
        {
            TestGate::$mayEdit = false;
            self::assertSame('', (new TestGate())->control(self::LIST, self::ITEM));
            TestGate::$mayEdit   = true;
            TestGate::$itemSlice = null;
            self::assertSame('', (new TestGate())->control(self::LIST, self::ITEM), 'no such item');
            TestGate::$itemSlice = TestRun::SLICE;
            self::assertSame('', (new TestGate())->control('ab"de', self::ITEM), 'no list id');
            self::assertSame('', (new TestGate())->control(self::LIST, 'cafe'), 'no long item id');
            TestGate::$listExists = false;
            self::assertSame('', (new TestGate())->control(self::LIST, self::ITEM), 'no such list');
            self::assertSame(5, TestGate::$noStores, 'a visitor\'s page without the control is not cached either');
        }

        public function test_control_unrelatedList_isEmpty(): void
        {
            TestGate::$relates = false;
            self::assertSame('', (new TestGate())->control(self::LIST, self::ITEM));
            self::assertFalse((new TestGate())->allowed(self::LIST, self::ITEM));
            self::assertTrue((new TestGate())->allowed(self::LIST, self::ITEM, false), 'stopping needs no relation');
        }

        public function test_unrelatedList_sendingStarted_canOnlyBeStopped(): void
        {
            $id                = self::sending(3, Run::ERROR, ['note' => 'SMTP is down']);
            TestGate::$relates = false;

            $html = (new TestGate())->control(self::LIST, self::ITEM);
            self::assertSame(['stop'], self::actions($html));
            self::assertStringContainsString('The list is not related to the item&apos;s slice', $html);

            self::assertSame(["The list is not related to the item's slice", Gate::ERR_DENIED], (new TestGate())->act('resume', self::LIST, self::ITEM), 'plain text - the response escapes it');
            self::assertSame([[self::LIST, TestRun::SLICE, self::ITEM]], TestGate::$refusals);

            [, $error] = (new TestGate())->act('stop', self::LIST, self::ITEM);
            self::assertSame(0, $error);
            self::assertSame(Run::ENDED, self::stored($id)['state']);
            self::assertCount(1, TestGate::$refusals, 'stop is no refusal');
        }

        /** @return array<string, array{?string, array<string, string>, list<string>, list<string>}> state => [run state, its props, actions, texts] */
        public static function states(): array
        {
            return [
                'never sent' => [null, [], ['test', 'send'], []],
                'sending'    => [Run::SENDING, ['cursor' => '2', 'outcomes' => '{"1":"f"}'], ['stop'], ['Sending 2 / 4']],
                'stopped'    => [Run::ENDED, ['cursor' => '2'], ['send'], ['Stopped 2 / 4']],
                'error'      => [Run::ERROR, ['cursor' => '1', 'note' => 'SMTP is down'], ['resume', 'stop'], ['SMTP is down', '1 / 4']],
                'done'       => [Run::DONE, ['cursor' => '4', 'outcomes' => '{"3":"s"}', 'finished' => '1900000000'], ['send'], [date('Y-m-d H:i', 1900000000), 'sent 3, skipped 1']],
            ];
        }

        /** @param array<string, string> $props @param list<string> $actions @param list<string> $texts */
        #[DataProvider('states')]
        public function test_control_states(?string $state, array $props, array $actions, array $texts): void
        {
            if ($state !== null) {
                self::sending(4, $state, $props);
            }
            $html = (new TestGate())->control(self::LIST, self::ITEM);

            self::assertStringStartsWith('<span class="aa-mailbulk" data-aa-mailbulk data-list="abcde" data-item="' . self::ITEM . '" data-state="' . ($state ?? '') . '">', $html);
            self::assertSame($actions, self::actions($html));
            foreach ($texts as $text) {
                self::assertStringContainsString('<span class="aa-mailbulk-text">' . $text . '</span>', $html);
            }
            self::assertSame([], GateLetter::$asked, 'the control renders no mail');
            self::assertNotContains('readers', TestGate::$opened, 'no template of the page runs with the readers open');
        }

        public function test_control_missingAlias_showsProblemAndNoButtons(): void
        {
            GateLetter::$problem = 'The slice of the item lacks the aliases _#MAILBODY';
            $html = (new TestGate())->control(self::LIST, self::ITEM);

            self::assertSame([], self::actions($html));
            self::assertStringContainsString('lacks the aliases _#MAILBODY', $html);
        }

        public function test_control_escapesTheErrorText(): void
        {
            self::sending(2, Run::ERROR, ['note' => '550 <script>alert(1)</script>']);
            $html = (new TestGate())->control(self::LIST, self::ITEM);

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('550 &lt;script&gt;', $html);
        }

        public function test_control_printed_switchesThePageCacheOff(): void
        {
            (new TestGate())->control(self::LIST, self::ITEM);
            self::assertSame(1, TestGate::$noStores);
        }

        public function test_failure_isLogged_andTheControlStays(): void
        {
            GateLetter::$problemThrows = new \JsonException('damaged');

            self::assertStringContainsString('cannot be shown', (new TestGate())->control(self::LIST, self::ITEM));
            self::assertSame(['The action failed - see the log', Gate::ERR_FAILED], (new TestGate())->act('test', self::LIST, self::ITEM));
            self::assertSame([\JsonException::class, \JsonException::class], TestGate::$failures);
            self::assertSame([], self::runIds());
        }

        public function test_info_failure_isEmpty(): void
        {
            self::sending(2, Run::DONE, ['cursor' => '2', 'outcomes' => '{damaged']);

            self::assertSame('', (new TestGate())->info(self::LIST, self::ITEM, 'sent'));
            self::assertSame([\JsonException::class], TestGate::$failures);
        }

        public function test_itemId_inUpperCase_isTheSameSending(): void
        {
            $id = self::sending(3, Run::SENDING);

            self::assertSame(0, (new TestGate())->act('stop', self::LIST, strtoupper(self::ITEM))[1]);
            self::assertSame(Run::ENDED, self::stored($id)['state']);
        }

        public function test_act_prepare_asksWithTheCount(): void
        {
            self::sending(1, Run::DONE, ['cursor' => '1']);

            self::assertSame(['Send to 2 recipients?', 0], (new TestGate())->act('prepare', self::LIST, self::ITEM), 'the reader sent to before is left out');
            self::assertSame(['item', 'readers'], TestGate::$opened, 'the problem with the item only, the readers then');

            GateLetter::$selection = [self::reader(0)];
            self::assertSame(Gate::ERR_FAILED, (new TestGate())->act('prepare', self::LIST, self::ITEM)[1], 'nobody pending');

            GateLetter::$problem = 'No <alias>';
            self::assertSame(['No <alias>', Gate::ERR_FAILED], (new TestGate())->act('prepare', self::LIST, self::ITEM), 'plain text - the response escapes it');
        }

        public function test_act_prepare_whileSending_isRefusedBeforeAsking(): void
        {
            self::sending(3, Run::SENDING);

            self::assertSame(['A sending of the item to the list is not finished yet', Gate::ERR_FAILED], (new TestGate())->act('prepare', self::LIST, self::ITEM));
            self::assertSame([], TestGate::$opened, 'no letter made');
        }

        public function test_act_send_noLock_saysSo(): void
        {
            TestCampaign::$lockedByOthers = true;
            TestCampaign::$holder         = null;

            [$text, $error] = (new TestGate())->act('send', self::LIST, self::ITEM);

            self::assertSame(Gate::ERR_FAILED, $error);
            self::assertStringContainsString('no lock', $text);
        }

        public function test_act_send_startsOneRun(): void
        {
            [$html, $error] = (new TestGate())->act('send', self::LIST, self::ITEM);

            self::assertSame(0, $error);
            self::assertSame(['stop'], self::actions($html));
            $ids = self::runIds();
            self::assertCount(1, $ids);
            $stored = self::stored($ids[0]);
            self::assertSame([Run::SENDING, self::USER_ID, '3'], [$stored['state'], $stored['user'], $stored['total']]);
        }

        public function test_act_sendTwice_secondIsRefused(): void
        {
            (new TestGate())->act('send', self::LIST, self::ITEM);
            [$text, $error] = (new TestGate())->act('send', self::LIST, self::ITEM);

            self::assertSame(Gate::ERR_FAILED, $error);
            self::assertStringContainsString('not finished', $text);
            self::assertCount(1, self::runIds());
        }

        public function test_act_test_sendsToTestAddresses_andStartsNoRun(): void
        {
            GateLetter::$testAddresses = ['t1@example.org', 't2@example.org'];
            $rows                      = count(TableDb::rows('object_text'));

            [$html, $error] = (new TestGate())->act('test', self::LIST, self::ITEM);

            self::assertSame(0, $error);
            self::assertSame(['t1@example.org', 't2@example.org'], TestGate::$mailer?->sent());
            self::assertSame([[self::reader(0), true]], GateLetter::$asked, 'one mail, made as a test');
            self::assertStringContainsString('The test went to t1@example.org, t2@example.org.', $html);
            self::assertSame(['test', 'send'], self::actions($html));
            self::assertSame(['item', 'readers'], TestGate::$opened, 'the control after the test made no letter again');
            self::assertSame([], self::runIds());
            self::assertSame($rows, count(TableDb::rows('object_text')), 'no state written');
            self::assertSame([], TableDb::rows('toexecute'));
        }

        public function test_act_test_failedAddress_isSaidInTheControl(): void
        {
            GateLetter::$testAddresses = ['t1@example.org'];
            TestGate::$mailer->outcomes = [Outcome::Failed];
            TestGate::$mailer->detail   = '550 <b>no</b>';

            [$html, $error] = (new TestGate())->act('test', self::LIST, self::ITEM);

            self::assertSame(0, $error, 'the control, not an error text');
            self::assertStringContainsString('t1@example.org (550 &lt;b&gt;no&lt;/b&gt;)', $html);
            self::assertSame(['test', 'send'], self::actions($html));
        }

        public function test_act_test_emptyMail_isRefused(): void
        {
            GateLetter::$subject = ' ';

            self::assertSame(['The mail has no subject or no text', Gate::ERR_FAILED], (new TestGate())->act('test', self::LIST, self::ITEM));
            self::assertSame([], TestGate::$mailer?->sent());
        }

        public function test_act_stop_endsTheRun(): void
        {
            $id = self::sending(3, Run::SENDING);

            [$html, $error] = (new TestGate())->act('stop', self::LIST, self::ITEM);

            self::assertSame(0, $error);
            self::assertSame(Run::ENDED, self::stored($id)['state']);
            self::assertSame(['send'], self::actions($html));
            self::assertSame(Gate::ERR_FAILED, (new TestGate())->act('stop', self::LIST, self::ITEM)[1], 'nothing left to stop');
        }

        public function test_act_resume_continuesTheRunInError(): void
        {
            $id = self::sending(3, Run::ERROR, ['note' => 'SMTP is down']);

            [, $error] = (new TestGate())->act('resume', self::LIST, self::ITEM);

            self::assertSame(0, $error);
            self::assertSame(Run::SENDING, self::stored($id)['state']);
            self::assertSame([$id], self::runIds());
        }

        public function test_act_unknownAction_isError(): void
        {
            self::assertSame(Gate::ERR_UNKNOWN_ACTION, (new TestGate())->act('<b>x', self::LIST, self::ITEM)[1]);
        }

        public function test_act_unrelatedList_isLoggedAndDenied(): void
        {
            TestGate::$relates = false;

            self::assertSame(Gate::ERR_DENIED, (new TestGate())->act('send', self::LIST, self::ITEM)[1]);
            self::assertSame([[self::LIST, TestRun::SLICE, self::ITEM]], TestGate::$refusals);
            self::assertSame([], self::runIds());

            TestGate::$relates = true;
            TestGate::$mayEdit = false;
            self::assertSame(Gate::ERR_DENIED, (new TestGate())->act('send', self::LIST, self::ITEM)[1]);
            self::assertCount(1, TestGate::$refusals, 'only a list not of the slice is logged');
        }

        public function test_info_values(): void
        {
            $gate = new TestGate();
            self::assertSame(['', '0', ''], [$gate->info(self::LIST, self::ITEM, 'state'), $gate->info(self::LIST, self::ITEM, 'total'), $gate->info(self::LIST, self::ITEM, 'date')]);

            self::sending(4, Run::DONE, ['cursor' => '4', 'outcomes' => '{"1":"f","2":"u"}', 'finished' => '1900000123']);
            // a Gate reads each sending once per request
            $gate   = new TestGate();
            $values = array_map(static fn(string $what): string => $gate->info(self::LIST, self::ITEM, $what), Gate::INFO);

            self::assertSame([Run::DONE, '4', '2', '1', '0', '1', '1900000123'], $values);
            self::assertSame('', $gate->info(self::LIST, self::ITEM, 'opened'), 'unknown');
        }

        public function test_info_withoutRight_isEmpty(): void
        {
            self::sending(2, Run::DONE, ['cursor' => '2']);
            TestGate::$mayEdit = false;

            self::assertSame('', (new TestGate())->info(self::LIST, self::ITEM, 'total'));
        }
    }
}
