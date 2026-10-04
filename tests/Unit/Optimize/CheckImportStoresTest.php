<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\CheckResult;
use AA\Import\Definition;
use AA\Import\StreamState;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckImportStores;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/** CheckImportStores: the stream states and catalogs that cannot be read - too big a read for every
 *  load of State Check, so an extended check. */
final class CheckImportStoresTest extends TestCase
{
    use BatchRunTables;

    private const SLICE = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';
    private const DEF   = '0123456789abcdef0123456789abcdef';
    private const DEF2  = 'fedcba9876543210fedcba9876543210';

    public function test_extendedOnly(): void
    {
        $this->assertSame([AbstractOptimize::CHECK_EXTENDED], CheckImportStores::types());
    }

    public function test_readable_ok(): void
    {
        self::stores(self::DEF, '<b>prices</b>');

        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, (new CheckImportStores())->findings()));
    }

    public function test_damaged_warningEach(): void
    {
        self::stores(self::DEF, '<b>prices</b>');
        self::stores(self::DEF2, 'rates');
        self::storedSet(StreamState::idOf(self::DEF), 'streams', '{"s1": {"v": "2026"');
        self::storedSet(Catalog::idOf(self::DEF2), 'entries', '{"a": ["Alpha"');

        $findings = (new CheckImportStores())->findings();

        $this->assertSame(['state:' . self::DEF, 'catalog:' . self::DEF2], array_map(static fn(Finding $f): string => $f->key, $findings));
        $this->assertSame([Finding::WARNING, Finding::WARNING], array_map(static fn(Finding $f): string => $f->severity, $findings));
        $this->assertSame(safe('<b>prices</b>'), $findings[0]->evidence[0][_m('Import')]);
    }

    /** a damaged check() is reported by CheckImports */
    public function test_damagedCheck_leftToCheckImports(): void
    {
        self::stores(self::DEF, 'prices');
        self::storedSet(StreamState::idOf(self::DEF), 'check', '[[[[1]]]]');

        $this->assertSame(Finding::OK, (new CheckImportStores())->findings()[0]->severity);
    }

    public function test_noImport_ok(): void
    {
        $this->assertSame(Finding::OK, (new CheckImportStores())->findings()[0]->severity);
    }

    private static function stores(string $def, string $name): void
    {
        $definition = new Definition();
        $definition->setNew($def, self::SLICE, $name);
        $definition->setProperty('source', 'custom:none');
        self::assertSame($def, $definition->save());
        $state = StreamState::create($def);
        $state->record('s1', ['v' => '2026']);
        $state->recordCheck(new CheckResult(true, '', ''), self::NOW);
        $state->store();
        $catalog = Catalog::create($def);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')]), self::NOW);
        $catalog->store();
    }
}
