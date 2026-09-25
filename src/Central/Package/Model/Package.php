<?php

declare(strict_types=1);

namespace AA\Central\Package\Model;

/** An aa-app package in memory: what the export collects, Writer turns into XML and the reader
 *  returns. Plain data - all texts already in UTF-8, ids unpacked. The order of the lists does not
 *  matter: Writer sorts everything. `meta/tables` is not here - Writer derives it from Schema. */
final readonly class Package
{
    /**
     * @param string                $name           meta/name
     * @param array<string, string> $source         meta/source attributes: url, aa-version, exported
     *                                              (xs:dateTime, e.g. gmdate('Y-m-d\TH:i:s\Z')) required;
     *                                              aa-build, site optional
     * @param list<Module>          $modules        at least one (XSD)
     * @param list<ConstantGroup>   $constantGroups
     * @param string|null           $publish        no | users | all
     * @param string|null           $requires       meta/requires/@aa-version
     * @param bool                  $withItems      meta/contents/@items - modules may carry items only then
     */
    public function __construct(
        public string $name,
        public array $source,
        public array $modules,
        public array $constantGroups = [],
        public ?string $description = null,
        public ?string $version = null,
        public ?string $publish = null,
        public ?string $requires = null,
        public bool $withItems = false,
        public ?string $doc = null,
    ) {}
}
