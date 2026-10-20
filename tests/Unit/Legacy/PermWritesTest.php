<?php

declare(strict_types=1);

// include/perm_sql.php is reached through include/locsess.php, which needs the constants
// include/config.php normally provides. Safe test values, defined before the autoloader
// resolves the first legacy class (same block as MembershipCacheTest).

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    /** AA_Perm builds its backends from names - 'fakestore' is this class. It stores the general perms
     *  and records every write it gets. */
    class AA_Permsystem_Fakestore extends AA_Permsystem
    {
        /** @var list<array{string, list<mixed>}> */
        public static array $calls = [];

        public function storesGeneralPerms(): bool
        {
            return true;
        }

        public static bool $login_free = true;

        public function isUsernameFree($username): bool
        {
            return true;
        }

        public function isLoginFree(string $login): bool
        {
            return self::$login_free;
        }

        /** @param list<mixed> $args */
        private static function record(string $method, array $args, string|bool $result): string|bool
        {
            self::$calls[] = [$method, $args];
            return $result;
        }

        public function addUser(array $user): false|string
        {
            return self::record(__FUNCTION__, func_get_args(), '7');
        }

        public function delUser($user_id, int $flags = 3): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function changeUser(array $user): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function addGroup(array $group): false|string
        {
            return self::record(__FUNCTION__, func_get_args(), '9');
        }

        public function delGroup($group_id, int $flags = 3): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function changeGroup(array $group): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function addGroupMember($group_id, $id): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function delGroupMember($group_id, $id): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function addPermObject($objectID, string $objectType): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function delPermObject($objectID, string $objectType): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function addPerm($id, $objectID, string $objectType, $perm): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function delPerm($id, $objectID, string $objectType): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function changePerm($id, $objectID, string $objectType, $perm): bool
        {
            return self::record(__FUNCTION__, func_get_args(), true);
        }

        public function getGroup($group_id): false|array
        {
            return false;
        }

        public function getIDsInfo($uid): false|array
        {
            return false;
        }

        public function getGroupMembers($group_id): array
        {
            return [];
        }

        public function getMembership($id, $flags = 0): array
        {
            return [];
        }
    }

    /** 'fakereader': a backend of users only, as Reader is - it stores no general perms */
    class AA_Permsystem_Fakereader extends AA_Permsystem
    {
        public static bool $free = true;

        public function isUsernameFree($username): bool
        {
            return self::$free;
        }

        public function isLoginFree(string $login): bool
        {
            return true;
        }

        public function getGroup($group_id): false|array
        {
            return false;
        }

        public function getIDsInfo($uid): false|array
        {
            return false;
        }

        public function getGroupMembers($group_id): array
        {
            return [];
        }

        public function getMembership($id, $flags = 0): array
        {
            return [];
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use AA_Perm;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The writes of users, groups, memberships and permissions: the exact statements the SQL backend
     * sends, and AA_Perm handing every write to the one backend which stores the perms.
     */
    final class PermWritesTest extends TestCase
    {
        private const SLICE = 'b5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';

        private mixed $perm = null;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            $this->perm = AA::$perm ?? null;
            AA::$perm   = new AA_Perm(['sql', 'Reader']);
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            \AA_Permsystem_Fakestore::$login_free = true;
        }

        protected function tearDown(): void
        {
            AA::$perm = $this->perm;
        }

        /** @return array<string, array{callable(): mixed, list<string>, string|bool}> */
        public static function sqlWrites(): array
        {
            $user  = ['uid' => 'jan', 'mail' => 'jan@example.org', 'givenname' => 'Jan', 'sn' => 'Novak', 'userpassword' => 'secret'];
            $slice = self::SLICE;
            $perm  = [
                "DELETE FROM `perms`  WHERE object_type = 'slice' AND objectid = '$slice' AND userid = '7'",
                "INSERT INTO `perms` (`object_type`,`objectid`,`userid`,`perm`) VALUES ('slice','$slice','7','2')",
            ];
            return [
                'AddUser' => [static fn() => AA::$perm->addUser($user), [
                    "SELECT uid FROM `users` WHERE uid = 'jan' LIMIT  1",
                    "SELECT LOWER(HEX(module.id)) AS unpackid FROM slice, module WHERE slice.id = module.id AND slice.type = 'ReaderManagement' AND module.deleted < 1",
                    "SELECT id FROM `users` WHERE LOWER(TRIM(CONVERT(uid USING utf8mb4))) COLLATE utf8mb4_bin = 'jan' LIMIT  1",
                    "SELECT id FROM `users` WHERE LOWER(TRIM(CONVERT(mail USING utf8mb4))) COLLATE utf8mb4_bin = 'jan' LIMIT  1",
                    "INSERT INTO `users` (`type`,`password`,`uid`,`mail`,`name`,`description`,`givenname`,`sn`) VALUES ('User','<hash>','jan','jan@example.org','Jan Novak','','Jan','Novak')",
                ], '1'],
                'DelUser' => [static fn() => AA::$perm->delUser('7'), [
                    "DELETE FROM `membership`  WHERE memberid = '7'",
                    "DELETE FROM `perms`  WHERE userid = '7'",
                    "DELETE FROM `users`  WHERE id = '7'",
                ], true],
                'ChangeUser' => [static fn() => AA::$perm->changeUser(['uid' => '7', 'mail' => ['jan@example.org'], 'givenname' => 'Jan', 'sn' => 'Novak', 'userpassword' => '']), [
                    "SELECT * FROM `users` WHERE id='7'",
                    "UPDATE `users` SET `mail`='jan@example.org', `name`='Jan Novak', `givenname`='Jan', `sn`='Novak' WHERE id='7'",
                ], true],
                'AddGroup' => [static fn() => AA::$perm->addGroup(['name' => 'Editors', 'description' => 'Desk']), [
                    "INSERT INTO `users` (`type`,`password`,`uid`,`mail`,`name`,`description`,`givenname`,`sn`) VALUES ('Group','crypt will never return this','','','Editors','Desk','','')",
                ], '1'],
                'DelGroup' => [static fn() => AA::$perm->delGroup('9'), [
                    "DELETE FROM `membership`  WHERE groupid = '9'",
                    "DELETE FROM `membership`  WHERE memberid = '9'",
                    "DELETE FROM `perms`  WHERE userid = '9'",
                    "DELETE FROM `users`  WHERE id = '9'",
                ], true],
                'ChangeGroup' => [static fn() => AA::$perm->changeGroup(['uid' => '9', 'name' => 'Editors', 'description' => 'Desk']), [
                    "SELECT * FROM `users` WHERE id='9'",
                    "UPDATE `users` SET `name`='Editors', `description`='Desk' WHERE id='9'",
                ], true],
                'AddGroupMember' => [static fn() => AA::$perm->addGroupMember('9', '7'), [
                    "DELETE FROM `membership`  WHERE groupid = '9' AND memberid = '7'",
                    "INSERT INTO `membership` (`groupid`,`memberid`) VALUES (9,'7')",
                ], true],
                'DelGroupMember' => [static fn() => AA::$perm->delGroupMember('9', '7'), [
                    "DELETE FROM `membership`  WHERE groupid = '9' AND memberid = '7'",
                ], true],
                // SQL keeps no ACL objects
                'AddPermObject' => [static fn() => AA::$perm->addPermObject($slice, 'slice'), [], true],
                'DelPermObject' => [static fn() => AA::$perm->delPermObject($slice, 'slice'), [], true],
                'AddPerm'       => [static fn() => AA::$perm->addPerm('7', $slice, 'slice', '2'), $perm, true],
                'DelPerm'       => [static fn() => AA::$perm->delPerm('7', $slice, 'slice'), [$perm[0]], true],
                'ChangePerm'    => [static fn() => AA::$perm->changePerm('7', $slice, 'slice', '2'), $perm, true],
            ];
        }

        /** @param list<string> $expected */
        #[DataProvider('sqlWrites')]
        public function test_sqlWrites_emitTheSameStatements(callable $write, array $expected, string|bool $result): void
        {
            // the id of a new user or group is what the insert reports - RecordingDb says '1'
            self::assertSame($result, $write());

            // the password hash is salted - a new string every time
            $log = preg_replace('/\$2y\$\d+\$[.\/A-Za-z0-9]{53}/', '<hash>', RecordingDb::$log);
            self::assertSame($expected, $log);
        }

        public function test_bothBackendsLoadInOneProcess(): void
        {
            require_once __DIR__ . '/../../../include/perm_sql.php';
            require_once __DIR__ . '/../../../include/perm_ldap.php';

            self::assertTrue(class_exists('AA_Permsystem_Ldap'));
            self::assertTrue(class_exists('AA_Permsystem_Sql'));
            foreach (['AddUser', 'DelUser', 'ChangeUser', 'AddGroup', 'DelGroup', 'ChangeGroup', 'AddGroupMember', 'DelGroupMember', 'AddPermObject', 'DelPermObject', 'AddPerm', 'DelPerm', 'ChangePerm'] as $function) {
                self::assertFalse(function_exists($function), $function);
            }
        }

        /** @return array<string, array{string, list<mixed>, string|bool}> */
        public static function writes(): array
        {
            return [
                'addUser'        => ['addUser', [['uid' => 'jan']], '7'],
                'delUser'        => ['delUser', ['7', 1], true],
                'changeUser'     => ['changeUser', [['uid' => '7']], true],
                'addGroup'       => ['addGroup', [['name' => 'Editors']], '9'],
                'delGroup'       => ['delGroup', ['9', 2], true],
                'changeGroup'    => ['changeGroup', [['uid' => '9']], true],
                'addGroupMember' => ['addGroupMember', ['9', '7'], true],
                'delGroupMember' => ['delGroupMember', ['9', '7'], true],
                'addPermObject'  => ['addPermObject', ['x', 'slice'], true],
                'delPermObject'  => ['delPermObject', ['x', 'slice'], true],
                'addPerm'        => ['addPerm', ['7', 'x', 'slice', '2'], true],
                'delPerm'        => ['delPerm', ['7', 'x', 'slice'], true],
                'changePerm'     => ['changePerm', ['7', 'x', 'slice', '2'], true],
            ];
        }

        /** @param list<mixed> $args */
        #[DataProvider('writes')]
        public function test_facade_sendsWritesToTheStorageBackend(string $method, array $args, string|bool $result): void
        {
            \AA_Permsystem_Fakestore::$calls = [];
            \AA_Permsystem_Fakereader::$free = true;

            self::assertSame($result, (new AA_Perm(['fakereader', 'fakestore']))->$method(...$args));
            self::assertSame([[$method, $args]], \AA_Permsystem_Fakestore::$calls);
        }

        public function test_facade_addUserRefusesATakenLogin(): void
        {
            \AA_Permsystem_Fakestore::$calls = [];
            \AA_Permsystem_Fakereader::$free = false;

            self::assertFalse((new AA_Perm(['fakestore', 'fakereader']))->addUser(['uid' => 'jan', 'userpassword' => 'secret']));
            self::assertSame([], \AA_Permsystem_Fakestore::$calls, 'the storage is not asked to add a taken login');
        }

        public function test_facade_addUserRefusesALoginTakenInAnotherCase(): void
        {
            \AA_Permsystem_Fakestore::$calls      = [];
            \AA_Permsystem_Fakestore::$login_free = false;   // 'jan' is free as typed, 'Jan' is not
            \AA_Permsystem_Fakereader::$free      = true;

            self::assertFalse((new AA_Perm(['fakestore', 'fakereader']))->addUser(['uid' => 'jan', 'userpassword' => 'secret']));
            self::assertSame([], \AA_Permsystem_Fakestore::$calls, 'the folded login would fit both accounts and log in neither');
        }

        public function test_facade_withoutStorageThrows(): void
        {
            $this->expectException(\LogicException::class);
            (new AA_Perm(['fakereader']))->addPerm('7', 'x', 'slice', '2');
        }
    }
}
