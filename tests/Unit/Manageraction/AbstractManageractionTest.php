<?php

declare(strict_types=1);

/**
 * A row button of the Manager runs a write action (delete a row, run a task, move to the trash).
 * It must not be a link: a prefetch, a crawler or an <img src> would run it by GET. The button
 * carries the action in data- attributes and javascript/manager.js POSTs it.
 */

namespace Tests\Unit\Manageraction;

use AA\Managed;
use AA\Manageraction\AbstractManageraction;
use AA\Manageraction\Central_DeleteTrash;
use AA\Manageraction\Central_MoveItem;
use AA\Manageraction\Central_Tab;
use AA\Manageraction\Item_DeleteTrash;
use AA\Manageraction\Item_Gobookmark;
use AA\Manageraction\Item_Tab;
use AA\Manageraction\Links_DeleteTrash;
use AA\Manageraction\Links_GoBookmark;
use AA\Manageraction\Links_GoCateg;
use AA\Manageraction\Links_Tab;
use AA\Manageraction\Manageractions;
use AA\Manageraction\Object_Delete;
use AA\Manageraction\Polls_DeleteTrash;
use AA\Manageraction\Table_Row_Delete;
use AA\Manageraction\Taskmanager_Execute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbstractManageractionTest extends TestCase
{
    private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    /** the row id as the table Manager passes it - expanded per row */
    private const KEY_ALIAS = '{rawurlencode:{id}}';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Legacy/fixtures/object_form_fixtures.php';
    }

    public function test_getButton_rendersPostButtonNotLink(): void
    {
        $code = self::rowDeleteButton();

        $this->assertStringStartsWith('<button', $code);
        $this->assertStringContainsString('type="button"', $code);
        $this->assertStringContainsString('data-aa-akce="DeleteTRow"', $code);
        $this->assertStringContainsString('data-aa-url="?module_id=' . self::MODULE . '"', $code);
        $this->assertStringContainsString('data-aa-chb="' . self::KEY_ALIAS . '"', $code);   // expanded with the row
        $this->assertStringNotContainsString('href', $code);
        $this->assertDoesNotMatchRegularExpression('/[?&;]akce=/', $code);   // no action in a URL
    }

    public function test_getButton_objectManagerKeepsUrlPlaceholderAndAliases(): void
    {
        $code = (new Object_Delete('DeleteObject'))->getButton(Managed::MANGED_URL, '_#AA_ID___', '_#AA_OWNER')['DeleteObject'];

        $this->assertStringContainsString('data-aa-url="' . Managed::MANGED_URL . '?module_id=_#AA_OWNER"', $code);
        $this->assertStringContainsString('data-aa-chb="_#AA_ID___"', $code);
    }

    public function test_getButton_confirmIsDataNotInlineJs(): void
    {
        $code = self::rowDeleteButton();

        $this->assertStringContainsString('data-aa-confirm="Do you really want to delete this row?"', $code);
        $this->assertStringContainsString('class="aa-button-delete"', $code);
        $this->assertStringNotContainsString('onclick', $code);
    }

    public function test_getButton_withoutConfirmHasNoConfirmAttribute(): void
    {
        $code = (new Taskmanager_Execute('ExecuteTaskAction'))->getButton('', self::KEY_ALIAS, self::MODULE)['ExecuteTaskAction'];

        $this->assertStringNotContainsString('data-aa-confirm', $code);
        $this->assertStringContainsString('class="aa-button-run"', $code);
    }

    public function test_getButton_condWrapsButtonInIf1(): void
    {
        $code = (new Central_MoveItem('Folder3', 3))->getButton('', self::KEY_ALIAS, self::MODULE)['Folder3'];

        $this->assertStringStartsWith('{if1:{ifeq:{_#STATUS_C}:3:0:1}:<button', $code);
        $this->assertStringEndsWith('>Trash</button>}', $code);
    }

    public function test_getButton_defaultClass(): void
    {
        $action = new class ('Go') extends AbstractManageraction {
            protected function getButtonDef(): array
            {
                return ['label' => 'Go'];
            }
        };
        $code = $action->getButton('', self::KEY_ALIAS, self::MODULE)['Go'];

        $this->assertStringContainsString('class="aa-button"', $code);
    }

    public function test_getButton_noButtonDefNoButton(): void
    {
        $action = new class ('Quiet') extends AbstractManageraction {};
        $this->assertSame([], $action->getButton('', self::KEY_ALIAS, self::MODULE));
    }

    public function test_getManagerButtonsGrouped_secondaryAreButtonsToo(): void
    {
        $actions = new Manageractions();
        $actions->addAction(new Table_Row_Delete('DeleteTRow'));
        $actions->addAction(new Central_MoveItem('Folder3', 3));

        $grouped = $actions->getManagerButtonsGrouped('', self::KEY_ALIAS, self::MODULE);

        $this->assertSame(['DeleteTRow'], array_keys($grouped['primary']));
        $this->assertSame(['Folder3'], array_keys($grouped['secondary']));
        $this->assertStringContainsString('<button', $grouped['secondary']['Folder3']);
    }

    /** [class, constructor arguments, read from GET] - every switch a Manager page registers
     *  @return array<string, array{class-string<AbstractManageraction>, list<mixed>, bool}> */
    public static function switches(): array
    {
        return [
            'Item_Tab'            => [Item_Tab::class, ['Tab1a', 'app'], true],
            'Central_Tab'         => [Central_Tab::class, ['Tab1', 'app'], true],
            'Links_Tab'           => [Links_Tab::class, ['Tab'], true],
            'Links_GoCateg'       => [Links_GoCateg::class, ['GoCateg'], true],
            'Links_GoBookmark'    => [Links_GoBookmark::class, ['GoBookmark'], true],
            'Item_Gobookmark'     => [Item_Gobookmark::class, ['GoBookmark'], true],
            'Item_DeleteTrash'    => [Item_DeleteTrash::class, ['DeleteTrash', false], false],
            'Central_DeleteTrash' => [Central_DeleteTrash::class, ['DeleteTrash', false], false],
            'Links_DeleteTrash'   => [Links_DeleteTrash::class, ['DeleteTrash'], false],
            'Polls_DeleteTrash'   => [Polls_DeleteTrash::class, ['DeleteTrash', false], false],
            'an action'           => [Table_Row_Delete::class, ['DeleteTRow'], false],
        ];
    }

    /** a switch which changes data must not run from a link */
    #[DataProvider('switches')]
    public function test_allowGet_onlyForViewSwitches(string $class, array $args, bool $get): void
    {
        $this->assertSame($get, (new $class(...$args))->allowGet());
    }

    private static function rowDeleteButton(): string
    {
        return (new Table_Row_Delete('DeleteTRow'))->getButton('', self::KEY_ALIAS, self::MODULE)['DeleteTRow'];
    }
}
