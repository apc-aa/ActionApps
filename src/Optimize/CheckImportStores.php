<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Import\Catalog;
use AA\Import\Definition;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;

/** A stream state or catalog whose JSON cannot be read acts as empty: the next run of changed data
 *  imports everything, the import detail lists every stream as missing. Reading them means decoding up
 *  to megabytes per import, so this runs on request and in the daily snapshot, not with CheckImports. */
class CheckImportStores extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Import stream states and catalogs');
    }

    public static function description(): string
    {
        return _m('Reads the stored stream versions and catalogs of the imports and lists those that cannot be read.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'feeds';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $defs = DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'aa_type'], ['value', Definition::class]]);
        $defs = array_values(array_filter(array_map('strval', $defs), static fn(string $id): bool => preg_match('/^[0-9a-f]{32}\z/', $id) === 1));
        if (!$defs) {
            return [new Finding(Finding::OK, _m('No import is defined'))];
        }
        $names = DB_AA::select(['object_id' => 'value'], 'SELECT object_id, value FROM `object_text`', [['object_id', $defs], ['property', 'aa_name']]);
        $findings = [];
        foreach ($defs as $def) {
            $about = [_m('Import') => safe((string) ($names[$def] ?? '') ?: $def)];
            // one record in memory at a time
            $state = StreamState::forDefinition($def);
            if ($state) {
                // its stored check() is CheckImports' - small enough for every load
                $state->streams();
                if ($state->damaged()) {
                    $findings[] = new Finding(Finding::WARNING, _m('The stream state of an import cannot be read'), evidence: [$about], advice: _m('Its versions read as empty, so the next run of changed data imports everything; that run stores the state again.'), key: "state:$def");
                }
            }
            $catalog = Catalog::forDefinition($def);
            if ($catalog) {
                $catalog->entry('');     // decodes the entries without building them
                if ($catalog->damaged()) {
                    $findings[] = new Finding(Finding::WARNING, _m('The catalog of an import source cannot be read'), evidence: [$about], advice: _m('It reads as empty, so the import detail lists every stream as missing at the source. Check the source again to fill it anew.'), key: "catalog:$def");
                }
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('The stream states and catalogs of %1 imports can be read', [count($defs)]))];
    }
}
