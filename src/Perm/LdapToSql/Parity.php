<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

/**
 * Compares what the LDAP permission system answers with what the converted SQL one does:
 * LDAP is asked with every spelling of a DN (ask(), before the import), SQL with its id
 * (compare(), after it, inside the same transaction).
 *
 * Any right SQL would deny that LDAP grants blocks the switch, and so does an answer LDAP
 * did not give although its snapshot has it - its read functions turn failures into
 * empty answers; so does any right SQL grants beyond LDAP's answer for the directory DN,
 * which is all the import took. Gains under other spellings are info.
 *
 * @phpstan-type Answers array{
 *     objects: array<string, list<string>>,
 *     objectPerms: array<string, array<string, string>>,
 *     people: array<int|string, array<string, PersonAnswer>>,
 *     groups: array<int|string, GroupAnswer>,
 * }
 * @phpstan-type PersonAnswer array{perms: array<string, array<string, string>>, membership: array{0: list<string>, 1: list<string>}, info: false|array<string, mixed>}
 * @phpstan-type GroupAnswer array{info: false|array<string, mixed>, members: list<string>}
 */
final class Parity
{
    /** category => [blocking, text]; findings come out in this order */
    private const CATEGORIES = [
        'lost'       => [true, 'Rights LDAP grants and SQL does not, as CheckPerms() combines them'],
        'objects'    => [true, 'Object rights missing, different or extra in SQL'],
        'unknown'    => [true, 'People or groups SQL does not know, or knows as another type'],
        'membership' => [true, 'Group memberships missing in SQL'],
        'members'    => [true, 'Group members missing in SQL'],
        'silent'     => [true, 'Answers LDAP did not give although its snapshot has them (a failed LDAP read?)'],
        'gained'     => [false, 'Rights SQL grants beyond LDAP'],
        'joined'     => [false, 'Group memberships SQL has beyond LDAP'],
        'details'    => [false, 'Login, mail or name differs between LDAP and SQL'],
    ];

    /** @var \AA_Perm set by compare() only - ask() must not touch SQL */
    private object $sql;

    /** @var array<int|string, string> id => directory DN */
    private array $dnOf = [];

    /** @var array<string, true> normalized DNs of groups */
    private array $groupKeys = [];

    /** @var array<string, list<string>> category => items */
    private array $items = [];

    /** @var array<string, mixed> SQL answers by question; the spellings of one DN share an id */
    private array $sqlMemo = [];

    private function __construct(private readonly Plan $plan)
    {
        foreach ($plan->ids as $key => $id) {
            $this->dnOf[(string) $id] = $plan->canonical[$key];
        }
        $groupIds = array_column($plan->groupRows, 'id', 'id');
        foreach ($plan->ids as $key => $id) {
            if (isset($groupIds[$id])) {
                $this->groupKeys[$key] = true;
            }
        }
    }

    /**
     * Everything compare() needs from LDAP, asked once before any transaction. Each person
     * is asked under the directory DN, the DN AA builds from the typed login and every
     * referenced spelling (Plan::$remap).
     *
     * @param \AA_Perm $ldap AA_Perm(['ldap', 'Reader'])
     * @param list<array{type: string, id: string}> $objects the objects of this DB
     * @param ?string $people_base LDAP_PEOPLE; null reads the constant (no login spelling without it)
     * @return Answers
     */
    public static function ask(object $ldap, Plan $plan, array $objects, string $aa_id, ?string $people_base = null): array
    {
        $parity = new self($plan);
        $people_base ??= \defined('LDAP_PEOPLE') ? (string) \constant('LDAP_PEOPLE') : '';

        $byType = [];
        foreach ($objects as $object) {
            $byType[$object['type']][$object['id']] = true;
        }
        $answers = ['objects' => [], 'objectPerms' => [], 'people' => [], 'groups' => []];
        foreach ($byType as $type => $ids) {
            foreach (array_keys($ids) as $id) {
                $answers['objects'][(string) $type][] = (string) $id;
                $answers['objectPerms']["$type $id"]  = self::strings($ldap->getObjectsPerms((string) $id, (string) $type));
            }
        }
        // the AA right decides every slice right too
        $wanted       = $byType;
        $wanted['aa'] = ($wanted['aa'] ?? []) + [$aa_id => true];

        foreach ($parity->spellings($people_base) as $id => $dns) {
            foreach ($dns as $dn) {
                $perms = [];
                foreach ($wanted as $type => $ids) {
                    $perms[(string) $type] = array_intersect_key(self::strings($ldap->getUserPerms($dn, (string) $type)), $ids);
                }
                $answers['people'][$id][$dn] = [
                    'perms'      => $perms,
                    'membership' => [self::list($ldap->getMembership($dn, 0)), self::list($ldap->getMembership($dn, 1))],
                    'info'       => $ldap->getIDsInfo($dn),
                ];
            }
        }
        foreach ($plan->groupRows as $group) {
            $dn = $parity->dnOf[(string) $group['id']];
            $answers['groups'][(string) $group['id']] = [
                'info'    => $ldap->getIDsInfo($dn),
                'members' => self::list(array_keys($ldap->getGroupMembers($dn))),
            ];
        }
        return $answers;
    }

