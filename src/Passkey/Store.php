<?php

declare(strict_types=1);

namespace AA\Passkey;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Txt;

/**
 * The passkeys of the accounts - the only way to the table authenticator. Not a slice: a slice admin or a
 * template could read or swap a public key, and the login looks a passkey up by its hashed id. Binary values
 * are stored as text (base64url, hex): a binary string literal in an utf8mb4 connection can fail in strict SQL mode.
 */
final class Store implements Credentials
{
    public const TABLE = 'authenticator';
    private const TYPE    = 'passkey';
    private const COLUMNS = 'SELECT `id`, `uid`, `rp_id`, `credential_id`, `public_key`, `sign_count`, `user_handle`, `aaguid`, `backup_eligible`, `backed_up`, `name`, `created`, `last_used` FROM `' . self::TABLE . '`';

    private static ?bool $available = null;

    /** false until sql_update.php created the table - AA then works as without passkeys */
    public function available(): bool
    {
        return self::$available ??= DB_AA::exists_table(self::TABLE);
    }

    /** forgets whether the table exists - for tests and right after the table is created */
    public static function reset(): void
    {
        self::$available = null;
    }

    public function find(string $credential_id): ?Credential
    {
        if (!$this->available()) {
            return null;
        }
        $row = DB_AA::select1([], self::COLUMNS, [['credential_hash', self::hash($credential_id)], ['type', self::TYPE]]);
        return is_array($row) ? self::credential($row) : null;
    }

    public function forUid(string $uid): array
    {
        return ($where = $this->where($uid)) ? array_map(self::credential(...), DB_AA::select([], self::COLUMNS, $where, ['created', 'id'])) : [];
    }

    public function has(string $uid): bool
    {
        return ($where = $this->where($uid)) && DB_AA::test(self::TABLE, $where);
    }

    public function count(string $uid): int
    {
        return ($where = $this->where($uid)) ? DB_AA::count(self::TABLE, $where) : 0;
    }

    public function add(Credential $credential): int
    {
        $id = AA::Metabase()->doInsert(self::TABLE, [
            'uid'             => $credential->uid,
            'type'            => self::TYPE,
            'rp_id'           => $credential->rpId,
            'credential_hash' => self::hash($credential->credentialId),
            'credential_id'   => Txt::bin2url($credential->credentialId),
            'public_key'      => $credential->publicKey,
            'sign_count'      => $credential->signCount,
            'user_handle'     => Txt::bin2url($credential->userHandle),
            'aaguid'          => $credential->aaguid,
            'backup_eligible' => (int) $credential->backupEligible,
            'backed_up'       => (int) $credential->backedUp,
            'name'            => $credential->name,
            'created'         => $credential->created,
            'last_used'       => $credential->lastUsed,
        ]);
        return is_int($id) ? $id : 0;
    }

    public function used(int $id, int $sign_count, int $time): void
    {
        AA::Metabase()->doUpdate(self::TABLE, ['id' => $id, 'sign_count' => $sign_count, 'last_used' => $time]);
    }

    /** @return bool false when nothing changed - a passkey of another account, or the same name */
    public function rename(string $uid, int $id, string $name): bool
    {
        return ($where = $this->where($uid)) && ((int) DB_AA::update(self::TABLE, [['name', Credential::cleanName($name, time())]], array_merge([['id', $id, 'i']], $where)) > 0);
    }

    /** @return ?Credential the deleted passkey; null for a passkey of another account */
    public function delete(string $uid, int $id): ?Credential
    {
        if (!($where = $this->where($uid))) {
            return null;
        }
        $where = array_merge([['id', $id, 'i']], $where);
        $row   = DB_AA::select1([], self::COLUMNS, $where);
        return (is_array($row) && ((int) DB_AA::delete(self::TABLE, $where) > 0)) ? self::credential($row) : null;
    }

    /** @return int how many passkeys of the account were deleted */
    public function deleteAll(string $uid): int
    {
        return ($where = $this->where($uid)) ? (int) DB_AA::delete(self::TABLE, $where) : 0;
    }

    /** @return list<string> the hosts passkeys were made on, the most used first */
    public function hosts(): array
    {
        if (!$this->available()) {
            return [];
        }
        return array_map('strval', array_keys(DB_AA::select(['rp_id' => 'cnt'], 'SELECT `rp_id`, COUNT(*) AS cnt FROM `' . self::TABLE . '`', [['type', self::TYPE]], ['cnt-'], ['rp_id'])));
    }

    /** @return ?list<array<int, mixed>> the condition of the account's passkeys; null with no account or no table */
    private function where(string $uid): ?array
    {
        return (($uid !== '') && $this->available()) ? [['uid', $uid], ['type', self::TYPE]] : null;
    }

    /** the indexed key of a credential id - the id itself may have up to 1023 bytes */
    private static function hash(string $credential_id): string
    {
        return hash('sha256', $credential_id);
    }

    /** @param array<string, mixed> $row */
    private static function credential(array $row): Credential
    {
        return new Credential(
            id: (int) $row['id'],
            uid: (string) $row['uid'],
            rpId: (string) $row['rp_id'],
            credentialId: (string) Txt::url2bin((string) $row['credential_id']),
            publicKey: (string) $row['public_key'],
            signCount: (int) $row['sign_count'],
            userHandle: (string) Txt::url2bin((string) $row['user_handle']),
            aaguid: (string) $row['aaguid'],
            backupEligible: (bool) $row['backup_eligible'],
            backedUp: (bool) $row['backed_up'],
            name: (string) $row['name'],
            created: (int) $row['created'],
            lastUsed: (int) $row['last_used'],
        );
    }
}
