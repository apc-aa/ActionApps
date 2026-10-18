<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Recipient;
use AA\Isds\RecipientFilter;
use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\UnusableRegisterException;
use AA\Isds\Schema;
use AA\Isds\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\FakeRegisterIndex;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/fixtures/Sending.php';

/**
 * Recipients = (type ∩ membership) ∪ include − exclude, each data box once; a bad box skips the
 * recipient with its reason, never stops the rest. Only items of the source slice are read. A box
 * the register of data boxes has not, or has for another IČO than the item's, is foreign; one that is
 * not the main box of a municipality or a city district is skipped too.
 */
final class RecipientsTest extends TestCase
{
    // the territory: cities, a statutory city, a city district - and an item of another slice
    private const ADAMOV  = 'a0000000000000000000000000000001';
    private const BENESOV = 'b0000000000000000000000000000002';
    private const CHEB    = 'c0000000000000000000000000000003';
    private const DECIN   = 'd0000000000000000000000000000004';
    private const STRED   = 'e0000000000000000000000000000005';
    private const OLD     = 'f0000000000000000000000000000006';   // expired
    private const LETTER  = 'ca000000000000000000000000000007';   // a campaign

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/fixtures/FakeRecipients.php';
        require_once __DIR__ . '/fixtures/FakeRegisterIndex.php';
    }

    protected function setUp(): void
    {
        FakeRecipients::forget();
    }

    /**
     * @param array<string, array<string, mixed>> $over   item id => what differs from the territory
     * @param ?list<string>                       $fields the fields the slice has; null = all of Sending
     */
    private static function source(array $over = [], ?array $fields = null): FakeRecipients
    {
        $items = [
            self::ADAMOV  => Sending::item('Adamov', 'mesto', '1', 'ada1234'),
            self::BENESOV => Sending::item('Benešov', 'mesto', '0', 'ben1234'),
            self::CHEB    => Sending::item('Cheb', 'mesto', '', 'che1234'),
            self::DECIN   => Sending::item('Děčín', 'mestostatut', '1', 'dec1234'),
            self::STRED   => Sending::item('Brno-střed', 'mestocast', '1', 'str1234'),
            self::OLD     => ['active' => false] + Sending::item('Aš', 'mesto', '1', 'as12345'),
            self::LETTER  => ['slice' => Sending::CAMPAIGN] + Sending::item('Invitation', 'mesto', '1', 'abc1234'),
        ];
        foreach ($over as $id => $values) {
            $items[$id] = array_replace_recursive($items[$id] ?? Sending::item('', '', '', ''), $values);
        }

        return is_null($fields) ? new FakeRecipients($items) : new FakeRecipients($items, $fields);
    }

    private static function config(string $typeField = Sending::TYPE): Config
    {
        return Config::fromValues(Sending::SOURCE, ['name_field' => Sending::NAME, 'ico_field' => Sending::ICO, 'ds_field' => Sending::DS, 'type_field' => $typeField]);
    }

    /**
     * @param array<string, mixed> $over
     * @param ?RegisterIndex       $register null = one with every box a municipality's, none with an IČO
     * @return list<Recipient>
     */
    private static function resolve(FakeRecipients $source, array $over = [], ?Setup $page = null, ?RegisterIndex $register = null): array
    {
        $page ??= Sending::page();

        return $source->resolve($page, RecipientFilter::fromJson(Sending::filter($over), $page), self::config(), $register ?? FakeRegisterIndex::permissive());
    }

    /**
     * @param list<Recipient> $recipients
     * @return list<string>
     */
    private static function names(array $recipients): array
    {
        return array_map(static fn(Recipient $r): string => $r->name, $recipients);
    }

    /** @return list<string> every id content() read */
    private static function read(): array
    {
        return array_merge([], ...FakeRecipients::$read);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function memberships(): array
    {
        // a value other than 1 - 0 or none - is no member
        return [
            'all'        => ['all', ['Adamov', 'Benešov', 'Cheb']],
            'members'    => ['members', ['Adamov']],
            'nonmembers' => ['nonmembers', ['Benešov', 'Cheb']],
        ];
    }

    /** @param list<string> $names */
    #[DataProvider('memberships')]
    public function test_resolve_typeAndMembership(string $membership, array $names): void
    {
        self::assertSame($names, self::names(self::resolve(self::source(), ['membership' => $membership])));
    }

    public function test_resolve_typeMatchesExactly(): void
    {
        $source = self::source();

        self::assertSame(['Adamov', 'Benešov', 'Cheb'], self::names(self::resolve($source, ['types' => ['mesto']])));
        self::assertSame(['Děčín'], self::names(self::resolve($source, ['types' => ['mestostatut']])));
        self::assertSame([Sending::SOURCE, [[Sending::TYPE => 1, 'operator' => '==', 'value' => 'mesto']], null], FakeRecipients::$searches[0], 'the exact match - LIKE would find mestostatut and mestocast for mesto');
    }

    // what the database finds by type stays, though typed in capitals or with a space after
    public function test_resolve_typeAsTheDatabaseComparesIt(): void
    {
        $source = self::source([self::ADAMOV => ['fields' => [Sending::TYPE => 'Mesto ']], self::CHEB => ['fields' => [Sending::TYPE => 'MESTO']]]);

        self::assertSame(['Adamov', 'Benešov', 'Cheb'], self::names(self::resolve($source)));
    }

    // AA's search skips a condition on a field the slice lost and finds every item
    public function test_resolve_typeFieldGoneFromTheSlice(): void
    {
        $source = self::source(fields: [Sending::NAME, Sending::DS, Sending::MEMBER]);

        self::assertSame(['Děčín'], self::names(self::resolve($source, ['types' => ['mestostatut']])));
    }

    public function test_resolve_recipientValues(): void
    {
        self::assertEquals([
            new Recipient(self::STRED, 'Brno-střed', 'str1234', '', '', FakeRegisterIndex::NAME),
            new Recipient(self::DECIN, 'Děčín', 'dec1234', '', '', FakeRegisterIndex::NAME),
        ], self::resolve(self::source(), ['types' => ['mestostatut', 'mestocast']]));
    }

    // exclude wins over both the type and include
    public function test_resolve_unionOfIncludeMinusExclude(): void
    {
        $recipients = self::resolve(self::source(), ['types' => ['mesto'], 'membership' => 'members', 'include' => [self::DECIN, self::STRED, self::CHEB], 'exclude' => [self::STRED, self::ADAMOV]]);

        self::assertSame(['Děčín', 'Cheb'], self::names($recipients), 'an included item counts whatever its type and membership');
        self::assertSame([Sending::SOURCE, [], [self::DECIN, self::CHEB]], FakeRecipients::$searches[1], 'include searched in the source slice, without what is excluded');
        self::assertSame([], array_intersect([self::STRED, self::ADAMOV], self::read()), 'what is excluded is not read');
    }

    public function test_resolve_includeOfAnotherSliceOrNotActiveNeverRead(): void
    {
        $recipients = self::resolve(self::source(), ['types' => [], 'include' => [self::LETTER, self::OLD, self::DECIN, 'f00000000000000000000000000000ff']]);

        self::assertSame(['Děčín'], self::names($recipients));
        self::assertSame([[self::DECIN]], FakeRecipients::$read);
    }

    public function test_resolve_nobodyChosenReadsNothing(): void
    {
        self::assertSame([], self::resolve(self::source(), ['types' => [], 'include' => [self::ADAMOV], 'exclude' => [self::ADAMOV]]));
        self::assertSame([], FakeRecipients::$searches, 'all of include excluded - no search');
        self::assertSame([], self::resolve(self::source(), ['exclude' => [self::ADAMOV, self::BENESOV, self::CHEB]]));
        self::assertSame([], FakeRecipients::$read);
    }

    /** @return array<string, array{string|list<string>, string, bool, string}> */
    public static function boxes(): array
    {
        return [
            'a box'        => ['ada1234', 'ada1234', true, ''],
            'space at end' => ['ada1234 ', 'ada1234', true, ''],
            'line end'     => [" ada1234\n", 'ada1234', true, ''],
            'in capitals'  => ['ADA1234', 'ada1234', true, ''],
            'empty'        => ['', '', false, Schema::BOX_MISSING],
            'spaces only'  => ['   ', '', false, Schema::BOX_MISSING],
            'no value'     => [[], '', false, Schema::BOX_MISSING],
            '6 characters' => ['ada123', 'ada123', false, Schema::BOX_FORMAT],
            '8 characters' => ['ada12345', 'ada12345', false, Schema::BOX_FORMAT],
            'space inside' => ['ada 123', 'ada 123', false, Schema::BOX_FORMAT],
            'a dash'       => ['ada-123', 'ada-123', false, Schema::BOX_FORMAT],
            'a diacritic'  => ['adá1234', 'adá1234', false, Schema::BOX_FORMAT],
        ];
    }

    /** @param string|list<string> $field */
    #[DataProvider('boxes')]
    public function test_resolve_box(string|array $field, string $box, bool $valid, string $reason): void
    {
        $recipients = self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::DS => $field]]]));

        self::assertSame(['Adamov', $box, $valid, $reason, $reason === ''], [$recipients[0]->name, $recipients[0]->box, $recipients[0]->boxValid(), $recipients[0]->reason, $recipients[0]->sendable()]);
        self::assertSame(['Benešov', 'Cheb'], self::names(array_values(array_filter($recipients, static fn(Recipient $r): bool => $r->sendable() && ($r->name !== 'Adamov')))), 'the others still get it');
    }

    /**
     * The city keeps the box its district carries too; of the same names the smaller id. The content
     * comes in reverse id order, so keeping the first read, or no tie by id, gives another keeper.
     */
    public function test_resolve_duplicateBoxKeptByTheFirstByName(): void
    {
        $twin       = 'e00000000000000000000000000000ff';
        $recipients = self::resolve(self::source([
            self::STRED   => ['fields' => [Sending::NAME => 'Cheb-západ', Sending::DS => ' CHE1234']],
            self::ADAMOV  => ['fields' => [Sending::NAME => 'Zlín', Sending::DS => 'zli1234']],
            $twin         => ['fields' => [Sending::NAME => 'Zlín', Sending::TYPE => 'mesto', Sending::DS => 'zli1234 ']],
            self::BENESOV => ['fields' => [Sending::DS => '']],
            self::DECIN   => ['fields' => [Sending::TYPE => 'mesto', Sending::DS => '']],
        ]), ['types' => ['mesto', 'mestocast']]);

        // Czech order: ch after h
        self::assertSame([
            [self::BENESOV, 'Benešov', '', Schema::BOX_MISSING],
            [self::DECIN, 'Děčín', '', Schema::BOX_MISSING],
            [self::CHEB, 'Cheb', 'che1234', ''],
            [self::STRED, 'Cheb-západ', 'che1234', Schema::BOX_DUPLICATE],
            [self::ADAMOV, 'Zlín', 'zli1234', ''],
            [$twin, 'Zlín', 'zli1234', Schema::BOX_DUPLICATE],
        ], array_map(static fn(Recipient $r): array => [$r->itemId, $r->name, $r->box, $r->reason], $recipients));
        self::assertTrue($recipients[3]->boxValid(), 'a duplicate box is a box');
    }

    public function test_resolve_withoutMembers(): void
    {
        self::assertSame(['Adamov', 'Benešov', 'Cheb'], self::names(self::resolve(self::source(), [], Sending::page(''))));
    }

    public function test_resolve_settingsWithoutTypeField(): void
    {
        $this->expectException(\RuntimeException::class);

        self::source()->resolve(Sending::page(), RecipientFilter::default(Sending::page()), self::config(''), FakeRegisterIndex::permissive());
    }

    // ------------------------------------------------------------ the register of data boxes

    /**
     * The register with the boxes of Adamov, Benešov and Děčín - not of Cheb
     * @param array<string, array{0: string, 1: string, 2?: int, 3?: bool}> $boxes box id => IČO, official name,
     *        subtype and master flag (an authority's own box when left out), over these
     */
    private static function register(array $boxes = []): FakeRegisterIndex
    {
        return new FakeRegisterIndex(RegisterIndex::OK, $boxes + [
            'ada1234' => ['00283924', 'Město Adamov'],
            'ben1234' => ['00231401', 'Město Benešov'],
            'dec1234' => ['00261238', 'Statutární město Děčín'],
        ]);
    }

    /**
     * @param list<Recipient> $recipients
     * @return list<array{string, string, string}> name, reason and official name of each
     */
    private static function checked(array $recipients): array
    {
        return array_map(static fn(Recipient $r): array => [$r->name, $r->reason, $r->official], $recipients);
    }

    public function test_resolve_marksBoxOutsideRegisterForeign(): void
    {
        $recipients = self::resolve(self::source(), register: self::register());

        self::assertSame([
            ['Adamov', '', 'Město Adamov'],
            ['Benešov', '', 'Město Benešov'],
            ['Cheb', Schema::BOX_FOREIGN, ''],
        ], self::checked($recipients));
        self::assertSame(['che1234', true], [$recipients[2]->box, $recipients[2]->boxValid()], 'a foreign box is a box');
    }

    public function test_resolve_otherIcoForeign(): void
    {
        $source     = self::source([self::ADAMOV => ['fields' => [Sending::ICO => '00231401']], self::BENESOV => ['fields' => [Sending::ICO => "\u{A0}231 401 "]]]);
        $recipients = self::resolve($source, register: self::register());

        self::assertSame(['Adamov', Schema::BOX_FOREIGN, 'Město Adamov'], self::checked($recipients)[0], 'the register names whose box it is');
        self::assertSame('00231401', $recipients[0]->ico);
        self::assertSame(['Benešov', '', 'Město Benešov'], self::checked($recipients)[1], 'the same IČO without leading zeros, with spaces');
        self::assertSame('00231401', $recipients[1]->ico);
    }

    /** @return array<string, array{string, string}> the IČO of the item, of the register */
    public static function missingIco(): array
    {
        return [
            'none in the item'       => ['', '00283924'],
            'spaces in the item'     => ['  ', '00283924'],
            'a no-break space'       => ["\u{A0}", '00283924'],
            'zero-width characters'  => ["\u{200B}\u{FEFF}", '00283924'],
            'none in either'         => ['', ''],
            'no IČO in the register' => ['', RegisterIndex::BAD_ICO],
        ];
    }

    // an item without an IČO is checked by the box alone
    #[DataProvider('missingIco')]
    public function test_resolve_keepsMissingIco(string $ico, string $registerIco): void
    {
        $recipients = self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::ICO => $ico]]]), register: self::register(['ada1234' => [$registerIco, 'Město Adamov']]));

        self::assertSame(['Adamov', '', 'Město Adamov'], self::checked($recipients)[0]);
        self::assertSame('', $recipients[0]->ico);
    }

    /** @return array<string, array{string}> the IČO the register has for the box */
    public static function boxWithoutIco(): array
    {
        return [
            'none'       => [''],
            'not an IČO' => [RegisterIndex::BAD_ICO],
        ];
    }

    // a box without an IČO - an office, an archive, a district of Brno - is no item's that has one
    #[DataProvider('boxWithoutIco')]
    public function test_resolve_boxWithoutIcoForeignToItemWithIco(string $registerIco): void
    {
        $recipients = self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::ICO => '00283924']]]), register: self::register(['ada1234' => [$registerIco, 'Archiv']]));

        self::assertSame(['Adamov', Schema::BOX_FOREIGN, 'Archiv'], self::checked($recipients)[0]);
    }

    /** @return array<string, array{string, string}> the IČO the register has for the box, the item's */
    public static function icoThatIsNone(): array
    {
        return [
            'letters, no IČO in the register' => ['', 'Adamov'],
            'the mark of a bad IČO in both'   => [RegisterIndex::BAD_ICO, RegisterIndex::BAD_ICO],
            'nine digits'                     => ['00283924', '123456789'],
        ];
    }

    // the preview points at the field to fix - the IČO, not the box - and never matches it to the register
    #[DataProvider('icoThatIsNone')]
    public function test_resolve_icoFieldThatIsNoIco(string $registerIco, string $ico): void
    {
        $register   = self::register(['ada1234' => [$registerIco, 'Město Adamov']]);
        $recipients = self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::ICO => " $ico "]]]), register: $register);

        self::assertSame([['Adamov', Schema::BOX_ICO, ''], $ico, true], [self::checked($recipients)[0], $recipients[0]->ico, $recipients[0]->boxValid()]);
        self::assertSame([['ben1234', 'che1234']], $register->lookups, 'its box is not looked up');
    }

    // the first by name with a box keeps it - unless the box is not its own
    public function test_resolve_foreignLeavesTheBoxToItsOwner(): void
    {
        $source = self::source([
            self::ADAMOV => ['fields' => [Sending::ICO => '00231401']],
            self::DECIN  => ['fields' => [Sending::TYPE => 'mesto', Sending::DS => 'ada1234', Sending::ICO => '283924']],
        ]);

        self::assertSame([
            ['Adamov', Schema::BOX_FOREIGN, 'Město Adamov'],
            ['Benešov', '', 'Město Benešov'],
            ['Děčín', '', 'Město Adamov'],
            ['Cheb', Schema::BOX_FOREIGN, ''],
        ], self::checked(self::resolve($source, register: self::register())));
    }

    // an item without an IČO first by name - a forged one, maybe - leaves the box to the item the register gives it
    public function test_resolve_duplicateKeptByTheItemWhoseIcoTheRegisterGives(): void
    {
        $source = self::source([
            self::ADAMOV => ['fields' => [Sending::DS => 'dec1234']],
            self::DECIN  => ['fields' => [Sending::TYPE => 'mesto', Sending::ICO => '261238']],
        ]);

        self::assertSame([
            ['Adamov', Schema::BOX_DUPLICATE, 'Statutární město Děčín'],
            ['Benešov', '', 'Město Benešov'],
            ['Děčín', '', 'Statutární město Děčín'],
            ['Cheb', Schema::BOX_FOREIGN, ''],
        ], self::checked(self::resolve($source, register: self::register())));
    }

    /** @return array<string, array{int, bool}> the subtype of the box in the register, whether it is a main box */
    public static function notAnAuthoritysOwnBox(): array
    {
        return [
            'a part of a city, with its IČO'   => [13, false],
            'a company acting as an authority' => [16, true],
            'a notary'                         => [15, true],
            'subtype 10 under another box'     => [Box::AUTHORITY, false],
        ];
    }

    // a forged item cannot send the letter to a part of an authority, nor to a person or company acting as one
    #[DataProvider('notAnAuthoritysOwnBox')]
    public function test_resolve_boxNotAnAuthoritysOwnSkipped(int $subtype, bool $master): void
    {
        $source = self::source([
            self::ADAMOV  => ['fields' => [Sending::ICO => '00283924']],
            self::BENESOV => ['fields' => [Sending::DS => 'ada1234']],
            self::CHEB    => ['fields' => [Sending::DS => 'ada1234', Sending::ICO => '00231401']],
        ]);

        // named as the city's own box, so only the kind of box skips it
        $name = 'Město Adamov, městská policie';
        self::assertSame([
            ['Adamov', Schema::BOX_KIND, $name],
            ['Benešov', Schema::BOX_KIND, $name],
            ['Cheb', Schema::BOX_FOREIGN, $name],
        ], self::checked(self::resolve($source, register: self::register(['ada1234' => ['00283924', $name, $subtype, $master]]))), 'no keeper, so no duplicate; another IČO is foreign first');
    }

    /** @return array<string, array{string, string}> the official name of an authority's own main box, the reason */
    public static function officialNames(): array
    {
        return [
            'a municipality' => ['Obec Dolní Lhota', ''],
            'a school'       => ['Mensa gymnázium, o.p.s.', Schema::BOX_KIND],
            'a region'       => ['Kraj Vysočina', Schema::BOX_KIND],
        ];
    }

    // a forged item cannot send the letter to a school or a region either - only to the main box of a
    // municipality or a city district
    #[DataProvider('officialNames')]
    public function test_resolve_onlyMunicipalBoxSendable(string $official, string $reason): void
    {
        $register = self::register(['ada1234' => ['00283924', $official]]);

        self::assertSame(['Adamov', $reason, $official], self::checked(self::resolve(self::source(), register: $register))[0]);
    }

    /** @return array<string, array{string}> the item's IČO */
    public static function authoritysOwnBox(): array
    {
        return [
            'the register\'s IČO' => ['283924'],
            'none'                => [''],
        ];
    }

    #[DataProvider('authoritysOwnBox')]
    public function test_resolve_authoritysOwnBoxSendable(string $ico): void
    {
        $register = self::register(['ada1234' => ['00283924', 'Město Adamov', Box::AUTHORITY, true]]);

        self::assertSame(['Adamov', '', 'Město Adamov'], self::checked(self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::ICO => $ico]]]), register: $register))[0]);
    }

    /** @return array<string, array{string}> */
    public static function unusableRegister(): array
    {
        return [
            'missing'    => [RegisterIndex::MISSING],
            'unreadable' => [RegisterIndex::UNREADABLE],
        ];
    }

    #[DataProvider('unusableRegister')]
    public function test_resolve_unusableRegisterRefusedUpFront(string $state): void
    {
        try {
            self::resolve(self::source(), register: new FakeRegisterIndex($state));
            self::fail('recipients told without the register');
        } catch (UnusableRegisterException $e) {
            self::assertSame("The register of data boxes is $state", $e->getMessage());
        }
        self::assertSame([[], []], [FakeRecipients::$searches, FakeRecipients::$read], 'nothing searched or read');
    }

    public function test_resolve_oneLookupForAllBoxes(): void
    {
        $source   = self::source([
            self::BENESOV => ['fields' => [Sending::DS => '']],
            self::CHEB    => ['fields' => [Sending::DS => 'ADA1234']],
            self::DECIN   => ['fields' => [Sending::DS => 'dec 1234']],
            self::STRED   => ['fields' => [Sending::DS => 'str1234']],
        ]);
        $over     = ['include' => [self::DECIN, self::STRED]];
        $register = self::register();

        $preview = self::resolve($source, $over, register: $register);
        self::assertSame([['ada1234', 'str1234']], $register->lookups, 'each box once, none that is no box');
        self::assertSame([
            ['Adamov', '', 'Město Adamov'],
            ['Benešov', Schema::BOX_MISSING, ''],
            ['Brno-střed', Schema::BOX_FOREIGN, ''],
            ['Děčín', Schema::BOX_FORMAT, ''],
            ['Cheb', Schema::BOX_DUPLICATE, 'Město Adamov'],
        ], self::checked($preview));
    }
}
