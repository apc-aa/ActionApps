<?php

declare(strict_types=1);

/**
 * {mailbulk} is a thin door to AA\Mailbulk\Gate: the control, or one value for a listing of one's own.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA\Mailbulk\Gate;
use AA_Stringexpand_Mailbulk;
use AA_Stringexpander;
use PHPUnit\Framework\TestCase;

final class StringexpandMailbulkTest extends TestCase
{
    private const LIST = 'ab00000000000000000000000000000c';
    private const ITEM = 'cd00000000000000000000000000000e';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        foreach (['AA_INSTAL_URL' => 'http://localhost/aa/', 'AA_INSTAL_PATH' => '/aa/'] as $k => $v) {
            if (!defined($k)) {
                define($k, $v);
            }
        }
    }

    // the requires of the page live in the expander - each test starts a page of its own
    protected function setUp(): void
    {
        (new \ReflectionProperty(AA::class, 'stringexpander'))->setValue(null, new AA_Stringexpander());
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(AA::class, 'stringexpander'))->setValue(null, null);
    }

    public function test_expand_control_goesToTheGate(): void
    {
        $command = self::command(control: fn(string $list, string $item): string => "<span data-aa-mailbulk>$list|$item</span>");

        self::assertSame('<span data-aa-mailbulk>' . self::LIST . '|' . self::ITEM . '</span>', $command->expand(self::LIST, self::ITEM));
        self::assertStringContainsString('javascript/mailbulk.js', self::head());
    }

    public function test_expand_info_goesToTheGate(): void
    {
        $command = self::command(info: fn(string $list, string $item, string $what): string => "$list|$item|$what");

        self::assertSame(self::LIST . '|' . self::ITEM . '|sent', $command->expand('info', self::LIST, self::ITEM, 'sent'));
        self::assertStringNotContainsString('mailbulk.js', self::head());
    }

    // no right, or a list of another slice: nothing on the page, not even the script
    public function test_expand_emptyControl_requiresNoScript(): void
    {
        $command = self::command(control: fn(): string => '');

        self::assertSame('', $command->expand(self::LIST, self::ITEM));
        self::assertStringNotContainsString('mailbulk.js', self::head());
    }

    private static function head(): string
    {
        return @AA::Stringexpander()->getRequireHead();   // computeRequires() reads unset keys of its own arrays
    }

    private static function command(?\Closure $control = null, ?\Closure $info = null): AA_Stringexpand_Mailbulk
    {
        $gate = new class ($control, $info) extends Gate {
            public function __construct(private ?\Closure $controlFn, private ?\Closure $infoFn) {}

            public function control(string $list, string $itemId): string
            {
                return ($this->controlFn)($list, $itemId);
            }

            public function info(string $list, string $itemId, string $what): string
            {
                return ($this->infoFn)($list, $itemId, $what);
            }
        };
        return new class ($gate) extends AA_Stringexpand_Mailbulk {
            public function __construct(private Gate $fake)
            {
                parent::__construct([]);
            }

            protected function gate(): Gate
            {
                return $this->fake;
            }
        };
    }
}
