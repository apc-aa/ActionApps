<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\UnusableRegisterException;

/**
 * The register of data boxes given here instead of a file and the database. lookup() answers as
 * RegisterIndex's does: a state that is not usable throws.
 */
final class FakeRegisterIndex extends RegisterIndex
{
    /** @var list<list<string>> the boxes of each lookup() */
    public array $lookups = [];

    /**
     * @param string                                $given  the state()
     * @param ?array<string, array{string, string}> $boxes  box id => its IČO as lookup() gives it ('' none, BAD_ICO) and
     *                                                      its official name; null = every box asked for, without either
     * @param bool                                  $breaks lookup() finds the index replaced and cannot make it again -
     *                                                      the state turns UNREADABLE
     */
    public function __construct(private string $given = self::OK, private ?array $boxes = [], private bool $breaks = false)
    {
        parent::__construct('/nonexistent/register.xml');
    }

    /** a register that has every box, none with an IČO - for what is not about the register */
    public static function permissive(): self
    {
        return new self(self::OK, null);
    }

    public function state(): string
    {
        return $this->given;
    }

    public function lookup(array $boxes): array
    {
        $this->lookups[] = $boxes;
        if ($this->breaks) {
            $this->given = self::UNREADABLE;
        }
        if (!$this->usable()) {
            throw new UnusableRegisterException("The register of data boxes is $this->given");
        }
        $found = [];
        foreach ($boxes as $box) {
            [$ico, $name] = is_null($this->boxes) ? ['', ''] : ($this->boxes[$box] ?? [null, null]);
            if (!is_null($ico)) {
                $found[$box] = ['id' => $box, 'ico' => $ico, 'name' => (string) $name];
            }
        }

        return $found;
    }
}
