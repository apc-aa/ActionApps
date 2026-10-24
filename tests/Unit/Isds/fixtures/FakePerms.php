<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA;
use AA\Isds\Access;
use AA_Perm;
use AA_Permsystem;

/**
 * AA::$perm over the rights given here, joined as perm_sql.php joins them (getJoinedPerms()): the
 * grants of a login's groups - the reader slice and the sets of readers - add up, and the login's own
 * grant on a slice replaces them there. Rights on slices, and the AA-wide ones of $aa (a superadmin).
 * Change $grants to take a right away.
 */
final class FakePerms extends AA_Permsystem
{
    /** AA::$perm before install(); the outer array tells it was saved */
    private static array $saved = [];

    /**
     * @param array<string, array<string, string>> $grants subject (a login, a group) => slice => letters or a role id
     * @param array<string, list<string>>          $groups login => the groups it is a member of
     * @param array<string, string>                $aa     login => letters or a role id over all of AA
     */
    public function __construct(public array $grants = [], public array $groups = [], public array $aa = []) {}

    /**
     * @param array<string, array<string, string>> $grants
     * @param array<string, list<string>>          $groups
     * @param array<string, string>                $aa
     */
    public static function install(array $grants, array $groups = [], array $aa = []): self
    {
        self::$saved = self::$saved ?: [AA::$perm];
        $fake        = new self($grants, $groups, $aa);
        $perm        = new AA_Perm([]);
        (new \ReflectionProperty(AA_Perm::class, 'perm_systems'))->setValue($perm, ['fake' => $fake]);
        AA::$perm = $perm;

        return $fake;
    }

    public static function uninstall(): void
    {
        if (self::$saved) {
            AA::$perm    = self::$saved[0];
            self::$saved = [];
        }
    }

    /** Access as the login of the request sees it */
    public static function login(string $uid): Access
    {
        return new class ($uid) extends Access {
            public function __construct(private string $login) {}

            public function uid(): string
            {
                return $this->login;
            }
        };
    }

    public function storesGeneralPerms(): bool
    {
        return true;
    }

    public function getMembership($id, $flags = 0): array
    {
        return $this->groups[$id] ?? [];
    }

    public function getJoinedPerms($uid, $objectType, $groups = []): array
    {
        if ($objectType === 'aa') {
            return isset($this->aa[$uid]) ? [AA_ID => $this->aa[$uid]] : [];
        }
        if ($objectType !== 'slice') {
            return [];
        }
        $joined = [];
        foreach ($groups as $group) {
            foreach ($this->grants[$group] ?? [] as $slice => $perm) {
                $joined[$slice] = ($joined[$slice] ?? '') . $perm;
            }
        }

        return ($this->grants[$uid] ?? []) + $joined;
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
}
