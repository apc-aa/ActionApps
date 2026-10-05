<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Perm;
use PHPUnit\Framework\TestCase;

// AA_Perm lives in include/perm_core.php, which the legacy boot loads
require_once __DIR__ . '/fixtures/object_form_fixtures.php';

/**
 * AA_Perm::hasSlicePerm() decides a right of any user on a slice as CheckPerms() decides it for the
 * logged-in one - but from the perm storage, so asking about another user (who saved a planned task)
 * leaves the permissions the session keeps for its own user as they are.
 */
final class PermSlicePermTest extends TestCase
{
    private const SLICE = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';

    /** @param array<string, array<string, string>> $stored object type => id => perms, as the storage keeps them */
    private static function perm(array $stored): AA_Perm
    {
        return new class ([], $stored) extends AA_Perm {
            /** @param array<string, array<string, string>> $stored */
            public function __construct(array $systems, private readonly array $stored)
            {
                parent::__construct($systems);
            }

            public function getUserPerms($uid, $objectType, $flags = 0): array
            {
                return ($uid === 'uid-1') ? ($this->stored[$objectType] ?? []) : [];
            }
        };
    }

    public function test_hasSlicePerm_lettersAndRoles(): void
    {
        $this->assertTrue(self::perm(['slice' => [self::SLICE => PS_FEEDING]])->hasSlicePerm('uid-1', self::SLICE, PS_FEEDING));
        $this->assertTrue(self::perm(['slice' => [self::SLICE => AA_Perm::ROLES['ADMINISTRATOR']['id']]])->hasSlicePerm('uid-1', self::SLICE, PS_FEEDING), 'a role is its letters');
        $this->assertFalse(self::perm(['slice' => [self::SLICE => AA_Perm::ROLES['EDITOR']['id']]])->hasSlicePerm('uid-1', self::SLICE, PS_FEEDING));
        $this->assertFalse(self::perm(['slice' => ['6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c' => PS_FEEDING]])->hasSlicePerm('uid-1', self::SLICE, PS_FEEDING), 'another slice');
    }

    public function test_hasSlicePerm_superadminOfTheInstallation(): void
    {
        $perm = self::perm(['aa' => [AA_ID => AA_Perm::ROLES['SUPER']['id']], 'slice' => [self::SLICE => PS_EDIT_ALL_ITEMS]]);

        $this->assertTrue($perm->hasSlicePerm('uid-1', self::SLICE, PS_FEEDING), 'the super role is above the role on the slice');
    }

    public function test_hasSlicePerm_noUserNoRightAndSessionUntouched(): void
    {
        $GLOBALS['permission_uid'] = 'uid-session';
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => PS_FEEDING]];
        try {
            $perm = self::perm(['slice' => [self::SLICE => PS_FEEDING]]);

            $this->assertFalse($perm->hasSlicePerm('', self::SLICE, PS_FEEDING));
            $this->assertTrue($perm->hasSlicePerm('uid-1', self::SLICE, PS_FEEDING));
            $this->assertSame('uid-session', $GLOBALS['permission_uid']);
        } finally {
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        }
    }
}
