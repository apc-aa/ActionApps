<?php

declare(strict_types=1);

namespace AA\Import\Credential;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A key, a password or a token - wrapped so that it cannot leak by accident.
 *
 * Same contract as AA\Credential\Secret; replaced by it once that class is in main.
 *
 * No __toString(): a secret never lands in a log, an error message or JSON just because somebody
 * concatenated it; reading it takes reveal(), which is greppable. var_dump() and json_encode()
 * show stars and PHP keeps the constructor parameter out of stack traces.
 *
 * It refuses serialize(): AA hashes memoized call parameters with it (AA::Contentcache()) and
 * sessions and debug dumps use it too - each would write the key out in clear.
 */
final class Secret implements JsonSerializable
{
    /**
     * Values live outside the object: var_export() and (array) ignore __debugInfo() and would
     * print a property in clear. Weak keys let a value go with its Secret.
     *
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $values = null;

    public function __construct(#[SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    /**
     * The real value. Every call site is a place to check that it cannot end up somewhere public:
     * pass it only to parameters marked #[\SensitiveParameter] - AA's debug trace prints the
     * beginning of string arguments.
     */
    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    /**
     * What a person may see: enough to tell two long keys apart, nothing of a short one - not
     * even its length, four characters of a short password are too much of it.
     */
    public function hint(): string
    {
        $value = $this->reveal();

        return mb_strlen($value) < 12 ? '***' : '…' . mb_substr($value, -4);
    }

    /**
     * A clone cannot reach the original's value without keeping it on the object again, and an
     * immutable key never needs a copy - pass the same Secret along.
     */
    public function __clone()
    {
        throw new LogicException('A Secret is not cloned - pass the same object');
    }

    public function jsonSerialize(): string
    {
        return '***';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('A Secret must not be serialized - pass the object, or reveal() it where it is used');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A Secret is never stored, so there is nothing to restore');
    }
}
