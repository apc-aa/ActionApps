<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\DB\DB_AA;
use AA\Util\Txt;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * A record an import definition owns once (StreamState, Catalog): its id derives from the definition,
 * so a second create() replaces the first instead of adding another. Read past the per-request cache
 * of AA_Object::load(). Values are stored as ASCII JSON - object_text.value is cp1250 or utf8mb3 on
 * some installs, where a 4-byte character would break the JSON.
 */
trait OwnedByDefinition
{
    /** the stored JSON could not be read - the record acts as empty (a full import, an empty catalog) */
    private bool $damaged = false;

    /** @return list<string> the properties the class keeps; store() writes them */
    abstract protected static function ownProperties(): array;

    /** an empty record of $defId; its store() replaces the stored one */
    public static function create(string $defId): static
    {
        $record = new static();
        $record->setId(self::idOf($defId));
        $record->setOwnerId(self::defId($defId));
        return $record;
    }

    /** the record of $defId as stored now; null when there is none (or something foreign at its id) */
    public static function forDefinition(string $defId): ?static
    {
        $rows = self::rowsOf($defId, []);
        if (!self::isOwn($rows, $defId)) {
            return null;
        }
        $record = self::create($defId);
        foreach (static::ownProperties() as $property) {
            $record->$property = $rows[$property] ?? '';
        }
        return $record;
    }

    public static function idOf(string $defId): string
    {
        return md5(static::class . ':' . self::defId($defId));
    }

    /** Deletes the record of $defId (or a half-written one) without reading its values; a foreign record
     *  at its id stays. In a transaction the read locks: a plain read sees the transaction's snapshot,
     *  which misses a record the end of a concurrent step has just created.
     *  @return bool whether there was one to delete */
    public static function deleteFor(string $defId): bool
    {
        $rows = self::rowsOf($defId, ['aa_type', 'aa_owner'], DB_AA::inTransaction());
        if (!$rows || self::foreignAt($rows, $defId)) {
            return false;
        }
        self::deleteObjects([self::idOf($defId)]);
        return true;
    }

    public function damaged(): bool
    {
        return $this->damaged;
    }

    /** The first store saves the whole record, later ones change only ownProperties() - update() of a
     *  record that is not there (never stored, or deleted when the settings changed) would leave rows
     *  without aa_type and aa_owner that nothing finds or deletes.
     *  The caller holds the run's lock: commitStep() holds the run row FOR UPDATE, and a settings change
     *  ends the run under that lock before it deletes the record - no one deletes it in between.
     *  A half-written record of this class at the id (no aa_owner) is saved whole again - the id derives
     *  from the definition, so it cannot be another definition's.
     *  @throws UnexpectedValueException when the id holds a record of another type or owner, or this
     *                                   object was not made by create() / forDefinition() */
    private function storeOwned(): void
    {
        $defId = (string) $this->getOwnerId();
        if ($this->getId() !== self::idOf($defId)) {
            throw new UnexpectedValueException(static::class . ' is stored only at the id of its definition');
        }
        DB_AA::transaction(function () use ($defId): void {
            $rows = self::rowsOf($defId, ['aa_type', 'aa_owner']);
            if (self::isOwn($rows, $defId)) {
                $this->update(static::ownProperties());
            } elseif (self::foreignAt($rows, $defId)) {
                throw new UnexpectedValueException('The id of ' . static::class . " of $defId holds another record");
            } else {
                $this->save();
            }
        });
    }

    /** @param list<string> $properties [] = all
     *  @param bool $lock SELECT ... FOR UPDATE - only inside a transaction
     *  @return array<string, string> property => value */
    private static function rowsOf(string $defId, array $properties, bool $lock = false): array
    {
        return self::textRows(self::idOf($defId), $lock, $properties);
    }

    /** @param array<string, string> $rows aa_type and aa_owner at idOf($defId) */
    public static function isOwn(array $rows, string $defId): bool
    {
        return (($rows['aa_type'] ?? '') === static::class) && (($rows['aa_owner'] ?? '') === self::defId($defId));
    }

    /** Another record holds the id of the record of $defId: store() throws on it and deleteFor() leaves
     *  it. Not a half-written record of this class, which the next store saves whole.
     *  @param array<string, string> $rows aa_type and aa_owner at idOf($defId) */
    public static function foreignAt(array $rows, string $defId): bool
    {
        return $rows && !self::isOwn($rows, $defId) && !self::isHalfOwn($rows);
    }

    /** @param array<string, string> $rows */
    private static function isHalfOwn(array $rows): bool
    {
        return (($rows['aa_type'] ?? '') === static::class) && !isset($rows['aa_owner']);
    }

    /** ids are stored lower case; a mistyped id is a bug of the caller, not "no record" */
    private static function defId(string $defId): string
    {
        $defId = strtolower($defId);
        if (preg_match('/^[0-9a-f]{32}\z/', $defId) !== 1) {
            throw new InvalidArgumentException('A definition id is a long id');
        }
        return $defId;
    }

    /** A JSON object even for a list, so numeric keys stay keys; $depth levels at most, so what is
     *  stored can always be read back. */
    private static function encode(array $value, int $depth): string
    {
        return json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, $depth);
    }

    /** [] for what cannot be read, and the record says damaged() - a broken value must not stop a step */
    private function decode(string $json, int $depth): array
    {
        $value = self::decoded($json, $depth);
        if ($value === null) {
            $this->damaged = true;
        }
        return $value ?? [];
    }

    /** @return ?array null when the JSON cannot be read */
    private static function decoded(string $json, int $depth): ?array
    {
        if ($json === '') {
            return [];
        }
        try {
            // json_decode() needs one level more than json_encode() for the same data
            $value = json_decode($json, true, $depth + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $value = null;
        }
        return is_array($value) ? $value : null;
    }

    /** a text from the source as stored: valid UTF-8 of at most $bytes, without control characters
     *  and bidi overrides, which would garble the admin page it is shown on */
    private static function clean(string $text, int $bytes): string
    {
        return (string) preg_replace(['/\p{Cc}/u', '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'], [' ', ''], Txt::utf8Cut($text, $bytes));
    }

    private static function hasControls(string $text): bool
    {
        return preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text) === 1;
    }
}
