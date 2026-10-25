<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\IO\DB\DB_AA;
use AA\Isds\Gate;
use AA\Isds\Schema;
use AA_Credentials;
use AA_Dbcondition;
use AA_Perm;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Isds\Fixtures\FakeGate;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

// data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/**
 * {datovkabulk:filter}, {datovkabulk:source}, {datovkabulk:search}, {datovkabulk:message} and
 * {datovkabulk:events}: what a template shows of the filter of a campaign, of a recipient and of a
 * message - only of the page's own items, escaped, and never a pattern or a value that expands.
 */
final class GateShowsTest extends TestCase
{
    private const ITEM    = 'ca00000000000000000000000000000b';   // the campaign of the page
    private const ITEM2   = 'ca00000000000000000000000000000c';   // another campaign of the campaign slice
    private const LETTER  = 'be00000000000000000000000000000d';   // a message of ITEM
    private const LETTER2 = 'be00000000000000000000000000000e';   // a message of ITEM2

    // items of the source slice
    private const ADAMOV  = 'a0000000000000000000000000000001';
    private const BENESOV = 'b0000000000000000000000000000002';
    private const CHEB    = 'c0000000000000000000000000000003';
    private const DECIN   = 'd0000000000000000000000000000004';
    private const BRNO    = 'e0000000000000000000000000000005';
    private const KRAJ    = 'f0000000000000000000000000000006';   // of a type the page does not offer
    private const GONE    = 'f0000000000000000000000000000007';   // in the trash
    private const FOREIGN = 'f0000000000000000000000000000008';   // of another slice

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/fixtures/FakeSliceRules.php';
        require_once __DIR__ . '/fixtures/FakePerms.php';
        require_once __DIR__ . '/fixtures/FakeGate.php';
        require_once __DIR__ . '/fixtures/FakeRecipients.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode(), QuoteColons()
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                       // AA::Pagecache()
        }
    }

    protected function setUp(): void
    {
        FakeRecipients::forget();
        FakePerms::install([Sending::SET => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]], [Sending::READER => [Sending::READERS, Sending::SET]]);
        $_GET = [];
    }

    /** what a test changed of the request stays in it */
    protected function tearDown(): void
    {
        FakeSliceRules::forget();
        FakePerms::uninstall();
        Closure::bind(function (): void {
            foreach (get_object_vars($this) as $name => $value) {
                if (is_array($value)) {
                    $this->$name = [];
                }
            }
        }, AA_Credentials::singleton(), AA_Credentials::class)();
        (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
        AA::Contentcache()->clear();
        FakeRecipients::forget();
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        $_GET = [];
    }

    /** @return array<string, array{slice: string, active?: bool, fields: array<string, string>}> the territory of the source slice */
    private static function territory(): array
    {
        return [
            self::ADAMOV  => Sending::item('Adamov', 'mesto', '1', 'ada1234'),
            self::BENESOV => Sending::item('Benešov', 'mesto', '1', ''),
            self::CHEB    => Sending::item('Cheb', 'mesto', '0', 'che1234'),
            self::DECIN   => Sending::item('Děčín', 'mestocast', '0', 'ADA1234'),
            self::BRNO    => Sending::item('Brno', 'mestocast', '1', 'brn1234'),
            self::KRAJ    => Sending::item('Kraj Adamov', 'kraj', '1', 'kra1234'),
            self::GONE    => ['active' => false] + Sending::item('Adamovice', 'mesto', '1', 'gon1234'),
            self::FOREIGN => ['slice' => Sending::OTHER] + Sending::item('Adamov jinde', 'mesto', '1', 'for1234'),
        ];
    }

    /**
     * The page of SITE (Sending::page(): cities, statutory cities and districts, cities chosen at first)
     * with its campaigns - item id => the filter of an active item of the campaign slice, or what
     * stored() gives - over the territory, or the items given
     * @param array<string, string|array{slice: string, active: bool, filter: string, state: string}> $campaigns
     * @param ?array<string, array{slice: string, active?: bool, fields: array<string, string>}>        $items
     */
    private static function gate(array $campaigns = [], ?array $items = null, string $memberField = Sending::MEMBER): FakeGate
    {
        $rules            = FakeSliceRules::ready(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE)->withSource(Sending::SITE, Sending::SOURCE);
        $gate             = new FakeGate(Sending::SITE, $rules, Sending::bound(), FakePerms::login(Sending::READER));
        $gate->setup      = Sending::page($memberField);
        $gate->campaigns  = array_map(static fn(string|array $c): array => is_string($c) ? ['slice' => Sending::CAMPAIGN, 'active' => true, 'filter' => $c, 'state' => 'draft'] : $c, $campaigns);
        $gate->resolver   = new FakeRecipients($items ?? self::territory());
        $gate->items      = $items ?? self::territory();

        return $gate;
    }

    /** what $inside gives, run inside the UI of the page */
    private static function inside(Gate $gate, Closure $inside): mixed
    {
        $got = null;
        $gate->page('{}', '', '', static function () use ($inside, &$got): string {
            $got = $inside();

            return '';
        });

        return $got;
    }

    /** @return array<string, string> each part of the filter of $campaign */
    private static function filter(Gate $gate, string $campaign): array
    {
        return self::inside($gate, static fn(): array => array_combine($parts = ['types', 'membership', 'include', 'exclude'], array_map(static fn(string $part): string => $gate->filter($campaign, $part), $parts)));
    }

    // ------------------------------------------------------------ filter

    public function test_filter_storedAndDefault(): void
    {
        $gate = self::gate([
            self::ITEM  => Sending::filter(['types' => ['mesto', 'mestocast'], 'membership' => 'members', 'include' => [self::CHEB, self::ADAMOV], 'exclude' => [self::BRNO]]),
            self::ITEM2 => " \n",
        ]);

        self::assertSame(['types' => 'mesto,mestocast', 'membership' => 'members', 'include' => self::CHEB . '-' . self::ADAMOV, 'exclude' => self::BRNO], self::filter($gate, strtoupper(self::ITEM)));
        $default = ['types' => 'mesto', 'membership' => 'all', 'include' => '', 'exclude' => ''];
        self::assertSame($default, self::filter($gate, ''), 'a new campaign');
        self::assertSame($default, self::filter($gate, self::ITEM2), 'a campaign that stored none');
        self::assertSame('', $gate->filter(self::ITEM, 'types'), 'outside the UI');
        self::assertSame('', self::inside($gate, static fn(): string => $gate->filter(self::ITEM, 'all')), 'unknown question');
    }

    /** @return array<string, array{string|array{slice: string, active: bool, filter: string, state: string}, string}> */
    public static function unreadableFilters(): array
    {
        return [
            'broken JSON'           => ['{"types":', 'The recipient filter is a JSON object of types, membership, include, exclude'],
            'a type not offered'    => [Sending::filter(['types' => ['kraj']]), 'The page does not offer the types kraj'],
            'a key missing'         => ['{"types":[],"membership":"all","include":[]}', 'The recipient filter is a JSON object of types, membership, include, exclude'],
            'not an id included'    => [Sending::filter(['include' => ['12" onclick="x']]), 'include is a list of item ids - 32 hexadecimal digits'],
            'a campaign in the bin' => [['slice' => Sending::CAMPAIGN, 'active' => false, 'filter' => Sending::filter(), 'state' => 'draft'], ''],
            'an item of messages'   => [['slice' => Sending::MESSAGE, 'active' => true, 'filter' => Sending::filter(), 'state' => 'sent'], ''],
        ];
    }

    /**
     * nothing - never the filter a new campaign starts with; a filter that cannot be read goes to the
     * log once, as recipients() logs it, for State Check
     * @param string|array{slice: string, active: bool, filter: string, state: string} $stored
     */
    #[DataProvider('unreadableFilters')]
    public function test_filter_brokenEmpty(string|array $stored, string $logged): void
    {
        $empty = ['types' => '', 'membership' => '', 'include' => '', 'exclude' => ''];
        $gate  = self::gate([self::ITEM => $stored]);

        self::assertSame($empty, self::filter($gate, self::ITEM));
        self::assertSame(['', ''], self::inside($gate, static fn(): array => [$gate->recipients(self::ITEM, 'count'), $gate->filter(self::ITEM, 'types')]));
        self::assertSame(($logged === '') ? [] : [[Sending::SITE . ':' . Sending::CAMPAIGN . ':' . self::ITEM, $logged]], $gate->recipientsLogged);
        self::assertSame($empty, self::filter(self::gate([self::ITEM => Sending::filter()]), 'x' . self::ITEM), 'not an id');
    }

    /**
     * include and exclude whole on every page - the draft form stores its hidden fields as the whole
     * filter; includepage and excludepage a page at a time, for the chips
     */
    public function test_filter_wholeForTheFormPagedForChips(): void
    {
        $items = [];
        foreach (range(1, 120) as $n) {
            $items[sprintf('a%031d', $n)] = Sending::item("Obec $n", 'mesto', '1', '');
        }
        $ids  = array_keys($items);
        $gate = self::gate([self::ITEM => Sending::filter(['include' => array_slice($ids, 0, 60), 'exclude' => array_slice($ids, 60)])], $items);
        $page = static function (string $page) use ($gate): array {
            $_GET = ['isds_page' => $page];

            return self::inside($gate, static fn(): array => array_map(static fn(string $part): string => $gate->filter(self::ITEM, $part), ['include', 'exclude', 'includepage', 'excludepage']));
        };
        $dashed = static fn(int $from, int $count): string => implode('-', array_slice($ids, $from, $count));

        self::assertSame([$dashed(0, 60), $dashed(60, 60), $dashed(0, 50), $dashed(60, 50)], $page('1'));
        self::assertSame([$dashed(0, 60), $dashed(60, 60), $dashed(50, 10), $dashed(110, 10)], $page('2'));
        self::assertSame([$dashed(0, 60), $dashed(60, 60), '', ''], $page('3'));
    }

    public function test_filter_foreignIdsLeftOut(): void
    {
        $gate = self::gate([self::ITEM => Sending::filter([
            'include' => [self::FOREIGN, self::DECIN, self::GONE, self::LETTER, self::ADAMOV],
            'exclude' => [self::GONE, self::CHEB],
        ])]);

        $filter = self::filter($gate, self::ITEM);

        self::assertSame(self::DECIN . '-' . self::ADAMOV, $filter['include'], 'in the stored order');
        self::assertSame(self::CHEB, $filter['exclude']);
        self::assertSame([[Sending::SOURCE, [], [self::FOREIGN, self::DECIN, self::GONE, self::LETTER, self::ADAMOV], [], 0]], array_slice(FakeRecipients::$searches, 0, 1), 'one search over the source slice');
    }

    // ------------------------------------------------------------ source

    /**
     * @param list<string> $what
     * @return list<string> what source() tells of $item
     */
    private static function source(Gate $gate, string $item, array $what = ['name', 'box', 'type', 'member', 'reason']): array
    {
        return self::inside($gate, static fn(): array => array_map(static fn(string $w): string => $gate->source($item, $w), $what));
    }

    public function test_source_whatThePageShows(): void
    {
        self::assertSame(['Děčín', 'ADA1234', 'City districts', '0', ''], self::source(self::gate(), strtoupper(self::DECIN)));
        self::assertSame(['Kraj Adamov', 'kra1234', 'kraj', '1', ''], self::source(self::gate(), self::KRAJ), 'a type the page does not offer as it is');
        self::assertSame(['Adamov', ''], self::source(self::gate(memberField: ''), self::ADAMOV, ['name', 'member']), 'a page that tells no members');
        self::assertSame([''], self::source(self::gate(), self::ADAMOV, ['ico']), 'unknown question');
        self::assertSame('', self::gate()->source(self::ADAMOV, 'name'), 'outside the UI');
    }

    /** @return array<string, array{string}> */
    public static function notOfTheSource(): array
    {
        return [
            'of another slice' => [self::FOREIGN],
            'a message'        => [self::LETTER],
            'no such item'     => ['f000000000000000000000000000000f'],
            'not an id'        => ['12" onclick="x'],
        ];
    }

    #[DataProvider('notOfTheSource')]
    public function test_source_onlySourceSlice(string $item): void
    {
        self::assertSame(['', '', '', '', ''], self::source(self::gate(), $item));
    }

    public function test_source_onlyActiveBin(): void
    {
        self::assertSame(['', '', '', '', ''], self::source(self::gate(), self::GONE));
    }

    public function test_source_escaped(): void
    {
        $gate = self::gate([], [self::ADAMOV => Sending::item('<b>"Obec"</b> & _#HEADLINE {datovkabulk:env}', '<mesto>', '1', "x'1")]);

        self::assertSame(['&lt;b&gt;&quot;Obec&quot;&lt;/b&gt; &amp; _&#35;HEADLINE {datovkabulk:env}', 'x&apos;1', '&lt;mesto&gt;'], self::source($gate, self::ADAMOV, ['name', 'box', 'type']));
    }

    // Benešov has no box, Děčín the box of Adamov - only for the campaign the page shows
    public function test_source_reasonOfCampaign(): void
    {
        $gate   = self::gate([self::ITEM => Sending::filter(['include' => [self::DECIN]]), self::ITEM2 => Sending::filter(['types' => []])]);
        $reason = static fn(string $item): string => self::source($gate, $item, ['reason'])[0];

        self::assertSame(['', '', ''], [$reason(self::ADAMOV), $reason(self::BENESOV), $reason(self::DECIN)], 'no campaign shown');
        $_GET['isds_campaign'] = self::ITEM;
        self::assertSame(['', Schema::BOX_MISSING, Schema::BOX_DUPLICATE, ''], [$reason(self::ADAMOV), $reason(self::BENESOV), $reason(self::DECIN), $reason(self::BRNO)]);
        AA::Contentcache()->clear();
        $_GET['isds_campaign'] = self::ITEM2;
        self::assertSame(['', ''], [$reason(self::BENESOV), $reason(self::DECIN)], 'a campaign of no recipients');
    }

    /** a page lists the recipients and the found ones: each listing reads its items at the first question of a row */
    public function test_source_preloadedOnce(): void
    {
        $gate                  = self::gate([self::ITEM => Sending::filter()]);
        $_GET['isds_campaign'] = self::ITEM;
        $_GET['isds_q']        = 'br';
        $missing               = 'f000000000000000000000000000000f';
        RecordingDb::$answer   = static fn(string $sql): array => [['id' => '12']];
        $GLOBALS['spareDBs']   = [new RecordingDb()];

        $names = self::inside($gate, static function () use ($gate, $missing): array {
            $names = [];
            $gate->view('isds_src_rows', $gate->recipients(self::ITEM, 'ids'));
            $gate->view('isds_src_rows', $gate->recipients(self::ITEM, 'skippedids'));
            self::assertSame([], $gate->reads, 'nothing read before a row asks');
            foreach ([self::ADAMOV, self::CHEB, self::BENESOV] as $id) {
                $names[] = $gate->source($id, 'name');
                $names[] = $gate->source($id, 'reason');
            }
            $gate->view('isds_src_rows', $gate->search());
            $names[] = $gate->source(self::BRNO, 'name');
            $names[] = $gate->source($missing, 'name') . $gate->source($missing, 'box');

            return $names;
        });

        self::assertSame(['Adamov', '', 'Cheb', '', 'Benešov', Schema::BOX_MISSING, 'Brno', ''], $names);
        self::assertSame([[self::ADAMOV, self::CHEB, self::BENESOV], [self::BRNO], [$missing]], $gate->reads, 'a listing at once, an id with no item once');
    }

    // ------------------------------------------------------------ search

    /** @return string what search() finds for isds_q $text */
    private static function search(mixed $text, ?FakeGate $gate = null): string
    {
        $gate ??= self::gate();
        $_GET = ['isds_q' => $text];

        return self::inside($gate, static fn(): string => $gate->search());
    }

    /** @return string the SQL AA's search makes of the condition on the name of the first search */
    private static function nameSql(): string
    {
        $cond = FakeRecipients::$searches[0][1][0];

        return (new AA_Dbcondition('c0', 'text', $cond['operator'], $cond['value'], 'text', true))->getWhereExp();
    }

    public function test_search_likeNotRegex(): void
    {
        self::assertSame('', self::search('^Ad.*'));
        self::assertSame([Sending::NAME => 1, 'operator' => 'CONTAIN', 'value' => '^Ad.*'], FakeRecipients::$searches[0][1][0]);
        self::assertSame('(' . DB_AA::quoteIdentifier('c0.text') . " LIKE '%^Ad.*%')", self::nameSql());
        self::assertSame(self::DECIN, self::search(' děč '), 'trimmed; the collation of the database takes the case');
    }

    public function test_search_wildcardsEscaped(): void
    {
        $gate = self::gate([], [self::ADAMOV => Sending::item('Obec 50%_x', 'mesto', '1', ''), self::BRNO => Sending::item('Obec 500ax', 'mesto', '1', '')]);

        self::assertSame('', self::search("50%_x'", $gate));
        self::assertSame('(' . DB_AA::quoteIdentifier('c0.text') . " LIKE '%50\\%\\_x\\'%')", self::nameSql());
        self::assertSame(self::ADAMOV, self::search('50%_x', $gate), 'not Obec 500ax');
        FakeRecipients::forget();
        // AA's LIKE escapes a backslash after quoting: it would make % and _ wildcards again
        self::assertSame(self::ADAMOV, self::search('50\\%_\\x', $gate));
        self::assertSame('50%_x', FakeRecipients::$searches[0][1][0]['value']);
    }

    public function test_search_typesOfPage(): void
    {
        self::assertSame(self::ADAMOV . '-' . self::BENESOV, self::search('ov'), 'by name, none of kraj, the trash or another slice');
        $ofTypes = [Sending::TYPE => 1, 'operator' => '=', 'value' => '"mesto" OR "mestostatut" OR "mestocast"'];
        self::assertSame([[Sending::SOURCE, [[Sending::NAME => 1, 'operator' => 'CONTAIN', 'value' => 'ov'], $ofTypes], null, [[Sending::NAME => 'a']], 20]], FakeRecipients::$searches, 'one search');
        $quoted = static fn(string $t): string => DB_AA::quoteIdentifier('c1.text') . "= '$t'";
        self::assertSame('(' . implode(' or ', array_map($quoted, ['mesto', 'mestostatut', 'mestocast'])) . ')', (new AA_Dbcondition('c1', 'text', '=', $ofTypes['value'], 'text', true))->getWhereExp(), 'each type exactly');
        self::assertSame(self::BENESOV . '-' . self::BRNO . '-' . self::DECIN, self::search('n'), 'of all the types by name');
    }

    // a type field gone from the slice: AA's search skips the condition, the type read again does not
    public function test_search_typeCheckedAgain(): void
    {
        $gate           = self::gate();
        $gate->resolver = new FakeRecipients(self::territory(), [Sending::NAME, Sending::DS, Sending::MEMBER]);

        self::assertSame(self::ADAMOV . '-' . self::BENESOV, self::search('ov', $gate));
        self::assertSame([[self::ADAMOV, self::BENESOV, self::KRAJ]], FakeRecipients::$read, 'the type of what the search found');
    }

    public function test_search_max20(): void
    {
        $items = [];
        foreach (range(1, 30) as $n) {
            $items[sprintf('a%031d', $n)] = Sending::item(sprintf('Obec %02d', 31 - $n), ($n % 2) ? 'mesto' : 'mestocast', '1', '');
        }
        $found = explode('-', self::search('obec', self::gate([], $items)));

        self::assertSame(array_map(static fn(int $n): string => sprintf('a%031d', $n), range(30, 11)), $found);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function queries(): array
    {
        return [
            '100 characters' => [str_repeat('ě', 100), true],
            '101 characters' => [str_repeat('ě', 101), false],
            'empty'          => ['', false],
            'spaces'         => ["  \n", false],
            'an array'       => [['Adamov'], false],
            'not UTF-8'      => ["Ad\xE8", false],
            'backslashes'    => ['\\\\', false],
        ];
    }

    #[DataProvider('queries')]
    public function test_search_tooLongEmpty(mixed $text, bool $searched): void
    {
        self::assertSame('', self::search($text));
        self::assertSame($searched, FakeRecipients::$searches !== []);
    }

    // ------------------------------------------------------------ message

    /** messages of both campaigns; the timeline of LETTER is $events */
    private static function messageGate(string $events = '[]'): FakeGate
    {
        $message      = static fn(string $slice, string $campaign, string $events): array => ['slice' => $slice, 'fields' => [Schema::MESSAGE_CAMPAIGN => $campaign, Schema::MESSAGE_EVENTS => $events]];
        $gate         = self::gate([self::ITEM => Sending::filter(), self::ITEM2 => Sending::filter()]);
        $gate->items += [
            self::LETTER  => $message(Sending::MESSAGE, strtoupper(self::ITEM), $events),
            self::LETTER2 => $message(Sending::MESSAGE, self::ITEM2, '[{"at":1,"kind":"queued"}]'),
            // the template of a campaign is in the same field
            self::ITEM2   => $message(Sending::CAMPAIGN, self::ITEM, '[{"at":1,"kind":"queued"}]'),
        ];

        return $gate;
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function messagesOfTheUrl(): array
    {
        return [
            'a message of the campaign'   => [['isds_campaign' => self::ITEM, 'isds_msg' => strtoupper(self::LETTER)], self::LETTER],
            'of another campaign'         => [['isds_campaign' => self::ITEM, 'isds_msg' => self::LETTER2], ''],
            'an item of the campaigns'    => [['isds_campaign' => self::ITEM, 'isds_msg' => self::ITEM2], ''],
            'no campaign shown'           => [['isds_msg' => self::LETTER], ''],
            'a campaign of another slice' => [['isds_campaign' => self::LETTER, 'isds_msg' => self::LETTER], ''],
            'not an id'                   => [['isds_campaign' => self::ITEM, 'isds_msg' => self::LETTER . '"'], ''],
            'an array'                    => [['isds_campaign' => self::ITEM, 'isds_msg' => [self::LETTER]], ''],
        ];
    }

    /** @param array<string, mixed> $get */
    #[DataProvider('messagesOfTheUrl')]
    public function test_message_onlyOfCampaignOfPage(array $get, string $shown): void
    {
        $gate = self::messageGate();
        $_GET = $get;

        self::assertSame($shown, self::inside($gate, static fn(): string => $gate->message()));
        self::assertSame('', $gate->message(), 'outside the UI');
    }

    // ------------------------------------------------------------ events

    /**
     * the rows of the timeline of LETTER shown on the page of ITEM, each expanded by $expand
     * @param ?Closure(string): string $expand
     */
    private static function events(string $events, string $row, ?Closure $expand = null, string $message = self::LETTER): string
    {
        $gate                  = self::messageGate($events);
        $_GET['isds_campaign'] = self::ITEM;

        return self::inside($gate, static fn(): string => $gate->events($message, $row, $expand ?? static fn(string $text): string => "[$text]"));
    }

    public function test_events_sortedByAtUnknownLast(): void
    {
        $timeline = [
            ['at' => 100, 'kind' => 'queued'],
            ['at' => 0, 'kind' => 'av', 'code' => '3'],
            ['at' => 300, 'kind' => 'delivered'],
            ['at' => 200, 'kind' => 'submitted'],
            ['at' => 300, 'kind' => 'login'],
            ['at' => 0, 'kind' => 'timeout'],
        ];

        self::assertSame('[delivered 300][login 300][submitted 200][queued 100][av ][timeout ]', self::events((string) json_encode($timeline), '_#KIND _#AT'));
        self::assertSame('', self::events((string) json_encode($timeline), '_#KIND', message: self::LETTER2), 'a message of another campaign');
        self::assertSame('', self::events('{"at":', '_#KIND'), 'a timeline that cannot be read');
    }

    public function test_events_allSixEscapedOnePass(): void
    {
        $perms                         = FakePerms::install([Sending::SET => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]], [Sending::READER => [Sending::READERS, Sending::SET]]);
        $perms->names[Sending::READER] = 'Jana <b>Nová</b>';
        // "uid name", as SendRun::verify() keeps who verified it
        $entry = ['at' => 5, 'kind' => 'verified', 'code' => 'a<b', 'message' => '_#KIND <i>"x"</i> & y', 'dmID' => '12', 'by' => Sending::READER . ' Jana Stará'];

        self::assertSame(
            '[5|verified|a&lt;b|_&#35;KIND &lt;i&gt;&quot;x&quot;&lt;/i&gt; &amp; y|12|Jana &lt;b&gt;Nová&lt;/b&gt;]',
            self::events((string) json_encode([$entry]), '_#AT|_#KIND|_#CODE|_#TEXT|_#DMID|_#BY'),
        );
        self::assertSame('[|queued||||]', self::events('[{"at":0,"kind":"queued"}]', '_#AT|_#KIND|_#CODE|_#TEXT|_#DMID|_#BY'), 'what an entry does not have is empty');
        $by = static fn(string $by): string => self::events((string) json_encode([['at' => 5, 'kind' => 'verified', 'by' => $by]]), '_#BY');
        self::assertSame(['[Petr &lt;i&gt;Starý]', '[88]'], [$by('77 Petr <i>Starý'), $by('88')], 'a login AA no longer knows: the name kept, else its id');
    }

    // only the six names: an alias that begins as one of them stays for the expansion of the row
    public function test_events_longerAliasesStay(): void
    {
        self::assertSame('[_#ATTACHME 5 _#TEXTO___ x _#AT_ _#BYLINE__]', self::events('[{"at":5,"kind":"failed","message":"x"}]', '_#ATTACHME _#AT _#TEXTO___ _#TEXT _#AT_ _#BYLINE__'));
    }

    // the row expands, a value in it does not - nor ends a deferred part, nor names an alias
    public function test_events_valuesNotExpanded(): void
    {
        $entry = ['at' => 5, 'kind' => 'failed', 'message' => '{datovkabulk:env}:x)} {(y)} _#HEADLINE'];

        self::assertSame(
            '<li>test: {datovkabulk:env}:x)} {(y)} _&#35;HEADLINE</li>',
            self::events((string) json_encode([$entry]), '<li>{datovkabulk:env}: _#TEXT</li>', static fn(string $row): string => AA::Un($row)),
        );
    }
}
