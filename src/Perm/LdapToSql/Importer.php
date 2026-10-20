<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

use AA\IO\DB\DB_AA;

/**
 * Replaces the SQL users, groups, memberships and rights with the converted LDAP ones,
 * through the SQL backend's own methods.
 *
 * Meant to run inside DB_AA::transaction(): every failure throws, so the caller's
 * transaction rolls back and the install keeps its previous state. Each read loads its
 * whole result before the next write, so the block needs only the transaction's handle.
 */
final class Importer
{
    /**
     * @return array{deleted: int, users: int, groups: int, membership: int, perms: int}
     *         deleted = rows removed by a call of their own: every users row, plus membership
     *         and perms rows left behind by ids that no longer exist
     * @throws \RuntimeException on the first write the backend refuses
     */
    public static function run(\AA_Permsystem_Sql $sql, Plan $plan): array
    {
        $deleted = self::wipe($sql);

        foreach ($plan->users as $row) {
            self::ensure($sql->importUser($row), "user {$row['uid']} ({$row['id']})");
        }
        foreach ($plan->groupRows as $row) {
            self::ensure($sql->importGroup($row), "group {$row['name']} ({$row['id']})");
        }
        foreach ($plan->membership as $row) {
            self::ensure($sql->addGroupMember($row['groupid'], $row['memberid']), "member {$row['memberid']} of group {$row['groupid']}");
        }
        foreach ($plan->perms as $row) {
            self::ensure(
                $sql->addPerm($row['userid'], $row['objectid'], $row['object_type'], $row['perm']),
                "right of {$row['userid']} on {$row['object_type']} {$row['objectid']}",
            );
        }
        \AA_Permsystem_Sql::forgetMembership();

        return [
            'deleted'    => $deleted,
            'users'      => count($plan->users),
            'groups'     => count($plan->groupRows),
            'membership' => count($plan->membership),
            'perms'      => count($plan->perms),
        ];
    }

    /**
     * Removes every existing row: dormant SQL accounts would otherwise wake up beside the
     * imported ones, and a stale numeric id in perms would grant rights to whoever gets it.
     */
    private static function wipe(\AA_Permsystem_Sql $sql): int
    {
        // any other type than Group is deleted as a user - the row must go either way
        $users = DB_AA::select(['id' => 'type'], 'SELECT id, type FROM users ORDER BY id');
        foreach ($users as $id => $type) {
            $done = (strcasecmp((string) $type, 'Group') === 0) ? $sql->delGroup($id, 3) : $sql->delUser($id, 3);
            self::ensure($done, "delete of users row $id");
        }

        // what delUser()/delGroup() do not reach: rows of a groupid or userid without a users row
        // (readers' hex ids, deleted accounts) and memberships pointing into a person's id
        $membership = DB_AA::select([], 'SELECT groupid, memberid FROM membership');
        foreach ($membership as $row) {
            self::ensure($sql->delGroupMember($row['groupid'], $row['memberid']), "delete of member {$row['memberid']} of group {$row['groupid']}");
        }
        $perms = DB_AA::select([], 'SELECT object_type, objectid, userid FROM perms');
        foreach ($perms as $row) {
            self::ensure(
                $sql->delPerm($row['userid'], $row['objectid'], (string) $row['object_type']),
                "delete of the right of {$row['userid']} on {$row['object_type']} {$row['objectid']}",
            );
        }

        return count($users) + count($membership) + count($perms);
    }

    private static function ensure(bool $done, string $what): void
    {
        if (!$done) {
            throw new \RuntimeException("LDAP to SQL import failed: $what was not stored.");
        }
    }
}
