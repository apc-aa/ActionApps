<?php

declare(strict_types=1);

namespace AA\Passkey;

/** One passkey of an account, as the table authenticator keeps it. The ids are raw bytes. */
final class Credential
{
    public function __construct(
        public readonly int $id,
        /** AA_Perm::passkeyId() of the account - the same person always gets the same one */
        public readonly string $uid,
        /** the host it was made on - a browser offers it nowhere else */
        public readonly string $rpId,
        public readonly string $credentialId,
        public readonly string $publicKey,
        public readonly int $signCount,
        /** random, the same for all passkeys of the account; the authenticator returns it at login */
        public readonly string $userHandle,
        public readonly string $aaguid,
        /** the passkey may be copied to the user's other devices (BE flag) ... */
        public readonly bool $backupEligible,
        /** ... and it is (BS) - a passkey without it is lost with its device */
        public readonly bool $backedUp,
        public readonly string $name,
        public readonly int $created,
        public readonly int $lastUsed,
    ) {}

    /** a name the user recognises the passkey by: one line that fits the column; the date when none is given */
    public static function cleanName(string $name, int $now): string
    {
        $name = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $name));
        return ($name === '') ? date('Y-m-d H:i', $now) : mb_substr($name, 0, 100);
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            uid: $this->uid,
            rpId: $this->rpId,
            credentialId: $this->credentialId,
            publicKey: $this->publicKey,
            signCount: $this->signCount,
            userHandle: $this->userHandle,
            aaguid: $this->aaguid,
            backupEligible: $this->backupEligible,
            backedUp: $this->backedUp,
            name: $this->name,
            created: $this->created,
            lastUsed: $this->lastUsed,
        );
    }
}
