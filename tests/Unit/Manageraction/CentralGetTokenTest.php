<?php

declare(strict_types=1);

namespace Tests\Unit\Manageraction;

use AA\Manager;
use AA\Manageraction\Central_GetToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\CentralConfDb;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use zids;

/**
 * Get Token for several AAs of the Central list with one login: the action asks for the login in its
 * settings and asks no AA without both its parts. Each AA logs in by Actionapps::authenticate()
 * (ActionappsCredentialTest).
 */
final class CentralGetTokenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/zids.php';
        require_once __DIR__ . '/../../../include/formutil.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';   // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';
        require_once __DIR__ . '/../../../central/include/actionapps.class.php';
        require_once __DIR__ . '/../Legacy/fixtures/central_fixtures.php';  // CentralConfDb
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        unset($_POST['akce_param']);
    }

    public function test_perform_asksEachRowAndListsWhatEachSaid(): void
    {
        // http - the transport refuses before it connects, so the reason is known; Actionapps::find()
        // keeps the rows for the whole process - ids no other test uses
        CentralConfDb::install([
            ['id' => '9101', 'ORG_NAME' => '<b>One</b>', 'AA_HTTP_DOMAIN' => 'http://one.example.org/', 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => ''],
            ['id' => '9102', 'ORG_NAME' => 'Two', 'AA_HTTP_DOMAIN' => 'http://two.example.org/', 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => ''],
        ]);
        $_POST['akce_param'] = ['username' => 'central', 'password' => 'pw'];

        $html = (string) (new Central_GetToken('GetToken'))->perform($this->createStub(Manager::class), new zids(['9101', '9199', '9102'], 's'), $_POST['akce_param']);

        self::assertSame(2, substr_count($html, '<span class="aa-auth aa-auth-get-token">'), 'each row asked, the missing one skipped');
        self::assertStringContainsString('https', $html);
        self::assertStringContainsString('&lt;b&gt;One&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>One</b>', $html);
        self::assertSame([], CentralConfDb::updates());
        self::assertSame(['username' => 'central'], $_POST['akce_param'], 'the password is not kept');
    }

    public function test_htmlSettings_asksForTheLogin(): void
    {
        $html = (new Central_GetToken('GetToken'))->htmlSettings();

        self::assertStringContainsString('<input type="text" name="akce_param[username]"', $html);
        self::assertStringContainsString('<input type="password" name="akce_param[password]"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
    }

    public static function incompleteLogins(): array
    {
        return [
            'no parameters'   => [''],
            'no password'     => [['username' => 'central', 'password' => '']],
            'blank username'  => [['username' => '  ', 'password' => 'pw']],
            'not a text'      => [['username' => ['central'], 'password' => 'pw']],
        ];
    }

    #[DataProvider('incompleteLogins')]
    public function test_perform_incompleteLogin_asksNoAa(mixed $param): void
    {
        // id 1 would be looked up in central_conf - the refusal comes before
        $result = (new Central_GetToken('GetToken'))->perform($this->createStub(Manager::class), new zids(['1'], 's'), $param);

        self::assertIsString($result);
        self::assertStringContainsString('Fill in the username and the password.', $result);
    }
}
