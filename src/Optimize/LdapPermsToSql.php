<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_Permsystem_Ldap;
use CVarset;
use Throwable;

/** Migrate users, groups, memberships and permissions from the LDAP
 *  permission backend (perm_ldap) into the SQL permission backend
 *  (perm_sql), and rewrite user-id references in existing AA data
 *  (item.posted_by, item.edited_by) from LDAP DN form to the new
 *  SQL numeric ids.
 *
 *  Intended to be run once on each install that wants to switch
 *  PERM_LIB from 'ldap' to 'sql'. Idempotent and revertable via a
 *  dedicated ldap2sql_map table that records every DN->id mapping.
 *
 *  Note: perm_sql.php is deliberately NOT required - it declares the
 *  same global function names (AddUser, AddGroup, ...) as perm_ldap.php,
 *  which is already loaded via locauth.php when PERM_LIB='ldap'. All
 *  writes to the SQL permission tables are therefore done directly
 *  via DB_AA from this class.
 */
class LdapPermsToSql extends AbstractOptimize
{
    public const MAP_TABLE = 'ldap2sql_map';

    /** Columns in AA data tables that store the current user id and
     *  therefore need rewriting from LDAP DN form to SQL numeric id.
     *  Hard-coded for safety and inspectability (see plan).
     */
    public const REWRITE_COLUMNS = [
        ['table' => 'item', 'col' => 'posted_by'],
        ['table' => 'item', 'col' => 'edited_by'],
    ];

    /** @var bool - true during test(), suppresses all writes */
    private bool $dry_run = false;

    /** @var AA_Permsystem_Ldap */
    private $ldap;

    /** Name function */
    public static function name(): string
    {
        return _m("Migrate LDAP perms to SQL perms");
    }

    /** Description function */
    public static function description(): string
    {
        return _m("Copies all users, groups, memberships and permissions from the LDAP backend into the SQL backend tables (users, membership, perms), and rewrites item.posted_by / item.edited_by from LDAP DN form (uid=...,ou=...) to the new SQL numeric ids. Run once per install, then switch PERM_LIB to 'sql' in config. 'Test' is a read-only dry run. 'Repair' performs the migration and is re-runnable. 'Revert' uses the mapping table to undo everything.");
    }

    public static function actions(): array
    {
        return ['test', 'repair', 'revert'];
    }

    public static function types(): array
    {
        return ['private_fix'];
    }

    /** Guard + initialize both permission backends */
    private function init(): bool
    {
        if (!defined('PERM_LIB') || PERM_LIB !== 'ldap') {
            $this->message(_m("This optimizer only runs when PERM_LIB='ldap'. Current PERM_LIB='%1'.", [defined('PERM_LIB') ? PERM_LIB : '?']));
            return false;
        }
        $this->ldap = new AA_Permsystem_Ldap();
        $this->ensureMapTable();
        return true;
    }

