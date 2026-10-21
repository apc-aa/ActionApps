<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

/**
 * Asks the LDAP and the converted SQL permission system the same questions and reports
 * where the answers differ: LDAP is asked with each spelling of a DN, SQL with its id.
 *
 * Whatever SQL would deny that LDAP grants blocks the switch; whatever SQL grants on top
 * is info. Principals and DN spellings the plan already drops (with its own finding) are
 * not reported again.
 */
final class Parity
{
    /** Finding texts list at most this many examples; the count is always complete. */
    private const EXAMPLES = 10;

    /** category => [blocking, text]; findings come out in this order */
    private const CATEGORIES = [
        'lost'       => [true, 'Rights LDAP grants and SQL does not (missing letters)'],
        'unknown'    => [true, 'People or groups SQL does not know, or knows as another type'],
        'membership' => [true, 'Group memberships missing in SQL'],
        'members'    => [true, 'Group members missing in SQL'],
        'objects'    => [true, 'Object rights missing or different in SQL'],
        'dropped'    => [false, 'Rights LDAP grants only through a DN spelling the conversion drops (see the plan findings)'],
        'gained'     => [false, 'Rights SQL grants beyond LDAP (extra letters)'],
        'joined'     => [false, 'Group memberships SQL has beyond LDAP'],
        'extra'      => [false, 'Object rights SQL has and LDAP does not'],
        'details'    => [false, 'Login, mail or name differs between LDAP and SQL'],
    ];

    /** @var array<string, array<string, true>> object type => object id => true */
    private array $objects = [];

    /** @var array<string, string> id => directory DN */
    private array $dnOf = [];

    /** @var array<string, true> "type\0object\0id" where the plan drops a spelling granting other rights */
    private array $droppedSpelling = [];

    /** @var array<string, list<string>> category => items */
    private array $items = [];

    /** @var array<string, mixed> SQL answers by question; variants of one DN share an id */
    private array $sqlMemo = [];

    /**
     * @param \AA_Perm $ldap
     * @param \AA_Perm $sql
     */
    private function __construct(
        private readonly object $ldap,
        private readonly object $sql,
        private readonly Plan $plan,
    ) {
        foreach ($plan->ids as $key => $id) {
            $this->dnOf[(string) $id] = $plan->canonical[$key];
        }
    }

    /**
     * @param \AA_Perm $ldap AA_Perm(['ldap', 'Reader'])
     * @param \AA_Perm $sql AA_Perm(['sql', 'Reader'])
     * @param list<string> $variants every referenced DN value of a person (Plan::$remap keys)
     * @param list<array{type: string, id: string}> $objects the objects of this DB
     * @return list<array{blocking: bool, text: string}>
     */
    public static function compare(object $ldap, object $sql, Plan $plan, array $variants, array $objects, string $aa_id): array
    {
        $parity = new self($ldap, $sql, $plan);
        foreach ($objects as $object) {
            $parity->objects[$object['type']][$object['id']] = true;
        }
        // object rights first: they tell which lost letters the plan's dropped spellings explain
        foreach ($parity->objects as $type => $ids) {
            foreach (array_keys($ids) as $id) {
                $parity->object((string) $type, (string) $id);
            }
        }
        $spellings = [];
        foreach ($plan->users as $user) {
            $spellings[(string) $user['id']] = [];
        }
        foreach ($plan->ids as $key => $id) {
            if (isset($spellings[(string) $id])) {
                $spellings[(string) $id][] = $plan->canonical[$key];
            }
        }
        foreach (array_unique($variants) as $variant) {
            $id = $parity->map($variant);
            if ($id !== null && isset($spellings[$id]) && !in_array($variant, $spellings[$id], true)) {
                $spellings[$id][] = $variant;
            }
        }
        foreach ($spellings as $id => $dns) {
            foreach ($dns as $i => $dn) {
                $parity->person((string) $id, $dn, $i === 0);
            }
        }
        foreach ($plan->groupRows as $group) {
            $parity->group((string) $group['id']);
        }

        $findings = [];
        if (!$parity->hasSuperadmin($aa_id)) {
            $findings[] = [
                'blocking' => true,
                'text'     => "In SQL no user and no group with a member holds the superadmin right (4) on aa $aa_id, so admin/setup.php would let anyone create a new superadmin.",
            ];
        }
        foreach (self::CATEGORIES as $category => [$blocking, $text]) {
            $items = $parity->items[$category] ?? [];
            if ($items) {
                $more       = count($items) > self::EXAMPLES ? '; ...' : '';
                $findings[] = [
                    'blocking' => $blocking,
                    'text'     => "$text (" . count($items) . '): ' . implode('; ', array_slice($items, 0, self::EXAMPLES)) . $more,
                ];
            }
        }
        return $findings;
    }

