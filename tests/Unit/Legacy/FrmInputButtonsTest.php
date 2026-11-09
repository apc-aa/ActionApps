<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use inputform;
use PHPUnit\Framework\TestCase;

/**
 * Which buttons of getFrmInputButtons() (include/formutil.php) carry the class aa-once - a form sent
 * by such a button refuses a second submit until the next page (AA_Adminpageutil::addSubmitGuard()).
 * The saving buttons have it by default; an action that answers with a download must not, else the
 * page would stay locked.
 */
final class FrmInputButtonsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/formutil.php';
    }

    /** @return array<string, bool> name of each submit button => has aa-once */
    private static function onceFlags(array $buttons): array
    {
        preg_match_all('/<input type="submit"[^>]*>/', getFrmInputButtons($buttons, false), $m);
        $ret = [];
        foreach ($m[0] as $tag) {
            preg_match('/name="([^"]*)"/', $tag, $name);
            $ret[$name[1]] = (bool) preg_match('/class="[^"]*\baa-once\b/', $tag);
        }
        return $ret;
    }

    public function test_saving_buttons_send_once(): void
    {
        $this->assertSame(['update' => true, 'insert' => true, 'submitbtn' => true], self::onceFlags(['update', 'insert', 'submit']));
    }

    public function test_other_submit_buttons_only_on_request(): void
    {
        $this->assertSame(['export' => false], self::onceFlags(['export' => ['type' => 'submit', 'value' => 'Export']]));
        $this->assertSame(['send' => true], self::onceFlags(['send' => ['type' => 'submit', 'value' => 'Send', 'once' => true]]));
    }

    public function test_a_saving_button_may_opt_out(): void
    {
        $this->assertSame(['update' => false], self::onceFlags(['update' => ['once' => false]]));
    }

    public function test_item_form_submit_buttons_send_once(): void
    {
        $buttons = inputform::_getButton('upd_edit', 'submit', 'Update & Edit', '') + inputform::_getButton('ins_edit', 'submit', 'Insert & Edit', 'Save');
        $this->assertSame([], inputform::_getButton('upd_edit', 'submit', 'Update & Edit', ''), 'an empty profile text hides the button');
        $this->assertSame(['ins_edit' => true], self::onceFlags($buttons));
        $this->assertFalse(inputform::_getButton('cancel', 'button', 'Cancel', 'x')['cancel']['once']);
    }
}
