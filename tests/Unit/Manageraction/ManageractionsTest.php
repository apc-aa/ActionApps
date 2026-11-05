<?php

declare(strict_types=1);

namespace Tests\Unit\Manageraction;

use AA\Manageraction\Manageractions;
use AA\Manageraction\Table_Row_Delete;
use PHPUnit\Framework\TestCase;

/**
 * The action id comes from the POST, so an unknown one is an ordinary input, not a bug.
 */
final class ManageractionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Legacy/fixtures/object_form_fixtures.php';
    }

    public function test_getAction_knownIdReturnsTheAction(): void
    {
        $actions = new Manageractions();
        $delete  = new Table_Row_Delete('DeleteTRow');
        $actions->addAction($delete);

        $this->assertSame($delete, $actions->getAction('DeleteTRow'));
    }

    public function test_getAction_unknownIdIsNullWithoutWarning(): void
    {
        $actions = new Manageractions();
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $this->assertNull($actions->getAction('NoSuchAction'));
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);
    }
}
