<?php

declare(strict_types=1);

/**
 * CreateAppStoreAccount: the public account of the app store is ready only as a user of the public
 * password with no rights and no groups - its password is in every AA's code.
 */

namespace Tests\Unit\Optimize;

use AA;
use AA\Central\AppStore;
use AA\Optimize\CreateAppStoreAccount;
use PHPUnit\Framework\TestCase;

final class CreateAppStoreAccountTest extends TestCase
{
    private mixed $perm = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/mgettext.php';
    }

    protected function setUp(): void
    {
        $this->perm = AA::$perm ?? null;
    }

    protected function tearDown(): void
    {
        AA::$perm = $this->perm;
    }

    /** AA_Perm of one user, 7 - of the login and password given, with the rights and groups given
     *  @param array<string, mixed> $perms @param list<string> $groups */
    private static function perm(?string $login, string $password = AppStore::PUBLIC_PASSWORD, array $perms = [], array $groups = [], bool $reader = false): object
    {
        return new class ($login, $password, $perms, $groups, $reader) {
            public function __construct(private ?string $login, private string $password, private array $perms, private array $groups, private bool $reader) {}

            public function isUsernameFree($username): bool
            {
                return $username !== $this->login;
            }

            public function authenticateUsername($username, $password, $code2fa = ''): false|string
            {
                return (($username === $this->login) && ($password === $this->password)) ? '7' : false;
            }

            public function isUserEditable($uid): bool
            {
                return ($uid === '7') && !$this->reader;
            }

            public function getUserLoginName($uid): string
            {
                return ($uid === '7') ? (string) $this->login : '';
            }

            public function getUserPerms($uid, $objType, $ignore_groups = false): array
            {
                return $this->perms[$objType] ?? [];
            }

            public function getMembership($id, $flags = 0): array
            {
                return $this->groups;
            }
        };
    }

    private static function tested(): bool
    {
        return (new CreateAppStoreAccount())->test();
    }

    public function test_the_account_is_ready_as_a_user_of_the_public_password_without_rights(): void
    {
        AA::$perm = self::perm(AppStore::PUBLIC_USER);
        self::assertTrue(self::tested());
    }

    public function test_no_account_is_not_ready(): void
    {
        AA::$perm = self::perm(null);
        self::assertFalse(self::tested());
    }

    public function test_a_user_of_the_name_with_another_password_is_not_the_account(): void
    {
        AA::$perm = self::perm(AppStore::PUBLIC_USER, 'someone-s-own');
        self::assertFalse(self::tested());
    }

    public function test_a_reader_of_the_name_is_not_the_account(): void
    {
        AA::$perm = self::perm(AppStore::PUBLIC_USER, reader: true);
        self::assertFalse(self::tested());
    }

    /** rights would open more than the published apps to anyone */
    public function test_rights_or_groups_are_not_ready(): void
    {
        AA::$perm = self::perm(AppStore::PUBLIC_USER, perms: ['slice' => [str_repeat('a', 32) => 'E']]);
        self::assertFalse(self::tested(), 'a right on a slice');

        AA::$perm = self::perm(AppStore::PUBLIC_USER, perms: ['aa' => ['AA' => 'A']]);
        self::assertFalse(self::tested(), 'a right on the AA');

        AA::$perm = self::perm(AppStore::PUBLIC_USER, groups: ['9']);
        self::assertFalse(self::tested(), 'a group');
    }
}