    /** The rights LDAP keeps on the object, mapped to ids as the plan imported them */
    private function object(string $type, string $object): void
    {
        $where = "$type $object";
        /** @var array<string, array{0: string, 1: string, 2: bool}> $chosen id => [principal, perm, spelled as in the directory] */
        $chosen  = [];
        $dropped = [];
        foreach ($this->ldap->getObjectsPerms($object, $type) as $principal => $perm) {
            $principal = (string) $principal;
            $id        = $this->map($principal);
            if ($id === null) {
                continue;
            }
            // the plan's choice among spellings of one principal
            $asDirectory = strcasecmp($principal, $this->dnOf[$id] ?? '') === 0;
            if (!isset($chosen[$id])) {
                $chosen[$id] = [$principal, (string) $perm, $asDirectory];
                continue;
            }
            if ($asDirectory && !$chosen[$id][2]) {
                $dropped[$id][] = $chosen[$id][1];
                $chosen[$id]    = [$principal, (string) $perm, true];
            } else {
                $dropped[$id][] = (string) $perm;
            }
        }
        foreach ($dropped as $id => $perms) {
            foreach ($perms as $perm) {
                if (self::letters($perm) !== self::letters($chosen[$id][1])) {
                    $this->droppedSpelling["$type\0$object\0$id"] = true;
                }
            }
        }

        $sqlPerms = $this->sqlObjectPerms($type, $object);
        foreach ($chosen as $id => [$principal, $perm]) {
            if (!isset($sqlPerms[$id])) {
                $this->items['objects'][] = "$principal on $where: missing (LDAP $perm)";
            } elseif (self::letters($perm) !== self::letters($sqlPerms[$id])) {
                $this->items['objects'][] = "$principal on $where: LDAP $perm, SQL {$sqlPerms[$id]}";
            }
        }
        foreach (array_diff_key($sqlPerms, $chosen) as $id => $perm) {
            $this->items['extra'][] = $this->label((string) $id) . " on $where: $perm";
        }
    }

    private function person(string $id, string $dn, bool $canonical): void
    {
        foreach ($this->objects as $type => $objects) {
            $type = (string) $type;
            $ldap = $this->ldap->getUserPerms($dn, $type);
            $sql  = $this->sqlUserPerms($id, $type);
            foreach (array_keys($objects) as $object) {
                $object  = (string) $object;
                $ldapHas = (string) ($ldap[$object] ?? '');
                $sqlHas  = (string) ($sql[$object] ?? '');
                $where   = "$dn on $type $object";
                if ($lost = array_diff(self::letters($ldapHas), self::letters($sqlHas))) {
                    $category                 = $this->explainedByDroppedSpelling($id, $type, $object) ? 'dropped' : 'lost';
                    $this->items[$category][] = "$where: " . implode('', $lost) . " (LDAP $ldapHas, SQL $sqlHas)";
                }
                // a variant seldom has an ACI of its own; its gains would only repeat the canonical ones
                if ($canonical && ($gained = array_diff(self::letters($sqlHas), self::letters($ldapHas)))) {
                    $this->items['gained'][] = "$where: " . implode('', $gained) . " (LDAP $ldapHas, SQL $sqlHas)";
                }
            }
        }

        foreach ([0 => 'any level', 1 => 'direct'] as $flags => $level) {
            $sqlGroups = array_flip($this->sqlMembership($id, $flags));
            $ldapIds   = [];
            foreach ($this->ldap->getMembership($dn, $flags) as $group) {
                $group   = (string) $group;
                $groupId = $this->map($group);
                if ($groupId === null || !isset($sqlGroups[$groupId])) {
                    $this->items['membership'][] = "$dn in $group ($level)";
                } else {
                    $ldapIds[$groupId] = true;
                }
            }
            if ($canonical) {
                foreach (array_diff_key($sqlGroups, $ldapIds) as $groupId => $_) {
                    $this->items['joined'][] = "$dn in " . $this->label((string) $groupId) . " ($level)";
                }
            }
        }

        $ldapInfo = $this->ldap->getIDsInfo($dn);
        $sqlInfo  = $this->sqlInfo($id);
        if (!$sqlInfo) {
            if ($canonical) {
                $this->items['unknown'][] = "$dn (id $id): missing";
            }
        } elseif ($ldapInfo && ($ldapInfo['type'] ?? '') !== ($sqlInfo['type'] ?? '')) {
            $this->items['unknown'][] = "$dn (id $id): LDAP {$ldapInfo['type']}, SQL {$sqlInfo['type']}";
        } elseif ($ldapInfo && $canonical) {
            foreach (['login', 'mail', 'name'] as $field) {
                $ldapValue = (string) ($ldapInfo[$field] ?? '');
                $sqlValue  = (string) ($sqlInfo[$field] ?? '');
                if ($ldapValue !== $sqlValue) {
                    $this->items['details'][] = "$dn $field: LDAP '$ldapValue', SQL '$sqlValue'";
                }
            }
        }
    }

