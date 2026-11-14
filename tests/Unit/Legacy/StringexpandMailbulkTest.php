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
    private const SLICE = 'ef00000000000000000000000000000f';

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

    // the module posts through AA_Response() of the AA JS library
    public function test_expand_control_requiresAnAaJslibVariant(): void
    {
        $command = self::command(control: fn(): string => '<span data-aa-mailbulk></span>');
        $command->expand(self::LIST, self::ITEM);

        self::assertMatchesRegularExpression('~javascript/aajslib-(jquery|legacy)[^"]*\.(js|php)~', self::head());
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

    // the item the command is expanded over gives its slice - a listing reads no slice per control
    public function test_expand_overAnItem_tellsTheGateItsSlice(): void
    {
        $seen    = [];
        $command = self::command(control: fn(): string => '', seen: $seen, item: self::item(['u_slice_id......' => self::SLICE]));
        $command->expand(self::LIST, self::ITEM);

        self::assertSame([[self::ITEM, self::SLICE]], $seen);
    }

    // a content not read from the item table (a form, say) has no u_slice_id...... - its slice_id........ is not trusted
    public function test_expand_overContentWithoutReadSlice_tellsTheGateNothing(): void
    {
        $seen    = [];
        $command = self::command(control: fn(): string => '', seen: $seen, item: self::item(['slice_id........' => self::SLICE]));
        $command->expand(self::LIST, self::ITEM);

        self::assertSame([], $seen);
    }

    /** @param array<string, string> $fields */
    private static function item(array $fields): \AA_Item
    {
        $content = ['unpacked_id.....' => [['value' => self::ITEM]]];
        foreach ($fields as $id => $value) {
            $content[$id] = [['value' => $value]];
        }
        return new \AA_Item(new \ItemContent($content));
    }

    private static function head(): string
    {
        return @AA::Stringexpander()->getRequireHead();   // computeRequires() reads unset keys of its own arrays
    }

    /** @param list<array{string, string}> $seen what itemSeen() got */
    private static function command(?\Closure $control = null, ?\Closure $info = null, array &$seen = [], ?\AA_Item $item = null): AA_Stringexpand_Mailbulk
    {
        $gate = new class ($control, $info, $seen) extends Gate {
            /** @param list<array{string, string}> $seen */
            public function __construct(private ?\Closure $controlFn, private ?\Closure $infoFn, private array &$seen) {}

            public function itemSeen(string $itemId, string $slice): void
            {
                $this->seen[] = [$itemId, $slice];
            }

            public function control(string $list, string $itemId): string
            {
                return ($this->controlFn)($list, $itemId);
            }

            public function info(string $list, string $itemId, string $what): string
            {
                return ($this->infoFn)($list, $itemId, $what);
            }
        };
        return new class ($gate, $item) extends AA_Stringexpand_Mailbulk {
            public function __construct(private Gate $fake, ?\AA_Item $item)
            {
                parent::__construct(['item' => $item]);
            }

            protected function gate(): Gate
            {
                return $this->fake;
            }
        };
    }
}
