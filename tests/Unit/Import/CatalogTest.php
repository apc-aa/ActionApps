<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\Stream;
use AA\Import\StreamState;
use AA\IO\SourceRefused;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;

// the in-memory object_text, the config constants and the stored() helpers of the batch run tests
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

final class CatalogTest extends TestCase
{
    use BatchRunTables;

    private const DEF  = '0123456789abcdef0123456789abcdef';
    private const TIME = 1_900_000_000;

    public function test_catalog_tooBig(): void
    {
        // entries of 1000-byte names up to just under the limit, then one that fills it exactly
        $entries = [];
        for ($i = 0; $i < 5140; $i++) {
            $entries[] = new CatalogEntry(sprintf('k%05d', $i), str_repeat('a', 1000));
        }
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage($entries, 'p2'), self::TIME);
        $used = strlen(self::storedEntries($catalog));
        $fill = Catalog::MAX_BYTES - $used - strlen(',"k99999":["","",[]]');
        $catalog->addPage(new CatalogPage([new CatalogEntry('k99999', str_repeat('b', $fill))], 'p3'), self::TIME);
        $catalog->store();
        self::assertSame(Catalog::MAX_BYTES, strlen(self::stored($catalog->getId())['entries']), 'exactly at the limit');

        $again = Catalog::forDefinition(self::DEF);
        self::assertNotNull($again);
        try {
            $again->addPage(new CatalogPage([new CatalogEntry('k99999', str_repeat('b', $fill + 1)), new CatalogEntry('new', '')]), self::TIME);
            self::fail('Over the limit');
        } catch (SourceRefused $e) {
            self::assertSame('CATALOG_TOO_BIG', $e->reason);
        }
        self::assertNull($again->entry('new'), 'nothing of the page that tipped it is added');
        self::assertSame(str_repeat('b', $fill), $again->entry('k99999')?->name);
        self::assertNull($again->filledAt());
    }

    public function test_catalog_tooManyEntries(): void
    {
        $entries = [];
        for ($i = 0; $i <= Catalog::MAX_ENTRIES; $i++) {
            $entries[] = new CatalogEntry((string) $i, '');
        }
        $catalog = Catalog::create(self::DEF);
        try {
            $catalog->addPage(new CatalogPage($entries), self::TIME);
            self::fail('Over the limit');
        } catch (SourceRefused $e) {
            self::assertSame('CATALOG_TOO_BIG', $e->reason);
        }
        self::assertSame([], $catalog->entries());

        array_pop($entries);
        $catalog->addPage(new CatalogPage($entries), self::TIME);
        self::assertCount(Catalog::MAX_ENTRIES, $catalog->entries());
    }

    public function test_catalog_diff(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha'), new CatalogEntry('b', 'Beta'), new CatalogEntry('7', 'Seven'), new CatalogEntry('c', "Ce\x01")]), self::TIME);

        $diff = $catalog->diff([
            new Stream('s1', 'Alpha', [], 'a'),
            new Stream('s2', 'Beta (old)', [], 'b'),
            new Stream('s3', 'Gone', [], 'gone'),
            new Stream('s4', 'Not from the catalog', []),
            new Stream('s5', "Ce\x01", [], 'c'),
        ]);

        self::assertSame(['new' => ['7'], 'missing' => ['s3'], 'renamed' => ['s2']], $diff, 'the label is compared cleaned, as the name was stored');
    }

    public function test_diff_whileFillingNothingMissing(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha'), new CatalogEntry('b', 'Beta')], 'p2'), self::TIME);

        $diff = $catalog->diff([new Stream('s1', 'Alpha (old)', [], 'a'), new Stream('s3', 'Later', [], 'later')]);

        self::assertSame(['new' => ['b'], 'missing' => [], 'renamed' => ['s1']], $diff);
    }

    /** the streams of the check that listed the catalog, kept with it for diff() - only those whose
     *  catalog key an entry could have */
    public function test_listStreams_keptForDiff(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->listStreams([
            new Stream('s1', 'Alpha', ['p' => 'x'], 'a'),
            new Stream('s2', 'Beta', [], "b\x01"),
            new Stream('s3', 'Not from the catalog', []),
            new Stream('7', "Se\x01ven", [], '7'),
            new Stream('s5', 'Long', [], str_repeat('k', 256)),
            new Stream('s6', 'Broken', [], "\xff"),
        ]);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha'), new CatalogEntry('7', 'Seven'), new CatalogEntry('n', 'New')], 'p2'), self::TIME);
        $catalog->store();
        $read = Catalog::forDefinition(self::DEF);
        self::assertNotNull($read);
        $read->addPage(new CatalogPage([]), self::TIME);
        $read->store();

        $streams = Catalog::forDefinition(self::DEF)?->streams() ?? [];

        self::assertSame([['s1', 'Alpha', 'a'], ['7', 'Se ven', '7']], array_map(static fn(Stream $s): array => [$s->key, $s->label, $s->catalogKey], $streams), 'labels cleaned, kept by later pages');
        self::assertSame(['new' => ['n'], 'missing' => [], 'renamed' => ['7']], Catalog::forDefinition(self::DEF)?->diff($streams));
    }

    public function test_listStreams_capped(): void
    {
        $many = array_map(static fn(int $i): Stream => new Stream("s$i", '', [], "k$i"), range(0, Catalog::MAX_ENTRIES));
        $big  = array_map(static fn(int $i): Stream => new Stream("s$i", str_repeat('a', 1000), [], "k$i"), range(0, 5300));
        foreach (['entries' => $many, 'bytes' => $big] as $case => $streams) {
            try {
                Catalog::create(self::DEF)->listStreams($streams);
                self::fail("Over the limit of $case");
            } catch (SourceRefused $e) {
                self::assertSame('CATALOG_TOO_BIG', $e->reason, $case);
            }
        }
        $catalog = Catalog::create(self::DEF);
        $catalog->listStreams(array_slice($many, 0, Catalog::MAX_ENTRIES));
        self::assertCount(Catalog::MAX_ENTRIES, $catalog->streams());
    }

    /** whatever is stored at the streams, only usable ones come back */
    public function test_streams_damagedLeftOut(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->store();
        self::storedSet($catalog->getId(), 'streams', '{"<x>":["a","A"],"s1":"text","s2":["b"],"s3":["c","C"],"s4":[1,"C"],"s5":["e",2]}');

        self::assertSame([['s2', '', 'b'], ['s3', 'C', 'c']], array_map(static fn(Stream $s): array => [$s->key, $s->label, $s->catalogKey], Catalog::forDefinition(self::DEF)?->streams() ?? []));

        self::storedSet($catalog->getId(), 'streams', 'not json');
        $read = Catalog::forDefinition(self::DEF);
        self::assertSame([], $read?->streams());
        self::assertTrue($read->damaged());
    }

    /** a page of the listing makes the entries of that page only */
    public function test_entriesAt_onePage(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage(array_map(static fn(int $i): CatalogEntry => new CatalogEntry("k$i", "N$i", ['u' => "$i"]), range(0, 9))), self::TIME);

        self::assertSame(10, $catalog->count());
        self::assertEquals([new CatalogEntry('k3', 'N3', ['u' => '3']), new CatalogEntry('k4', 'N4', ['u' => '4'])], $catalog->entriesAt(3, 2));
        self::assertSame(['k8', 'k9'], array_map(static fn(CatalogEntry $e): string => $e->key, $catalog->entriesAt(8, 5)));
        self::assertSame([], $catalog->entriesAt(10, 5));
    }

    public function test_catalog_pagesAcrossSteps(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('0', 'Zero', ['unit' => '%'], '2024-01-01')], 'p2'), self::TIME);
        $catalog->store();
        self::assertNull(Catalog::forDefinition(self::DEF)?->filledAt(), 'still filling');

        $catalog = Catalog::forDefinition(self::DEF);
        self::assertNotNull($catalog);
        $catalog->addPage(new CatalogPage([new CatalogEntry('1', 'One')]), self::TIME + 5);
        $catalog->store();

        $read = Catalog::forDefinition(self::DEF);
        self::assertNotNull($read);
        self::assertSame(self::TIME + 5, $read->filledAt());
        self::assertEquals([new CatalogEntry('0', 'Zero', ['unit' => '%'], '2024-01-01'), new CatalogEntry('1', 'One')], $read->entries());
        self::assertEquals(new CatalogEntry('1', 'One'), $read->entry('1'));
        self::assertNull($read->entry('2'));
        self::assertStringStartsWith('{"0":', self::stored($read->getId())['entries'], 'numeric keys stay an object');
        self::assertSame(self::DEF, self::stored($read->getId())['aa_owner']);
    }

    public function test_create_startsAFreshFill(): void
    {
        $old = Catalog::create(self::DEF);
        $old->addPage(new CatalogPage([new CatalogEntry('old', 'Old')]), self::TIME);
        $old->store();

        $new = Catalog::create(self::DEF);
        $new->addPage(new CatalogPage([new CatalogEntry('new', 'New')]), self::TIME);
        $new->store();

        self::assertSame(['new'], array_map(static fn(CatalogEntry $e): string => $e->key, Catalog::forDefinition(self::DEF)?->entries() ?? []));
    }

    public function test_addPage_textsFromSourceCleaned(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('k', "Na\xffme\u{202E}\t" . str_repeat('x', 2000), ["un\xffit" => "k\xffg", 'count' => 5, 'share' => 0.5, 'final' => true, 'none' => null], "20\xff24")]), self::TIME);
        $catalog->store();

        $entry = Catalog::forDefinition(self::DEF)?->entry('k');
        self::assertNotNull($entry);
        // the substitute is mb_substitute_character's
        $clean = static fn(string $s): string => mb_scrub($s, 'UTF-8');
        self::assertSame([$clean("un\xffit") => $clean("k\xffg"), 'count' => '5', 'share' => '0.5', 'final' => '1', 'none' => ''], $entry->meta, 'a JSON null is an empty value');
        self::assertStringStartsWith($clean("Na\xffme") . ' x', $entry->name, 'bidi override gone, tab a space');
        self::assertSame(1000 - strlen("\u{202E}"), strlen($entry->name), 'cut to 1000 bytes, then the override goes');
        self::assertSame($clean("20\xff24"), $entry->updated);
    }

    /** why the source would not add an entry is kept with it, cleaned as its other texts; an entry it
     *  would add is stored as before, so catalogs stored earlier read the same */
    public function test_addPage_refusalKept(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('ok', 'Fine'), new CatalogEntry('no', 'Bad', [], '2024', "empty\x07 per\xff")]), self::TIME);

        self::assertStringStartsWith('{"ok":["Fine","",[]],"no":["Bad","2024",[],"empty', self::storedEntries($catalog));
        $read = Catalog::forDefinition(self::DEF);
        self::assertNotNull($read);
        self::assertSame('', $read->entry('ok')?->refusal);
        self::assertSame(mb_scrub("empty  per\xff", 'UTF-8'), $read->entry('no')?->refusal, 'a control character a space');
        self::assertSame(['', mb_scrub("empty  per\xff", 'UTF-8')], array_map(static fn(CatalogEntry $e): string => $e->refusal, $read->entriesAt(0, 2)));

        $long = Catalog::create(self::DEF);
        $long->addPage(new CatalogPage([new CatalogEntry('k', 'Name', [], '', str_repeat('r', 2000))]), self::TIME);
        self::assertSame(str_repeat('r', 1000), $long->entry('k')?->refusal, 'cut as the other texts');

        self::storedSet($read->getId(), 'entries', '{"old":["Old","",[]]}');
        self::assertEquals(new CatalogEntry('old', 'Old'), Catalog::forDefinition(self::DEF)?->entry('old'));
    }

    public function test_store_unicodeRoundTripsAsAscii(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('😀', 'Příliš 😀', ['jednotka' => '€'])]), self::TIME);
        $catalog->store();

        self::assertMatchesRegularExpression('/^[\x20-\x7e]*$/', self::stored($catalog->getId())['entries'], 'object_text may be cp1250 or utf8mb3');
        self::assertEquals(new CatalogEntry('😀', 'Příliš 😀', ['jednotka' => '€']), Catalog::forDefinition(self::DEF)?->entry('😀'));
    }

    public function test_addPage_refusesBadKey(): void
    {
        foreach (['', "k\xff", str_repeat('k', 256), "k\x00", "k\u{2066}"] as $key) {
            try {
                Catalog::create(self::DEF)->addPage(new CatalogPage([new CatalogEntry($key, 'Name')]), self::TIME);
                self::fail('Key accepted');
            } catch (SourceRefused $e) {
                self::assertSame('SOURCE', $e->reason);
            }
        }
    }

    public function test_addPage_refusesNestedMeta(): void
    {
        $catalog = Catalog::create(self::DEF);
        try {
            $catalog->addPage(new CatalogPage([new CatalogEntry('ok', 'Fine'), new CatalogEntry('k', 'Name', ['deep' => ['x']])]), self::TIME);
            self::fail('Nested meta accepted');
        } catch (SourceRefused $e) {
            self::assertSame('SOURCE', $e->reason);
        }
        self::assertSame([], $catalog->entries());
    }

    public function test_damagedJson_actsEmpty(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('k', 'Name')]), self::TIME);
        $catalog->store();
        self::storedSet($catalog->getId(), 'entries', '{"k":["Name"');

        $read = Catalog::forDefinition(self::DEF);
        self::assertNotNull($read);
        self::assertSame([], $read->entries());
        self::assertTrue($read->damaged());
    }

    public function test_store_noOrphanRowsAfterDelete(): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->store();
        Catalog::deleteFor(self::DEF);
        self::assertNull(Catalog::forDefinition(self::DEF));
        $catalog->addPage(new CatalogPage([new CatalogEntry('k', 'Name')]), self::TIME);
        $catalog->store();

        $stored = self::stored($catalog->getId());
        self::assertSame([Catalog::class, self::DEF], [$stored['aa_type'] ?? null, $stored['aa_owner'] ?? null]);
    }

    public function test_forDefinition_ownRecordOnly(): void
    {
        self::assertNull(Catalog::forDefinition(self::DEF));
        StreamState::create(self::DEF)->store();
        self::assertNull(Catalog::forDefinition(self::DEF), 'the state of the same definition is another record');
        self::assertNotSame(StreamState::idOf(self::DEF), Catalog::idOf(self::DEF));
    }

    /** stores the catalog and returns its entries as stored */
    private static function storedEntries(Catalog $catalog): string
    {
        $catalog->store();
        return self::stored($catalog->getId())['entries'];
    }
}
