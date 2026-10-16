<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Credentials;
use Closure;
use PHPUnit\Framework\TestCase;

/** AA_Credentials::checkCryptedPassword() - the reading password of a slice, hashed, against what the request knows */
final class CredentialsCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // AA_Credentials
    }

    /** the singleton outlives the test */
    protected function tearDown(): void
    {
        unset($GLOBALS['slice_pwd']);
        Closure::bind(function (): void {
            $this->_pwd = [];
        }, AA_Credentials::singleton(), AA_Credentials::class)();
    }

    public function test_checkCryptedPassword_slicePwdFromUrl_isKnown(): void
    {
        $GLOBALS['slice_pwd'] = 'cc-from-url';

        $this->assertTrue(AA_Credentials::singleton()->checkCryptedPassword(AA_Credentials::encrypt('cc-from-url')));
    }

    /** slice_pwd[]=... makes it an array - no password, and no TypeError from hashing it */
    public function test_checkCryptedPassword_slicePwdArrayFromUrl_isNoPassword(): void
    {
        $GLOBALS['slice_pwd'] = ['cc-array'];

        $this->assertFalse(AA_Credentials::singleton()->checkCryptedPassword(AA_Credentials::encrypt('cc-array')));
    }

    /** "0e<digits>" hashes are equal numbers for == */
    public function test_checkCryptedPassword_zeroEHashes_areNotTheSamePassword(): void
    {
        $this->assertFalse(AA_Credentials::singleton()->checkCryptedPassword('0e462097431906509019562988736854', '0e830400451993494058024219903391'));
    }
}
