<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Recipient;
use AA\Isds\RecipientFilter;
use AA\Isds\Registry\Config;
use AA\Isds\Schema;
use AA\Isds\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/fixtures/Sending.php';

/**
 * Recipients = (type ∩ membership) ∪ include − exclude, each data box once; a bad box skips the
 * recipient with its reason, never stops the rest. Only items of the source slice are read. A box goes
 * out as the item has it, no register checks it, and the item's IČO plays no part.
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
     * @return list<Recipient>
     */
    private static function resolve(FakeRecipients $source, array $over = [], ?Setup $page = null): array
    {
        $page ??= Sending::page();

        return $source->resolve($page, RecipientFilter::fromJson(Sending::filter($over), $page), self::config());
    }

    /**
     * @param list<Recipient> $recipients
     * @return list<string>
     */
    private static function names(array $recipients): array
    {
        return array_map(static fn(Recipient $r): string => $r->name, $recipients);
    }

    /**
     * @param list<Recipient> $recipients
     * @return list<array{string, string, string}> name, box and reason of each
     */
    private static function rows(array $recipients): array
    {
        return array_map(static fn(Recipient $r): array => [$r->name, $r->box, $r->reason], $recipients);
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
            new Recipient(self::STRED, 'Brno-střed', 'str1234', ''),
            new Recipient(self::DECIN, 'Děčín', 'dec1234', ''),
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

    // an IČO gives no claim to a box
    public function test_resolve_duplicateKeptByTheFirstWhateverTheIco(): void
    {
        $recipients = self::resolve(self::source([
            self::ADAMOV => ['fields' => [Sending::DS => 'dec1234']],
            self::DECIN  => ['fields' => [Sending::TYPE => 'mesto', Sending::ICO => '00261238']],
        ]));

        self::assertSame([
            ['Adamov', 'dec1234', ''],
            ['Benešov', 'ben1234', ''],
            ['Děčín', 'dec1234', Schema::BOX_DUPLICATE],
            ['Cheb', 'che1234', ''],
        ], self::rows($recipients));
    }

    // the items of the source slice are trusted: the letter goes to the box the item has, whoever's it is
    public function test_resolve_boxNoRegisterKnowsSent(): void
    {
        $recipients = self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::DS => 'zzz9999']]]));

        self::assertSame([['Adamov', 'zzz9999', ''], ['Benešov', 'ben1234', ''], ['Cheb', 'che1234', '']], self::rows($recipients));
    }

    /** @return array<string, array{string}> what the IČO field of the item holds */
    public static function icos(): array
    {
        return [
            'an IČO'           => ['00283924'],
            'another city\'s'  => ['231 401'],
            'no IČO at all'    => ['Adamov'],
            'nine digits'      => ['123456789'],
        ];
    }

    // the settings name the IČO field for the pairing; sending does not even read it
    #[DataProvider('icos')]
    public function test_resolve_icoOfTheItemIgnored(string $ico): void
    {
        $recipients = self::resolve(self::source([self::ADAMOV => ['fields' => [Sending::ICO => $ico]]]));

        self::assertSame([['Adamov', 'ada1234', ''], ['Benešov', 'ben1234', ''], ['Cheb', 'che1234', '']], self::rows($recipients));
        self::assertNotContains(Sending::ICO, array_merge(...FakeRecipients::$fieldsRead));
    }

    public function test_resolve_withoutMembers(): void
    {
        self::assertSame(['Adamov', 'Benešov', 'Cheb'], self::names(self::resolve(self::source(), [], Sending::page(''))));
    }

    public function test_resolve_settingsWithoutTypeField(): void
    {
        $this->expectException(\RuntimeException::class);

        self::source()->resolve(Sending::page(), RecipientFilter::default(Sending::page()), self::config(''));
    }
}
