<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** A slice of the target a slice of the package may go into (ExistingSlices): what the planner compares
 *  the package with. Texts are UTF-8, as the package's - converted from the slice's charset; a value the
 *  charset cannot read is RawBytes. */
final readonly class ExistingSlice
{
    /**
     * @param string                              $id       32 lower-case hex
     * @param string                              $name     module.name
     * @param string                              $type     slice.type - ItemLint::READER_SLICE for Reader Management
     * @param string                              $langFile module.lang_file
     * @param string                              $charset  of the lang_file; '' = unknown
     * @param array<string, array<string, mixed>> $fields   field id => its row
     * @param array<int, array<string, mixed>>    $views    view id => {name, field3, type, odd}
     * @param array<int, array<string, mixed>>    $emails   e-mail id => {description}
     * @param array<string, array{string, string}> $objects top-level objects the slice owns: id => [class, name]
     * @param list<string>                        $settings the settings fields (`_...`) with a value - a field the
     *                                                      slice has not, too: a value outlives its field
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $langFile,
        public string $charset,
        public array $fields,
        public array $views,
        public array $emails,
        public array $objects,
        public array $settings = [],
    ) {}
}