    private function group(string $id): void
    {
        $dn      = $this->dnOf[$id];
        $sqlInfo = $this->sqlInfo($id);
        if (!$sqlInfo || ($sqlInfo['type'] ?? '') !== 'Group') {
            $this->items['unknown'][] = "$dn (id $id): " . ($sqlInfo ? "SQL {$sqlInfo['type']}" : 'missing');
        }
        $sqlMembers = $this->sqlMembers($id);
        foreach (array_keys($this->ldap->getGroupMembers($dn)) as $member) {
            $member   = (string) $member;
            $memberId = $this->map($member);
            // a member the plan drops (deleted, placeholder) has its own plan finding
            if ($memberId !== null && !isset($sqlMembers[$memberId])) {
                $this->items['members'][] = "$member in $dn";
            }
        }
    }

    /** The condition admin/setup.php checks before it offers to create a superadmin */
    private function hasSuperadmin(string $aa_id): bool
    {
        foreach ($this->sqlObjectPerms('aa', $aa_id) as $id => $perm) {
            // loose, as setup.php compares
            if ($perm != \AA_Perm::ROLES['SUPER']['id']) {
                continue;
            }
            $info = $this->sqlInfo((string) $id);
            if ($info && (($info['type'] ?? '') !== 'Group' || count($this->sqlMembers((string) $id)) > 0)) {
                return true;
            }
        }
        return false;
    }

    /** LDAP lost the letters only where the plan dropped a spelling of the person or of one of their groups */
    private function explainedByDroppedSpelling(string $id, string $type, string $object): bool
    {
        foreach ([$id, ...$this->sqlMembership($id, 0)] as $principal) {
            if (isset($this->droppedSpelling["$type\0$object\0$principal"])) {
                return true;
            }
        }
        return false;
    }

    /** SQL id of an LDAP principal; a reader id stays; null for what the plan does not import */
    private function map(string $value): ?string
    {
        if (Dn::isDn($value)) {
            $id = $this->plan->ids[Dn::normalize($value)] ?? null;
            return $id === null ? null : (string) $id;
        }
        return \is_long_id($value) ? $value : null;
    }

    private function label(string $id): string
    {
        return isset($this->dnOf[$id]) ? "{$this->dnOf[$id]} (id $id)" : $id;
    }

    /** @return list<string> distinct letters, the roles resolved */
    private static function letters(string $perms): array
    {
        $letters = array_unique(str_split(\AA_Perm::resolveRoles($perms)));
        sort($letters);
        return $letters;
    }

    /** @return array<string, string> object id => perm */
    private function sqlUserPerms(string $id, string $type): array
    {
        return $this->sqlMemo["perms\0$id\0$type"] ??= self::strings($this->sql->getUserPerms($id, $type));
    }

    /** @return list<string> group ids */
    private function sqlMembership(string $id, int $flags): array
    {
        return $this->sqlMemo["membership\0$id\0$flags"] ??= array_values(array_map('strval', $this->sql->getMembership($id, $flags)));
    }

    /** @return false|array<string, mixed> */
    private function sqlInfo(string $id): false|array
    {
        return $this->sqlMemo["info\0$id"] ??= $this->sql->getIDsInfo($id);
    }

    /** @return array<string, mixed> member id => info */
    private function sqlMembers(string $id): array
    {
        return $this->sqlMemo["members\0$id"] ??= $this->sql->getGroupMembers($id);
    }

    /** @return array<string, string> principal id => perm */
    private function sqlObjectPerms(string $type, string $object): array
    {
        return $this->sqlMemo["objects\0$type\0$object"] ??= self::strings($this->sql->getObjectsPerms($object, $type));
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<string, string>
     */
    private static function strings(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[(string) $key] = (string) $value;
        }
        return $out;
    }
}
