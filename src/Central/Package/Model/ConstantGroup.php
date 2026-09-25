<?php

declare(strict_types=1);

namespace AA\Central\Package\Model;

/** A constant group of a package. Rows as in Module: element
 *  `constant_slice` (at most one row) and `constant` => rows of DB columns, `constant.id` included. */
final readonly class ConstantGroup
{
    /**
     * @param string                                                  $name  group id (constant_slice.group_id)
     * @param string                                                  $owner the owning slice of the package, '' = none
     * @param array<string, list<array<string, string|int|float|RawBytes|null>>> $rows
     */
    public function __construct(
        public string $name,
        public string $owner = '',
        public array $rows = [],
    ) {}
}
