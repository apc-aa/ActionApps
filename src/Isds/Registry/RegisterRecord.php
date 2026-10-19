<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\IO\DB\DB_AA;
use AA_Object;
use AA_Property;

/**
 * What DownloadedRegister vouches for the register in the upload directory with
 * (DownloadedRegister::RECORD), kept in the install's database, which other PHP users of the server
 * cannot write into. One record per install, as Security\Settings.
 */
final class RegisterRecord extends AA_Object
{
    protected $xml = '';
    protected $size = '';
    protected $time = '';

    public static function getClassProperties(): array
    {
        $props = [];
        foreach (array_keys(DownloadedRegister::RECORD) as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    /** @return array<string, string> DownloadedRegister::RECORD as stored now - past load()'s per-request
     *          cache */
    public static function read(): array
    {
        $rows = self::textRows(self::id(), false, array_keys(DownloadedRegister::RECORD));
        return array_merge(DownloadedRegister::RECORD, array_intersect_key($rows, DownloadedRegister::RECORD));
    }

    /**
     * Stores the fields of $values, keeps the others - in one transaction, so a reader sees all of a
     * change or none of it; a stored record gets only those fields, so two writers of other fields
     * lose nothing.
     * @param array<string, string|int> $values fields of DownloadedRegister::RECORD
     */
    public static function write(array $values): void
    {
        $values = array_intersect_key($values, DownloadedRegister::RECORD);
        // update([]) would delete the whole object, aa_type and aa_owner too
        if ($values === []) {
            return;
        }
        DB_AA::transaction(static function () use ($values): void {
            $record = new self();
            $record->setNew(self::id(), (string) constant('AA_ID'));
            foreach ($values as $field => $value) {
                $record->$field = (string) $value;
            }
            // update() of a record that is not there would leave rows without aa_type and aa_owner
            if ((self::textRows(self::id(), true, ['aa_type'])['aa_type'] ?? '') === self::class) {
                $record->update(array_keys($values));
            } else {
                $record->save();
            }
        });
    }

    /** derived from AA_ID, so it cannot meet a random object id */
    private static function id(): string
    {
        return md5('aa-isds-register:' . constant('AA_ID'));
    }
}