    /**
     * @param Answers $answers from ask() with the same plan
     * @param \AA_Perm $sql AA_Perm(['sql', 'Reader'])
     * @return list<array{blocking: bool, text: string}>
     */
    public static function compare(array $answers, object $sql, Plan $plan, string $aa_id): array
    {
        $parity      = new self($plan);
        $parity->sql = $sql;
        foreach ($answers['objects'] as $type => $ids) {
            foreach ($ids as $id) {
                $parity->object((string) $type, $id, $answers['objectPerms']["$type $id"] ?? []);
            }
        }
        foreach ($answers['people'] as $id => $spellings) {
            foreach ($spellings as $dn => $answer) {
                $parity->person((string) $id, (string) $dn, $answer, $answers['objects'], $aa_id);
            }
        }
        foreach ($answers['groups'] as $id => $answer) {
            $parity->group((string) $id, $answer);
        }

        $findings = [];
        if (!$parity->hasSuperadmin($aa_id)) {
            $findings[] = [
                'blocking' => true,
                'text'     => "In SQL no user and no group with a member holds the superadmin right (4) on aa $aa_id, so nobody could administer this AA and admin/setup.php, once switched on in config.php, would create a new superadmin.",
            ];
        }
        foreach (self::CATEGORIES as $category => [$blocking, $text]) {
            if ($finding = Plan::finding($blocking, $text, $parity->items[$category] ?? [])) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * Directory DN first, then the login DN and the referenced spellings of the person.
     *
     * @return array<int|string, list<string>> id => spellings
     */
    private function spellings(string $people_base): array
    {
        /** @var array<int|string, list<string>> $variants id => referenced spellings */
        $variants = [];
        foreach (array_keys($this->plan->remap) as $variant) {
            $variants[$this->map((string) $variant) ?? ''][] = (string) $variant;
        }
        $out = [];
        foreach ($this->plan->users as $user) {
            $id  = (string) $user['id'];
            $dns = [$this->dnOf[$id]];
            if ($people_base !== '') {
                // as AA_Permsystem_Ldap::authenticateUsername() builds the session DN
                $dns[] = 'uid=' . self::escapeDn($user['uid']) . ',' . $people_base;
            }
            $out[$id] = [];
            foreach ([...$dns, ...$variants[$id] ?? []] as $dn) {
                // a login DN under another base does not bind, so no session ever had it
                if (!in_array($dn, $out[$id], true) && $this->map($dn) === $id) {
                    $out[$id][] = $dn;
                }
            }
        }
        return $out;
    }

    /** @param array<string, string> $ldapPerms principal => perm, as LDAP keeps them on the object */
    private function object(string $type, string $object, array $ldapPerms): void
    {
        $where = "$type $object";
        // spellings LDAP never applied, and principals the plan does not import, have their own plan findings
        $ldap = Plan::applied($ldapPerms, $this->plan->ids, $this->plan->canonical, $this->groupKeys)['applied'];
        $sqlPerms = $this->sqlObjectPerms($type, $object);
        foreach ($ldap as $id => [$principals, $perm]) {
            if (!isset($sqlPerms[$id])) {
                $this->items['objects'][] = "$principals on $where: missing (LDAP $perm)";
            } elseif (self::letters($perm) !== self::letters($sqlPerms[$id])) {
                $this->items['objects'][] = "$principals on $where: LDAP $perm, SQL {$sqlPerms[$id]}";
            }
        }
        // the import copies LDAP, so a row LDAP lacks means LDAP did not answer
        foreach (array_diff_key($sqlPerms, $ldap) as $id => $perm) {
            $this->items['objects'][] = $this->label((string) $id) . " on $where: only in SQL ($perm)";
        }
    }

    /**
     * @param PersonAnswer $answer
     * @param array<string, list<string>> $objects
     */
    private function person(string $id, string $dn, array $answer, array $objects, string $aa_id): void
    {
        // SQL holds only what LDAP applied to the directory DN: more in SQL there means LDAP did not
        // answer; another spelling may just miss a person's own ACI
        $canonical = $dn === $this->dnOf[$id];
        $ldapAa    = $answer['perms']['aa'][$aa_id] ?? '';
        $sqlAa     = $this->sqlUserPerms($id, 'aa')[$aa_id] ?? '';
        foreach ($objects as $type => $ids) {
            $type = (string) $type;
            $sql  = $this->sqlUserPerms($id, $type);
            foreach ($ids as $object) {
                $ldapHas = $answer['perms'][$type][$object] ?? '';
                $sqlHas  = $sql[$object] ?? '';
                if ($type === 'slice') {
                    // a slice right replaces the AA one unless that is SUPER
                    $ldapHas = (string) \AA_Perm::joinSliceAndAAPerm($ldapHas, $ldapAa);
                    $sqlHas  = (string) \AA_Perm::joinSliceAndAAPerm($sqlHas, $sqlAa);
                }
                $where = "$dn on $type $object";
                if ($lost = array_diff(self::letters($ldapHas), self::letters($sqlHas))) {
                    $this->items['lost'][] = "$where: " . implode('', $lost) . " (LDAP $ldapHas, SQL $sqlHas)";
                }
                if ($gained = array_diff(self::letters($sqlHas), self::letters($ldapHas))) {
                    $this->items[$canonical ? 'silent' : 'gained'][] = "$where: " . implode('', $gained) . " (LDAP $ldapHas, SQL $sqlHas)";
                }
            }
        }
        // IsSuperadmin() looks for the role digit itself, not for its letters
        $super = \AA_Perm::ROLES['SUPER']['id'];
        if (\IsPerm($ldapAa, $super) && !\IsPerm($sqlAa, $super)) {
            $this->items['lost'][] = "$dn: superadmin (LDAP $ldapAa, SQL $sqlAa)";
        } elseif (!\IsPerm($ldapAa, $super) && \IsPerm($sqlAa, $super)) {
            $this->items[$canonical ? 'silent' : 'gained'][] = "$dn: superadmin (LDAP $ldapAa, SQL $sqlAa)";
        }

        foreach ([0 => 'any level', 1 => 'direct'] as $flags => $level) {
            $sqlGroups = array_flip($this->sqlMembership($id, $flags));
            /** @var array<int|string, true> $ldapIds */
            $ldapIds = [];
            foreach ($answer['membership'][$flags] as $group) {
                $groupId = $this->map($group);
                if ($groupId !== null) {
                    $ldapIds[$groupId] = true;
                }
                if ($groupId === null || !isset($sqlGroups[$groupId])) {
                    $this->items['membership'][] = "$dn in $group ($level)";
                }
            }
            if (!$canonical) {
                continue;
            }
            foreach (array_diff_key($sqlGroups, $ldapIds) as $groupId => $_) {
                $this->items['joined'][] = "$dn in " . $this->label((string) $groupId) . " ($level)";
            }
            if ($flags === 1) {
                foreach ($this->plan->membership as $row) {
                    if ($row['memberid'] === $id && !isset($ldapIds[(string) $row['groupid']])) {
                        $this->items['silent'][] = "$dn in " . $this->label((string) $row['groupid']);
                    }
                }
            }
        }

        $ldapInfo = $answer['info'];
        $sqlInfo  = $this->sqlInfo($id);
        if ($canonical && !$ldapInfo) {
            $this->items['silent'][] = "$dn: no entry";
        }
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

    /** @param GroupAnswer $answer */
    private function group(string $id, array $answer): void
    {
        $dn      = $this->dnOf[$id];
        $sqlInfo = $this->sqlInfo($id);
        if (!$answer['info']) {
            $this->items['silent'][] = "$dn: no entry";
        }
        if (!$sqlInfo || ($sqlInfo['type'] ?? '') !== 'Group') {
            $this->items['unknown'][] = "$dn (id $id): " . ($sqlInfo ? "SQL {$sqlInfo['type']}" : 'missing');
        }
        $sqlMembers = $this->sqlMembers($id);
        /** @var array<int|string, true> $ldapIds */
        $ldapIds = [];
        foreach ($answer['members'] as $member) {
            $memberId = $this->map($member);
            // a member the plan drops (deleted, placeholder) has its own plan finding
            if ($memberId === null) {
                continue;
            }
            $ldapIds[$memberId] = true;
            if (!isset($sqlMembers[$memberId])) {
                $this->items['members'][] = "$member in $dn";
            }
        }
        foreach ($this->plan->membership as $row) {
            $memberId = $row['memberid'];
            // LDAP lists only members it can describe, never a reader
            if ((string) $row['groupid'] === $id && !\is_long_id($memberId) && !isset($ldapIds[$memberId])) {
                $this->items['silent'][] = $this->label($memberId) . " in $dn";
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

    /** ldap_escape($value, '', LDAP_ESCAPE_DN); test hosts lack ext-ldap */
    private static function escapeDn(string $value): string
    {
        if (\function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_DN);
        }
        $out = '';
        foreach (str_split($value) as $char) {
            $out .= str_contains("\\,=+<>;\"#\r", $char) ? sprintf('\\%02x', \ord($char)) : $char;
        }
        if (str_starts_with($out, ' ')) {
            $out = '\\20' . substr($out, 1);
        }
        if (strlen($out) > 1 && str_ends_with($out, ' ')) {
            $out = substr($out, 0, -1) . '\\20';
        }
        return $out;
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
        return $this->sqlMemo["membership\0$id\0$flags"] ??= self::list($this->sql->getMembership($id, $flags));
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

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function list(array $values): array
    {
        return array_values(array_map('strval', $values));
    }
}
