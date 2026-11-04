<?php

declare(strict_types=1);

namespace AA\Passkey;

/** The passkeys a ceremony reads and writes - Store in AA, an array in the tests. */
interface Credentials
{
    /** the passkey of this id, made on any host */
    public function find(string $credential_id): ?Credential;

    /** @return list<Credential> all passkeys of the account, on any host */
    public function forUid(string $uid): array;

    /** @return int id of the stored passkey */
    public function add(Credential $credential): int;

    public function used(int $id, int $sign_count, int $time): void;
}
