<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Field;
use AA_Fields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Field ids arrive from requests, imports and remote AAs (include/field.class.php): a wrong one
 * is ordinary input and must be told apart without a warning.
 */
final class FieldIdValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ids(): array
    {
        return [
            'content field'     => ['headline........', true],
            'numbered slot'     => ['text...........1', true],
            'alerts field'      => ['alerts1....abcde', true],
            'too short'         => ['headline.......', false],
            'no dot'            => ['headlineheadline', false],
            'trailing newline'  => ["headline.......\n", false],
            'upper-case prefix' => ['Headline........', false],
        ];
    }

    #[DataProvider('ids')]
    public function test_isValidFieldId(string $id, bool $valid): void
    {
        $this->assertSame($valid, AA_Field::isValidFieldId($id));
    }

    public function test_isField_unknownIdIsFalseWithoutWarning(): void
    {
        $fields = new AA_Fields(str_repeat('a1', 16));
        // all fields "loaded" and none found - as for a slice without that field
        (new ReflectionProperty(AA_Fields::class, 'loaded_all'))->setValue($fields, true);

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $this->assertFalse($fields->isField('headline........'));
            $this->assertFalse($fields->isField('no such id'));
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);
    }
}
