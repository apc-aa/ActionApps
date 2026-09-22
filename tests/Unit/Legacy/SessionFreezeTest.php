<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Session;

/**
 * Session::freeze() (include/phplib.php) is the register_globals leftover:
 * at the end of a page it copies global variables back into $_SESSION.
 * It must do so only for the legacy variables that are still written through
 * globals (Session::LEGACY_GLOBAL_VARS); a key written straight into $_SESSION
 * has no global twin and used to be nulled - that broke the CKEditor upload
 * folder (r_last_module_id), the MngWidget AJAX state and the UI-prefs token.
 */
final class SessionFreezeTest extends TestCase
{
    private array $session_backup = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../include/phplib.php';
        $this->session_backup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session_backup;
        foreach (['r_state', 'rusr', 'r_last_module_id', 'aa_ui_csrf'] as $name) {
            unset($GLOBALS[$name]);
        }
    }

    private function session(): Session
    {
        // the constructor starts a PHP session; freeze()/register() touch only the arrays
        return (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
    }

    public function test_freeze_copies_a_legacy_global_into_the_session(): void
    {
        $_SESSION['r_state'] = ['module_id' => 'old'];
        $GLOBALS['r_state']  = ['module_id' => 'new', 'bin' => 'app'];

        $this->session()->freeze();

        $this->assertSame(['module_id' => 'new', 'bin' => 'app'], $_SESSION['r_state']);
    }

    public function test_freeze_leaves_a_bare_session_key_alone(): void
    {
        $_SESSION['r_last_module_id'] = 'abc';
        unset($GLOBALS['r_last_module_id']);

        $this->session()->freeze();

        $this->assertSame('abc', $_SESSION['r_last_module_id']);
    }

    public function test_freeze_does_not_create_a_legacy_key_that_is_not_in_the_session(): void
    {
        unset($_SESSION['rusr']);
        $GLOBALS['rusr'] = 'someone';

        $this->session()->freeze();

        $this->assertArrayNotHasKey('rusr', $_SESSION);
    }

    public function test_register_of_an_unlisted_name_is_reported(): void
    {
        $GLOBALS['aa_ui_csrf'] = 'tok';
        $reported = '';
        set_error_handler(static function (int $no, string $msg) use (&$reported): bool {
            $reported = $msg;
            return true;
        });
        try {
            $this->session()->register('aa_ui_csrf');
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('aa_ui_csrf', $reported);
        $this->assertSame('tok', $_SESSION['aa_ui_csrf'], 'the value is still registered, only reported');
    }
}
