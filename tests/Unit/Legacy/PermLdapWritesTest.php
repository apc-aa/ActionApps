<?php

declare(strict_types=1);

// include/perm_ldap.php is reached through include/locsess.php, which needs the constants
// include/config.php normally provides (same block as PermWritesTest), plus the LDAP ones.

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
        'LDAP_HOST'       => 'localhost',
        'LDAP_BINDDN'     => 'cn=aauser,ou=AA',
        'LDAP_BINDPW'     => 'somepasswd',
        'LDAP_BASEDN'     => 'ou=AA',
        'LDAP_PEOPLE'     => 'ou=People,ou=AA',
        'LDAP_GROUPS'     => 'ou=AA',
        'LDAP_ACLS'       => 'ou=ACLs,ou=AA',
        'LDAP_PORT'       => 389,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    // The workstation and CI have no ext-ldap: these stand in for it, so the LDAP writes run here too.
    // With the extension loaded the test is skipped - the real functions cannot be replaced.
    if (!extension_loaded('ldap')) {
        function ldap_connect(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_bind(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_unbind(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_search(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_read(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_get_entries(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_first_entry(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_get_attributes(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_free_result(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_add(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_delete(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_mod_add(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_mod_replace(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }

        function ldap_mod_del(mixed ...$args): mixed
        {
            return \Tests\Unit\Legacy\LdapStub::call(__FUNCTION__, $args);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Permsystem_Ldap;
    use PHPUnit\Framework\TestCase;

    /** Records every ldap_* call as "name json(args)" and answers from per-function queues */
    final class LdapStub
    {
        /** @var list<string> */
        public static array $log = [];
        /** @var array<string, list<mixed>> function => answers of its next calls, in order */
        public static array $answers = [];

        private const DEFAULTS = [
            'ldap_connect'        => 'ds',
            'ldap_search'         => 'search',
            'ldap_read'           => 'read',
            'ldap_first_entry'    => 'entry',
            'ldap_get_entries'    => ['count' => 0],
            'ldap_get_attributes' => ['count' => 0],
        ];

        /** @param list<mixed> $args */
        public static function call(string $function, array $args): mixed
        {
            self::$log[] = $function . ' ' . json_encode($args, JSON_UNESCAPED_SLASHES);
            if (self::$answers[$function] ?? []) {
                return array_shift(self::$answers[$function]);
            }
            return self::DEFAULTS[$function] ?? true;
        }
    }

    /**
     * The writes of AA_Permsystem_Ldap - the directory operations each one sends, in order.
     */
    final class PermLdapWritesTest extends TestCase
    {
        private const USER  = 'uid=jan,ou=People,ou=AA';
        private const OTHER = 'uid=eva,ou=People,ou=AA';
        private const GROUP = 'cn=Editors,ou=AA';
        private const SLICE = 'b5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';
        private const ACL   = 'apcobjectid=' . self::SLICE . ',ou=ACLs,ou=AA';

        private const CONNECT = ['ldap_connect ["ldap://localhost:389"]', 'ldap_bind ["ds","cn=aauser,ou=AA","somepasswd"]'];

        public static function setUpBeforeClass(): void
        {
            if (extension_loaded('ldap')) {
                self::markTestSkipped('ext-ldap is loaded - its functions cannot be stubbed');
            }
        }

        protected function setUp(): void
        {
            LdapStub::$log     = [];
            LdapStub::$answers = [];
        }

        /** @param list<string> $members */
        private static function attribute(string $name, array $members): array
        {
            return [$name => ['count' => count($members)] + $members];
        }

        public function test_delUser_leavesItsGroupsAndAclsThenGoes(): void
        {
            LdapStub::$answers['ldap_get_entries'] = [
                ['count' => 1, ['dn' => self::GROUP]],
                ['count' => 1, ['apcobjectid' => ['count' => 1, self::SLICE], 'apcobjecttype' => ['count' => 1, 'slice']]],
            ];
            LdapStub::$answers['ldap_get_attributes'] = [
                self::attribute('member', [self::USER, self::OTHER]),
                self::attribute('apcaci', [self::USER . ':2', self::OTHER . ':1']),
            ];

            self::assertTrue((new AA_Permsystem_Ldap())->delUser(self::USER, 3));
            self::assertSame([
                ...self::CONNECT,
                'ldap_search ["ds","ou=AA","(&(objectclass=groupOfNames)(member=uid=jan,ou=People,ou=AA))",[""]]',
                'ldap_get_entries ["ds","search"]',
                // delGroupMember() - on a connection of its own
                ...self::CONNECT,
                'ldap_read ["ds","cn=Editors,ou=AA","objectclass=groupOfNames",["member"]]',
                'ldap_first_entry ["ds","read"]',
                'ldap_get_attributes ["ds","entry"]',
                'ldap_mod_replace ["ds","cn=Editors,ou=AA",{"member":["uid=eva,ou=People,ou=AA"]}]',
                'ldap_unbind ["ds"]',
                'ldap_free_result ["search"]',
                'ldap_search ["ds","ou=ACLs,ou=AA","(&(objectclass=apcacl)(apcaci=uid=jan,ou=People,ou=AA:*))",["apcObjectType","apcaci","apcObjectID"]]',
                'ldap_get_entries ["ds","search"]',
                // delPerm()
                ...self::CONNECT,
                'ldap_read ["ds","' . self::ACL . '","objectclass=apcacl",["apcaci"]]',
                'ldap_first_entry ["ds","read"]',
                'ldap_get_attributes ["ds","entry"]',
                'ldap_mod_replace ["ds","' . self::ACL . '",{"apcaci":["uid=eva,ou=People,ou=AA:1"]}]',
                'ldap_unbind ["ds"]',
                'ldap_free_result ["search"]',
                'ldap_delete ["ds","uid=jan,ou=People,ou=AA"]',
                'ldap_unbind ["ds"]',
            ], LdapStub::$log);
        }

        public function test_delUser_aclWithoutTypeDoesNotStopTheDelete(): void
        {
            LdapStub::$answers['ldap_get_entries'] = [
                ['count' => 1, ['apcobjectid' => ['count' => 1, self::SLICE]]],
            ];
            LdapStub::$answers['ldap_get_attributes'] = [self::attribute('apcaci', [self::USER . ':2'])];

            self::assertTrue((new AA_Permsystem_Ldap())->delUser(self::USER, 2));
            self::assertSame('ldap_delete ["ds","uid=jan,ou=People,ou=AA"]', LdapStub::$log[count(LdapStub::$log) - 2]);
        }

        public function test_addPerm_createsAMissingAclObjectFirst(): void
        {
            LdapStub::$answers['ldap_read']           = [false, 'read'];
            // a new object has no apcaci yet - an empty list keeps the test about the writes, not PHP warnings
            LdapStub::$answers['ldap_get_attributes'] = [self::attribute('apcaci', [])];

            self::assertTrue((new AA_Permsystem_Ldap())->addPerm(self::USER, self::SLICE, 'slice', '2'));
            self::assertSame([
                ...self::CONNECT,
                'ldap_read ["ds","' . self::ACL . '","objectclass=apcacl",["apcaci"]]',
                // addPermObject() - on a connection of its own
                ...self::CONNECT,
                'ldap_add ["ds","' . self::ACL . '",{"objectclass":["top","apcacl"],"apcobjectid":"' . self::SLICE . '","apcobjecttype":"slice"}]',
                'ldap_unbind ["ds"]',
                'ldap_read ["ds","' . self::ACL . '","objectclass=apcacl",["apcaci"]]',
                'ldap_first_entry ["ds","read"]',
                'ldap_get_attributes ["ds","entry"]',
                'ldap_mod_replace ["ds","' . self::ACL . '",{"apcaci":["uid=jan,ou=People,ou=AA:2"]}]',
                'ldap_unbind ["ds"]',
            ], LdapStub::$log);
        }

        public function test_delPerm_ofTheLastAciDeletesTheValue(): void
        {
            LdapStub::$answers['ldap_get_attributes'] = [self::attribute('apcaci', [self::USER . ':2'])];

            self::assertTrue((new AA_Permsystem_Ldap())->delPerm(self::USER, self::SLICE, 'slice'));
            self::assertSame([
                ...self::CONNECT,
                'ldap_read ["ds","' . self::ACL . '","objectclass=apcacl",["apcaci"]]',
                'ldap_first_entry ["ds","read"]',
                'ldap_get_attributes ["ds","entry"]',
                'ldap_mod_del ["ds","' . self::ACL . '",{"apcaci":["uid=jan,ou=People,ou=AA:2"]}]',
                'ldap_unbind ["ds"]',
            ], LdapStub::$log);
        }

        public function test_addGroupMember(): void
        {
            self::assertTrue((new AA_Permsystem_Ldap())->addGroupMember(self::GROUP, self::USER));
            self::assertSame([
                ...self::CONNECT,
                'ldap_mod_add ["ds","cn=Editors,ou=AA",{"member":"uid=jan,ou=People,ou=AA"}]',
                'ldap_unbind ["ds"]',
            ], LdapStub::$log);
        }

        public function test_delGroupMember_ofTheLastMemberLeavesTheBindDn(): void
        {
            LdapStub::$answers['ldap_get_attributes'] = [self::attribute('member', [self::USER])];

            self::assertTrue((new AA_Permsystem_Ldap())->delGroupMember(self::GROUP, self::USER));
            self::assertSame([
                ...self::CONNECT,
                'ldap_read ["ds","cn=Editors,ou=AA","objectclass=groupOfNames",["member"]]',
                'ldap_first_entry ["ds","read"]',
                'ldap_get_attributes ["ds","entry"]',
                'ldap_mod_replace ["ds","cn=Editors,ou=AA",{"member":["cn=aauser,ou=AA"]}]',
                'ldap_unbind ["ds"]',
            ], LdapStub::$log);
        }

        public function test_addUser_recordShape(): void
        {
            $dn = (new AA_Permsystem_Ldap())->addUser(['uid' => 'jan', 'givenname' => 'Jan', 'sn' => 'Novak', 'mail' => ['jan@example.org'], 'userpassword' => 'secret', 'phone' => '123']);

            self::assertSame(self::USER, $dn);
            // the password hash is salted - a new string every time
            $log = preg_replace('/\{CRYPT\}\$6\$rounds=5000\$[^"]+/', '<hash>', LdapStub::$log);
            self::assertSame([
                ...self::CONNECT,
                'ldap_add ["ds","uid=jan,ou=People,ou=AA",{"objectclass":["top","person","organizationalperson","inetorgperson"],"cn":"Jan  Novak","sn":"Novak","givenname":"Jan","mail":["jan@example.org"],"uid":"jan","userPassword":"<hash>","telephoneNumber":"123"}]',
                'ldap_unbind ["ds"]',
            ], $log);
        }
    }
}
