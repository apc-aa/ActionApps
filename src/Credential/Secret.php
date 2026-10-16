<?php

declare(strict_types=1);

namespace AA\Credential;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A key, a password or a token - wrapped so that it cannot leak by accident.
 *
 * No __toString(): a secret never lands in a log line, an error message, a template or a JSON
 * response just because somebody concatenated it. Reading the real value takes saying so
 * (reveal()), which is greppable. The value is not a property of the object, so no dump shows
 * it (var_dump(), var_export(), print_r(), an (array) cast), json_encode() shows stars, and the
 * constructor parameter is marked sensitive, so PHP keeps it out of stack traces (PHP 8.2).
 * With no property to look at, == finds any two Secrets equal: compare them only as
 * hash_equals($a->reveal(), $b->reveal()).
 *
 * It also refuses to be serialized, which matters more than it sounds: AA hashes the parameters
 * of a memoized call with serialize() (AA::Contentcache()->get_result()), and a session or a
 * debug dump does the same - a Secret passed along either way would write the key out in clear.
 */
final class Secret implements JsonSerializable
{
    /**
     * Values live outside the objects: var_export() and an (array) cast ignore __debugInfo() and
     * would print a property in clear. Weak keys let a value go together with its Secret.
     *
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $values = null;

    public function __construct(#[SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        if (isset(self::$values[$this])) {
            throw new LogicException('A Secret keeps the value it was made with');
        }
        self::$values[$this] = $value;
    }

    /**
     * The real value. Every call site is a place to check that it cannot end up somewhere public:
     * hand it only to parameters marked #[\SensitiveParameter] - AA's debug trace prints the
     * beginning of every other string argument (include/debug.class.php).
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
     * even its length, four characters of a short password are a big part of it.
     */
    public function hint(): string
    {
        $value = $this->reveal();

        return mb_strlen($value) < 12 ? '***' : '…' . mb_substr($value, -4);
    }

    /**
     * A copy would need its value on the object again, and an immutable key never needs one -
     * pass the same Secret along.
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
