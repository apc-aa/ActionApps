<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Matcher;
use AA\Isds\Registry\MatchReport;
use AA\Isds\Registry\MatchRow;
use AA\Isds\Registry\SdsList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Most cases run on the 31 real boxes of the SdsList fixture; spellings it lacks (zero-padded or
 * dotted numbers, no-break spaces) on a few boxes made up in the register's style.
 */
final class MatcherTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/seznam_ds_ovm-sample.xml';

    private const DISTRICT = 'district';

    /** @var array<string, Box> */
    private static array $fixture = [];

    public static function setUpBeforeClass(): void
    {
        self::$fixture = array_column(iterator_to_array(SdsList::fromXml(self::FIXTURE), false), null, 'id');
    }

    // the region's box was stored for the city; the IČO finds the city's own
    public function test_match_cityByIcoChangesTheBox(): void
    {
        $row = self::one(['name' => 'Brno', 'ico' => '44992785', 'ds' => 'x2pbqzq', 'type' => 'city']);

        self::assertEquals(new MatchRow('i', 'Brno', 'city', 'x2pbqzq', self::$fixture['a7kbrrn'], MatchRow::BY_ICO, MatchRow::CHANGED), $row);
        self::assertSame(['a7kbrrn', 'Statutární město Brno'], [$row->newDs, $row->boxName]);
    }

    public function test_match_districtWithoutIcoByName(): void
    {
        $row = self::one(['name' => 'Brno-střed', 'type' => self::DISTRICT]);

        self::assertEquals(new MatchRow('i', 'Brno-střed', self::DISTRICT, '', self::$fixture['qykbwe7'], MatchRow::BY_NAME, MatchRow::NEW), $row);
        self::assertSame('Městská část Brno-střed', $row->boxName);
    }

    /** @return array<string, array{string, string, string}> */
    public static function districtsWithTheCitysIco(): array
    {
        return ['Brno' => ['Brno-střed', '44992785', 'qykbwe7'], 'Ostrava' => ['Hošťálkovice', '00845451', 'g47bft8']];
    }

    // districts of Brno or Ostrava use the city's IČO; their own boxes have none
    #[DataProvider('districtsWithTheCitysIco')]
    public function test_match_districtWithTheCitysIcoByName(string $name, string $ico, string $id): void
    {
        $row = self::one(['name' => $name, 'ico' => $ico, 'ds' => $id, 'type' => self::DISTRICT]);

        self::assertSame([$id, MatchRow::BY_NAME, MatchRow::SAME, false], [$row->newDs, $row->method, $row->status, $row->icoMismatch]);
    }

    public function test_match_icoOfAnotherKindOfBoxIgnored(): void
    {
        $school = ['ico' => '05282446', 'type' => 'city'];     // Mateřská škola Pitkovice, e23ma5f

        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Praha 10'] + $school)->status);
        $row = self::one(['name' => 'Plzeň'] + $school);
        self::assertSame(['6iybfxn', MatchRow::BY_NAME, true], [$row->newDs, $row->method, $row->icoMismatch]);
    }

    /** @return array<string, array{string}> */
    public static function sameBox(): array
    {
        return ['trailing space' => ['7c6by6u '], 'leading space' => [' 7c6by6u'], 'capitals' => ['7C6BY6U']];
    }

    #[DataProvider('sameBox')]
    public function test_match_storedBoxComparedTrimmedAndLowercased(string $ds): void
    {
        $row = self::one(['name' => 'Liberec', 'ico' => '00262978', 'ds' => $ds]);

        self::assertSame(MatchRow::SAME, $row->status);
        self::assertSame($ds, $row->oldDs, 'shown as stored');
        self::assertSame('7c6by6u', $row->newDs);
    }

    /** @return array<string, array{string}> */
    public static function icoSpellings(): array
    {
        return [
            'without leading zeros' => ['240044'],
            'spaces around'         => [' 00240044 '],
            'one digit short'       => ['0240044'],
            'inner space'           => ['002 40044'],
            'no-break space'        => ["00240044\u{A0}"],
        ];
    }

    #[DataProvider('icoSpellings')]
    public function test_match_icoNormalised(string $ico): void
    {
        $row = self::one(['name' => 'Běchovice', 'ico' => $ico, 'type' => self::DISTRICT]);

        self::assertSame(['erdb3s9', MatchRow::BY_ICO, false], [$row->newDs, $row->method, $row->icoMismatch]);
    }

    // subtype 10 also holds "Mateřská škola …, Praha 10"; the district's subordinate box shares its IČO
    public function test_match_districtByNameNotTheSchoolNorTheSubordinateBox(): void
    {
        $row = self::one(['name' => 'Praha 10', 'type' => self::DISTRICT]);

        self::assertSame(['irnb7wg', 'Městská část Praha 10', MatchRow::BY_NAME], [$row->newDs, $row->boxName, $row->method]);
    }

    public function test_match_schoolNeverMatchesByName(): void
    {
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Praha 10', 'type' => 'city'])->status);
    }

    public function test_match_zeroPaddedNumber(): void
    {
        $boxes = [self::box('praha01', 'Městská část Praha 1'), self::box('praha10', 'Městská část Praha 10')];

        self::assertSame('praha01', self::one(['name' => 'Praha 01', 'type' => self::DISTRICT], $boxes)->newDs);
        self::assertSame('praha10', self::one(['name' => 'Praha 10', 'type' => self::DISTRICT], $boxes)->newDs);
    }

    /** @return array<string, array{string}> */
    public static function districtsOfOstrava(): array
    {
        return ['bare' => ['Hošťálkovice'], 'spaced hyphen' => ['Ostrava - Hošťálkovice'], 'hyphen' => ['Ostrava-Hošťálkovice']];
    }

    // the register names Ostrava's districts without the city; the box's address has it
    #[DataProvider('districtsOfOstrava')]
    public function test_match_districtWithOrWithoutItsCity(string $name): void
    {
        $row = self::one(['name' => $name, 'type' => self::DISTRICT]);

        self::assertSame(['g47bft8', 'Městský obvod Hošťálkovice', MatchRow::BY_NAME], [$row->newDs, $row->boxName, $row->method]);
        self::assertSame('Ostrava', $row->box?->city);
    }

    /** @return array<string, array{string, string}> */
    public static function districtsWithACity(): array
    {
        return [
            'its city'              => ['Ostrava-Lhotka', 'lhotka1'],
            'its city, spaced'      => ['Opava - Komárov', 'komarov'],
            'another city'          => ['Praha-Lhotka', ''],
            'another numbered city' => ['Praha 4 - Lhotka', ''],
            'another city, Komárov' => ['Brno-Komárov', ''],
        ];
    }

    #[DataProvider('districtsWithACity')]
    public function test_match_cityBeforeTheHyphenMustBeTheBoxsCity(string $name, string $id): void
    {
        $boxes = [self::box('lhotka1', 'Městský obvod Lhotka', '', 'Ostrava'), self::box('komarov', 'Městská část Komárov', '', 'Opava')];

        self::assertSame($id, self::one(['name' => $name, 'type' => self::DISTRICT], $boxes)->newDs);
    }

    // "Slezská Ostrava" is one name - only a hyphen separates the city
    public function test_match_enDashAsAHyphen(): void
    {
        $boxes = [self::box('hrabova', 'Městský obvod Hrabová', '', 'Ostrava')];

        self::assertSame('hrabova', self::one(['name' => 'Ostrava – Hrabová', 'type' => self::DISTRICT], $boxes)->newDs);
    }

    public function test_match_cityPartSplitOnlyAtAHyphen(): void
    {
        $boxes = [self::box('ostrava', 'Městský obvod Ostrava', '', 'Slezská')];

        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Slezská Ostrava', 'type' => self::DISTRICT], $boxes)->status);
    }

    public function test_match_districtWithTheCityWinsOverTheBarePart(): void
    {
        $boxes = [self::box('jih0001', 'Městský obvod Ostrava-Jih', '', 'Ostrava'), self::box('jih0002', 'Městský obvod Jih', '', 'Ostrava')];

        self::assertSame('jih0001', self::one(['name' => 'Ostrava - Jih', 'type' => self::DISTRICT], $boxes)->newDs);
    }

    public function test_match_trailingDotIgnored(): void
    {
        $boxes = [self::box('pardub5', 'Městský obvod Pardubice V.'), self::box('pardub6', 'Městský obvod Pardubice VI')];

        self::assertSame('pardub5', self::one(['name' => 'Pardubice V', 'type' => self::DISTRICT], $boxes)->newDs);
    }

    public function test_match_trailingParentheticalIgnored(): void
    {
        $boxes = [self::box('komarov', 'Městská část Komárov'), self::box('komobec', 'Obec Komárov')];

        $row = self::one(['name' => 'Komárov (orp Opava)', 'type' => self::DISTRICT], $boxes);

        self::assertSame(['komarov', 'Městská část Komárov'], [$row->newDs, $row->boxName]);
        self::assertSame('komobec', self::one(['name' => 'Komárov (okres Tábor)', 'type' => 'city'], $boxes)->newDs);
    }

    public function test_match_spaceOrHyphenAfterTheCity(): void
    {
        $boxes = [self::box('reckovi', 'Městská část Brno Řečkovice a Mokrá Hora')];

        self::assertSame('reckovi', self::one(['name' => 'Brno-Řečkovice a Mokrá Hora', 'type' => self::DISTRICT], $boxes)->newDs);
    }

    public function test_match_noBreakAndDoubleSpaces(): void
    {
        $boxes = [self::box('slezska', "Městský\u{A0}obvod  Slezská\u{A0}Ostrava")];

        self::assertSame('slezska', self::one(['name' => "Slezská  Ostrava", 'type' => self::DISTRICT], $boxes)->newDs);
        self::assertSame('slezska', self::one(['name' => "Slezská\u{A0}Ostrava ", 'type' => self::DISTRICT], $boxes)->newDs);
    }

    /** @return array<string, array{string, string}> */
    public static function legalForms(): array
    {
        return [
            'capital city, capitals' => ['Praha', '48ia97h'],
            'statutory city, capitals' => ['Liberec', '7c6by6u'],
            'statutory city' => ['Plzeň', '6iybfxn'],
            'městys' => ['Nehvizdy', 'bgxbr7u'],
            'the form in the item' => ['Statutární město Brno', 'a7kbrrn'],
        ];
    }

    #[DataProvider('legalForms')]
    public function test_match_municipalityByNameWithoutTheLegalForm(string $name, string $id): void
    {
        $row = self::one(['name' => $name, 'type' => 'city']);

        self::assertSame([$id, MatchRow::BY_NAME, MatchRow::NEW, false], [$row->newDs, $row->method, $row->status, $row->icoMismatch]);
    }

    // "Město Albrechtice" is the town's whole name, "Obec Albrechtice" another municipality
    public function test_match_legalFormInTheNameItself(): void
    {
        $boxes = [self::box('mestoal', 'Město Město Albrechtice'), self::box('obecalb', 'Obec Albrechtice')];

        self::assertSame('mestoal', self::one(['name' => 'Město Albrechtice', 'type' => 'city'], $boxes)->newDs);
        self::assertSame('obecalb', self::one(['name' => 'Albrechtice', 'type' => 'city'], $boxes)->newDs);
    }

    public function test_match_sameNameIsAmbiguous(): void
    {
        $row = self::one(['name' => 'Adamov', 'ds' => 'xx3bzck', 'type' => 'city']);

        self::assertSame([MatchRow::AMBIGUOUS, MatchRow::BY_NAME, '', '', null], [$row->status, $row->method, $row->newDs, $row->boxName, $row->box]);
        self::assertSame(
            [['xx3bzck', 'Město Adamov'], ['jm6amhn', 'Obec Adamov'], ['q7bb677', 'Obec Adamov']],
            array_map(static fn(Box $box): array => [$box->id, $box->name], $row->candidates),
        );
    }

    public function test_match_icoDecidesBetweenSameNames(): void
    {
        $row = self::one(['name' => 'Adamov', 'ico' => '00581160', 'type' => 'city']);

        self::assertSame(['q7bb677', MatchRow::BY_ICO, []], [$row->newDs, $row->method, $row->candidates]);
    }

    public function test_match_twoMastersWithTheIcoAreAmbiguous(): void
    {
        $boxes = [self::box('twin001', 'Obec Dvojí', '12345678'), self::box('twin002', 'Obec Dvojí II', '12345678')];

        $row = self::one(['name' => 'Dvojí', 'ico' => '12345678', 'type' => 'city'], $boxes);

        self::assertSame([MatchRow::AMBIGUOUS, MatchRow::BY_ICO], [$row->status, $row->method]);
        self::assertSame(['twin001', 'twin002'], array_column($row->candidates, 'id'));
    }

    /** @return array<string, array{string, string}> */
    public static function subordinates(): array
    {
        return ['městys Cerhenice' => ['00235300', 'eerak7a'], 'městys Nehvizdy' => ['00240524', 'bgxbr7u'], 'Brno' => ['44992785', 'a7kbrrn']];
    }

    // the city police and a city organisation share the IČO, but are not the city's box
    #[DataProvider('subordinates')]
    public function test_match_icoFindsTheMasterOnly(string $ico, string $master): void
    {
        self::assertSame($master, self::one(['name' => 'x', 'ico' => $ico, 'type' => 'city'])->newDs);
    }

    public function test_match_subordinateBoxOfSubtype10Ignored(): void
    {
        $boxes = [new Box('subordi', '87654321', '', 'Obec Podřízená', 'OVM', 10, false, false)];

        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Podřízená', 'ico' => '87654321', 'type' => 'city'], $boxes)->status);
    }

    /** @return array<string, array{string, bool}> IČO, whether it is a well-formed one */
    public static function unusableIco(): array
    {
        return [
            'unknown to the register' => ['12345678', true],
            'letters'                 => ['CZ00235300', false],
            'nine digits'             => ['000235300', false],
            'sign'                    => ['-235300', false],
            'not UTF-8'               => ["0023\xFF5300", false],
        ];
    }

    // the name then finds a box with another IČO, which the page shows next to the item's
    #[DataProvider('unusableIco')]
    public function test_match_unusableIcoFallsBackToTheName(string $ico, bool $mismatch): void
    {
        $row = self::one(['name' => 'Cerhenice', 'ico' => $ico, 'type' => 'city']);

        self::assertSame(['eerak7a', MatchRow::BY_NAME, $mismatch], [$row->newDs, $row->method, $row->icoMismatch]);
    }

    public function test_match_districtAndMunicipalityKeptApart(): void
    {
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Brno-střed', 'type' => 'city'])->status);
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Adamov', 'type' => self::DISTRICT])->status);
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => 'Brno', 'type' => self::DISTRICT])->status);
    }

    public function test_match_unmatched(): void
    {
        $row = self::one(['name' => 'Nikde', 'ico' => '', 'ds' => 'abcdefg', 'type' => 'city']);

        self::assertEquals(new MatchRow('i', 'Nikde', 'city', 'abcdefg', null, '', MatchRow::UNMATCHED), $row);
        self::assertSame(['', ''], [$row->newDs, $row->boxName]);
    }

    // "Obec -" is only a legal form; its key '' must not pair with an item without a name
    public function test_match_emptyNameNeverMatches(): void
    {
        $boxes = [self::box('onlyobe', 'Obec -')];

        self::assertSame(MatchRow::UNMATCHED, self::one([], $boxes)->status);
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => '-', 'type' => 'city'], $boxes)->status);
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => '(bez názvu)', 'type' => 'city'], $boxes)->status);
    }

    public function test_match_nameNotUtf8Unmatched(): void
    {
        self::assertSame(MatchRow::UNMATCHED, self::one(['name' => "Brno\xFF", 'type' => 'city'])->status);
    }

    // the pattern for trailing dots is quadratic: 50 000 dots before a letter took 28 s without the PCRE JIT
    public function test_match_overlongNameUnmatchedAtOnce(): void
    {
        $jit = (string) ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            $dots  = str_repeat('.', 50000);
            $boxes = [self::box('dotted1', "Obec Tečky{$dots}x"), self::box('tecky01', 'Obec Tečky')];
            $start = microtime(true);
            $row   = self::one(['name' => "Tečky{$dots}x", 'type' => 'city'], $boxes);

            self::assertLessThan(1.0, microtime(true) - $start);
            self::assertSame(MatchRow::UNMATCHED, $row->status);
            $longest = 'Tečky' . str_repeat('.', 249);
            self::assertSame(255, strlen($longest));
            self::assertSame('tecky01', self::one(['name' => $longest, 'type' => 'city'], $boxes)->newDs, 'the longest name of the register');
        } finally {
            ini_set('pcre.jit', $jit);
        }
    }

    // one broken value must not stop the run for all the others
    public function test_match_valuesThatAreNotTextTolerated(): void
    {
        $report = self::report([
            'number'     => ['name' => 'Cerhenice', 'ico' => 235300, 'ds' => null, 'type' => ['city']],
            'array'      => ['name' => ['Plzeň'], 'ico' => new \stdClass()],
            'string'     => 'Plzeň',
            'bool'       => ['name' => 'Plzeň', 'ico' => true, 'type' => false],
            'stringable' => ['name' => new class {
                public function __toString(): string
                {
                    return 'Nehvizdy';
                }
            }],
        ]);

        self::assertSame(['eerak7a', '', '', '6iybfxn', 'bgxbr7u'], array_column($report->rows, 'newDs'));
        self::assertSame([MatchRow::BY_ICO, '', '', MatchRow::BY_NAME, MatchRow::BY_NAME], array_column($report->rows, 'method'));
        self::assertSame(['', '', '', '', ''], array_column($report->rows, 'type'));
        self::assertFalse($report->rows[3]->icoMismatch, 'true is no IČO "1"');
    }

    public function test_match_rowsInItemOrderWithTheirIds(): void
    {
        $report = self::report(['z' => ['name' => 'Plzeň'], 7 => ['name' => 'Adamov'], 'a' => ['name' => 'Nikde']]);

        self::assertSame(['z', 7, 'a'], array_column($report->rows, 'itemId'));
    }

    public function test_match_registerReadOnce(): void
    {
        $report = self::report(['a' => ['name' => 'Plzeň'], 'b' => ['ico' => '00235300']], SdsList::fromXml(self::FIXTURE));

        self::assertSame(['6iybfxn', 'eerak7a'], array_column($report->rows, 'newDs'));
    }

    /**
     * @param array<string, mixed> $item
     * @param ?list<Box>           $boxes the fixture when null
     */
    private static function one(array $item, ?array $boxes = null): MatchRow
    {
        return self::report(['i' => $item], $boxes)->rows[0];
    }

    /**
     * @param array<array-key, mixed> $items
     * @param ?iterable<Box>          $boxes the fixture when null
     */
    private static function report(array $items, ?iterable $boxes = null): MatchReport
    {
        return (new Matcher([self::DISTRICT]))->match($boxes ?? self::$fixture, $items);
    }

    /** a master authority box, the kind matched */
    private static function box(string $id, string $name, string $ico = '', string $city = ''): Box
    {
        return new Box($id, $ico, '', $name, 'OVM', 10, true, false, $city);
    }
}
