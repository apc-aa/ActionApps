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
