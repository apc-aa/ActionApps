<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\RimWidget;
use AA_Content;
use AA_Property;
use PHPUnit\Framework\TestCase;

/**
 * The related-items widget shows its buttons below the list; only the file upload button (F) needs
 * a setup script.
 */
final class RimWidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_getButtonsDown_withoutUploadButtonRaisesNoWarning(): void
    {
        $widget   = new RimWidget(['show_buttons' => 'R']);
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $html = $widget->getButtonsDown(new AA_Property('rel', 'Rel'), new AA_Content(), 'aa-n1_x-rel', str_repeat('a1', 16), 'relation.......1', str_repeat('b2', 16));
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertStringStartsWith('<script>  </script><article>', $html);
        self::assertStringContainsString("AA_RimSearch(this, 'aa-n1_x-rel'", $html);
    }
}