    /** Create the persistent DN->id mapping table if it does not exist. */
    private function ensureMapTable(): void
    {
        if (DB_AA::exists_table(self::MAP_TABLE)) {
            return;
        }
        if ($this->dry_run) {
            $this->message(_m("Would create mapping table `%1`", [self::MAP_TABLE]));
            return;
        }
        $db = getDb();
        $db->query("CREATE TABLE IF NOT EXISTS `" . self::MAP_TABLE . "` (
            ldap_dn  VARCHAR(255) NOT NULL,
            sql_id   BIGINT       NOT NULL,
            kind     ENUM('user','group') NOT NULL,
            login    VARCHAR(100) NOT NULL,
            created  DATETIME     NOT NULL,
            PRIMARY KEY (ldap_dn),
            INDEX idx_sql_id (sql_id),
            INDEX idx_login  (login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        freeDB($db);
    }

    //-------------------------------------------------------------- test

    public function test(): bool
    {
        $this->clear_report();
        $this->dry_run = true;
        try {
            if (!$this->init()) {
                return false;
            }
            $this->runMigration();
        } catch (Throwable $e) {
            $this->message(_m("Test failed: %1", [safe($e->getMessage())]));
            return false;
        }
        $this->message(_m("Test finished (dry run - no changes made)."));
        return true;
    }

    //------------------------------------------------------------ repair

    public function repair(): bool
    {
        $this->clear_report();
        $this->dry_run = false;
        try {
            if (!$this->init()) {
                return false;
            }
            $this->runMigration();
        } catch (Throwable $e) {
            $this->message(_m("Repair failed: %1", [safe($e->getMessage())]));
            return false;
        }
        $this->message(_m("Repair finished."));
        return true;
    }

    //------------------------------------------------------------ revert

    public function revert(): bool
    {
        $this->clear_report();
        $this->dry_run = false;
        try {
            if (!$this->init()) {
                return false;
            }
            $this->runRevert();
        } catch (Throwable $e) {
            $this->message(_m("Revert failed: %1", [safe($e->getMessage())]));
            return false;
        }
        $this->message(_m("Revert finished."));
        return true;
    }

    //============================================================== core

    private function runMigration(): void
    {
        $this->migrateUsers();
        $this->migrateGroups();
        $this->migrateMemberships();
        $this->migratePerms();
        $this->rewriteUserRefs();
    }

    //----- phase 1+2: users

    private function migrateUsers(): void
    {
        $ldap_users = $this->ldap->findUsernames('');
        if (!is_array($ldap_users)) {
            $this->message(_m("LDAP findUsernames failed or returned: %1", [safe(print_r($ldap_users, true))]));
            return;
        }
        $this->message(_m("LDAP users found: %1", [count($ldap_users)]));

        $created = 0;
        $reused = 0;
        $failed = 0;
        foreach ($ldap_users as $dn => $_row) {
            if ($this->mapGet($dn)) {
                continue;
            }

            $info = $this->ldap->getIDsInfo($dn);
            if (!$info || ($info['type'] ?? '') !== 'User') {
                continue;
            }
            $login = $info['login'] ?: $this->extractUidFromDn($dn);
            if (!$login) {
                $this->message(_m("Skipping user %1 - cannot determine login", [safe($dn)]));
                $failed++;
                continue;
            }

            // Conflict policy (plan): reuse existing sql user with same login.
            $existing = DB_AA::select1('', "SELECT id, password FROM `users`", [['uid', $login], ['type', 'User']]);
            if (is_array($existing)) {
                $sql_id = (int) $existing['id'];
                $this->message(_m("User %1 already exists in SQL (id %2), reusing", [safe($login), $sql_id]));
                if (!$this->dry_run) {
                    $this->mapPut($dn, $sql_id, 'user', $login);
                    // If row holds our placeholder hash, overwrite with the
                    // real LDAP hash so opportunistic rehash still works.
                    if ($this->isPlaceholderHash($existing['password'])) {
                        $this->maybeCopyLdapHash($dn, $sql_id);
                    }
                }
                $reused++;
                continue;
            }

            if ($this->dry_run) {
                $this->message(_m("Would create SQL user: %1 (from %2)", [safe($login), safe($dn)]));
                $created++;
                continue;
            }

            $pwd_hash = $this->readLdapUserPassword($dn) ?: $this->placeholderHash();
            $sql_id = $this->sqlInsertUser($login, $info, $pwd_hash);
            if (!$sql_id) {
                $this->message(_m("Failed to create SQL user %1", [safe($login)]));
                $failed++;
                continue;
            }
            $this->mapPut($dn, $sql_id, 'user', $login);
            $created++;
        }
        $this->message(_m("Users: created=%1 reused=%2 failed=%3", [$created, $reused, $failed]));
    }

    //----- phase 3: groups

    private function migrateGroups(): void
    {
        $ldap_groups = $this->ldap->findGroups('');
        $this->message(_m("LDAP groups found: %1", [count($ldap_groups)]));

        $created = 0;
        $reused = 0;
        $failed = 0;
        foreach ($ldap_groups as $dn => $row) {
            if ($this->mapGet($dn)) {
                continue;
            }

            $gname = $row['name'] ?? '';
            if (!$gname) {
                continue;
            }
            $full = $this->ldap->getGroup($dn) ?: [];
            $gdesc = $full['description'] ?? '';

            // Conflict policy: reuse existing SQL group by name.
            $existing = DB_AA::select1('', "SELECT id FROM `users`", [['name', $gname], ['type', 'Group']]);
            if (is_array($existing)) {
                $sql_id = (int) $existing['id'];
                $this->message(_m("Group %1 already exists in SQL (id %2), reusing", [safe($gname), $sql_id]));
                if (!$this->dry_run) {
                    $this->mapPut($dn, $sql_id, 'group', $gname);
                }
                $reused++;
                continue;
            }

            if ($this->dry_run) {
                $this->message(_m("Would create SQL group: %1 (from %2)", [safe($gname), safe($dn)]));
                $created++;
                continue;
            }

            $sql_id = $this->sqlInsertGroup($gname, $gdesc);
            if (!$sql_id) {
                $this->message(_m("Failed to create SQL group %1", [safe($gname)]));
                $failed++;
                continue;
            }
            $this->mapPut($dn, $sql_id, 'group', $gname);
            $created++;
        }
        $this->message(_m("Groups: created=%1 reused=%2 failed=%3", [$created, $reused, $failed]));
    }

    //----- phase 4: memberships

    private function migrateMemberships(): void
    {
        $ldap_groups = $this->ldap->findGroups('');
        $added = 0;
        $skipped = 0;
        foreach ($ldap_groups as $group_dn => $_row) {
            $group_sql_id = $this->mapGet($group_dn);
            if (!$group_sql_id) {
                continue;
            }
            $members = $this->ldap->getGroupMembers($group_dn);
            foreach ($members as $member_dn => $_info) {
                $member_sql_id = $this->mapGet($member_dn);
                if (!$member_sql_id) {
                    $skipped++;
                    continue;
                }
                if ($this->dry_run) {
                    $added++;
                    continue;
                }
                // idempotent: delete then insert (mirrors perm_sql AddGroupMember)
                DB_AA::transaction(static function () use ($group_sql_id, $member_sql_id) {
                    DB_AA::delete('membership', [['groupid', $group_sql_id, 'i'], ['memberid', $member_sql_id, 'i']]);
                    AA::Metabase()->doInsert('membership', [
                        'groupid'  => (string) $group_sql_id,
                        'memberid' => (string) $member_sql_id,
                    ]);
                });
                self::forgetMembershipMemo();
                $added++;
            }
        }
        $this->message(_m("Memberships: added=%1 skipped_unmapped=%2", [$added, $skipped]));
    }

    //----- phase 5: permissions

    private function migratePerms(): void
    {
        $object_ids = $this->collectPermObjects();
        $total = 0;
        foreach ($object_ids as $object_type => $ids) {
            foreach ($ids as $oid) {
                $acl = $this->ldap->getObjectsPerms($oid, $object_type);
                if (!$acl) {
                    continue;
                }
                foreach ($acl as $dn => $perm) {
                    $sql_id = $this->mapGet($dn);
                    if (!$sql_id) {
                        continue;
                    }
                    if ($this->dry_run) {
                        $total++;
                        continue;
                    }
                    // idempotent: delete+insert (mirrors perm_sql AddPerm)
                    DB_AA::transaction(static function () use ($object_type, $oid, $sql_id, $perm) {
                        DB_AA::delete('perms', [
                            ['object_type', $object_type],
                            ['objectid',    $oid],
                            ['userid',      (string) $sql_id],
                        ]);
                        AA::Metabase()->doInsert('perms', [
                            'object_type' => $object_type,
                            'objectid'    => $oid,
                            'userid'      => (string) $sql_id,
                            'perm'        => (string) $perm,
                        ]);
                    });
                    $total++;
                }
            }
        }
        $this->message(_m("Permission rows migrated: %1", [$total]));
    }

    /** All (object_type, object_id) pairs whose perms need migration.
     *  In AA there are two object types: "aa" (single = AA_ID) and
     *  "slice" (all modules in the module table).
     */
    private function collectPermObjects(): array
    {
        $slices = DB_AA::select('unpackid', "SELECT LOWER(HEX(`id`)) AS unpackid FROM `module`");
        return [
            'aa'    => [AA_ID],
            'slice' => is_array($slices) ? $slices : [],
        ];
    }

    //----- phase 6: rewrite user-id references in AA data

    private function rewriteUserRefs(): void
    {
        $pairs = $this->mapAll();
        if (!$pairs) {
            $this->message(_m("No mapping entries - nothing to rewrite."));
            return;
        }
        foreach (self::REWRITE_COLUMNS as $spec) {
            $t = $spec['table'];
            $c = $spec['col'];
            $changed = 0;
            foreach ($pairs as $row) {
                $cnt = DB_AA::count($t, [[$c, $row['ldap_dn']]]);
                if (!$cnt) {
                    continue;
                }
                if ($this->dry_run) {
                    $changed += $cnt;
                    continue;
                }
                DB_AA::update($t, [[$c, (string) $row['sql_id']]], [[$c, $row['ldap_dn']]]);
                $changed += $cnt;
            }
            $this->message(_m("Rewrite %1.%2: %3 rows", [$t, $c, $changed]));
        }
    }

    //============================================================ revert

    private function runRevert(): void
    {
        $pairs = $this->mapAll();
        if (!$pairs) {
            $this->message(_m("Mapping table is empty - nothing to revert."));
            return;
        }
        // 1. Reverse user-id rewrites in AA data.
        foreach (self::REWRITE_COLUMNS as $spec) {
            $t = $spec['table'];
            $c = $spec['col'];
            $changed = 0;
            foreach ($pairs as $row) {
                $cnt = DB_AA::count($t, [[$c, (string) $row['sql_id']]]);
                if (!$cnt) {
                    continue;
                }
                DB_AA::update($t, [[$c, $row['ldap_dn']]], [[$c, (string) $row['sql_id']]]);
                $changed += $cnt;
            }
            $this->message(_m("Revert %1.%2: %3 rows", [$t, $c, $changed]));
        }
        // 2. Delete SQL users/groups we created (mirrors perm_sql DelUser/DelGroup).
        $users = 0;
        $groups = 0;
        foreach ($pairs as $row) {
            $sql_id = (int) $row['sql_id'];
            DB_AA::delete('membership', [['memberid', $sql_id, 'i']]);
            DB_AA::delete('membership', [['groupid',  $sql_id, 'i']]);
            self::forgetMembershipMemo();
            DB_AA::delete('perms', [['userid', (string) $sql_id]]);
            DB_AA::delete('users', [['id', $sql_id, 'i']]);
            if ($row['kind'] === 'group') {
                $groups++;
            } else {
                $users++;
            }
        }
        $this->message(_m("Deleted SQL users=%1 groups=%2", [$users, $groups]));
        // 3. Drop mapping rows but keep the table itself for audit.
        DB_AA::sql("DELETE FROM `" . self::MAP_TABLE . "`");
    }

    //========================================================== helpers

    /** Extract the `uid` component from a DN like `uid=foo,ou=People,...`. */
    private function extractUidFromDn(string $dn): string
    {
        if (preg_match('/^uid=([^,]+),/i', $dn, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Direct SQL insert into the users table (mirrors perm_sql::AddUser). */
    private function sqlInsertUser(string $login, array $info, string $pwd_hash): ?int
    {
        $id = AA::Metabase()->doInsert('users', [
            'type'        => 'User',
            'uid'         => $login,
            'mail'        => is_array($info['mails'] ?? null) ? ($info['mails'][0] ?? '') : ($info['mail'] ?? ''),
            'name'        => trim(($info['givenname'] ?? '') . ' ' . ($info['sn'] ?? '')),
            'sn'          => $info['sn'] ?? '',
            'givenname'   => $info['givenname'] ?? '',
            'description' => '',
            'password'    => $pwd_hash,
        ]);
        return $id ? (int) $id : null;
    }

    /** Direct SQL insert into the users table for a group
     *  (mirrors perm_sql::AddGroup).
     */
    private function sqlInsertGroup(string $name, string $description): ?int
    {
        $id = AA::Metabase()->doInsert('users', [
            'type'        => 'Group',
            'uid'         => '',
            'mail'        => '',
            'name'        => $name,
            'sn'          => '',
            'givenname'   => '',
            'description' => $description,
            'password'    => 'crypt will never return this',
        ]);
        return $id ? (int) $id : null;
    }

    /** Read the userPassword attribute from LDAP for a given user DN.
     *  Returns the raw hash string (e.g. {SSHA}...) or null on failure.
     *  Uses perm_ldap.php's InitLDAP() which is already loaded.
     */
    private function readLdapUserPassword(string $dn): ?string
    {
        if (!function_exists('InitLDAP')) {
            return null;
        }
        $ds = \InitLDAP();
        if (!$ds) {
            return null;
        }
        $result = @\ldap_read($ds, $dn, 'objectclass=*', ['userPassword']);
        if (!$result) {
            @\ldap_unbind($ds);
            return null;
        }
        $entries = \ldap_get_entries($ds, $result);
        @\ldap_unbind($ds);
        if (!$entries || $entries['count'] < 1) {
            return null;
        }
        $attr = $entries[0]['userpassword'] ?? null;
        if (!is_array($attr) || $attr['count'] < 1) {
            return null;
        }
        return (string) $attr[0];
    }

    /** An unusable placeholder hash used when we cannot read the LDAP
     *  userPassword. It will fail both legacy-hash and bcrypt checks,
     *  and can later be overwritten by a repair re-run.
     */
    private function placeholderHash(): string
    {
        return '{CRYPT}$6$disabled$x';
    }

    private function isPlaceholderHash(?string $hash): bool
    {
        return $hash === null || $hash === '' || str_contains((string) $hash, '$disabled$');
    }

    /** Overwrite the SQL users.password with the raw LDAP hash so the
     *  opportunistic-rehash login path can verify it on first login.
     */
    private function maybeCopyLdapHash(string $dn, int $sql_id): void
    {
        $hash = $this->readLdapUserPassword($dn);
        if (!$hash) {
            return;
        }
        DB_AA::update('users', [['password', $hash]], [['id', $sql_id, 'i']]);
    }

    //----- mapping table helpers

    private function mapGet(string $dn): ?int
    {
        $row = DB_AA::select1('sql_id', "SELECT sql_id FROM `" . self::MAP_TABLE . "`", [['ldap_dn', $dn]]);
        return $row ? (int) $row : null;
    }

    private function mapPut(string $dn, int $sql_id, string $kind, string $login): void
    {
        $varset = new CVarset([
            ['ldap_dn', $dn],
            ['sql_id',  $sql_id],
            ['kind',    $kind],
            ['login',   $login],
            ['created', date('Y-m-d H:i:s')],
        ]);
        $varset->doInsert(self::MAP_TABLE);
    }

    /** @return array<int,array{ldap_dn:string,sql_id:int,kind:string,login:string}> */
    private function mapAll(): array
    {
        $rows = DB_AA::select([], "SELECT ldap_dn, sql_id, kind, login FROM `" . self::MAP_TABLE . "`");
        return is_array($rows) ? $rows : [];
    }

    /**
     * AA_Permsystem_Sql memoizes getMembership() per request; every write to `membership` has to
     * drop that memo. perm_sql.php is only loaded when PERM_LIB is 'sql' (it must never be
     * required from here - its functions clash with perm_ldap.php), so the call is guarded.
     */
    private static function forgetMembershipMemo(): void
    {
        if (class_exists(\AA_Permsystem_Sql::class, false)) {
            \AA_Permsystem_Sql::forgetMembership();
        }
    }
}
