<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA_Field;
use AA_Fields;
use AA_Module;
use AA_Slice;

/** A slice of the given fields and settings (reading_password, historylog, ...), registered where
 *  AA_Slice::getModule() finds it - no database. */
final class PropertySlice extends AA_Slice
{
    /** @param array<string, mixed> $props */
    public function __construct(string $slice_id, private AA_Fields $slice_fields, private array $props)
    {
        // no parent::__construct() - it would load the slice from the database
        $this->module_id = $slice_id;
        AA_Module::$_modules[$slice_id] = $this;
    }

    /** the registry is process-wide */
    public static function forget(string $slice_id): void
    {
        unset(AA_Module::$_modules[$slice_id]);
    }

    public function getId(): string
    {
        return $this->module_id;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function deleted()
    {
        return false;
    }

    public function getField($field_id): ?AA_Field
    {
        return $this->slice_fields->getField($field_id);
    }

    public function isField($fname): bool
    {
        return $this->slice_fields->isField($fname);
    }

    public function getFields($dynamic_fields = false): AA_Fields
    {
        return $this->slice_fields;
    }

    public function getProperty($prop)
    {
        return $this->props[$prop] ?? null;
    }
}
