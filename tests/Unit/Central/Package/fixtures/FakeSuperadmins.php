<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

/** AA::$perm for the Central tests: who is a superadmin (AA_Perm::isSuperadminUser()) - $asked logs every
 *  user asked about - and the cache AaAfterCommit::permissions() invalidates */
final class FakeSuperadmins
{
    /** @var list<string> */
    public array $asked = [];

    /** @param list<string> $superadmins */
    public function __construct(public array $superadmins = []) {}

    public function isSuperadminUser(string $uid): bool
    {
        $this->asked[] = $uid;
        return in_array($uid, $this->superadmins, true);
    }

    public function invalidateCache(): void {}
}
