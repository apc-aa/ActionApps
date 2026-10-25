<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\RecipientFilter;
use AA\Isds\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/fixtures/Sending.php';

/**
 * The filter a campaign stores comes from the browser: only what the page offers passes, and a key
 * spelt otherwise never widens the recipients.
 */
final class RecipientFilterTest extends TestCase
{
    private const A = 'a0000000000000000000000000000001';
    private const B = 'b0000000000000000000000000000002';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
    }

    public function test_default_theDefaultTypesOfThePage(): void
    {
        $filter = RecipientFilter::default(Sending::page());

        self::assertSame([['mesto'], 'all', [], []], [$filter->types, $filter->membership, $filter->include, $filter->exclude]);
    }

    public function test_fromJson_idsInSmallLettersEachOnce(): void
    {
        $filter = RecipientFilter::fromJson(Sending::filter(['types' => ['mestocast', 'mesto', 'mesto'], 'membership' => 'members', 'include' => [strtoupper(self::A), self::A, self::B], 'exclude' => [self::B]]), Sending::page());

        self::assertSame([['mestocast', 'mesto'], 'members', [self::A, self::B], [self::B]], [$filter->types, $filter->membership, $filter->include, $filter->exclude]);
    }

    public function test_toJson_readBackTheSame(): void
    {
        $filter = RecipientFilter::fromJson(" \n" . Sending::filter(['membership' => 'nonmembers', 'include' => [self::A]]) . "\n", Sending::page());

        self::assertSame('{"types":["mesto"],"membership":"nonmembers","include":["' . self::A . '"],"exclude":[]}', $filter->toJson());
        self::assertEquals($filter, RecipientFilter::fromJson($filter->toJson(), Sending::page()));
    }

    public function test_fromJson_noTypesChoosesOnlyTheIncluded(): void
    {
        self::assertSame([], RecipientFilter::fromJson(Sending::filter(['types' => [], 'include' => [self::A]]), Sending::page())->types);
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        $ids = array_map(static fn(int $i): string => sprintf('%032x', $i), range(1, Setup::MAX_RECIPIENTS + 1));

        return [
            'empty'                   => [''],
            'not JSON'                => ['types=mesto'],
            'broken JSON'             => ['{"types":["mesto"],'],
            'a list'                  => ['["types","membership","include","exclude"]'],
            'empty object'            => ['{}'],
            'a key missing'           => ['{"types":["mesto"],"membership":"all","include":[]}'],
            'a key misspelt'          => ['{"types":["mesto"],"member":"members","include":[],"exclude":[]}'],
            'a key more'              => [Sending::filter(['members' => true])],
            'types a text'            => [Sending::filter(['types' => 'mesto'])],
            'types an object'         => [Sending::filter(['types' => ['a' => 'mesto']])],
            'type a number'           => [Sending::filter(['types' => [1]])],
            'type not offered'        => [Sending::filter(['types' => ['kraj']])],
            'type in capitals'        => [Sending::filter(['types' => ['Mesto']])],
            'type with a space'       => [Sending::filter(['types' => ['mesto ']])],
            'membership unknown'      => [Sending::filter(['membership' => 'everyone'])],
            'membership a list'       => [Sending::filter(['membership' => ['all']])],
            'membership missing'      => [Sending::filter(['membership' => null])],
            'include not a list'      => [Sending::filter(['include' => self::A])],
            'include not an id'       => [Sending::filter(['include' => ['12" onclick="x']])],
            'include 31 digits'       => [Sending::filter(['include' => [substr(self::A, 1)]])],
            'include nested'          => [Sending::filter(['include' => [[self::A]]])],
            'exclude not an id'       => [Sending::filter(['exclude' => ['x']])],
            'include too many'        => [Sending::filter(['include' => $ids])],
            'exclude too many'        => [Sending::filter(['exclude' => $ids])],
            'longer than the maximum' => [Sending::filter(['include' => [self::A]]) . str_repeat(' ', RecipientFilter::MAX_BYTES)],
        ];
    }

    #[DataProvider('refused')]
    public function test_fromJson_refused(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecipientFilter::fromJson($json, Sending::page());
    }

    public function test_fromJson_anIdInCapitalsCountsOnceToTheLimit(): void
    {
        $ids = array_map(static fn(int $i): string => sprintf('%032x', $i), range(1, Setup::MAX_RECIPIENTS));
        $abc = array_map(static fn(int $i): string => sprintf('%032X', $i), range(0xa, 0xf));

        self::assertCount(Setup::MAX_RECIPIENTS, RecipientFilter::fromJson(Sending::filter(['include' => [...$ids, ...$abc]]), Sending::page())->include);
    }

    public function test_fromJson_membersOnlyWhenThePageTellsThem(): void
    {
        self::assertSame('all', RecipientFilter::fromJson(Sending::filter(), Sending::page(''))->membership);

        $this->expectException(\InvalidArgumentException::class);
        RecipientFilter::fromJson(Sending::filter(['membership' => 'members']), Sending::page(''));
    }

    // only these items, whatever the page offers: a page without members reads it back too
    public function test_ofItems_readBackByAnyPage(): void
    {
        $filter = RecipientFilter::ofItems([strtoupper(self::A), self::A, self::B]);

        self::assertSame(['types' => [], 'membership' => 'all', 'include' => [self::A, self::B], 'exclude' => []], (array) $filter);
        self::assertEquals($filter, RecipientFilter::fromJson($filter->toJson(), Sending::page('')));
    }

    /** @return array<string, array{list<string>}> */
    public static function notItems(): array
    {
        return ['no id' => [['adamov']], 'an id too short' => [[substr(self::A, 1)]]];
    }

    /** @param list<string> $ids */
    #[DataProvider('notItems')]
    public function test_ofItems_refused(array $ids): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecipientFilter::ofItems($ids);
    }

    public function test_ofItems_noMoreThanTheLimit(): void
    {
        $ids = array_map(static fn(int $i): string => sprintf('a%031x', $i), range(0, Setup::MAX_RECIPIENTS));

        self::assertCount(Setup::MAX_RECIPIENTS, RecipientFilter::ofItems(array_slice($ids, 1))->include);
        $this->expectException(\InvalidArgumentException::class);
        RecipientFilter::ofItems($ids);
    }
}
