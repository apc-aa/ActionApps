<?php

declare(strict_types=1);

namespace AA\Central\Package\Model;

/** One module of a package: a wrapper element of design balicek-v1.md § 4, its table rows (§ 6),
 *  objects (§ 5.4), site tree (§ 6.3) and items.
 *
 *  A row is `DB column => value` with only the columns a package carries - Schema::rowColumns() plus
 *  the key columns of Schema::rowAttributes() (`view.id`, `relation.destination_id`, ...); the module
 *  column and installation columns are the collector's to drop. Value null = SQL NULL.
 *
 *  Object properties and item values cannot be NULL (<p>, <v> have no null="true"): Writer throws.
 *  Decision for the collector (C4.3 part 3): NULL item values of old rows (GetItemContent(),
 *  include/util.php:1080 - `number` NULL) are mapped to ''. */
final readonly class Module
{
    public const SITE   = 'site-module';
    public const SLICE  = 'slice-module';
    public const ALERTS = 'alerts-module';

    /**
     * @param string                                                  $type     self::SITE | SLICE | ALERTS
     * @param string                                                  $id       32 hex, unpacked
     * @param array<string, list<array<string, string|int|float|null>>> $rows   row element (a key of
     *                                                                          Schema::rowTables(), e.g. module, slice, view, uses)
     *                                                                          => rows; `module` and `slice`/`site` have one row
     * @param array<string, mixed>|null                               $settings AA_Modulesettings_* properties (see $objects
     *                                                                          `props`, scalars only); null = no <settings>
     * @param list<array<string, mixed>>                              $objects  AA_Aliasfunc, AA_Set: {id, class, name?, props}.
     *                                                                          props: name => scalar (index 0) or [index => scalar
     *                                                                          | subobject {id, class, props}]
     * @param array<string, mixed>|null                               $spotTree site tree in the canonical form of
     *                                                                          sitetree::toArray(): {start_id, spots: list of
     *                                                                          {id, n, p, f?, v?, c?, po?, ch?}} - v/c keyed
     *                                                                          arrays, po/ch lists, null = absent
     * @param list<array<string, mixed>>                              $items    {id, content: GetItemContent() form
     *                                                                          field id => [key => {value, flag?}]}, the key a
     *                                                                          position or a translation number (>= AA_Value::MAX_INDEX)
     * @param string|null                                             $doc      author's note shown before installation
     */
    public function __construct(
        public string $type,
        public string $id,
        public array $rows,
        public ?array $settings = null,
        public array $objects = [],
        public ?array $spotTree = null,
        public array $items = [],
        public ?string $doc = null,
    ) {}
}
